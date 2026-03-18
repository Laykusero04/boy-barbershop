# Boy Barbershop — Codebase Overview

**Document date:** March 18, 2026  
**Purpose:** Organized list of what is already in place — structure, logic, functions, and features.

---

## 1. Project overview

- **App:** Boy Barbershop — Cashier / management system for a barbershop (Tamnag, Lutayan, Sultan Kudarat).
- **Stack:** PHP (procedural + includes), MySQL via PDO, Bootstrap 5.3, Bootstrap Icons. No front-end framework.
- **Timezone:** `Asia/Manila` (set in `connection.php` and via `SET time_zone = '+08:00'`).
- **Currency:** Philippine Peso (₱) used in display and formatting.

---

## 2. Directory and file structure

| Path | Role |
|------|------|
| `connection.php` | PDO connection, timezone, DB config |
| `partials/header.php` | Layout head, nav, sidebar, dark mode, settings helpers |
| `partials/footer.php` | Closing main, scripts, footer text |
| `index.php` | Dashboard (Today, This Month, ROI, insights, quick actions) |
| `add_sale.php` | Record sale; daily breakdown by barber/service |
| `barbers.php` | CRUD barbers + percentage share |
| `services.php` | CRUD services + default price |
| `payment_methods.php` | CRUD payment methods |
| `expenses.php` | Add expense; list/filter by date range |
| `investments.php` | Add investment items; list; total investment |
| `reports.php` | Date-range reports (daily/weekly/monthly/custom), payroll, print |
| `analytics.php` | Peak: activity by hour (today + historical), daily target |
| `inventory.php` | CRUD inventory items; low-stock threshold |
| `owner_insights.php` | Owner pay %, target years; suggested pay; business savings; ROI progress |
| `assets/css/style.css` | App-specific styles |
| `assets/img/logo.png` | Logo (navbar + favicon) |
| `files/development_phases.md` | Roadmap (phases 1–6) |
| `files/codebase-overview-2026-03-18.md` | This document |

---

## 3. Database

- **DB name:** `boy_barbershop`
- **Connection:** `connection.php` — PDO, `utf8mb4`, exception mode, `FETCH_ASSOC`.

### 3.1a Data integrity (foreign keys)

- **sales:** `barber_id` → `barbers(id)`, `service_id` → `services(id)` with `ON DELETE RESTRICT` (applied via `files/apply_data_integrity.php` or `files/sql/data_integrity_foreign_keys.sql`). Prevents hard-deleting barbers/services that are used in sales; soft delete (deactivate) is used in the app.

### 3.1 Tables (inferred from code)

| Table | Purpose |
|-------|---------|
| `barbers` | `id`, `name`, `percentage_share`, `is_active` |
| `services` | `id`, `name`, `default_price`, `is_active` |
| `payment_methods` | `id`, `name`, `is_active` |
| `sales` | `id`, `barber_id`, `service_id`, `price`, `payment_method` (string), `notes`, `sale_datetime` (default CURRENT_TIMESTAMP) |
| `expenses` | `id`, `expense_date`, `category`, `description`, `amount` |
| `investments` | `id`, `item_name`, `cost`, `investment_date`, `created_at` |
| `settings` | `key`, `value` (key-value store) |
| `inventory_items` | `id`, `item_name`, `stock_qty`, `low_stock_threshold`, `unit`, `is_active` |

### 3.2 Settings keys in use

| Key | Used in | Meaning |
|-----|---------|---------|
| `dark_mode` | `partials/header.php` | `0` / `1` for theme |
| `insight_owner_pay_percent` | `index.php`, `owner_insights.php` | Owner pay % (1–100), default 70 |
| `insight_target_years` | `index.php`, `owner_insights.php` | Target years to recover investment (0 = off) |
| `daily_target` | `analytics.php` | Daily sales target (Peak section) |

---

## 4. Connection and config

- **File:** `connection.php`
- **Behavior:** Creates `$pdo`, sets `date_default_timezone_set('Asia/Manila')`, runs `SET time_zone = '+08:00'`.
- **Credentials:** `localhost`, `boy_barbershop`, `root`, empty password (XAMPP default).

---

## 5. Layout and partials

### 5.1 Header (`partials/header.php`)

- Requires `connection.php` (via `__DIR__ . '/../connection.php'`).
- **Functions:**
  - `bb_get_setting($pdo, $key, $default)` — read from `settings`.
  - `bb_set_setting($pdo, $key, $value)` — insert/update `settings`.
- **POST action:** `toggle_dark_mode` — toggles `dark_mode` and redirects back.
- **Output:** `<!DOCTYPE html>`, `<head>` (charset, viewport, title, **favicon** `assets/img/logo.png`, Bootstrap CSS, Bootstrap Icons, `assets/css/style.css`), `<body>` with theme class, navbar (logo, brand, dark/light toggle), sidebar nav (Today, Add sale, Barbers, Services, Payment methods, Expenses, Investments, Reports, Owner pay & insights, Peak links, Inventory).
- **Active state:** Current page highlighted in sidebar via `basename($_SERVER['PHP_SELF'])`.

### 5.2 Footer (`partials/footer.php`)

- Closes main card and container, adds “Boy Barbershop · Cashier System MVP”, loads Bootstrap JS bundle.

---

## 6. Pages, logic, and features

### 6.1 Dashboard — `index.php`

- **Insight settings (POST `save_insight_settings`):** Saves `insight_owner_pay_percent` (1–100) and `insight_target_years` (1–100 or delete if 0); redirects back.
- **Loads from DB:** Today/month date ranges; today/month sales, barber share, expenses; today/month profit; top barber today/month; all-time sales, share, expenses, profit; total investment; ROI % and progress; last 12 months aggregates (sales, share, expenses, profit per month).
- **Derived:** `$todayProfit`, `$monthProfit`, `$allProfit`, `$roiPercent`, `$roiProgress`; average monthly sales/expenses/profit; suggested owner pay; payback time (years/months/days); if target years set, required monthly profit and approximate sales; this month vs average tips (sales/expenses); list of today’s sales; barber earnings for today.
- **UI:** “Today” block (customers, total sales, barber share, expenses, net profit, top barber). “This month” block (sales, expenses, net profit, top barber). “All-time & ROI” (total sales, barber share, expenses, net profit, investment, ROI %, progress bar). “Insights” (avg monthly sales/expenses/profit, suggested owner pay, payback estimate, goal required profit/sales, tips). Quick action cards (Add sale, Expenses, Reports). Today’s sales list. Today’s barber earnings table.

### 6.2 Add Sale — `add_sale.php`

- **Data loaded:** Active barbers, active services, active payment methods (with fallback if table missing).
- **POST:** Inserts into `sales` (barber_id, service_id, price, payment_method, notes); `sale_datetime` is DB default.
- **Daily breakdown:** Optional `day` (YYYY-MM-DD); query groups by barber and service for that day (count, sales); grouped into `$breakdownByBarber` for drawer.
- **UI:** Form (barber, service with auto price, editable price, payment method, notes). “Daily breakdown” button opens offcanvas with per-barber, per-service breakdown for selected day.

### 6.3 Barbers — `barbers.php`

- **POST:** `action` = `update` (with `id`) or `create`; updates or inserts `barbers` (name, percentage_share).
- **GET `deactivate`:** Sets `is_active = 0` for given id.
- **GET `edit`:** Loads one barber for form.
- **UI:** Form (add/edit), list of all barbers (active first) with edit/deactivate.

### 6.4 Services — `services.php`

- **POST:** `action` = `update` (with `id`) or `create`; updates or inserts `services` (name, default_price).
- **GET `deactivate`:** Sets `is_active = 0`.
- **GET `edit`:** Loads one service for form.
- **UI:** Form (add/edit), list of all services (active first) with edit/deactivate.

### 6.5 Payment methods — `payment_methods.php`

- **POST:** `action` = `update` (with `id`) or `create`; updates or inserts `payment_methods` (name). Handles duplicate-name PDO exception.
- **GET `deactivate`:** Sets `is_active = 0` (with try/catch if table missing).
- **GET `edit`:** Loads one payment method for form.
- **UI:** Form (add/edit), list (active first) with edit/deactivate.

### 6.6 Expenses — `expenses.php`

- **Filters:** `from` / `to` (default current month).
- **POST:** Inserts into `expenses` (expense_date, category, description, amount).
- **Queries:** List expenses in range; sum of amount in range.
- **UI:** Add-expense form; date-range filter; table of expenses; total for range.

### 6.7 Investments — `investments.php`

- **POST:** Inserts into `investments` (item_name, cost, investment_date optional).
- **Queries:** All investment items (ordered); SUM(cost) as total.
- **UI:** Add form; table of items with date/item/cost; total investment displayed.

### 6.8 Reports — `reports.php`

- **Presets:** daily (default), weekly (this week Mon–Sun), monthly (current month), custom.
- **Query params:** `preset`, `from`, `to`.
- **Queries:** Sales total and customer count in range; barber share in range; expenses in range; net profit; top barber in range; list of sales (for print); payroll by barber (name, percentage_share, total_sales) for range.
- **UI:** Preset + from/to form; summary cards (sales, barber share, expenses, net profit, top barber); sales table; payroll table; Print button (print-friendly).

### 6.9 Analytics (Peak) — `analytics.php`

- **Helpers:** `getSetting($pdo, $key, $default)`, `setSetting($pdo, $key, $value)` (same pattern as header).
- **POST `save_target`:** Saves `daily_target`; redirects to `analytics.php?section=peak&saved=1`.
- **Daily target:** Loads `daily_target`; today’s sales, customers, avg sale price; average service price (from `services` or fallback); remaining to target; estimated haircuts to reach target; progress %.
- **Peak today:** Sales by hour (0–23); peak hour by customer count.
- **Activity by hour (historical):** `lookback_days` (default 30, max 365); sales by hour in range; distinct days in range; per-hour average customers per day; “usually busy” threshold (50% of peak).
- **Section:** `section=activity` (usual customer activity by hour) or `section=peak` (peak today + daily target).
- **UI:** Tabs for “Usual customer activity by hour” and “Peak (today) & Daily Target”; activity table/chart; peak table; daily target form and summary (remaining, estimated haircuts, progress).

### 6.10 Inventory — `inventory.php`

- **POST:** `action` = `update` (with `id`) or `create`; updates or inserts `inventory_items` (item_name, stock_qty, low_stock_threshold, unit). Threshold/stock sanitized (≥ 0).
- **GET `deactivate`:** Sets `is_active = 0`.
- **GET `edit`:** Loads one item for form.
- **UI:** Form (add/edit); list of items (active first) with stock, threshold, unit; low-stock indication.

### 6.11 Owner pay & insights — `owner_insights.php`

- **Insight settings:** Same as dashboard (owner pay %, target years); POST redirects to self.
- **Loads:** Total investment; all-time profit (sales − barber share − expenses); last 12 months (sales, share, expenses, profit) — same logic as dashboard.
- **Derived:** Average monthly profit/sales; suggested monthly/daily owner pay; suggested monthly/daily business savings; payback time; goal required profit/sales if target years set; ROI progress.
- **UI:** “Set aside for the business” (total investment, link to investments). “Save for the business” (amount saved so far, progress bar to investment, suggested daily/monthly business amounts). “Your suggested payout” (monthly and daily owner pay). “Recover investment in X years” (goal required profit/sales if set). Settings form (owner pay %, target years). Optional tips (this month vs average).

---

## 7. Shared logic and formulas

- **Barber share:** `sale price × (barber.percentage_share / 100)`.
- **Net profit:** `Total sales − Total barber share − Total expenses`.
- **ROI progress:** `min(100, max(0, (all-time profit / total investment) × 100))`.
- **Payback time:** From average monthly profit and total investment; formatted as years, months, days.
- **Suggested owner pay:** `avg monthly profit × (owner_pay_percent / 100)`; daily = monthly / 30.
- **Business savings (owner insights):** `(100 − owner_pay_percent)%` of profit; daily/monthly suggested amounts.

---

## 8. Front-end and UX

- **Bootstrap 5.3:** Layout, cards, forms, tables, offcanvas, progress, nav.
- **Bootstrap Icons:** Used across pages.
- **Custom CSS:** `assets/css/style.css` (e.g. stat cards, action cards, navbar, sidebar, empty states).
- **Dark mode:** Stored in `settings.dark_mode`; applied via `data-bs-theme` and body class `bb-dark`.
- **Favicon:** `assets/img/logo.png` (set in header).
- **Responsive:** Grid and utilities from Bootstrap; sidebar and layout adapt to screen size.

---

## 9. Feature checklist (what’s in place)

| Feature | Status |
|---------|--------|
| Database connection (PDO, Manila timezone) | ✅ |
| Settings (key-value) + dark mode | ✅ |
| Barbers CRUD + percentage share | ✅ |
| Services CRUD + default price | ✅ |
| Payment methods CRUD | ✅ |
| Add sale (barber, service, price, payment, notes) | ✅ |
| Daily breakdown by barber/service (Add sale) | ✅ |
| Today’s sales and barber earnings on dashboard | ✅ |
| Today & This month dashboard blocks | ✅ |
| Expenses add + list + date range filter | ✅ |
| Net profit (today, month, all-time) | ✅ |
| Investments add + list + total | ✅ |
| ROI % and progress (dashboard) | ✅ |
| Reports: daily/weekly/monthly/custom, payroll, print | ✅ |
| Peak: activity by hour (today + historical lookback) | ✅ |
| Daily target (set, remaining, estimated haircuts) | ✅ |
| Owner insights: suggested pay, business savings, payback, goal years | ✅ |
| Insight settings (owner pay %, target years) | ✅ |
| Inventory CRUD + low-stock threshold | ✅ |
| Layout: header, navbar, sidebar, footer | ✅ |
| Favicon and logo in nav | ✅ |

---

## 10. Reference: development phases

See `files/development_phases.md` for the full roadmap (Phases 1–6). The current codebase implements the majority of Phases 1–5 and parts of Phase 6 (inventory, dark mode, responsive UI).
