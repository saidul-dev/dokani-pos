<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\ContactMessage;
use App\Models\HeroSlide;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Site;
use Illuminate\Http\Request;

class WebsiteController extends Controller
{
    public function home()
    {
        $company = CompanySetting::current();

        if ($company->ecommerce_enabled) {
            return $this->ecommerceHome($company);
        }

        // Filtered in PHP rather than a query HAVING clause: withCount's
        // products_count isn't a GROUP BY aggregate, and SQLite (unlike
        // MySQL) rejects HAVING on a plain column with no GROUP BY.
        $categories = Category::whereNull('parent_id')->where('status', true)
            ->withCount('products')
            ->with(['products' => fn ($q) => $q->where('status', true)->whereNotNull('image_path')->limit(1)])
            ->orderBy('name')
            ->get()
            ->filter(fn (Category $category) => $category->products_count > 0)
            ->values();

        $products = Product::with('stockUnit')
            ->where('status', true)->where('has_variants', false)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        // Top-of-page highlight row — prefer products with a real photo
        // (they carry the card visually), topped up with the newest
        // otherwise so the row still has 3 even with no photos uploaded yet.
        $featuredProducts = $products->sortByDesc(fn (Product $p) => $p->image_path ? 1 : 0)->take(3)->values();

        $branches = Site::where('status', true)->orderBy('name')->get();

        return view('website.home', [
            'company' => $company,
            'categories' => $categories,
            'products' => $products,
            'featuredProducts' => $featuredProducts,
            'branches' => $branches,
            'heroStats' => $this->catalogStats(),
        ]);
    }

    /**
     * The shopping-style homepage (see website/home-shop.blade.php) — only
     * ever reached once ecommerce_enabled is on (checked in home() above).
     */
    private function ecommerceHome(CompanySetting $company)
    {
        $slides = HeroSlide::where('status', true)->orderBy('sort_order')->orderBy('id')->get();

        $categories = Category::whereNull('parent_id')->where('status', true)
            ->orderBy('name')
            ->get();

        $flashSaleProducts = Product::where('status', true)->where('is_flash_sale', true)
            ->orderByDesc('id')->limit(12)->get();

        $featuredProducts = Product::where('status', true)->where('is_featured', true)
            ->orderByDesc('id')->limit(6)->get();

        $latestProducts = Product::where('status', true)
            ->orderByDesc('id')->limit(6)->get();

        // Ranked by total quantity ever sold (any non-cancelled sale,
        // POS or online) — not a curated flag, so it reflects real demand.
        $bestSellerIds = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', '!=', 'cancelled')
            ->selectRaw('sale_items.product_id, SUM(sale_items.quantity) as total_qty')
            ->groupBy('sale_items.product_id')
            ->orderByDesc('total_qty')
            ->limit(6)
            ->pluck('product_id');

        $bestSellers = Product::where('status', true)->whereIn('id', $bestSellerIds)->get()
            ->sortBy(fn (Product $p) => array_search($p->id, $bestSellerIds->all()))
            ->values();

        // No sales yet (fresh install, or just no online/POS orders today)
        // means the "Best Sellers" ranking has nothing to show — the
        // cheapest products fill that column instead so it's never empty.
        $thirdColumnIsBestSellers = $bestSellers->isNotEmpty();
        $thirdColumn = $thirdColumnIsBestSellers
            ? $bestSellers
            : Product::where('status', true)->orderBy('selling_price')->limit(6)->get();

        return view('website.home-shop', [
            'company' => $company,
            'slides' => $slides,
            'categories' => $categories,
            'flashSaleProducts' => $flashSaleProducts,
            'featuredProducts' => $featuredProducts,
            'latestProducts' => $latestProducts,
            'thirdColumn' => $thirdColumn,
            'thirdColumnIsBestSellers' => $thirdColumnIsBestSellers,
        ]);
    }

    public function about()
    {
        return view('website.about', [
            'company' => CompanySetting::current(),
            'branches' => Site::where('status', true)->orderBy('name')->get(),
            'stats' => $this->catalogStats(),
        ]);
    }

    /**
     * Real figures for the hero/about "at a glance" stat rows — never
     * invented copy, so it stays honest as the catalog grows (or starts
     * empty).
     */
    private function catalogStats(): array
    {
        return [
            'products' => Product::where('status', true)->count(),
            'categories' => Category::whereNull('parent_id')->where('status', true)
                ->whereHas('products', fn ($q) => $q->where('status', true))
                ->count(),
            'branches' => Site::where('status', true)->count(),
        ];
    }

    /**
     * Static placeholder — no gallery/press content model exists yet
     * (explicit decision: ship the page design now, wire real content
     * later).
     */
    public function media()
    {
        return view('website.media', ['company' => CompanySetting::current()]);
    }

    /**
     * Static "we're hiring" page — no job-posting management exists yet
     * (explicit decision: a real listings feature is a separate, later
     * piece of work).
     */
    public function career()
    {
        return view('website.career', ['company' => CompanySetting::current()]);
    }

    /**
     * Static pricing page — one-time setup + monthly maintenance copy is
     * hardcoded for now (explicit decision: ship the page design now,
     * wire real, admin-editable pricing later).
     */
    public function pricing()
    {
        return view('website.pricing', ['company' => CompanySetting::current()]);
    }

    public function contact()
    {
        return view('website.contact', [
            'company' => CompanySetting::current(),
            'branches' => Site::where('status', true)->orderBy('name')->get(),
        ]);
    }

    public function submitContact(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        ContactMessage::create($validated);

        return back()->with('success', "Thanks, {$validated['name']} — we've received your message and will get back to you soon.");
    }
}
