# Hassani ERP

Point of sale, stock and reports for **Hassani Bachat Bazaar**, in English and Urdu (اردو). It runs on the shop computer: no internet is needed to sell.

**What it does**

| Area | What you can do |
|---|---|
| **POS** | Sell by scanning or searching. Hold a sale and resume it later. Discounts and price changes need a manager PIN. Take cash, Easypaisa, JazzCash, card or Khata (customer credit). Sell an item that is not in the product list. Print the receipt in **Urdu only**, **English only** or both. Open and close shifts with a cash count (X and Z reports). Reprint receipts and take returns. |
| **Sales** | **Orders** from phone, WhatsApp, field agents or online: draft → confirm (stock reserved, Khata limit checked) → pick → pack → invoice. **Rider board**: assign deliveries, record delivered/failed/returned, receive the cash riders collected. **Wasooli**: Khata collections against open invoices. **Verify payments**: tick Easypaisa/JazzCash/card/bank payments against the statement. **Targets** per store or agent. |
| **Customers & Khata** | Customer records with duplicate check, credit limit, days to pay and price list. Khata statement (printable) and aging. Payment promises, notes/calls/visits, follow-ups, complaints. Merge duplicates. Rule-based segments with CSV export. |
| **Products and stock** | Products with Urdu names, barcodes and pack sizes; CSV import; shelf labels. Four prices per product: **Purchase price**, **Market price (MRP)**, **In-store price** and **Wholesale price**. **Pricing rules** give a lower price by themselves, for example the wholesale price to anyone buying more than 5. Stock on hand by location. Opening stock, write-offs, corrections and quarantine (big values need approval). Transfers between godown, shop floor and stores. Blind stock counts. Batches and expiry. Reorder suggestions that become a purchase order in one click. Repacking bulk into packs. **Products to create**: items sold at the POS that are not in the list yet. Costing uses weighted average, with FIFO batches where batches are used. |
| **Purchasing** | Suppliers and what you owe each one. Purchase orders, with approval above a limit. **Purchase stock**: add stock you bought and record the supplier's bill (paid now or on credit) in one step. Goods received with damage, batch/expiry and freight spread into cost. Supplier bills matched to what arrived. Supplier payments. Returns to supplier. |
| **Accounting** | Double-entry accounts that every sale, purchase, payment, stock change and payroll posts to automatically. Account ledgers, manual journals, reversals, quick expenses (rent, electricity …), month closing, and budgets. |
| **HR & payroll** | Employees with private documents (five-minute links), salary history and lifecycle (probation → confirmed → resigned …). Attendance with clock in/out and corrections. Weekly roster. Leave with balances and holidays. Monthly payroll (prepare → approve → post → pay) with printable payslips. Peshgi (salary advances) with instalment recovery, plus bonuses and deductions. **My work**: each person's own attendance, shifts, leave, payslips and Peshgi. |
| **Reports** | 55 reports in 13 sections (sales, products, customers, payments, agents, POS, inventory, purchasing, finance, HR, audit, forecasts). Each has date presets, store choice, comparison with an earlier period, summary cards, a chart, plain-language findings, a sortable table, saved views and CSV download for Excel. Clicking a number or name opens the document behind it. |
| **Settings** | Business details and every setting with its source (default, business or store). Users, roles and permissions per store. Stores. Lists (categories, brands, units, payment methods, reasons, locations, POS counters, departments, leave types, shifts, tags, tax rates). Security (audit trail, sign-ins, sessions, downloads). API keys for a future website or app. |

The whole interface is available in English and Urdu (right-to-left). Switch with the **اردو / English** button at the top.

---|---|
| **POS** | Sell by scanning or searching, put a sale on hold, discounts and price changes with a manager PIN, cash / Easypaisa / JazzCash / card / Khata (customer credit), sell an item that is not in the product list, print the receipt in **Urdu only**, **English only** or both, open and close shifts with a cash count (X and Z reports), reprint receipts and take returns. |
| **Inventory** | Products with Urdu names, barcodes, units and prices; CSV import; shelf labels; stock on hand by location; opening stock, write-offs, corrections and quarantine (big values need approval); transfers between godown, shop floor and stores; blind stock counts; batches and expiry; reorder suggestions; repacking bulk into packs; **"Products to create"**: items sold at the POS that are not in the list yet. Costing uses weighted average, with FIFO batches where batches are used. |
| **Reports** | 49 reports in 13 sections (sales, products, payments, POS and shifts, inventory, finance, audit, forecasts …). Each has date presets, store choice, comparison with an earlier period, summary cards, a chart, plain-language findings, a sortable table, saved views and CSV download for Excel. |
| **Users** | Staff accounts (no public sign-up), roles per store, temporary passwords, suspend and deactivate, optional authenticator-app sign-in. |

---

## 1. Start it (Windows shop computer)

1. Unzip the folder somewhere permanent, for example `C:\HassaniERP`. Do not run it from inside the zip.
2. Double-click **`Start Hassani ERP.exe`** (or `Start Hassani ERP.bat`, which does the same).
3. The first time only, it offers to install what is missing, using Windows' own installer (winget):
   * **PHP 8.3**: answer **Y**.
   * **PostgreSQL 16**: answer **Y**. Its `postgres` password is set to the one in `.env` (`DB_PASSWORD=postgres`).
   * If Windows asks "Do you want to allow this app to make changes?", answer **Yes**.

   If winget is not available, install them by hand: PHP 8.3 (Windows "Non Thread Safe" x64 zip from windows.php.net, unzipped to `C:\php`) and PostgreSQL 16 from postgresql.org, with the `postgres` password set to the one in `.env`.
4. The starter switches on the PHP extensions it needs, creates the `hassani_erp` database, brings it up to date, starts the app and opens it in Chrome (or Edge).
5. **First run:** a "Set up Hassani ERP" page asks for your name, e-mail and a password. That account is the **owner**. The page never appears again.

Keep the black window open while the shop is working. **Closing it stops the app.** If the app is somehow still running after the window was closed, double-click **`Stop Hassani ERP.bat`**.

Optional: double-click **`Create Desktop Shortcut.bat`** for a desktop icon. It can also start the app automatically when Windows starts.

Starting every morning is safe. It only applies updates that are needed and never deletes data.

### Linux / macOS

Run `./start.sh`. It needs PHP 8.2+ with `pdo_pgsql`, and PostgreSQL running. Then open http://localhost:8000.

### What is in the folder

| Item | Purpose |
|---|---|
| `Start Hassani ERP.exe` / `.bat` | One-click start (the `.exe` only runs `starter\start.ps1`; its source is in `starter\launcher`). |
| `Stop Hassani ERP.bat` | Emergency stop. |
| `.env` | Settings: database name and password, port, `SERVER_HOST`. |
| `vendor\`, `public\build\` | Ready-made program files. Composer and Node.js are **not** needed on the shop computer. |
| `storage\logs\` | Logs, useful when something goes wrong. |

---

## 2. Staff and permissions

The owner adds staff under **Settings → Users → Add user**: name, e-mail and role (Cashier, Store manager …), optionally limited to one store or until a date. A **temporary password** is shown once. Give it to the person; they choose their own at first sign-in.

Managers set an **approval PIN** under *My account*. The POS asks for a manager PIN for discounts above the limit, price changes, voids, refunds, cash payouts and Khata above the credit limit. Each approval is logged.

### Forgotten owner password

On the shop computer, open a command prompt in the Hassani ERP folder and run:

```
php artisan hassani:reset-owner-password owner@example.com
```

It prints a new temporary password. The owner must change it at the next sign-in.

---

## 3. Hardware

**Receipt printer (USB, 80 mm or 58 mm thermal).**
1. Install the printer's Windows driver and make it the **default printer**.
2. In the driver's settings, set the paper size to 80 mm (or 58 mm) receipt roll, and set the margins to 0.
3. The starter opens Chrome with `--kiosk-printing`, so receipts print straight away without a print dialog. In a normal browser window you will see the print dialog instead. Tick "Background graphics" off and set the margins to "None" once.

**Cash drawer.** A drawer plugged into the receipt printer's RJ11 port opens when a receipt prints. In the printer driver, turn on **"Open cash drawer" / "Cash drawer kick" before or after printing** (often under *Device settings* or *Peripherals*). Opening the drawer without a sale (POS → Cash → **Open drawer**) needs a reason, and each time is logged in the audit reports.

**Barcode scanner (USB).** Works as a keyboard. Set it to send **Enter** after each code (most do by default). On the POS, scanning adds the product. Scanning a barcode that is not in the list offers "Item not in list".

**Receipt language.** On the POS, the receipt language switch (English only / Urdu only / both) remembers its choice on each computer. The shop-wide default is under **Settings → Business → POS & cash**.

---

## 4. Selling an item that is not in the product list

On the POS, press **Item not in list** (or scan an unknown barcode). Type a name, a price, the quantity and, optionally, the barcode, then sell normally. The receipt shows the name you typed.

Each such sale is recorded under **Inventory → Products to create**, grouped by name or barcode. Each row shows how often it sold:

* **frequently**: 5 or more sales,
* **occasionally**: 2 to 4 sales,
* **rarely**: 1 sale.

Each row also shows the quantity, the last price and the total amount. **Create product** opens the product form already filled in. **Dismiss** removes the row from the list.

---

## 5. Using more than one computer (counter + office)

On the main computer, double-click **`Host on Wi-Fi.bat`**. Windows asks for permission once (it needs it for the firewall); answer **Yes**. The script sets `SERVER_HOST=0.0.0.0` in `.env`, opens the firewall for computers on the same shop network only, restarts the app and shows an address such as `http://192.168.1.20:8000` (also copied to the clipboard). Type that address on the other computers or phones.

* Keep the black Hassani window open on the main computer: it is the server.
* If Windows has marked your Wi-Fi as a *Public* network, the script offers to mark it *Private* (other computers cannot connect to a Public network).
* It is plain `http`, fine on your own shop network and not for public Wi-Fi. The app is never opened to the internet.
* USB and Bluetooth barcode scanners work on every computer. The camera scanner only works on the main computer, because browsers block cameras on `http` addresses.
* To stop sharing, double-click **`Stop sharing.bat`**.

### Scanning

A USB or Bluetooth scanner works anywhere on the POS screen, with no need to click the search box. It adds the item at once. Scanning a **receipt number** (printed as a barcode at the bottom of every receipt) opens that receipt, ready to print again or to return items; scanning an **order number** opens the order. The scanner must press Enter after the code, which is the factory setting on almost every scanner.

---

## 6. Backups

Copy the database every evening to a USB drive or cloud folder:

```
"C:\Program Files\PostgreSQL\16\bin\pg_dump.exe" -U postgres -Fc hassani_erp -f D:\backup\hassani_%date:~-4%%date:~3,2%%date:~0,2%.dump
```

To restore onto a fresh computer:

```
pg_restore -U postgres -d hassani_erp --clean file.dump
```

Also keep a copy of the `.env` file, because it holds the `APP_KEY`. Uploaded files are under `storage\app`.

---

## 7. Training / demo data

To practise without touching real data, use a database whose name ends in `_demo`. The command refuses to run on any other database.

```
set DB_DATABASE=hassani_demo
php artisan migrate --seed
php artisan hassani:demo-data
```

This creates demo staff (`owner@demo.test` / `cashier@demo.test`, password `demo-password-1`, PIN 2580), a few days of sales, a shift and a count. To use it, start the app with `DB_DATABASE=hassani_demo` in `.env`, then put it back to `hassani_erp` afterwards.

---

## 8. FBR (moving to FBR-integrated invoicing later)

FBR integration is **not** switched on. The groundwork is already in place:

* Invoices already store everything an FBR invoice needs: a unique number per store, date and time, items with quantity, unit price, discount, tax rate and tax amount, the payment method, and the buyer (name, CNIC/NTN if recorded).
* Sales tax is posted to its own account. The **Sales tax summary** report shows output tax by period. It is *not* an FBR return.

**Steps when you are ready:**

1. Register as a Tier-1 retailer, obtain the **POS ID** and the integration credentials from FBR (IRIS → POS integration).
2. Add tax rates per product category (17%/18% standard, reduced or exempt items) in the product form.
3. A developer adds an "FBR" service in `app/Domain/Sales/` that runs right after `CheckoutService::checkout` posts the invoice:
   * It sends the invoice (the JSON that FBR's POS API expects) to the FBR fiscal endpoint, or to the locally installed FBR component.
   * It saves the **FBR invoice number** on the invoice.
   * If the computer is offline, it queues the invoice and retries.
4. The receipt shows the FBR invoice number and the QR code / "Verify on Tax Asaan" line. `ReceiptData::forInvoice` and `ReceiptView.vue` are the two places to change.
5. Test on FBR's sandbox first. Invoices made before the switch stay as they are.

---

## 9. Troubleshooting

| Problem | Fix |
|---|---|
| "Cannot connect to PostgreSQL" | Check that the PostgreSQL service is running (*Services* → `postgresql-x64-16`). Check that `DB_PASSWORD` in `.env` matches the `postgres` password. |
| "pdo_pgsql could not be switched on" | Open `php.ini` next to `php.exe` and remove the `;` in front of `extension=pdo_pgsql` and `extension=pgsql`. |
| "Port 8000 is used by another program" | Close the other program, or put `SERVER_PORT=8010` (any free number) in `.env` and change `APP_URL` to match. |
| Receipt prints with a dialog | Start with the `.exe`, which uses Chrome kiosk printing. Make sure the receipt printer is the default printer. |
| Blank page after an update | Run `Start Hassani ERP` again. It clears and rebuilds the caches. |
| Anything else | Look at `storage\logs\laravel.log` (the most recent lines are at the bottom). |

---

## For developers

Laravel 12 (PHP 8.3), PostgreSQL 16, Inertia 2 + Vue 3 + TypeScript, Tailwind. The design is multi-company: every table carries `company_id` and is scoped automatically.

```
composer install && npm ci
npm run build           # or: npm run dev
php artisan test        # feature tests (uses database hassani_erp_test)
```

* `app/Domain/*` holds the business rules: POS checkout, ledgers, costing, counts, reports. Controllers are thin.
* The stock ledger and the journal are append-only, which is enforced in PostgreSQL. Corrections are new entries.
* Reports are defined in `app/Domain/Reporting/Catalog/*.php`. One array describes the SQL, columns, summary, chart, findings and definition of each report.
* Urdu text: `resources/js/lib/ur.ts` and `ur2.ts`. The English text is the key.

### API (for a future website, app or rider phone)

Create a key under **Settings → API keys** (one store, chosen abilities, shown once), then send `Authorization: Bearer <key>`:

| Method | Path | Ability |
|---|---|---|
| GET | `/api/v1/catalog/categories`, `/api/v1/catalog/products`, `/api/v1/catalog/products/{id}` | `catalog:read` |
| GET | `/api/v1/stock` | `stock:read` |
| POST | `/api/v1/customers` | `customers:write` |
| POST | `/api/v1/orders` · `/api/v1/orders/{number}/cancel` | `orders:write` |
| GET | `/api/v1/orders/{number}` | `orders:read` |

Riders sign in with their own login through `POST /api/v1/auth/token` and use `/api/v1/rider/deliveries` and `/api/v1/rider/cash`. Orders placed through the API appear under **Sales → Orders** with the channel "Online".
