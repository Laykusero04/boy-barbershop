# Boy Barbershop – Development Phases

This file is your roadmap: what to build **first**, **next**, and **later**.

---

## Phase 1 – Core Cashier & Barbers (MVP)

Goal: Make the system usable for **daily sales and barber earnings**.

**Includes:**
- Database setup (core tables):
  - `barbers`
  - `services`
  - `sales`
- Basic layout / template (header, sidebar, main content).
- **Barber Management:**
  - Add / edit / deactivate barbers.
  - Set barber percentage share.
- **Service Management (basic):**
  - Add / edit / deactivate services with default prices.
- **Add Sale screen:**
  - Form: date/time (auto), barber, service (auto price), price (editable), payment method, notes.
  - Save to `sales` table.
- **Today’s Sales summary (simple):**
  - Total sales today.
  - List of today’s sales.
- **Barber earnings (basic):**
  - For today: show each barber’s total sales and computed earnings.

**When Phase 1 is done:**  
You can run the shop daily with the app for recording and computing basic shares.

---

## Phase 2 – Expenses & Profit

Goal: See **real profit** after expenses.

**Includes:**
- Add `expenses` table.
- **Expenses screen:**
  - Add expense (date, category, description, amount).
  - List expenses with filters by date.
- **Profit calculation (daily & monthly):**
  - Use existing sales and barber shares from Phase 1.
  - Net Profit = Total Sales − Total Barber Share − Total Expenses.
  - Simple daily and monthly profit views.

**When Phase 2 is done:**  
You understand **how much you really earn**, not just total sales.

---

## Phase 3 – Investments & ROI

Goal: Track **initial investment** and **ROI progress**.

**Includes:**
- Add `investments` table.
- **Investments screen:**
  - Add items (item name, cost, optional date).
  - List all investments and total investment amount.
- **ROI view:**
  - Use total profit from Phase 2.
  - Show:
    - Total Investment
    - Total Profit Earned
    - ROI % and simple progress indicator.

**When Phase 3 is done:**  
You can see how close you are to **getting your money back**.

---

## Phase 4 – Dashboard & Reports

Goal: Give the owner a **clear overview** and printable summaries.

**Includes:**
- **Dashboard page:**
  - Today:
    - Total Customers
    - Total Sales
    - Total Barber Share
    - Total Expenses
    - Net Profit Today
    - Top Barber.
  - This Month:
    - Total Sales
    - Total Expenses
    - Net Profit
    - Top Barber of the Month.
- **Reports page:**
  - Daily / Weekly / Monthly report generator (based on date range).
  - Barber payroll report for a chosen period.
  - Simple print-friendly version.

**When Phase 4 is done:**  
You can quickly **review performance** and **print/share** basic reports.

---

## Phase 5 – Unique Features (Analytics)

Goal: Add **smart insights** that most barbershops don’t have.

**Includes:**
- **Peak Hour Analyzer:**
  - Analyze `sales` by hour.
  - Show which hours of the day have the most customers.
  - Simple chart or table with highlight for peak hours.
- **Daily Target Helper:**
  - Owner sets a daily target (e.g. ₱3,000).
  - Show:
    - Target vs. actual sales today.
    - Remaining amount to reach the target.
    - Estimated number of haircuts needed (based on average service price).

**When Phase 5 is done:**  
You get **actionable insights**: best hours, how far you are from your goal, and what to do.

---

## Phase 6 – Optional Extras

Goal: Quality-of-life improvements and optional features.

**Options (pick what you need):**
- **Inventory tracking:**
  - `inventory_items` table.
  - List + update stock.
  - Low-stock indicator.
- **Better UI/UX:**
  - Responsive design.
  - Dark mode (if you want).

**When Phase 6 is done:**  
The system feels more **professional and complete**, but these are not required for basic use.

---

## Data Integrity (tech upgrade)

- **Foreign keys:** `sales.barber_id` → `barbers.id` and `sales.service_id` → `services.id` with `ON DELETE RESTRICT` so barbers/services that have sales cannot be hard-deleted.
- **Soft delete:** Barbers and services use **deactivate** (`is_active = 0`) only; no `DELETE` in the app. The DB enforces that rows referenced by sales are never removed.
- **Apply once:** Run `files/apply_data_integrity.php` (browser or CLI) or run `files/sql/data_integrity_foreign_keys.sql` in MySQL to add the constraints.

---

## Recommended Order (Short Version)

1. **Phase 1:** Core cashier, barbers, services, sales, and basic earnings.
2. **Phase 2:** Expenses + profit calculation.
3. **Phase 3:** Investments + ROI.
4. **Phase 4:** Dashboard + reports.
5. **Phase 5:** Peak Hour Analyzer + Daily Target Helper.
6. **Phase 6:** Optional inventory, better UI, login, etc.

Use this file to check what you’ve finished and what to build next.

