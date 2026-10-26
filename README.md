# Transport Manager (TMS)

Web app for a container-transport business: quoting, trip capture (office + driver portal),
invoicing, statements, debtors, driver pay, subcontractors, commission, analytics.
PHP 7.4+/8.x and MySQL/MariaDB. No framework, no build step.

## 1. What you need
- Hosting with PHP 7.4+ (8.x best), MySQL/MariaDB, Apache (`.htaccess`). cPanel shared hosting is fine.
- A domain/subdomain with SSL (e.g. `app.yourcompany.co.za`).
- Optional, for PDF export: `composer require mpdf/mpdf` in the app folder.

## 2. Install (about 10 minutes)
1. In cPanel create an **empty MySQL database** and a **database user** with ALL privileges on it.
2. Upload the app folder contents to the web root of the (sub)domain.
3. Copy `config.local.example.php` to `config.local.php`, fill in the DB name/user/password, and set a
   random secret (`php -r "echo bin2hex(random_bytes(24));"`). Leave `TMS_BASE_URL` empty at a domain root.
4. Make `uploads/pod/` and `logs/` writable (755/775).
5. Open `https://your-domain/install.php`: enter company name and the owner's login. It builds the tables,
   loads the starter data and creates the master account.
6. **Delete `install.php`** from the server. (It refuses to re-run once data exists, but remove it anyway.)
7. Sign in → **My Account** → turn on 2FA.

## 3. First-run checklist (all inside the app)
| Where | What |
|---|---|
| Admin → Settings | Company address, reg no, VAT no, bank details, invoice terms, trip-ref prefix, brand colour |
| Admin → Rates & Costs | Diesel price, consumption, running cost, driver-day cost, VAT %, commission % |
| Admin → **Clients** | Add/edit the companies you bill: levy/markup %, genset, standing rate, free hours, surcharges, T.O rule, invoice layout. Add shipping lines |
| Admin → **Destinations** | Routes + km + each client's base rate. "Quick-edit all rates" for bulk changes; "Apply a rate change" for a % rate letter |
| Admin → Empty Rates | Carrier empty-move rates |
| Admin → **Driver Pay** | What drivers earn per route/size. Edit one, quick-edit all, or change all by a % |
| Fleet → Trucks / Drivers / Subcontractors | Add manually |
| Admin → Users | Office staff, drivers (link to a driver record), subcontractor logins |

The starter data (9 clients, 39 routes, rates, driver pay, empty rates) is a template — edit or remove it.

## 4. How pricing works
Charged route rate = client's base rate for the route × (1 + client %), where the % applies only if the
client's mechanism is *fuel levy* or *markup*; *flat* clients are charged the base rate as is.
Genset = client genset charge × the same multiplier. Standing = (hours − free hours) × standing rate.
Surcharges are per client and may be flagged "apply %". Cost, profit, margin and commission come from the
Rates & Costs settings. Changing any value affects new quotes/trips immediately; existing trips keep the
amounts they were captured with.

## 5. Security notes
- Production needs `TMS_ENV=production` (forces HTTPS, secure cookies, hides errors).
- Back up the database and `uploads/` daily, off the server; test one restore.
- Never reuse the sample passwords; use a unique strong secret in `config.local.php`.
- Personal data (drivers, clients) falls under POPIA: restrict user roles accordingly.

## 6. Updating
Upload changed files over the old ones; keep `config.local.php` and `uploads/`.
