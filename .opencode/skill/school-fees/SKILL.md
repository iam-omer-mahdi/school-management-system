---
name: school-fees-mvp
description: Build the MVP of the school fees management system (Laravel, Livewire v4 single-file components, Tailwind) in three steps. Step 1 is school structure and student enrollment. Step 2 is fees and discounts. Step 3 is reports (with the payments and refunds data they need). Use when asked to start, continue, or review the school fees MVP.
---

# School Fees MVP

Build the MVP in **three sequential steps**. Finish, test, and get confirmation on each step before starting the next. Never mix the scope of the steps.

## Engineering standards (apply to every step)

### Stack and product conventions
- Laravel (latest stable), Livewire v4, Tailwind CSS. SQLite or MySQL.
- **No starter kit** (no Breeze/Jetstream/Fortify). Custom login page.
- Single user (finance person). No roles or permissions.
- Mostly **offline**: no CDN assets, no external fonts or APIs. Bundle everything locally with Vite.
- UI in **Arabic (RTL) and English**, switched by a display-language setting. User-entered data stays in one field in whatever language is typed.
- Use Western digits 0-9 in all languages.
- Money: store as integer minor units or `decimal(12,2)`. Pick one and use it everywhere.
- Never hard-delete students. Use status instead.

### Livewire v4 single-file components (SFC)
- Every page and UI piece is a **single-file component** (PHP class and Blade template in one `.blade.php` file). Generate with `php artisan make:livewire`, and follow the v4 file naming and location conventions that the installed version generates.
- Full pages are SFCs registered **directly in the routes** with `Route::livewire(...)`. No controllers for pages. Group routes by feature in `routes/web.php`, apply `auth` middleware to the group, and give every route a name.
- Keep components small and single-purpose. Extract reusable UI into child SFCs or Blade components (tables, status badge, money display, search input, confirm modal). Do not copy and paste markup.
- Use **Form objects** (`Livewire\Form`) for validation and persistence of create/edit forms.
- Put business logic in **Action or Service classes** (for example `EnrollStudent`, `CalculateEnrollmentBalance`, `RecordPayment`). Components only handle UI state and call them. Wrap multi-write operations in `DB::transaction`.
- Use `#[Computed]` for derived data and queries, never run queries in the Blade template.
- Do not expose full Eloquent models or big collections as public properties. Keep public properties to ids and scalar filter values, and resolve models in computed properties. Lock sensitive props (`#[Locked]`).
- Use `#[Url]` for search, filters, and sort so pages are linkable. Use `WithPagination` for every list. Debounce live inputs (`wire:model.live.debounce.300ms`) and use `wire:model.blur` or deferred binding where live updates are not needed.
- Use `wire:key` in loops, `wire:loading` states on actions, and `#[Lazy]` (with a skeleton placeholder) for heavy report sections so the page shell renders instantly.
- Use enums for statuses, discount types, and payment methods. Use backed enums with Eloquent casts.

### Fast navigation
- Use **`wire:navigate`** on every internal link and navigation element (sidebar, tabs, table row links, breadcrumbs, redirects). Use `wire:navigate.hover` on the main menu links to prefetch.
- Redirect inside components with `$this->redirectRoute('name', navigate: true)` so SPA-style navigation is kept.
- Keep a **single persistent layout** (sidebar and header). Use `@persist` for elements that must survive navigation (for example the language switcher and sidebar). Set the layout once for all pages and avoid reloading assets per page.
- Make scripts and styles navigate-safe (use `data-navigate-once` where needed and Livewire's lifecycle hooks, not `DOMContentLoaded`).
- Use full-page reloads only where required (language switch that changes `dir` and `lang`, login and logout).

### Query optimization
- **No N+1 queries.** Eager load with `with()` and constrain relations. In non-production call `Model::preventLazyLoading()` (and `shouldBeStrict()`), and fix every violation.
- Select only the needed columns. Never load all rows to filter or sum in PHP.
- Do totals and aggregates **in SQL**: `withSum`, `withCount`, `selectRaw` with `SUM`/`COUNT` and `GROUP BY`, and subquery joins. Never loop over students or enrollments to total money.
- Add **indexes and foreign keys** in migrations on every FK, on filter and sort columns (`students.name`, `students.phone`, `students.status`), and composite unique or index keys (`enrollments(academic_year_id, grade_id)`, `enrollments(student_id, academic_year_id)`, `payments(enrollment_id, fee_type_id)`).
- Paginate all lists (`simplePaginate` when counts are not needed). Use `chunkById` or `lazy()` for exports.
- Reuse query logic through **local scopes and a query class**, not duplicated builders. Cache only static lookups (schools and grades) and invalidate when they change.
- Check heavy pages with `EXPLAIN` and a query counter (Debugbar or Telescope in dev). Add a test that asserts a bounded query count for list and report pages.

### Clean code
- PSR-12 and Laravel Pint. Run `vendor/bin/pint` before finishing each step.
- Strict types, typed properties, return types, and constructor injection. No magic numbers and no business rules in Blade.
- Thin migrations that are reversible, one concern each. Use factories and seeders for all test data.
- Name things for the domain (`Enrollment`, `GradeFee`, `Discount`, `Payment`, `Refund`). Keep methods short, with early returns.
- Validation messages and UI strings go through translation files (`lang/en`, `lang/ar`). No hardcoded strings in components.
- Tests (Pest or PHPUnit): feature tests per step for the core rules and Livewire component tests for the main flows.

---

## Step 1: School structure and student enrollment

### Goal
A logged-in user can manage academic years, see the fixed school structure, register students, and enroll them in a grade for an academic year.

### 1.1 Authentication
- Custom login page, then redirect to a simple dashboard (placeholder cards for now).
- One seeded user. It cannot be deleted. It can only change its **password and email** (settings page).
- Forgot password: emailed OTP. Also 3 security questions set in settings (answers hashed) for offline recovery.
- Language switcher (English/Arabic) with RTL layout via `dir` on `<html>`.

### 1.2 School structure (seeded, not editable in UI)

| School | Grades |
|---|---|
| Elementary | 1 to 6 |
| Middle | 1 to 3 |
| High | 1 to 3 |

Tables: `schools`, `grades` (belongs to school, has a `number`). Seed them. Provide a read-only page that lists them.

### 1.3 Academic years
- Table `academic_years`: `name` (e.g. `26/27`, unique), `starts_on`, `ends_on` (optional).
- Creating a new year does **not** close older years. All years stay open and viewable.
- Simple CRUD page.

### 1.4 Students
Table `students`:
- `student_number`: auto-generated on creation, unique, **not editable**
- `name` (required), `phone` (required), `secondary_phone`
- `home_address`, `mother_name`, `blood_type`, `date_of_birth` (all optional)
- `guardian_name`, `guardian_relationship` (columns here, no guardian table)
- `status` enum: `active` (default), `transferred`, `expelled`, `left`, `suspended`, plus optional `status_note` and `status_changed_at`

Rules:
- Students are never deleted.
- Only `active` students can be enrolled in a new academic year.
- An inactive student can be **reactivated**. That only makes them available for enrollment again.
- Past enrollment data is always kept.

### 1.5 Enrollments
Table `enrollments`: `student_id`, `academic_year_id`, `grade_id`, timestamps. Unique on (`student_id`, `academic_year_id`).

### 1.6 UI
- **Student list and system-wide search** (name, student number, phone) with status badges.
- **New student form**: one form with student details plus an optional "Enroll now" section (school, then grade dropdown filtered by that school, then academic year) and a **single Save**. Use a DB transaction.
- **Student page**: info, status controls, enrollments list, and an **Enroll** button for existing or returning students.
- Editing student data is allowed. `student_number` stays read-only.

### Step 1 acceptance checklist
- [ ] Login works, seeded user limited to email and password changes, OTP and security-question recovery wired
- [ ] Arabic RTL and English render, digits are 0-9
- [ ] Schools and grades seeded
- [ ] Academic years CRUD works and does not close other years
- [ ] Student creation with and without "Enroll now" works in one save
- [ ] Student number unique, auto-generated, not editable
- [ ] Inactive students cannot be enrolled until reactivated
- [ ] No double enrollment in the same year
- [ ] Search works across the system
- [ ] All pages are SFCs routed with `Route::livewire`, links use `wire:navigate`
- [ ] No N+1 queries, indexes in place, Pint clean, tests pass

**Stop and ask for confirmation before Step 2.**

---

## Step 2: Fees and discounts

### Goal
Define fees per grade per academic year, compute what each enrollment owes, and apply discounts.

### 2.1 Fee types
Table `fee_types`: `name`, `is_tuition`, `is_mandatory`.
- Seed a default **Tuition** type (tuition, mandatory).
- Other types (transportation, books, exam) are user-created and **separate from tuition** in all totals.

### 2.2 Fee structures
Table `grade_fees`: `grade_id`, `academic_year_id`, `fee_type_id`, `amount`. Unique on (`grade_id`, `academic_year_id`, `fee_type_id`).

Fee setup flow (UI, in order): school, grade (dropdown limited to that school), academic year, fee type (Tuition default if none exist), amount.

Rules:
- Show a clear **warning that the amount cannot be changed after saving**. Once set it is locked, with no mid-year edits.
- Next year's fees are set separately, so old years keep original amounts.
- Every student enrolled in that grade and year owes the tuition fee.
- A **mandatory** fee type added later applies to all enrollments in that grade and year.
- An **optional** fee type is not auto-added. It can be assigned to specific students (`enrollment_fees`: `enrollment_id`, `grade_fee_id`).

### 2.3 Computing amounts owed
Compute, do not store drifting totals. Use query scopes or a service class, and do it in SQL where it spans many rows:
- Tuition due = tuition `grade_fees.amount` for the enrollment's grade and year
- Mandatory fees due = all mandatory `grade_fees` for that grade and year
- Optional fees due = only those linked through `enrollment_fees`
- Net tuition = tuition due minus discount

### 2.4 Discounts
Table `discounts`: `enrollment_id`, `type` (`percentage` or `fixed`), `value`, `note`.
- Per student per academic year (via the enrollment).
- Applies to **tuition only**.
- Percentage between 0 and 100. Fixed value cannot exceed the tuition amount.
- Can be edited or removed.

### 2.5 UI additions
- **Fee setup page** (flow above) with fees listed by year, school, and grade. Old years viewable.
- On the student page, each enrollment shows: tuition, discount, net tuition, each other fee, total due.
- Discount form on the enrollment.
- Simple per-grade summary: enrolled students and total expected fees (tuition separate from other fees). No paid or unpaid yet.

### Step 2 acceptance checklist
- [ ] Fee setup flow works with the lock warning, saved amounts cannot be edited
- [ ] Tuition applies to every enrollment in that grade and year
- [ ] New mandatory fee type applies to existing enrollments in that grade and year
- [ ] Optional fees apply only to assigned students
- [ ] Percentage and fixed discounts work, tuition only, validated
- [ ] Enrollment breakdown totals correct, old years unchanged
- [ ] Standards from "Engineering standards" met, tests cover the calculations

**Stop and ask for confirmation before Step 3.**

---

## Step 3: Reports

Reports need payment and refund data, so Step 3 starts with a small data and entry layer for payments and refunds, then builds the reports on top.

### 3.1 Payments (prerequisite)
Table `payments`: `enrollment_id`, `fee_type_id`, `amount`, `method` enum (`cash`, `bank_transfer`), `transaction_number` (required when `bank_transfer`), `paid_at`, `note`, unique `receipt_number` (auto-generated).
- Installment payments: any number of payments per enrollment and fee type. No due dates.
- Payment amount must be greater than 0. Show a warning (not a block) if it exceeds the remaining amount, since overpayment through bank transfer can happen, and the refund below handles it.
- Each payment is stored forever. A **printable receipt** page (print stylesheet, no external assets) is available for every payment.
- Payments are allowed for inactive students.
- Enrollment and student pages show per fee type: due, paid, remaining.

### 3.2 Refunds (prerequisite)
Table `refunds`: `student_id`, `academic_year_id`, `grade_id`, `fee_type_id`, `amount`, `note` (explanation, required), `refunded_at`.
- Refunds reflect the school's **cash position** only. A refunded student **still shows as owing**. There is no waiver or adjustment feature.
- Refunds are allowed for inactive students.

### 3.3 Calculation rules (single source of truth)
Implement in one report query class and reuse it everywhere:
- **Expected** = net tuition + mandatory fees + assigned optional fees
- **Paid** = sum of payments (gross)
- **Remaining (unpaid)** = expected minus paid (refunds do not change this)
- **Refunded** = sum of refunds
- **Net cash received** = paid minus refunded
- Tuition and other fees are always reported separately.

### 3.4 Reports
All reports are filterable by **academic year** (required), **school**, **grade**, and fee scope, with `#[Url]` state:
1. **School-wide report**: per school, number of students, expected, paid, unpaid, refunded, net cash. Example: 50 students, expected 50000, paid 43000, unpaid 7000.
2. **Per-grade report**: the same figures per grade, with a drill-down list of students (number, name, status, expected, paid, unpaid) ordered by unpaid amount.
3. **Tuition-only report**: the same figures using tuition fee types only (net of discounts), and total discounts given.
4. **Other-fees report**: the same figures by non-tuition fee type (transportation, books, exam, and so on), one column or row per type.
5. **Unpaid report**: students with remaining above 0, by year, school, grade. **Inactive students stay visible** for the academic year and grade they were enrolled in when money is unpaid, with a status badge (withdrawn, suspended, and so on), paid and unpaid amounts.
6. **Payments and refunds log**: filterable by date range, method, school, grade, with totals.
7. **Dashboard cards** (from Step 1 placeholders): current-year expected, paid, unpaid, and recent payments.
- Every report has a print-friendly view and a CSV export (streamed with `lazy()` or `chunkById`, no memory loading).
- Show status and totals for every student row, and add a totals row.

### 3.5 Report performance (required)
- Compute with grouped SQL aggregates. **Pre-aggregate** payments, refunds, and fees in subqueries, join on `enrollment_id` and fee type, then group. Never loop in PHP.
- Avoid join fan-out: aggregate each child table separately before joining so sums are not multiplied.
- Add composite indexes for report filters: `payments(enrollment_id, fee_type_id)`, `payments(paid_at)`, `refunds(academic_year_id, grade_id, fee_type_id)`, `enrollments(academic_year_id, grade_id)`.
- Use `#[Lazy]` on report sections with skeleton placeholders. Paginate student drill-downs.
- Add tests that compare report numbers to hand-calculated fixtures (including a discount, an optional fee, an installment, a refund, and an inactive student), and a test that asserts a bounded query count per report.

### Step 3 acceptance checklist
- [ ] Payments by cash or bank transfer, with transaction number required for transfers, installments supported
- [ ] Printable receipt for every payment
- [ ] Refunds stored with note, reflect cash position only, refunded student still shows as owing
- [ ] Enrollment and student pages show due, paid, remaining per fee type
- [ ] School-wide, per-grade, tuition-only, other-fees, unpaid, and payments/refunds reports are correct
- [ ] Inactive students with unpaid money remain visible with status in the right year and grade
- [ ] Print view and streamed CSV export work
- [ ] Report numbers match test fixtures, query counts bounded, no N+1
- [ ] Standards from "Engineering standards" met, Pint clean, tests pass

---

## Out of scope for the MVP
- Roles and permissions
- Fee waivers or adjustments
- Due dates, late fees, and reminders
- SMS or email notifications to guardians

## Working method
1. Before each step, output a short plan: migrations, models and relationships, actions/services, Livewire SFCs and routes, tests.
2. Build migrations, enums, models, factories, and seeders first, then actions and queries, then components, then tests.
3. Run Pint and the test suite, and fix failures before reporting a step done.
4. At the end of each step, summarize what was built, assumptions made, and anything that needs a decision.
