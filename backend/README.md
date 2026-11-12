# Shop.co E-Commerce — PHP 8+ REST API Backend

This directory houses the native **PHP 8+ REST API** powering the Shop.co e-commerce platform. It provides high-performance, transaction-safe e-commerce operations using native **MySQLi** prepared statements, local **MySQL (XAMPP)**, and **Kora (Korapay) Sandbox** payment flows.

---

## 1. Architectural Principles

1. **Single Public Document Root**:
   - Only `backend/public/` is exposed to the web.
   - All source code (`src/`), configuration files (`.env`), private downloads (`storage/private-downloads/`), and logs are strictly isolated outside the public web root.
2. **Zero Leaks & Safe Error Envelope**:
   - Database credentials, raw SQL queries, and stack traces are **never** returned to the client.
   - All exceptions are caught globally, logged to `storage/logs/error.log`, and returned in a sanitized JSON response envelope.
3. **No Heavy Framework Overhead**:
   - Zero-dependency modern PHP 8.2 architecture utilizing native `mysqli`, `spl_autoload_register`, and modular services.
4. **Strict CORS Allowlist**:
   - Restricts browser requests to explicit origins configured in `CORS_ALLOWED_ORIGINS` (default: `http://localhost:3000`).
   - Handles browser preflight `OPTIONS` requests automatically with `204 No Content`.

---

## 2. Directory Structure

```text
backend/
├── public/
│   ├── index.php                # Single public document root & API gateway
│   └── .htaccess                # Apache rewrite rules for clean URLs
├── src/
│   ├── Config/
│   │   └── Env.php              # Environment variable loader (.env)
│   ├── Database/
│   │   └── Database.php         # MySQLi connection singleton & prepared statement wrapper
│   ├── Support/
│   │   ├── Response.php         # Standardized JSON response & error envelope
│   │   └── Router.php           # Lightweight regex-based REST router
│   ├── Middleware/
│   │   ├── CorsMiddleware.php   # Environment-driven CORS headers & preflight
│   │   └── JsonBodyMiddleware.php # Content-Type validation & JSON body parser
│   ├── Controllers/
│   │   └── HealthController.php # System health check (/api/v1/health)
│   ├── Services/                # Domain business logic (Catalog, Orders, Shipping, etc.)
│   ├── Repositories/            # Database query layers
│   ├── Validators/              # Server-side input validation rules
│   └── Payments/
│       └── Kora/                # Kora checkout client & webhook processor
├── config/
│   └── shop.json                # What kind of shop this copy is (read by the API and the storefront)
├── migrations/                  # Versioned SQL database migrations (Step 3)
├── seeds/                       # seed.php and this shop's demo catalogue (seeds/demo/)
├── storage/
│   └── logs/                    # Backend error logs
├── tests/                       # Automated CLI test runners
├── .env.example                 # Environment configuration template
├── .env                         # Local environment configuration (untracked)
├── composer.json                # Composer manifest for autoloading
└── README.md                    # Backend documentation
```

---

## 3. Local Setup & Configuration

### Prerequisites
- **PHP 8.2+** with extensions: `mysqli`, `curl`, `mbstring`, `openssl`, `json`.
- **XAMPP** (or standalone Apache + MySQL).

### Step 1: Configure Environment Variables
Copy `.env.example` to `.env`:
```bash
cp backend/.env.example backend/.env
```

Review local database settings in `backend/.env`:
```ini
APP_NAME="Shop.co REST API"
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=shopco_dev
DB_USERNAME=root
DB_PASSWORD=""

STORE_NAME="SHOP.CO"
STORE_CURRENCY=NGN
STORE_ORIGIN_CITY=Enugu
STORE_ORIGIN_STATE=Enugu

CORS_ALLOWED_ORIGINS="http://localhost:3000,http://127.0.0.1:3000"
```

`ADMIN_COOKIE_NAME` (default `shopco_admin`) names the admin sign-in cookie. Browsers keep one set
of cookies for `localhost` whatever the port, so when several shops run on one PC give each its
own name, or signing in to one admin signs you out of another.

What kind of shop this copy is (its name, the names of the two product options, warranties, a fee
for heavy items, an age check) comes from `backend/config/shop.json`, read by `App\Config\Shop`
and by the storefront. It is not an environment setting.

---

## 4. Serving the API Locally

### Option A: PHP Built-in Server (Recommended for Quick Testing)
You can run the PHP built-in server pointing directly to the public document root:
```bash
php -S localhost:8000 -t backend/public backend/dev-router.php
```
The API is then immediately available at:
```text
http://localhost:8000/api/v1/health
```

### Option B: Local XAMPP Apache (Virtual Host)
Add the following virtual host to `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:
```apache
<VirtualHost *:80>
    ServerName shop-api.local
    DocumentRoot "C:/Users/smnkh/Desktop/E-templates/E-commerce/backend/public"
    <Directory "C:/Users/smnkh/Desktop/E-templates/E-commerce/backend/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```
Add `127.0.0.1 shop-api.local` to `C:\Windows\System32\drivers\etc\hosts`.

### Option C: Local XAMPP Apache (Subdirectory Alias)
If serving from Apache's `htdocs`:
Configure an Alias pointing `/shop-api` to the `backend/public` folder. The built-in `Router.php` automatically detects and strips directory prefixes.

### Speed and scale
- **Turn on OPcache** (PHP keeps compiled code in memory instead of recompiling every file
  on every request; typically 2-3x faster). In `C:\xampp\php\php.ini`:
  ```ini
  zend_extension=opcache
  opcache.enable=1
  opcache.memory_consumption=128
  opcache.max_accelerated_files=10000
  opcache.validate_timestamps=1   ; 0 in production (then restart PHP after each deploy)
  ```
- **Use Apache or nginx + PHP-FPM in production.** `php -S` handles one request at a time;
  it is for development only.
- **Catalogue cache:** public listing, homepage, filter and category responses are cached in
  `storage/cache` for `CATALOG_CACHE_SECONDS` (default 60) and cleared after every admin
  change. Responses are gzip-compressed and public ones carry `Cache-Control` + `ETag`
  (browsers get `304 Not Modified` when nothing changed); account, order and admin
  responses are `no-store`.
- **Shop page refresh:** the Next.js storefront also keeps pages for up to 60 s. Set
  `STOREFRONT_REVALIDATE_URL` and `REVALIDATE_SECRET` here and the same `REVALIDATE_SECRET`
  in the storefront's `.env.local`; after each successful admin change the API then calls
  the storefront's `POST /api/revalidate` (`App\Support\StorefrontRefresh`) and every page
  is rebuilt on its next visit. A failed call never undoes the admin change: it is logged,
  shown in admin → System status, and pages still refresh within about a minute. Under
  PHP-FPM the admin's response is sent first; elsewhere an unreachable storefront adds up
  to about a second to the admin request.
- **Best selling** sorts on `products.units_sold`, kept up to date when an order is paid,
  cancelled or refunded (`App\Services\ProductSales`). Search uses a full-text index.
- Measured on a test store with 20,000 products, 80,000 variants and 150,000 order lines:
  catalogue pages 30-130 ms uncached and ~17 ms cached (best selling was 125 s before).

---

## 5. Database Migrations & Scheduled Jobs

### Migrations
```bash
php backend/migrations/migrate.php
```
Each migration runs exactly once; applied files are recorded in the `schema_migrations` table.
Re-running the command only applies new files. (On a database created before the ledger existed,
migrations 001–008 are recorded as already applied the first time.)

The latest ones:

| Migration | Adds |
| :--- | :--- |
| `029_unpaid_order_cap.sql` | `orders.contact_phone`, `orders.device_hash` and the `max_unpaid_orders` setting. |
| `030_product_specifications.sql` | `products.specifications` (JSON list of name and value), `products.warranty`, `products.is_heavy`. |
| `031_heavy_item_delivery.sql` | `shipping_zones.heavy_item_fee`, charged per heavy item when `features.heavyDelivery` is on in `config/shop.json`. |

### Demo data (seeding)
```bash
php backend/seeds/seed.php                          # this shop's demo catalogue
php backend/seeds/seed.php path/to/catalog.json     # another catalogue file
php backend/seeds/seed.php backend/tests/fixtures/seeds   # a folder of NNN_*.sql files
```
With no argument it loads `backend/seeds/demo/catalog.json`: store settings, delivery zones,
promotions, categories, banners, products with variants and pictures, and reviews, all in one
file. `backend/seeds/demo/catalog.example.json` shows the shape. Pictures named in the file go in
`backend/seeds/demo/pictures/` and are copied into the picture storage as `catalog/<file name>`
(served at `/uploads/catalog/<file name>`). The whole file is checked first, so a mistake or a
missing picture stops the run before anything is written, and it never adds to a database that
already has products. The folder or file can also be given in the env value `SEED_PATH`.

A copy without `seeds/demo/catalog.json` loads the clothing catalogue the tests use
(`backend/tests/fixtures/seeds/*.sql`) instead.

### Releasing stock from unpaid orders
Placing an order reserves its stock until `orders.expires_at` (30 minutes after the order or the
latest gateway checkout attempt; 6 hours when the customer pays by WhatsApp/manual transfer).
Overdue orders are cancelled and their stock and promo use released exactly once.

One customer can hold only so many unpaid orders at once: store setting `max_unpaid_orders` (2 by
default, 0 = no cap; Admin, Store settings), counted by account, email, delivery phone and device
(migration 029). A further order is refused with 409 `TOO_MANY_UNPAID_ORDERS`.

The API does a small sweep whenever an order is placed or the admin order list or dashboard is opened. Schedule
the full job so stock is released even when the store is quiet; it asks Kora/Monnify for the final
status first, so a payment completed at the last minute is applied instead of cancelled:
```bash
php backend/scripts/expire_pending_orders.php                 # check gateways, then expire
php backend/scripts/expire_pending_orders.php --no-gateway-check
```
Windows Task Scheduler (every 10 minutes):
```powershell
schtasks /Create /SC MINUTE /MO 10 /TN "ShopCo Expire Orders" /TR "C:\xampp\php\php.exe C:\path\to\E-commerce\backend\scripts\expire_pending_orders.php"
```

Optional `.env` settings: `ORDER_PAYMENT_WINDOW_MINUTES` (default 30, never under 15) and
`ORDER_MANUAL_PAYMENT_WINDOW_HOURS` (default 6, never under 1).

### Sending email (outbox)
Every email is written to `email_outbox` first. Order emails (received, paid, shipped, cancelled,
refunded) get one delivery attempt once the order change is saved, **after** the response has gone
to the shopper or admin, so a slow or unreachable mail server never delays a checkout, a payment
confirmation or an admin action (`Response::finishForClient`; responses carry `Content-Length`).
Newsletters, and any email whose attempt failed, are sent by the outbox worker with retries after
1, 5, 15 and 60 minutes (failed after 5 attempts). A dedup key is unique, so the same email is
never queued twice. Sign-in links and admin confirmation codes are sent immediately (the person is
waiting for them) and never stored, so they cannot be re-sent. `MAIL_TIMEOUT_SECONDS` (default 10)
bounds each step of a send.
```bash
php backend/scripts/process_outbox.php              # one batch of up to 100
php backend/scripts/process_outbox.php --limit=500
php backend/scripts/process_outbox.php --limit=500 --max-seconds=50 --rate=8   # the scheduled command
```
`--max-seconds` keeps taking batches until the queue is empty or the time is up, so one run a
minute is not limited to one batch. `--rate` caps emails per second: Resend allows 10 requests a
second for the whole account by default, shared with the order emails the API sends itself, so 8
leaves room for those (with several workers, divide it between them). If Resend still answers
"too many requests", the email keeps all its attempts, is tried again a minute later, and the run
stops. The pace also keeps a large newsletter from slowing the shop.
**Retries happen automatically, with no scheduler:** after an API request has answered, the first
request each minute (across all PHP processes and servers, decided by one atomic row in
`store_settings`) sends up to 5 due emails, for at most 20 seconds (`MailService::retryDueAfterResponse`,
`MAIL_AUTO_RETRY=false` turns it off). Each email is claimed before it is sent, so this, the worker
below and other servers never send the same email twice. The worker is still recommended: it keeps
retries punctual when the shop is quiet and sends newsletters in bigger batches.

Schedule it **every minute** (php-win.exe runs without a console window; runs never overlap and
two at once are safe, because each email is claimed before it is sent):
```powershell
schtasks /Create /SC MINUTE /MO 1 /TN "ShopCo Send Email" /TR "C:\xampp\php\php-win.exe C:\path\to\E-commerce\backend\scripts\process_outbox.php --limit=500 --max-seconds=50 --rate=8"
```
```cron
* * * * *  php /var/www/shopco/backend/scripts/process_outbox.php --limit=500 --max-seconds=50 --rate=8
```
With `MAIL_MAILER=log` (the default) emails go to `backend/storage/logs/mail.log` and the admin outbox
shows them as "Logged only". The admin Email outbox page also has "Send queued now".

### Data retention, database check and clean-up
**Nothing is ever deleted automatically.** How long each kind of data is kept is chosen in
**admin → Data & storage**; when records pass those periods (or the database nears the size
limit the admin set), the admin is alerted and reviews the exact records there, page by page.
A clean-up only runs when the admin presses the button, re-enters their password and types a
6-digit code emailed to them (valid 10 minutes, 5 tries, once, same sign-in session). It
removes exactly the records that were reviewed, or refuses if any changed. Every step is in the
audit log, and all admins get an email when a clean-up has run.

Keep periods:
- **Customer records** (off until chosen; 3 months to 2 years): a customer with no sign-in and no
  order for that long, and nothing open, has their account, saved addresses, cart and wishlist
  deleted, and the name, phone, email and street address removed from their finished orders.
  The orders keep their items, totals, payments and delivery city/state for the accounts.
- **Unfinished checkouts** (7, 30 or 90 days), **emails and gateway notifications** (3, 6 or 12
  months; never longer than the customer-record period) and **admin activity / stock history**
  (kept unless a period is chosen).
- Expired sign-in sessions and links, old rate-limit counters and old slow-request records are
  listed too (technical rows, no customer data).

Money records (order totals and items, payments, refunds) and orders flagged for attention are
never removed. The daily check (removes nothing) looks at the database size against the admin's
limit, slow requests (`SLOW_REQUEST_MS`) and records past the keep periods, and emails the admins
(at most once a week) when something needs attention. The same check feeds the admin alert bell.
```bash
php backend/scripts/check_database.php
```
Windows Task Scheduler (daily at 03:00):
```powershell
schtasks /Create /SC DAILY /ST 03:00 /TN "ShopCo Database Check" /TR "C:\xampp\php\php.exe C:\path\to\E-commerce\backend\scripts\check_database.php"
```

### Pictures (uploads)
- `POST /api/v1/admin/upload-image` (admins only) accepts JPEG, PNG and WebP up to 5 MB, checked by
  content (not the file name); SVG is refused. Files get a new server-made key
  (`prod_<date>_<time>_<random>`) and the shop links to `/uploads/<key>`.
- **Storage layer** (`App\Storage`): every save, read, list and delete goes through `ObjectStorage`,
  chosen by `STORAGE_DRIVER`. `local` (default) keeps files in `public/uploads` (or `UPLOAD_DIR`).
  Keys are validated (`StorageKey`: no `..`, hidden files or odd characters) and the database only
  stores `/uploads/<key>`, so moving to an S3-compatible bucket later changes settings, not data.
  `s3` keeps them in a private S3-compatible bucket (Cloudflare R2), and the API serves
  `/uploads/<key>` from it (`MediaServer`). Setup: [`docs/STORAGE.md`](../docs/STORAGE.md).
- The browser resizes photos to at most 2560 px and re-encodes them at quality 0.92 before upload
  (`lib/image-optimize.ts`); customers get copies sized for their screen from next/image (AVIF/WebP).
- **Camera metadata is removed on upload** (`App\Support\ImageMetadata`: GPS location, device, owner,
  comments, XMP/IPTC), without re-encoding and without GD. Only the rotation is kept.
- **Pictures nothing uses any more** (replaced product photos, changed banners, uploads from forms that
  were never saved) are listed in admin → Data & storage under technical items once they are a day
  old, and removed only by a confirmed clean-up, like every other clean-up (`App\Support\UploadFiles`).
- Apache (`public/.htaccess`, needs mod_headers) lets browsers keep admin uploads for a year (their
  names never repeat) and other pictures for a day. nginx equivalent:
  ```nginx
  location ~* ^/uploads/prod_\d{8}_\d{6}_[0-9a-f]{8}\.(jpe?g|png|webp)$ { add_header Cache-Control "public, max-age=31536000, immutable"; }
  location ~* ^/uploads/.+\.(jpe?g|png|webp)$ { add_header Cache-Control "public, max-age=86400"; }
  ```
- Back them up with `scripts/backup.php` (database + pictures; see `docs/BACKUPS.md`).

### Admin panel
The admin lives at `/admin` on the storefront (one route per screen, e.g. `/admin/orders`, `/admin/shipping/zones`).
On a brand-new database `/admin` sends you to `/admin/setup` to create the first administrator and the store
details; after that the setup endpoint is closed and `/admin/login` is the way in. Old `/admin?tab=…` links
redirect to the matching route.

### Payment rules (PaymentFinalizer)
Every confirmation path (Kora/Monnify webhooks and verification, admin "Confirm payment") goes
through `src/Services/PaymentFinalizer.php`: amount and currency must match, a payment is never
applied twice, an order only moves `pending_payment -> paid`, and money that arrives for a
cancelled/expired order, a second successful payment, an underpayment or an overpayment is recorded
and flagged on the order (`requires_attention`) for the admin instead of changing it.

---

## 6. Verification & Testing Commands

### Test suites (throw-away database)
```bash
php backend/tests/run.php                    # every suite
php backend/tests/run.php Security Admin     # suites whose name contains these words
php backend/tests/run.php --keep             # keep shopco_test afterwards for debugging
```
The runner writes `backend/.env.testing` (a copy of `.env` pointing at `shopco_test` and a separate
mail log), drops and re-creates that database, applies every migration, runs `seeds/seed.php`
with the fixtures in `tests/fixtures/seeds`, starts the API on `127.0.0.1:8099` against it, runs
each `backend/tests/*Test.php`, then stops the server and drops the database. `.env` and
`shopco_dev` are never touched.

Every shop copy runs the same suites against the same data, so during a test run the fixture shop
`tests/fixtures/shop.json` (the clothing shop) is laid over the copy's `config/shop.json`. It
reaches the API as the env value `SHOP_CONFIG_OVERRIDES`, which `App\Config\Shop` reads only while
`APP_ENV` is `testing`. A suite that needs another kind of shop starts a second API with more
values on top (`TestKit::startApi`).

| Suite | Covers |
| :--- | :--- |
| `SecurityTest` | Admin access, cookies and CSRF, magic links, guest order lookup, rate limits, uploads, CORS. |
| `StorefrontFlowTest` | Catalogue filters and facets, cart check, delivery quotes, idempotent guest orders, payment windows, account linking, saved cart/wishlist, reviews, flash-deal expiry. |
| `PaymentIntegrityTest` | Payment finaliser, Kora/Monnify webhooks and signatures, expiry, promos, refunds, variants, transactions. |
| `AdminApiTest` | Every admin screen's API, the full order lifecycle, refunds, settings, sessions. |
| `ReportingTest` | Analytics agree with the database, CSV exports, audit log, email outbox endpoints. |
| `MailOutboxTest` | Outbox delivery, retries and backoff, SMTP protocol (fake server), templates. |
| `ShopKindsTest` | What `config/shop.json` changes: option names and the "none" words, specifications and warranty, the heavy item fee, age confirmation, the admin cookie name. |
| `CatalogSeederTest` | The demo catalogue seeder (`seeds/seed.php` with a `.json` file), on its own throw-away database. |

Each suite refuses to run directly against the configured database unless `ALLOW_DEV_DB=1` is set.

### PHP syntax check
```powershell
Get-ChildItem -Path backend -Recurse -Filter "*.php" | ForEach-Object { php -l $_.FullName }
```

---

## 7. Prepared Statement Usage Example

Always execute queries through `App\Database\Database::getInstance()` using prepared statements:

```php
use App\Database\Database;

$db = Database::getInstance();

// 1. Fetching multiple records with parameter bindings:
$products = $db->fetchAll(
    "SELECT id, name, price, stock FROM products WHERE category_id = ? AND status = ?",
    "ss",
    [$categoryId, "published"]
);

// 2. Fetching a single row:
$product = $db->fetchOne(
    "SELECT * FROM products WHERE slug = ? LIMIT 1",
    "s",
    [$slug]
);

// 3. Inserting a record:
$orderId = $db->insert(
    "INSERT INTO orders (customer_email, total_amount, currency) VALUES (?, ?, ?)",
    "sds",
    [$email, $total, "NGN"]
);

// 4. Atomic Transaction:
$db->transaction(function (Database $db) use ($orderId, $items) {
    foreach ($items as $item) {
        $db->execute(
            "UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?",
            "isi",
            [$item['qty'], $item['variant_id'], $item['qty']]
        );
    }
});
```
