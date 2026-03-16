💈 Boy Barbershop Management System – Owner Version

## 1. Overall Goal

Build a **simple but powerful web app** for barbershop owners to track:
- **Sales & cashier**
- **Barber commissions**
- **Expenses & profit**
- **Investments & ROI**
- **Analytics and unique insights**

Main user: **Owner / Manager** (can record sales, manage barbers & services, see reports).

---

## 2. Core Modules

### 2.1 Sales & Cashier (Main Feature)

**Purpose**: Record every finished service and compute daily/weekly/monthly sales.

**Fields per sale**:
- Date & Time (auto)
- Barber(can crud a barber)
- Service (Haircut can crud a services)
- Price (auto from service, editable)
- Payment Method (Cash / GCash / others)

**Example record**:
- 10:30 AM – John – Haircut – ₱100 – Cash  
- 11:00 AM – Mike – Haircut + Shave – ₱150 – GCash

**System automatically calculates**:
- Daily Sales
- Weekly Sales
- Monthly Sales

And provides a **sales list with filters** (by date range, barber, payment method).

---

### 2.2 Barber Percentage & Earnings

**Purpose**: Automatically compute each barber’s share and shop’s share.

**Config**:
- Each barber has a **percentage share** (e.g. John 60%, Mike 60%).

**Calculation** (per sale):
- Example: Haircut = ₱100, Barber percentage = 60%
- Barber share = ₱60
- Shop share = ₱40

**System calculates** (for any date range):
- Barber total earnings
- Shop total share

---

### 2.3 Barber Performance (Daily Production)

**Purpose**: Track productivity per barber.

**Dashboard example**:
- Barber | Customers | Total Sales | Earnings  
- John  | 15 | ₱1500 | ₱900  
- Mike  | 10 | ₱1000 | ₱600

**Helps you see**:
- Who is more productive
- Who needs improvement

Filters: **today / this week / this month / custom date range**.

---

### 2.4 Expenses Tracking

**Purpose**: Track daily expenses to know real profit.

**Fields per expense**:
- Date
- Category (Supplies, Electricity, Water, Rent, Maintenance, Other)
- Description
- Amount

**Example**:
- Mar 16 – Supplies – Razor blades – ₱200  
- Mar 16 – Utilities – Electricity – ₱500

**System shows**:
- Total expenses per day/week/month
- Expenses by category

---

### 2.5 Profit Monitoring

**Purpose**: See net profit after barber shares and expenses.

**Example**:
- Daily Sales = ₱3000
- Barber Share = ₱1800
- Expenses = ₱500
- **Net Profit = ₱700**

**Formula (per period)**:
- Net Profit = Total Sales − Total Barber Share − Total Expenses

Views: **daily, weekly, monthly**.

---

### 2.6 Business Investment & ROI

**Purpose**: Track total money invested and ROI progress.

**Fields per investment item**:
- Item
- Cost
- (Optional) Date

**Example**:
- Chairs – ₱12,000
- Mirrors – ₱3,000
- Renovation – ₱15,000
- Tools – ₱5,000

Total Investment = ₱35,000

**System shows ROI**:
- Example:
  - Total Profit Earned = ₱20,000
  - Investment = ₱35,000
  - **ROI Progress ≈ 57%**

---

### 2.7 Analytics / Dashboard 📊

**Owner overview – Today**:
- Total Customers
- Total Sales
- Total Barber Share
- Total Expenses
- Profit Today
- Top Barber (by customers or sales)

**This Month**:
- Total Sales
- Total Expenses
- Net Profit
- Top Barber of the Month

---

### 2.8 Reports

**Types of reports**:
- Daily Report
- Weekly Report
- Monthly Report
- Barber Payroll Report (for a date range)

**Daily report example**:
- Date: March 16, 2026
- Customers: 25
- Total Sales: ₱2500
- Barber Earnings:
  - John: ₱900
  - Mike: ₱600
- Expenses:
  - Supplies: ₱200
- Net Profit: ₱800

Future: print-friendly and PDF export.

---

### 2.9 Service & Price Management

**Purpose**: Manage services and default prices.

**Fields per service**:
- Service
- Default Price
- Status (active/inactive)

**Example**:
- Haircut – ₱100
- Haircut + Shave – ₱150
- Kids Haircut – ₱80

Used by cashier to auto-fill price when recording sales.

---

### 2.10 Inventory (Optional)

**Purpose**: Track shop supplies (simple version).

**Fields per item**:
- Item
- Stock
- Unit (pcs, bottles, etc.)

**Example**:
- Razor blades – 100
- Alcohol – 5 bottles
- Powder – 3

Optional extras: low-stock alerts, stock history.

---

## 3. Unique Feature Ideas

### 3.1 Peak Hour Analyzer (Original Unique Feature)

**Purpose**: Show the **busiest hours** of the day based on sales data.

**System shows** something like:
- Time | Customers  
- 9–10 AM – 2  
- 10–11 AM – 8  
- 11–12 PM – 10

**So you know when to**:
- Add more barbers in peak hours
- Run promos in slow hours

---

### 3.2 Daily Target Helper (New Unique Feature)

**Purpose**: Help the owner hit a **daily income target** by showing how much more sales are needed and suggesting actions.

**How it works**:
- Owner sets a **daily target amount** (e.g. ₱3,000).
- System compares **target vs. current sales for today**.
- System shows:
  - Target: ₱3,000
  - Sales so far: ₱1,800
  - Remaining: ₱1,200
- It also estimates **how many more haircuts** are needed based on average price (e.g. “Need ~8 more haircuts at ₱150”).

**Benefits**:
- Very clear **goal for the day**.
- Helps the owner decide:
  - Whether to **extend hours**.
  - Whether to **run quick promos** to reach the target.

---

## 4. Data Model (Database Overview)

Main tables to implement later:
- `barbers` – id, name, percentage, status
- `services` – id, name, default_price, status
- `sales` – id, datetime, barber_id, service_id, price, payment_method, customer_name (optional), notes
- `expenses` – id, date, category, description, amount
- `investments` – id, item, cost, date (optional)
- `inventory_items` – id, name, stock, unit (optional)
- `customers` – id, name, phone, total_visits, total_spent (for loyalty feature)

This section is only for reference when designing the database.

---

## 5. Screens / Pages (High-Level)

- **Dashboard**
  - Cards: Sales Today, Customers Today, Profit Today, Top Barber
  - Quick Actions: Add Sale, Add Expense, View Report
- **Sales / Add Sale**
  - Form to record sale with all fields
  - Sales history table with filters
- **Barbers**
  - Add/edit barbers and percentage
  - View barber performance summary
- **Expenses**
  - Add expense
  - List with filters and totals
- **Investments & ROI**
  - List of items, total investment, ROI progress
- **Reports**
  - Choose type + date range → generate report
- **Services**
  - Manage service names and prices
- **Inventory** (optional)
  - List items and stock

This structure makes it easy to turn the plan into actual PHP pages and database tables.
