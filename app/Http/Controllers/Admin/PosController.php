<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\CompanySetting;
use App\Models\LedgerAccount;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleDelivery;
use App\Models\StockMovement;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The POS counter: scan/search an item, pick or quick-create a customer,
 * take payment, and print a thermal receipt — all in one action. Unlike
 * the back-office Sale flow (create as 'pending', deliver later), a POS
 * checkout creates the Sale, delivers it in full, and collects payment for
 * it in a single DB transaction, reusing the exact stock/ledger posting
 * shape of SaleController::deliver() and CollectionController::store().
 */
class PosController extends Controller implements HasMiddleware
{
    public const WALKIN_PHONE = Party::WALKIN_PHONE;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:pos.view', only: ['index', 'products', 'receipt']),
            new Middleware('permission:pos.sell', only: ['checkout', 'storeCustomer']),
        ];
    }

    public function index()
    {
        $siteId = $this->currentSiteId();
        $accounts = LedgerAccount::where('group', 'cash_bank')->where('status', true)->orderBy('name')->get(['id', 'name', 'is_cash']);
        $customers = Party::where('is_customer', true)->where('status', true)->orderBy('name')
            ->get(['id', 'name', 'phone'])
            ->map(fn ($party) => (object) [
                'id' => $party->id,
                'name' => "{$party->name} — {$party->phone}",
                'phone' => $party->phone,
            ]);

        return view('admin.pos.index', compact('siteId', 'accounts', 'customers'));
    }

    /**
     * Barcode/name lookup for the POS item picker. An exact barcode/SKU
     * match (what a scanner's "type code + Enter" produces) is returned
     * alone; otherwise a short LIKE search across name/sku/barcode. Every
     * result carries its live stock balance at the cashier's site so the
     * terminal can block adding more than is actually on hand.
     */
    public function products(Request $request)
    {
        $q = trim((string) $request->get('q'));
        $siteId = $this->currentSiteId();

        if ($q === '' || ! $siteId) {
            return response()->json(['results' => []]);
        }

        $exactProduct = Product::where('status', true)->where('has_variants', false)
            ->where(fn ($query) => $query->where('barcode', $q)->orWhere('sku', $q))
            ->first();
        $exactVariant = ProductVariant::with('product')->where('status', true)
            ->where(fn ($query) => $query->where('barcode', $q)->orWhere('sku', $q))
            ->first();

        if ($exactProduct || $exactVariant) {
            $matches = collect([$exactVariant, $exactProduct])->filter();
        } else {
            $products = Product::with('stockUnit')->where('status', true)->where('has_variants', false)
                ->where(fn ($query) => $query->where('name', 'like', "%{$q}%")
                    ->orWhere('sku', 'like', "%{$q}%")
                    ->orWhere('barcode', 'like', "%{$q}%"))
                ->limit(15)->get();

            $variants = ProductVariant::with(['product.stockUnit', 'attributeValues'])->where('status', true)
                ->where(fn ($query) => $query->where('sku', 'like', "%{$q}%")
                    ->orWhere('barcode', 'like', "%{$q}%")
                    ->orWhereHas('product', fn ($productQuery) => $productQuery->where('name', 'like', "%{$q}%")))
                ->whereHas('product', fn ($query) => $query->where('status', true)->where('has_variants', true))
                ->limit(15)->get();

            $matches = $products->concat($variants);
        }

        $results = $matches->map(function ($item) use ($siteId) {
            $isVariant = $item instanceof ProductVariant;
            $product = $isVariant ? $item->product : $item;

            return [
                'id' => $isVariant ? "variant-{$item->id}" : "product-{$item->id}",
                'name' => $isVariant ? "{$product->name} — {$item->label} ({$item->sku})" : "{$product->name} ({$product->sku})",
                'unit' => $product->stockUnit?->short_name,
                'price' => (float) ($isVariant ? ($item->selling_price ?? $product->selling_price) : $product->selling_price),
                'available_qty' => StockMovement::balanceFor($product->id, $isVariant ? $item->id : null, $siteId),
            ];
        })->values();

        return response()->json(['results' => $results]);
    }

    /**
     * Minimal quick-create for a walk-in-style customer captured at the
     * counter — a dedicated endpoint (not a reuse of PartyController::store())
     * so a cashier only needs `pos.sell`, not the general `parties.create`
     * permission.
     */
    public function storeCustomer(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30', 'unique:parties,phone'],
        ]);

        $party = Party::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'is_customer' => true,
            'is_supplier' => false,
            'is_company' => false,
            'opening_balance' => 0,
            'opening_balance_type' => 'due',
            'status' => true,
        ]);

        return response()->json(['id' => $party->id, 'name' => $party->name, 'phone' => $party->phone], 201);
    }

    /**
     * Create + deliver + collect payment for one counter sale, atomically.
     * Mirrors SaleController::deliver()'s ledger lines (Dr Accounts
     * Receivable/Discount Allowed, Cr Sales Revenue, Dr COGS, Cr Inventory)
     * followed by CollectionController::store()'s ledger lines (Dr the
     * chosen cash/bank account, Cr Accounts Receivable) — two balanced
     * LedgerTransactions in one DB transaction.
     */
    public function checkout(Request $request)
    {
        $siteId = $this->currentSiteId();

        if (! $siteId) {
            throw ValidationException::withMessages(['site_id' => 'Select a site before selling.']);
        }

        $validated = $request->validate([
            'party_id' => ['nullable', 'integer', 'exists:parties,id'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'ledger_account_id' => ['required', 'integer', 'exists:ledger_accounts,id'],
            'cash_tendered' => ['nullable', 'numeric', 'min:0'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $party = $validated['party_id'] ?? null
            ? Party::findOrFail($validated['party_id'])
            : Party::walkIn();

        $account = LedgerAccount::where('group', 'cash_bank')->findOrFail($validated['ledger_account_id']);

        $rows = [];
        foreach ($validated['items'] as $i => $row) {
            [$product, $variant] = $this->resolveItem($row['item']);

            if (! $product) {
                throw ValidationException::withMessages(["items.{$i}.item" => 'Pick a valid product or variant.']);
            }

            $available = StockMovement::balanceFor($product->id, $variant?->id, $siteId);

            if ((float) $row['quantity'] > $available + 0.00001) {
                throw ValidationException::withMessages([
                    "items.{$i}.quantity" => "{$product->name}: only {$available} in stock at this site.",
                ]);
            }

            $unitPrice = (float) ($variant?->selling_price ?? $product->selling_price);

            $rows[] = [
                'product' => $product,
                'variant' => $variant,
                'quantity' => $row['quantity'],
                'unit_price' => $unitPrice,
                'subtotal' => round($row['quantity'] * $unitPrice, 2),
                'avg_cost' => (float) ($variant?->estimated_cost ?? $product->estimated_cost),
            ];
        }

        $subtotal = collect($rows)->sum('subtotal');
        $discount = min((float) ($validated['discount_amount'] ?? 0), $subtotal);
        $total = round($subtotal - $discount, 2);
        $today = now()->toDateString();

        $referenceNo = $validated['reference_no'] ?? null;

        $sale = DB::transaction(function () use ($rows, $subtotal, $discount, $total, $party, $account, $siteId, $today, $referenceNo) {
            $sale = Sale::create([
                'sale_no' => 'PENDING',
                'party_id' => $party->id,
                'site_id' => $siteId,
                'status' => 'pending',
                'channel' => 'pos',
                'order_date' => $today,
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'created_by' => Auth::id(),
            ]);
            $sale->update(['sale_no' => 'SO-'.str_pad($sale->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($rows as $i => $row) {
                $rows[$i]['saleItem'] = $sale->items()->create([
                    'product_id' => $row['product']->id,
                    'product_variant_id' => $row['variant']?->id,
                    'quantity' => $row['quantity'],
                    'unit_price' => $row['unit_price'],
                    'subtotal' => $row['subtotal'],
                ]);
            }

            $delivery = SaleDelivery::create([
                'sale_id' => $sale->id,
                'delivery_no' => 'PENDING',
                'fulfillment_type' => 'self',
                'delivered_date' => $today,
                'delivered_by' => Auth::id(),
            ]);
            $delivery->update(['delivery_no' => 'INV-'.str_pad($delivery->id, 6, '0', STR_PAD_LEFT)]);

            $grossRevenue = 0;
            $cogsValue = 0;

            foreach ($rows as $row) {
                $saleItem = $row['saleItem'];

                $delivery->items()->create([
                    'sale_item_id' => $saleItem->id,
                    'quantity' => $row['quantity'],
                ]);

                StockMovement::create([
                    'product_id' => $row['product']->id,
                    'product_variant_id' => $row['variant']?->id,
                    'site_id' => $siteId,
                    'type' => 'sale',
                    'quantity' => $row['quantity'],
                    'unit_cost' => $row['avg_cost'],
                    'moved_at' => $today,
                    'created_by' => Auth::id(),
                    'reference_type' => SaleDelivery::class,
                    'reference_id' => $delivery->id,
                ]);

                $saleItem->update(['delivered_quantity' => $saleItem->delivered_quantity + $row['quantity']]);

                $grossRevenue += $row['subtotal'];
                $cogsValue += round($row['quantity'] * $row['avg_cost'], 2);
            }

            $discountForDelivery = round($grossRevenue * $sale->discountRate(), 2);
            $netReceivable = round($grossRevenue - $discountForDelivery, 2);

            $revenueLines = [
                ['account' => 'accounts_receivable', 'debit' => $netReceivable, 'party_id' => $party->id],
            ];
            if ($discountForDelivery > 0) {
                $revenueLines[] = ['account' => 'discount_allowed', 'debit' => $discountForDelivery];
            }
            $revenueLines[] = ['account' => 'sales_revenue', 'credit' => $grossRevenue];
            $revenueLines[] = ['account' => 'cost_of_goods_sold', 'debit' => $cogsValue];
            $revenueLines[] = ['account' => 'inventory', 'credit' => $cogsValue];

            LedgerService::post([
                'type' => 'sale',
                'date' => $today,
                'site_id' => $siteId,
                'narration' => "POS sale {$sale->sale_no}",
                'reference' => $delivery,
                'created_by' => Auth::id(),
                'lines' => $revenueLines,
            ]);

            $sale->recalculateStatus();

            $collection = Collection::create([
                'collection_no' => 'PENDING',
                'party_id' => $party->id,
                'sale_id' => $sale->id,
                'ledger_account_id' => $account->id,
                'amount' => $netReceivable,
                'collection_date' => $today,
                'reference_no' => $referenceNo,
                'note' => "POS payment for {$sale->sale_no}",
                'created_by' => Auth::id(),
            ]);
            $collection->update(['collection_no' => 'COL-'.str_pad($collection->id, 6, '0', STR_PAD_LEFT)]);

            LedgerService::post([
                'type' => 'payment_in',
                'date' => $today,
                // Same convention as CollectionController: the receiving
                // account's own site wins if it's tied to one, else fall
                // back to the site the sale itself happened at.
                'site_id' => $account->site_id ?? $siteId,
                'narration' => "POS payment for {$sale->sale_no} ({$collection->collection_no})",
                'reference' => $collection,
                'created_by' => Auth::id(),
                'lines' => [
                    ['account' => $account, 'debit' => $netReceivable],
                    ['account' => 'accounts_receivable', 'party_id' => $party->id, 'credit' => $netReceivable],
                ],
            ]);

            return $sale;
        });

        return response()->json([
            'sale_id' => $sale->id,
            'sale_no' => $sale->sale_no,
            'receipt_url' => route('pos.receipt', $sale),
        ]);
    }

    public function receipt(Sale $sale)
    {
        $sale->load([
            'party', 'site', 'creator',
            'items.product.stockUnit', 'items.productVariant.attributeValues',
            'collections.account',
        ]);
        $company = CompanySetting::current();

        return view('admin.pos.receipt', compact('sale', 'company'));
    }

    protected function currentSiteId(): ?int
    {
        return session('current_site_id', Auth::user()?->current_site_id);
    }

    /**
     * Split the "product-{id}"/"variant-{id}" picker value back into its
     * parent Product (and ProductVariant, when it's a variant row) — same
     * convention as PurchaseController/SaleController's resolveItem().
     */
    protected function resolveItem(?string $item): array
    {
        if (! $item || ! str_contains($item, '-')) {
            return [null, null];
        }

        [$kind, $id] = explode('-', $item, 2);

        if ($kind === 'product') {
            $product = Product::where('status', true)->where('has_variants', false)->find($id);

            return [$product, null];
        }

        if ($kind === 'variant') {
            $variant = ProductVariant::with('product')->where('status', true)->find($id);

            return [$variant?->product, $variant];
        }

        return [null, null];
    }
}
