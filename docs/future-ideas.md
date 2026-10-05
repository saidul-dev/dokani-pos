# Future Feature Ideas

A running backlog of feature ideas that are not being implemented yet. When a new idea
comes up, append a new entry below (don't edit/remove older entries unless they get
implemented or dropped). When an idea is picked up for implementation, move its entry
into a proper spec under `docs/superpowers/specs/` and mark it `Status: In Progress`
here.

---

## AI Auto Issue Resolver (production self-healing with admin approval)

- **Added:** 2026-07-14
- **Status:** Idea (not scoped, not started)

### Problem

Production (`abc.com`, i.e. `erp.vexasoft.net`) has no error monitoring today. When an
exception happens, nobody knows until a user reports it, and fixing it requires a
developer to reproduce, diagnose, and patch manually.

### Idea

Build a self-healing loop that uses AI to shrink the time between "exception happens in
production" and "verified fix is live," with a human admin approval gate before anything
ever touches production.

Rough flow as originally proposed:

1. An exception occurs on the live site. It's captured and stored as an error log entry
   with an `unresolved` / `unchecked` status, along with an AI-generated "most probable
   solution" note written at capture time.
2. Admin opens the error log dashboard and sees the list of unresolved errors with their
   AI-suggested solutions.
3. Admin clicks "Check" on an error → gets redirected into `staging.abc.com`, where the
   same scenario that caused the error is reproduced (originally proposed as cloning the
   full production DB into staging; see feasibility notes below for why this needs to be
   narrower).
4. Admin waits on the live site until staging is ready with the reproduced scenario, then
   moves to staging to verify.
5. Once staging reproduces the issue and a candidate fix is ready, admin reviews it on
   staging and clicks "Approve."
6. On approval, the fix is committed and pushed to GitHub, which triggers the existing
   `main` branch auto-deploy (`.github/workflows/deploy.yml`) to production.
7. Goal: shrink recurring/routine bug-fix turnaround without needing a developer in the
   loop for every incident.

### Feasibility notes (from initial discussion)

- **Error capture + AI suggestion** — straightforward, low risk. Standard pattern
  (comparable to Sentry-style error tracking) plus a Claude API call for root-cause /
  suggested-fix analysis. Good first phase, valuable standalone even without the rest.
- **Staging scenario replication** — cloning the *entire* production DB into staging per
  error is not practical: exposes customer PII into a second environment, is slow for a
  large DB, and adds ongoing storage/infra cost. A narrower approach (replaying just the
  specific request/job that triggered the error, with anonymized/sampled data) is more
  realistic and should be the actual design target.
- **Admin approval gate before deploy** — this is the right safety pattern and should not
  be weakened. AI-authored fixes should never auto-deploy without a human checking them
  on staging first.
- **AI auto-commit + push triggering the existing auto-deploy pipeline** — technically
  doable (Claude can generate a patch, commit, and push), but this is the highest-risk
  part of the system: a bad fix could go straight to production if review is rushed or
  the diagnosis is wrong. This does not eliminate the need for a developer — it changes
  the developer's role from "write the first draft" to "review and approve the AI's
  draft," which still matters most for architectural, security-sensitive, or complex
  business-logic bugs.

### Suggested decomposition when this gets picked up

Too large for a single spec/implementation pass. Natural sub-projects, in build order:

1. Error capture + storage + AI-suggested-solution note (dashboard, read-only, no
   automation of fixes or environments).
2. Admin review workflow UI (list, status, drill into an error).
3. Staging scenario replay (narrow, request/job-level — not full DB clone).
4. Approval-gated AI fix generation + commit/push into the existing deploy pipeline.

Each of these should go through its own brainstorming → spec → plan cycle when picked up.

---

## Bangladesh Product Master Catalog + Progressive Stock Initialization

- **Added:** 2026-09-21
- **Status:** Idea (not scoped, not started)

### Problem

Today `Product` ([app/Models/Product.php](../app/Models/Product.php)) is a single, flat table:
one row per product, owned outright by whichever shop creates it, with its own
`selling_price`, `estimated_cost`, and stock tracked via `StockMovement`
([docs/stock-movements.md](stock-movements.md)). There is no concept of a shared/central
product list, and no "shop hasn't told us its stock yet" state — a product's stock is
whatever the sum of its `StockMovement` rows says, which defaults to 0 the moment a
product row exists. Every shop that wants to sell, say, Lux Soap has to create that
product from scratch, price it, and set an opening stock before a sale can happen against
it.

For small/medium Bangladeshi grocery/retail shops with a shop-wide range in the
thousands of SKUs, requiring "create the product before you can sell it" up front is a
real onboarding barrier.

### Idea

Ship the software with a **preloaded, central Bangladesh Product Master Catalog**
(~10,000–20,000+ commonly sold products: brand, size, barcode, category, etc.), separate
from each shop's own inventory row for that product (price, stock, min-stock). A shop
gets access to the whole catalog immediately but starts with *zero* shop-inventory rows
against it. Stock for a catalog product is only ever established when the shop actually
needs it to be — via one of three moments, all treated as equally valid ways to
"initialize" a product:

1. **Manual bulk setup** — an optional screen where the owner searches/scans and types in
   current quantities for the products they actually stock, at their own pace.
2. **First sale** — scanning/selecting a catalog product that has no shop-inventory row
   yet does not block the sale. Instead it prompts once: "How many do you currently
   have?" — the answer becomes the opening stock, and the sale's quantity is deducted
   from it in the same step.
3. **First purchase** — recording a purchase of a catalog product with no shop-inventory
   row yet creates the row and sets the purchase quantity directly as opening stock (no
   extra question needed — the purchase itself is the count).

Critically, **"not initialized" must be a distinct state from "stock = 0"** — a shop that
hasn't touched a catalog product yet is different from a shop that has confirmed it is
out of stock, and reporting (low-stock/out-of-stock counts, dashboards) must not conflate
the two. See the worked example and full state machine in the original brainstorm
(section 4, 6, 14, 21 of the source spec — ask for it if this gets picked up, or
reconstruct from this summary).

Products with no usable barcode (loose rice, lentils, potatoes, local produce, etc.)
still need a per-shop "shop-specific product" escape hatch that doesn't require getting
into the central catalog at all. Symmetrically, a scanned barcode that matches nothing in
the central catalog should offer "create a shop-specific product" or "request catalog
addition" rather than dead-ending the sale.

### Why this is a bigger change than it sounds

This is not just a new screen — it reshapes the core `Product` model:

- **Central vs. shop-owned data must actually split.** Today `Product` holds both
  catalog-ish fields (name, brand, category, barcode, image) and shop-owned fields
  (`selling_price`, `estimated_cost`, `reorder_level`). This would need to become two
  things — a `ProductCatalog`-style table (central, admin-managed, shared across shops)
  and a `ShopProduct`/inventory table (per-shop price + stock status + min stock),
  1-to-many from catalog → shop rows. Every existing query/view that reads
  `$product->selling_price` etc. needs to resolve through the shop's row instead of the
  product's own row.
- **This system is currently single-shop, not multi-tenant.** There's a `Site` model
  (branches) but no evidence of shop-level data isolation the way this feature implies
  ("Shop A" vs "Shop B" each with their own price/stock for the same catalog product).
  Confirm whether "shop" here means the existing `Site`/branch concept (multiple
  branches of one business, each keeping its own stock of the same catalog item) before
  assuming a new multi-tenant concept is needed — that changes the shape of this
  significantly.
- **Sale/purchase flows need a new interstitial step.** `SaleController`/`PurchaseController`
  would need to detect "no shop-inventory row for this catalog product yet" mid-transaction
  and branch into the quantity-prompt UX before continuing, rather than assuming the
  product row (and its stock) always already exists.
- **Stock audit trail** (section 16) mostly already exists in spirit via
  `StockMovement`/[docs/stock-movements.md](stock-movements.md) — the main new piece is
  recording the *initialization* event itself (an "Opening Stock" movement type sourced
  from first-sale/first-purchase/manual-setup) as a first-class, distinguishable entry,
  not just inferring it after the fact.
- **Populating the actual 10,000–20,000 product catalog** (real Bangladeshi brands,
  barcodes, sizes) is a data-sourcing project in its own right, separate from the
  schema/UX work.

### Suggested decomposition when this gets picked up

1. Decide the shop/tenant model this assumes (existing `Site`, or a new concept) —
   this gates everything else.
2. Split `Product` into central catalog + per-shop inventory row, with a migration plan
   for existing data (every current `Product` row becomes both a catalog entry and a
   shop-inventory row so nothing existing breaks).
3. Add the "not initialized" stock status as a real, queryable state (not just an absent
   row) and wire dashboard counts (section 21) to respect it.
4. Build the first-sale / first-purchase "how many do you have?" interstitial in the POS
   and purchase flows.
5. Shop-specific (non-catalog) product creation + "product not found → create or request"
   flow for unmatched barcodes.
6. Manual bulk stock setup screen (search/scan + quantity, save-as-you-go).
7. Source and load the actual product catalog data (separate, ongoing data project).

Each of these should go through its own brainstorming → spec → plan cycle when picked up.

---

## "Daily Book" — quick daily Purchase/Sale/Expense/Capital entry + Summary dashboard

- **Added:** 2026-09-21
- **Status:** Partially implemented (2026-09-21). The "Daily Book" sidebar menu is live
  with six sublinks: Summary, Purchase Entry, Sale Entry, Expense Entry, Capital Entry,
  Settings.

  **Summary** (`daily-book.summary` route, `DailyBookController::summary()`,
  `resources/views/admin/daily-book/summary.blade.php`) — a day/week/month/**custom**
  toggle (custom range takes `from`/`to` query params, defaults to today when absent,
  auto-swaps a backwards range) showing, for the selected range: Total Purchase, Total
  Sale, Total Expense, Gross Profit (**Sale × profit %**), and Net Profit (Gross Profit −
  Expense) — both shown as "—" with a "set your profit %" prompt until Settings has a
  margin. Gross Profit was first built as Sale − Purchase; the client caught (2026-10-03)
  that this books every purchase as an immediate loss (৳1,00,000 invested + ৳20,000 of
  stock bought + nothing sold showed −৳20,000 profit, when it should be ৳0 — unsold stock
  is still the shop's inventory). With no per-item cost in Daily Book, the owner's margin %
  is the only cost basis, so it now drives Gross Profit directly, and the separate
  "Estimated Profit" card (which computed the same Sale × % figure) was folded into it.
  Plus, **independent of
  the range toggle**, an all-time **Cash in Hand** figure (Total Capital + cash actually
  received: sales' `paid_amount` and customer collections − cash actually paid out:
  purchases' `paid_amount`, expenses, and supplier payments — since the very first entry
  ever logged; see "Suppliers" and "Customers" below). **Sourced
  entirely from `DailyBookEntry`** — not from the real `Purchase`/`Sale`/`Expense` tables.
  This was originally built the other way around (reading the real tables) and shipped
  with a known gap — logging an entry via Purchase/Sale/Expense Entry below didn't move
  the Summary numbers at all, since they read from disconnected sources. That gap was
  reported by the client (2026-09-21) and fixed the same day by switching Summary to
  read `DailyBookEntry` instead; see "Open questions" below for why this direction was
  chosen over the alternatives considered. A first cut of Summary also had a
  "Net (Sale − Purchase − Expense)" card that the client called out as not a meaningful
  number on its own (mixing two unrelated cash flows) — it was removed in favor of the
  Gross Profit / Net Profit pair, which mirrors a real P&L's structure instead.

  **Purchase/Sale/Expense/Capital Entry** (`daily-book.entries.*` routes,
  `DailyBookController::entryIndex/entryCreate/entryStore()`,
  `resources/views/admin/daily-book/entry-{index,create}.blade.php`) — explicitly a
  **separate, standalone quick-log**, NOT a lightweight way to create real
  Purchase/Sale/Expense/CapitalTransaction records. Backed by a new `daily_book_entries`
  table (`App\Models\DailyBookEntry`, `type` + `entry_date` + `amount` + optional
  `site_id`/`note`) that shares nothing with `purchases`/`sales`/`expenses`/
  `capital_transactions` and is never written to `LedgerTransaction` or `StockMovement`.
  This was a deliberate decision: the shop owner wants a fast day/amount/note log with
  zero accounting or stock side effects — it does **not** feed Accounts and does **not**
  feed Inventory (it does now feed Summary, per the fix above). The Site picker on the
  Entry form is present in the DOM but hidden (`class="hidden"` wrapper, not removed) per
  a later client request — the field and its backend fallback to `current_site_id` still
  work exactly as before, it's just not shown, keeping the form to the fewest visible
  decisions possible.

  **Capital** is the Daily Book stand-in for the real system's
  `CapitalTransaction` 'investment' type (see `app/Models/CapitalTransaction.php`) — how
  the owner records putting money into the business, at any time, repeatably. There is
  deliberately no separate "starting balance" concept: the client first asked for a
  locked, one-time "Initial Balance" setting, then asked to be able to invest into the
  business at any point, which subsumes the one-time-balance idea — so it was dropped in
  favor of just letting the first Capital entry serve as the effective starting point.

  **Suppliers** (2026-10-03; `daily-book.suppliers.*` routes, `DailyBookController::
  supplierIndex/supplierStore/supplierPay()`,
  `resources/views/admin/daily-book/supplier-index.blade.php`) — credit purchases from a
  supplier/wholesaler. First shipped under the name "Mohajon" (মহাজন, the everyday
  Bangladeshi word for the wholesaler you buy from on credit); the client renamed it to
  "Supplier" the same day, matching the full system's wording (সরবরাহকারী in Bangla).
  It **reuses the existing `parties` table** (a supplier is a Party with `is_supplier`),
  so the same people appear in the full system's Parties module after an upgrade — no
  separate supplier list. Suppliers can be added from the Suppliers page ("Add
  Supplier": name + phone; a phone that's already a customer gets the supplier flag too,
  one that's already a supplier is refused) or quick-added on a Purchase Entry (a phone
  already on file is reused, and the success message names who it was recorded under).
  The purchase form's "Paid now" is required and always typed by the owner (or filled by a
  "Full amount" button) — it was first auto-synced to Amount, but the client asked
  (2026-10-05) for it to stay empty until typed, so a forgotten field can't silently record
  a credit purchase as paid. Anything less than Amount is a due, and a due **requires** a
  supplier (enforced client- and server-side). Due per supplier =
  Σ(purchase amount − `paid_amount`) − Σ(`supplier_payment` entries)
  (`DailyBookEntry::supplierDues()`, company-wide). The Suppliers page lists every
  supplier with their due, highest first, with Quick Pay (amount typed by hand, the due
  shown as a placeholder, can't exceed it) and a per-supplier ledger modal (click a row;
  `supplierLedger()` returns a partial — running balance, oldest first, last balance =
  the listed due). **Dues are Daily-Book-only** — nothing posts to the ledger, so
  `Party::payableBalance()` in the full system does not include them; reconciling the two
  is a job for the eventual Daily Book → full system upgrade path. `party_id` is
  restrict-on-delete, so a supplier with Daily Book history can't be silently deleted
  from Parties (the existing PartyController doesn't catch that FK error yet — same
  pre-existing gap as parties with sales/purchases). Not built: an opening due for a
  supplier/customer from before the shop started using Daily Book.

  **Customers** (2026-10-05; `daily-book.customers.*` routes) — the exact mirror of
  Suppliers for credit sales (বাকি খাতা): Sale Entry has Customer + "Received now" (typed,
  with a "Full amount" button), a due requires a real customer, Customers page has Add
  Customer, per-customer due, **Quick Collect**, and the ledger modal. Both sides run on
  one implementation: `DailyBookEntry::SIDES` maps each side to its entry type
  (purchase/sale), settle type (`supplier_payment`/`customer_collection`) and Party flag
  (`is_supplier`/`is_customer`); `DailyBookEntry::dues($side)` and the controller's
  `partyIndex/partyStore/partyLedger/partySettle` take the side, with thin
  `supplier*`/`customer*` wrappers for the routes; views are `party-index` /
  `party-ledger` with side-specific wording. A sale with no customer picked is recorded
  against the **Walk-in Customer**, which can never carry a due (blocked client- and
  server-side, including a forged `party_id`). It's not a choice in the customer picker
  (it's what "no customer" means there) and can't be settled against, but it is listed on
  the Customers page — last, with a "Walk-in" badge — and its ledger shows the shop's
  walk-in sales; migration `2026_10_05_000002` attached the fully-received sales logged
  before customers existed (party_id was null) to it, so that history isn't missing.
  Walk-in unification: the app had two — PartySeeder
  created one under 01700000001 while POS created its own under 0000000000. There is now
  one, `Party::walkIn()` / `Party::WALKIN_PHONE` (0000000000), used by POS, Daily Book and
  the seeder; migration `2026_10_05_000001` re-phones the seeded one when the POS one
  doesn't exist yet (and backfills `paid_amount = amount` on pre-existing sales, so they
  stay fully received).

  **Settings** (`daily-book.settings.*` routes, gated by the stricter
  **`daily-book.edit`** permission rather than `.view` — only Admin/Super Admin have it
  by default) — one field, `CompanySetting::daily_book_profit_margin_percent` (nullable
  decimal, company-wide). Daily Book has no per-product cost, so this is the owner's own
  rough figure for their typical profit on sales, and Summary's Gross Profit is
  `Total Sale × this percentage` (Net Profit then subtracts Expense). Null means Gross/Net
  Profit can't be computed and show as "—" — never treated as 0%. (A
  `daily_book_initial_balance` column was briefly
  added here and then removed before ever being migrated, once Capital Entry replaced
  the initial-balance idea — see above.)

  Each entry can optionally carry **one receipt/bill photo** — `DailyBookEntry` uses the
  existing `HasAttachments` trait / polymorphic `Attachment` model (same mechanism as
  `Task`/`Employee` attachments), stored under `storage/app/public/daily-book`. The photo
  field is never required — saving an entry with no photo is the expected common case.
  The upload input uses `accept="image/*" capture="environment"` so a phone opens straight
  to its rear camera, with a gallery/file-picker fallback baked into the same control.

  The entry-create form and the sidebar's mobile "New Entry" button were also built/kept
  full-width and touch-sized (larger inputs, a sticky bottom Save/Cancel bar on phone
  screens) specifically because Daily Book is meant to be used on a phone in the shop,
  not just at a desktop.

  `daily-book.view` permission granted to Admin/Manager/Accountant (`RolePermissionSeeder`).

### Problem

Note: this is an independent idea, unrelated to the "Bangladesh Product Master Catalog +
Progressive Stock Initialization" entry elsewhere in this file — that one is about
onboarding a shop's product catalog gradually; this one is about logging daily totals
without itemized entry. They happen to share this document, nothing else.

Many mudi (small grocery) shop owners don't want the overhead of itemized, product-line
entry at all, at least not for day-to-day bookkeeping — they just want to log, in
seconds, "how much did I spend on purchase today," "how much did I sell today," and
"what expenses did I pay today," without picking a specific product line-by-line. The
existing `Purchase`/`Sale`/`Expense` flows ([app/Models/Purchase.php](../app/Models/Purchase.php),
[app/Models/Sale.php](../app/Models/Sale.php), [app/Models/Expense.php](../app/Models/Expense.php))
are all built around itemized, product-line entry (`PurchaseItem`, `SaleItem`), which is
correct for real inventory tracking but is friction for an owner who just wants a running
daily total.

### Why it's a *separate* log rather than a shortcut into the real tables

This is the key product intent behind Daily Book, stated explicitly by the client
(2026-09-21): **Daily Book is an onboarding on-ramp, not a permanent alternative
bookkeeping system.**

The expected adoption path for a new shop owner is:

1. **Day one:** the owner starts with Daily Book only — logging total daily
   purchase/sale/expense amounts, no products, no accounts, no learning curve. This is
   deliberately as close to "just write it in a notebook" as software gets.
2. **Once comfortable with the software** (days/weeks in, once the owner trusts it and
   wants real reporting — per-product stock, dues, profit/loss, etc.), they graduate to
   the full itemized system: real `Purchase`/`Sale`/POS with product lines, `Expense`
   against ledger accounts, and everything that feeds `LedgerTransaction` /
   `StockMovement` / real reporting.

Because Daily Book is explicitly a **temporary stepping stone** rather than a permanent
parallel bookkeeping method, it must stay structurally cheap to ignore or outgrow:
`DailyBookEntry` rows should never need migrating into `Purchase`/`Sale`/`Expense` later,
and the two systems are expected to coexist (an owner might keep using Daily Book for
quick same-day notes even after adopting the full system) rather than one replacing the
other in the data model. This is *why* `DailyBookEntry` was built as a fully standalone
table with zero relation to `purchases`/`sales`/`expenses`/`ledger_transactions`/
`stock_movements` — it was a deliberate simplicity choice for an onboarding tool, not an
oversight. (See "Open questions" below for what, if anything, should still tie the two
together — e.g. surfacing both in one place — without merging their data.)

### Commercial motive (client, 2026-09-21)

Daily Book is explicitly a **sales/conversion tool for the business selling this
software**, not just a UX nicety. The stated plan: a prospective client is first handed
just Daily Book — zero learning curve, phone-friendly, "log your day's numbers" — to get
them actually using the software daily. Once that habit is established and the shop
owner trusts the software with their real day-to-day numbers, the pitch becomes
upgrading them to the full ERP (Purchase/Sale/POS with products, Inventory, Accounts,
HRM, reporting, etc.) — i.e. Daily Book is the low-friction hook, and the full system is
what actually gets sold/subscribed once the owner is convinced.

Implications worth keeping in mind if/when this is revisited:

- Daily Book's UI/UX quality directly affects conversion — it's the client's first
  impression of the product, not a throwaway feature, which is part of why it got the
  mobile-app-style treatment (full-width touch-sized forms, camera capture, sticky
  bottom actions — see the Idea section below) rather than being treated as a minor
  internal tool.
- There is no gating/licensing logic yet that actually restricts a shop to
  Daily-Book-only vs. full-ERP access — today every seeded role with `daily-book.view`
  can also reach the full Purchase/Sale/Expense modules if granted those permissions
  too. If the sales motion needs an actual "Daily Book only" trial tier enforced in
  software (not just sales conversation), that's a separate, unscoped piece of work
  (likely a plan/subscription concept gating which permissions a company's users can
  hold) — nothing here currently enforces it.
- Nudging the owner toward the full system (in-app prompts, "you've logged N days —
  ready to see richer reports?", etc.) is implied by the strategy but not built —
  flagging it here as a natural next idea, not committing to it.

### Idea

Add a new **"Daily Book"** parent menu to the admin sidebar
([resources/views/layouts/app.blade.php](../resources/views/layouts/app.blade.php)),
positioned directly under "Dashboard" (i.e. at the very top, above the existing
"Operations" group that holds Purchase/POS/Sales/Inventory) — reflecting that this is
meant to be the owner's daily-use entry point, not a back-office admin screen.

For now, this parent menu holds exactly one submenu item: **"Summary"** — everything
else (quick purchase entry, quick sale entry, quick expense entry) is implied by the
feature name but explicitly deferred; only the Summary page is being specified here.

**Summary page** — a dashboard-style report, scoped like the existing
`DashboardController` (site-aware via `Auth::user()->current_site_id`, see
[app/Http/Controllers/Admin/DashboardController.php](../app/Http/Controllers/Admin/DashboardController.php)),
showing three headline numbers — **total Purchase, total Sale, total Expense** — each
switchable between three views:

- **Day-wise** — today's totals (and ideally a short recent-days list/table, not just
  "today"), by `order_date` for Purchase/Sale and `expense_date` for Expense.
- **Week-wise** — current week (or trailing 7 days, matching the existing dashboard's
  `weeklyChart()` convention) totals.
- **Month-wise** — current month (or trailing 30 days) totals.

Sale total should exclude cancelled sales (`status != 'cancelled'`, matching
`DashboardController::index()`'s existing `$todaySales` query) for consistency with the
rest of the app. Purchase total should likewise probably exclude `cancelled` purchases —
confirm against `Purchase::STATUSES` semantics when this is built.

### Open questions to resolve before implementation

- **Expense has no `site_id`** (see [app/Models/Expense.php](../app/Models/Expense.php)) —
  unlike Purchase and Sale, which are both site-scoped. Decide whether Expense stays
  company-wide in this summary (like Collections already do in the main dashboard) or
  whether `site_id` needs adding to `expenses` first.
- **Permission gate name** — every existing sidebar entry is wrapped in `@can('xxx.view')`
  (e.g. `sourcing.view`, `sales.view`, `inventory.view` — see the `@can` calls throughout
  `layouts/app.blade.php`). This needs its own gate, e.g. `daily-book.view`, registered
  wherever the others are (check `RolePermissionSeeder` and the `Gate`/policy setup) —
  and a decision on which existing roles (Super Admin, Admin, Manager, Accountant, ...)
  get it by default.
- **Route naming** — existing admin routes live under `Route::prefix('admin')` in
  [routes/web.php](../routes/web.php) with `->middleware(['auth', 'current-site'])`, named
  like `dashboard`, `sales.index`, etc. A `daily-book.summary` (or similar) name should
  follow that same convention.
- ~~**Whether "quick entry" ever gets built**~~ — resolved: built as `DailyBookEntry`, a
  deliberately standalone quick-log table, separate from `Purchase`/`Sale`/`Expense`. See
  "Status" above.
- ~~**Summary and Entry now read from two disconnected sources**~~ — resolved
  (2026-09-21), option (a) from the three considered here, inverted: Summary was
  switched to read **only** `DailyBookEntry` (not the real tables) rather than staying on
  the real tables or merging both. Rationale: Daily Book is meant to work end-to-end
  before a shop ever touches the full itemized system (see "Commercial motive" above),
  so its own Summary can't depend on that system having data. If a future need arises to
  also see real-transaction totals in one place, that's the full system's own
  reports/dashboard, not something Daily Book's Summary should absorb.
- ~~**No enforcement of a "Daily Book only" trial tier**~~ — partially addressed
  (2026-10-03): a **"Daily Book Admin"** role now exists
  (`RolePermissionSeeder`/`RoleController::ROLE_ORDER` — note the role order list is
  duplicated in both places, keep them in sync), holding only `daily-book.view` +
  `daily-book.edit` and nothing else. A user with just this role sees Dashboard + Daily
  Book in the sidebar and gets a 403 on every other module (verified: purchases, sales,
  pos, parties, sites, users, bank-accounts, ai-terminal). This is the shop-owner's "own
  admin, scoped to what they've bought so far" — upgrading them to the full ERP later is
  just assigning a broader role (Admin/Manager/etc.), no code change needed. What's still
  missing: this is a role an Admin has to deliberately choose to assign — there's no
  product/plan concept that *forces* a newly-signed-up company onto this role, or that
  blocks an Admin from just assigning themselves everything. If the sales motion needs
  that enforced (not just offered), that's still unscoped.

### Suggested decomposition when this gets picked up

1. ~~Resolve the Expense `site_id` question and the permission-gate name.~~ Done —
   Expense stayed company-wide (matching Collections); gate is `daily-book.view`.
2. ~~Add the `daily-book.summary` route + `DailyBookController`~~ Done.
3. ~~Build the Summary Blade view~~ Done.
4. ~~Add the "Daily Book" parent menu + "Summary" sublink~~ Done — menu now also carries
   Purchase/Sale/Expense Entry and Settings sublinks.
5. ~~Quick daily Purchase/Sale/Expense entry forms~~ Done — `DailyBookEntry` +
   `entryIndex`/`entryCreate`/`entryStore` on `DailyBookController`.
6. ~~Decide how `DailyBookEntry` totals surface on Summary~~ Done — Summary reads
   `DailyBookEntry` exclusively; see the resolved open question above.
7. ~~Optional receipt/bill photo per entry~~ Done — `HasAttachments` / `Attachment`,
   camera-capture input, never required.
8. ~~Approximate profit margin setting~~ Done — `daily-book.settings.*`,
   `CompanySetting::daily_book_profit_margin_percent`, which drives Gross/Net Profit on
   Summary (Gross Profit = Sale × %).
9. **Remaining/unscoped:** an actual Daily-Book-only trial tier enforced in software (see
   "Commercial motive"), and any in-app nudge toward upgrading to the full system.

Each of these should go through its own brainstorming → spec → plan cycle when picked up.

---
