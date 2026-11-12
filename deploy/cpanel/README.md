# Shop.co on shared hosting (cPanel or DirectAdmin)

The whole shop on one hosting account: the storefront as a Node.js app, the PHP API on a second
address, MySQL from the account, and pictures in your Cloudflare R2 bucket. You build on your PC and
upload two zip files, because builds on shared hosting usually run out of memory.

**How far this is tested (6 November 2026):** a full rehearsal on Linux (Ubuntu 24.04) with the real
zip files: Apache, Passenger running the storefront the way "Setup Node.js App" does, Node.js 20, PHP
8.3, MySQL 8, a database user limited to its own database, and the real R2 bucket for reading pictures.
A shopper's order, the WhatsApp payment, the admin confirming it, the scheduled jobs and a real browser
all passed, and so did all the backend tests.

**Not tested:** a real host, **LiteSpeed** (which most Nigerian hosts run), real https, uploading a
new picture from the host, or a host's process limits. So buy a plan with a money-back period, and
start in the order below.

## Before you buy

### 1. Ask the host this first

Hosts' websites often don't state these. Send it to their sales chat and keep the reply:

> I want to run a Node.js app and a PHP site on the same plan. Please confirm for the plan I'd buy:
> 1. Does it have "Setup Node.js App" (the Node.js selector), and can I choose **Node.js 20 or newer**?
> 2. Can I run a Next.js app from my own `server.js`, without running npm install on your server?
> 3. Is **PHP 8.2 or newer** available, with mysqli, curl, mbstring, openssl and opcache?
> 4. Is the database **MySQL 8 or MariaDB 10.4 or newer**?
> 5. Can I add a subdomain and choose its **document root**, including a folder outside public_html?
> 6. What are the limits per account: **memory, processes (NPROC), entry processes and inodes**?
> 7. How often can cron jobs run?
> 8. Is the server's Linux **version 8 or newer** of CloudLinux or AlmaLinux?
> 9. Is there a security check that shows a browser challenge page to visitors or to server-to-server requests?
> 10. Do you offer a refund if the app can't run on this plan? How many days?

### 2. Read the answers

| Question | Good answer | If not |
|---|---|---|
| 1. Node.js | Yes, 20 or newer | **Don't buy.** The shop can't run; version 18 or older won't work |
| 2. Own `server.js` | Yes | **Don't buy** if only their own framework templates are allowed |
| 3. PHP | 8.2 or newer | **Don't buy** |
| 4. Database | MySQL 8, or MariaDB 10.4+ | MySQL 5.7 hasn't been tested. Ask them to move you, or choose another host |
| 5. Subdomain folder | Yes | Still fine: step 2 has a way for a folder inside `public_html` |
| 6. Limits | Memory 1 GB or more, processes 60 or more, entry processes 20 or more, inodes 10,000 or more | Below these, expect errors when several people visit at once |
| 7. Cron | Every 5-15 minutes is enough | Fine: emails also go out after visits |
| 8. Linux | Version 8 or newer | On version 7: build with `-NoImageResizing`, and pictures are sent at full size (slower on phones) |
| 9. Browser check | None | **Don't buy** if it can't be turned off: the storefront's calls and payment notices would be refused |
| 10. Refund | Yes, a few days or more | Choose the shortest payment term you can |

**What the shop used in the rehearsal, to compare with their limits:** 55 MB of disk and about 3,150
files; Node.js 140 MB normally and 225 MB at its highest; 9-13 threads for Node.js. With 100 requests at
once, the whole account reached 88 processes and threads, mostly the web server's own.

### 3. Domain

- You need **one domain**. The API uses a free subdomain of it, such as `api.yourshop.com.ng`.
- `.com.ng` is usually cheapest, and looks local. `.com` suits you better if you'll work with clients
  outside Nigeria. Compare the **renewal** price, not only the first year's.
- If it's for showing several clients your work, name it after your business rather than one client's shop.
- Many plans include a free domain for the first year. Buying it with the hosting is the simplest, because
  the address then needs no setting up. Make sure it's registered **in your own name**, with an email you control.

### 4. Don't pay yet for

- More than one year. Pay for the shortest term until the shop has run there.
- SSL (free with AutoSSL), site builders, website "security" add-ons or a paid email plan.

### 5. Other things to have ready

- New R2 keys for the server (Cloudflare → R2 → API tokens), because the current ones were shared in chat.
- Your shop's WhatsApp number for customers.
- A strong administrator password. The clean export carries your current administrators to the host.

## 1. Check the account

As soon as you have the account, inside the money-back period:

Upload `check-host.php` (in `deploy/cpanel/`, and step 3 puts a copy on your Desktop) to the account's
web folder, open it in a browser, read the answers, then **delete it**. Keep a copy of what it shows.

Then open **Setup Node.js App** and confirm it really offers Node.js 20.9 or newer. If anything is
missing, ask the host to fix it, or ask for your refund.

## 2. Create the addresses and the database

1. In **Domains** or **Subdomains**, create the API's address, such as `api.example.com`. The shop uses
   the main domain.
2. Set the API's **document root** to `shopco-api/public`.
   - **If the panel won't allow a folder outside `public_html`** (DirectAdmin often doesn't), let it use
     the folder it offers, then in step 4 copy everything from `shopco-api/api-entry` into that folder,
     **including the hidden `.htaccess`**, and in its `index.php`, change `/home/ACCOUNT/` to your
     real home folder. The API itself stays safely outside the web folder. (Tested with a folder
     inside the shop's; if the API's address ever shows the storefront, tell me.)
3. Turn on free https for both (cPanel: **SSL/TLS Status → Run AutoSSL**; DirectAdmin: **SSL Certificates
   → Free & automatic certificate**). Sign-in needs https.
4. In **MySQL Databases** (DirectAdmin: **MySQL Management**), create a database and user, which get your
   account name in front, such as `myacc_shopco`. Give the user **all privileges** on that database.

## 3. Build on your PC

Commit your work first (only committed code is built). In a terminal, in the project folder:

```powershell
powershell -ExecutionPolicy Bypass -File deploy/cpanel/build.ps1 -ApiUrl https://api.example.com
```

About 5 minutes. It makes `shopco-web.zip` and `shopco-api.zip` in a `shopco-cpanel` folder on your
Desktop. The API address is written into the build, so a new address needs a new build.

Add `-NoImageResizing` only if the host's Linux is too old (question 8), or `-Cpu arm64` if
`check-host.php` shows an arm64 processor.

Then make the clean copy of your database:

```powershell
php backend/scripts/export_for_host.php
```

It keeps products, pictures' records, categories, delivery zones, coupons, banners, approved reviews,
settings and administrators. It leaves out test customers, orders, payments, sessions, subscribers, queued
emails and logs. Nothing on your PC changes. It lists the administrators it carried over, so
check that list. It writes `backend/storage/backups/for-host-<date>.sql.gz`.

## 4. Put the API online

1. Upload `shopco-api.zip` to a new folder `shopco-api` in your home folder, **not** inside `public_html`,
   then **Extract** it.
2. In **Select PHP Version**, choose 8.2 or newer and tick: mysqli, curl, mbstring, openssl,
   simplexml, ctype, zlib, opcache. Set `upload_max_filesize` to 8M, `post_max_size` to 10M.
3. Rename `env.cpanel.example` to `.env`, and fill in every `<...>` value. Make a random
   `REVALIDATE_SECRET` on your PC: `php -r "echo bin2hex(random_bytes(24));"`.
4. In **phpMyAdmin**, open the new database and **Import** the `for-host-<date>.sql.gz` file. Your products,
   settings and administrators arrive, and this **closes the setup page**.
5. Check the database is up to date. In **Cron Jobs**, add a job for the next minute, wait, then delete it:
   ```
   /usr/local/bin/php /home/<account>/shopco-api/migrations/migrate.php > /home/<account>/shopco-api/storage/logs/migrate.log 2>&1
   ```
   Open `storage/logs/migrate.log`. It should end with `Database is up to date` or `Applied`. The PHP path
   differs by host: the panel's cron page or the host's docs show it (others: `/opt/alt/php82/usr/bin/php`,
   `/opt/cpanel/ea-php82/root/usr/bin/php`). If the log shows `Status: 404`, that path is the wrong kind of PHP.
6. Open `https://<api address>/api/v1/health`. It should answer `"status":"healthy"`, not an error page or
   a browser check.

## 5. Put the storefront online

1. Upload `shopco-web.zip` to a new folder `shopco-web` in your home folder, and **Extract** it. You
   should see `server.js`, `package.json`, `node_modules`, `public`, and a hidden `.next` folder (turn on
   **Show Hidden Files** in File Manager).
2. Open **Setup Node.js App → Create Application**:

   | Setting | Value |
   |---|---|
   | Node.js version | 20.x or newer |
   | Application mode | Production |
   | Application root | `shopco-web` |
   | Application URL | the shop's address |
   | Application startup file | `server.js` |

3. Add these **environment variables**:

   | Name | Value | Why |
   |---|---|---|
   | `NODE_ENV` | `production` | |
   | `BACKEND_ORIGIN` | `https://<api address>` | Where the storefront finds the API |
   | `REVALIDATE_SECRET` | the same value as in the API's `.env` | Admin changes refresh the shop at once |
   | `UV_THREADPOOL_SIZE` | `2` | Stays within shared hosting's process limits |
   | `VIPS_CONCURRENCY` | `1` | The picture library would otherwise use half of the server's processors, which on a big shared server can break the process limit |

4. **Don't click "Run NPM Install".** Everything is in the zip. Click **Start** or **Restart**.
5. Open the shop's address, then check pictures load and you can sign in at `/admin`. Sections can be
   empty for up to a minute the first time, while the pages refresh from the API.

## 6. Scheduled jobs

Using the PHP path from step 4, and adding `>/dev/null 2>&1` if the host emails you each run:

| How often | Command |
|---|---|
| Every 5 minutes (every minute if allowed) | `php /home/<account>/shopco-api/scripts/process_outbox.php --limit=500 --max-seconds=50 --rate=8` |
| Every 10 minutes | `php /home/<account>/shopco-api/scripts/expire_pending_orders.php` |
| Daily, 03:00 | `php /home/<account>/shopco-api/scripts/check_database.php` |
| Daily, 02:30 | `php /home/<account>/shopco-api/scripts/backup.php && php /home/<account>/shopco-api/scripts/backup_to_bucket.php` |

A host that limits how often cron runs is fine: the shop also sends due emails after ordinary visits.

## 7. Check it properly

Turn on dev mode (admin → **Store settings**), then open **System status**:

- **Visitor addresses must show your own public address** (compare with a "what is my IP" site). If it
  shows the host's address, every visitor is being counted as one person, so 10 orders in 10 minutes
  from the whole shop would be refused, and 50 failed sign-ins by anyone would lock everyone out of
  signing in for 15 minutes, you included. Add the address it shows to `TRUSTED_PROXIES` in `.env`. Test
  from two different connections, such as Wi-Fi and mobile data, and if both show the same address,
  tell me; the host's proxy may need handling. (In the rehearsal, once the storefront's address
  was trusted, the real visitor's address came through, and faked ones were ignored.)
- No warnings; storage says `s3 · writable`; extensions complete; upload limit at least 5 MB.
- Turn dev mode off again before showing a client.

Then, on a phone with mobile data: browse, open a product, place an order, pay via WhatsApp, tap "I've
sent the transfer", confirm it in admin, and see the receipt. Upload one new picture in admin too, since
the rehearsal didn't cover that from a server.

Finally, on **uptimerobot.com** (free), monitor `https://<api address>/api/v1/health` and the shop's
address every 5 minutes. Hosts stop idle Node.js apps, which makes the next visitor wait while it starts
(under 1 second in the rehearsal, likely a few on a busy host); these visits usually keep it awake, and
they tell you when the shop is down.

## Turning on card payments

The shop starts with WhatsApp payment only. Do this once a gateway has approved your business.

1. In the API's `.env`, remove the `#` from that gateway's lines and fill in the keys from its dashboard
   (the block is under `PAYMENT_KORA_ENABLED`). Set its `PAYMENT_MONNIFY_ENABLED` or `PAYMENT_KORA_ENABLED`
   to `true`.
2. **Monnify:** keep `MONNIFY_API_BASE_URL=https://api.monnify.com`. Without that line it uses Monnify's
   test system (sandbox), where no real money moves. In Monnify's dashboard, set the transaction webhook to
   `https://<shop address>/api/v1/webhooks/monnify`.
3. **Kora:** nothing to set in its dashboard: each payment tells Kora where to send its notice,
   `APP_URL` + `/api/v1/webhooks/kora`, and that's why `APP_URL` must be the real https address.
4. In admin, **Settings → Payment methods**: check the gateway shows as live, not sandbox or "not set up",
   and choose which one "Pay now" uses.
5. Pay for a small real order and refund it, before telling customers.

A gateway without its keys isn't offered at checkout, so a half-finished setup can't take a customer to
a failing page.

## Updating later

1. Commit, then run step 3 again (the build; not the database export).
2. **API:** upload and extract `shopco-api.zip` over `shopco-api`. `.env` and `storage` aren't in the zip,
   so they're kept. Run the migration cron from step 4 if the database changed.
3. **Storefront:** in File Manager, delete the old `.next` folder in `shopco-web`, upload and extract
   `shopco-web.zip` over it, then **Restart** in Setup Node.js App.

Pages can fail during those few minutes, so don't update during a client demo.

**Never import a new export over a shop with real customers:** it replaces every table. Moving a live
shop takes a normal backup (`docs/BACKUPS.md`).

## Troubleshooting

| Problem | Look at |
|---|---|
| Every page or API call shows a 500 error | Permissions: files should be 644 and folders 755, which the zips set. If the zip wasn't extracted by File Manager, check these first. Then `shopco-api/storage/logs/` and the panel's **Errors** |
| The app won't start, or shows a 503 | **Setup Node.js App** → the app's log file; the Node.js version is at least 20.9; `server.js` is the startup file. On LiteSpeed hosts, tell me the log's message: that's the part not rehearsed |
| Pictures on product pages are broken, others fine | The picture library: look for `sharp` in the app log. Tell me the message and the host's processor type (`x64` or `arm64`; rebuild with `-Cpu arm64` if needed, or with `-NoImageResizing`) |
| "Resource temporarily unavailable", `EAGAIN` or "Unable to fork" in the log | The host's process limit. Check `UV_THREADPOOL_SIZE=2` and `VIPS_CONCURRENCY=1`; if it continues, ask the host for its limit or rebuild with `-NoImageResizing` |
| Error 508, or "Resource limit reached" | Too many visitors at once for the plan's entry processes: a bigger plan, or ask the host |
| Pages load but the catalogue is empty | Wait a minute, then `BACKEND_ORIGIN`, then open `/api/v1/health` on the API address; a browser check would also cause this |
| API answers 403 Forbidden to every address | The server applies `shopco-api/.htaccess` ("refuse everything") to `public` as well (seen on LiteSpeed). Packages built since 8 Nov 2026 allow `public` in its own `.htaccess`; for an older package, upload the current `backend/public/.htaccess` over `shopco-api/public/.htaccess` |
| API shows a 500 error | `shopco-api/storage/logs/` and the panel's **Errors**; check the database name and user in `.env`, and that `storage` is writable |
| The database import stops with an error | Tell me the message and the database version (phpMyAdmin's first page shows it) |
| Admin sign-in doesn't stay | Both addresses must be https, and `FRONTEND_URL` must match the shop's address |
| Pictures upload but don't appear | R2 keys in `.env`, and System status → storage |
