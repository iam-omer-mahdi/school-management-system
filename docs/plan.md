# Staff Roles & Permissions — Plan

Living design document for [issue #12](https://github.com/iam-omer-mahdi/school-management-system/issues/12)
and everything it touches. **Read this before changing roles/permissions or the schools code.**

Progress, current status and the resume point live in `docs/progress.md`.

- Owner: iam-omer-mahdi
- Started: 2026-10-18
- Status: design approved in conversation, not yet implemented

---

## 1. Purpose

Today every authenticated user can do everything, and there is no way to create or manage
staff accounts from the UI. This change gives each staff member an appropriate level of
access instead of one shared unrestricted login, and it makes the schools/grades module
admin-managed instead of seeded.

Done when: a read-only user can inspect permitted records but cannot change them, finance
access is managed separately from administrator settings, and the admin controls schools
and staff from the app.

---

## 2. Current state (verified 2026-10-18)

- No roles, permissions, gates or policies exist anywhere in the app. Any `auth` user can
  reach every page and call every Action.
- There is no user CRUD. Staff accounts are created out-of-band (seeders) and
  `php artisan user:reset-password {email}` is the only admin fallback.
- Every write already funnels through a named class in `app/Actions/`. This is the natural
  enforcement choke point.
- Livewire pages are single-file components under `resources/views/pages/**`, routed through
  `routes/web.php` with `Route::livewire('/path', 'pages::...')`.
- The three schools (elementary/middle/high) are **fixed seed rows** (`SchoolSeeder`).
  `schools.code` doubles as both the type and the display label: the UI renders
  `__('schools.'.$school->code)`. Grades are seeded alongside them.
- `schools.name` is `unique`. Grades are `unique(school_id, number)`.
- `schools.code` is read in ~10 places plus the report layer
  (`ReportQueries`, `FinancialSummary`, `ReportExport`) as `schools.code AS school_code`.

---

## 3. Decisions made

| # | Decision | Rationale |
|---|----------|-----------|
| D1 | Use `spatie/laravel-permission` **8.3.0** | Owner's call: the project will grow. Verified compatible with this app (`php ^8.3`, `illuminate ^13.0`). Dependency approved by owner per AGENTS.md. |
| D2 | **Model A — roles are the truth.** A user may hold several roles. | Owner's call. Changing what someone can do means changing their roles. There is no per-user permission editing. |
| D3 | Four roles: **Administrator, Registrar, Finance, Read-only**. | Issue #12 names three; Registrar was added because enrollment is clerical, not financial. |
| D4 | An admin can grant extra access by adding a role (e.g. Finance + Registrar). | "Finance can manage registrations" is a role combination, not a per-user flag. |
| D5 | Read-only access is changed by editing the Read-only **role** (affects all Read-only users), assigning a different role, or **creating a new role** with exactly the permissions needed and assigning it. | Consequence of Model A. Spatie has no "deny" — permissions are additive, so an unusual combination becomes its own role. |
| D6 | A school's **type is fixed at creation** and cannot be changed later. | Changing type would rewrite the grade list under live enrollments. |
| D7 | `SchoolType` is a PHP **enum**; grades are generated from it. | One definition instead of the seeder array. |
| D8 | School **name comes from the database**, not translation lookups. | `schools.code` stops doubling as the label. |
| D9 | Grade numbering: **elementary 1–6, middle 1–3, high 1–3**. | Owner's call, confirming today's data. No data migration for grade numbers; the enum encodes it. |
| D10 | Only an Administrator can add or remove a school (`schools.manage`). | Owner's call. |
| D11 | Admins can **create, rename and delete roles** and set each role's permissions from the Roles page. | Without this, Model A can only ever be as precise as the four seeded roles. This is what makes "this one finance user also does registrations" and "this read-only user cannot see fees" possible without touching anyone else. |
| D12 | When an admin creates a staff account they **set an initial password**. The new user is forced to **change it and set their three security questions on first login**. | Owner's call. Works offline (no mail server needed), unlike the email-code flow. |
| D13 | A bilingual **Help / How it works** page reachable from the sidebar for every user. | Owner's call. End users need one place that explains the functions and workflows. |

---

## 4. Scope of this change

### 4.1 Permission list

Spatie permissions, defined once in a PHP enum (`App\Enums\Permission`) and seeded.

| Module | View | Manage |
|--------|------|--------|
| Students | `students.view` | `students.manage` |
| Enrollments | `enrollments.view` | `enrollments.manage` |
| Fees & discounts | `fees.view` | `fees.manage` |
| Payments & refunds | `payments.view` | `payments.manage` |
| Reports | `reports.view` | — |
| Schools & grades | — (`schools.manage` covers entry) | `schools.manage` |
| Staff & roles | — (`staff.manage` covers entry) | `staff.manage` |

`manage` implies `view` for that module; the UI must not offer Manage without View.

### 4.2 Role presets (seeded, editable from the Roles page)

| | Students | Enrollments | Fees | Payments | Reports | Schools | Staff |
|---|---|---|---|---|---|---|---|
| **Administrator** | manage | manage | manage | manage | view | manage | manage |
| **Registrar** | manage | manage | view | view | view | — | — |
| **Finance** | view | view | manage | manage | view | — | — |
| **Read-only** | view | view | view | view | view | — | — |

### 4.3 The roles page

New route `GET /staff` → `pages::staff.index`, guarded by `staff.manage`. New nav entry in
`resources/views/layouts/nav-links.blade.php`, shown only to users with `staff.manage`.

Two sections on one page:

**A. Staff**
- Table: Name · Email · Roles (badges) · Status · Actions.
- "Add staff" → modal with Name, Email, one-or-more Roles (default Read-only). Creates the
  user; the person sets their first password through the existing forgot-password email-code
  flow (no new invite machinery). Admin may set an initial password instead — decide at build.
- "Edit access" per row → modal with a role multi-select and an Active/Inactive switch.
- Deactivate instead of delete, so future audit history (#13) keeps its actor rows.
- Deactivated users are blocked at login.

**B. Roles**
- List roles with assigned-user count and permission count.
- Select a role → permission matrix (module rows × View/Manage columns, `—` where not
  applicable). Save → `syncPermissions`.
- "New role" → name + starting permissions (optionally copy from an existing role, which is
  the fast way to build "Read-only but no fees"). Rename an existing role.
- Delete a role, blocked while it is assigned to any user (reassign first).

This is the escape hatch that makes Model A work: an unusual combination becomes its own
role instead of a per-user exception. It does let role count grow, so keep names meaningful.

### 4.4 Schools module

- `App\Enums\SchoolType` → `Elementary` (grades 1–6), `Middle` (1–3), `High` (1–3), with a
  translated `label()` reusing the existing `lang/*/schools.php` keys.
- Migration: replace `schools.code` with `schools.type`; backfill existing rows from `code`.
  `name` becomes the real, DB-backed label and stays the editable text.
- The schools page (`resources/views/pages/schools/index.blade.php`) becomes admin-managed:
  list, add (name + type), edit name. Type shown read-only after creation.
- Creating a school auto-creates its grades in one transaction.
- Delete guarded: blocked when any of the school's grades has an enrollment or a grade fee.
  Grades alone never block, since every school has them by design.
- Every `__('schools.'.$school->code)` display lookup is replaced by the school name, and the
  report layer's `schools.code AS school_code` selects become the school name. Files to touch:
  `resources/views/pages/schools/index.blade.php`, `students/create.blade.php`,
  `students/show.blade.php`, `enrollments/index.blade.php`, `dashboard.blade.php`,
  `app/Support/Reports/ReportQueries.php`, `app/Support/Reports/FinancialSummary.php`,
  `app/Support/Reports/ReportExport.php`, `database/seeders/SchoolSeeder.php`,
  `app/Models/School.php`.

### 4.5 First login and account setup (new staff)

- New `users.must_change_password` boolean, default `false`. Set `true` when an admin creates
  an account from the Staff page; also set it in `user:reset-password` so an admin-forced
  reset behaves the same way.
- New middleware (appended to the `web` group in `bootstrap/app.php`, next to `SetLocale`)
  that sends any authenticated user with the flag set to a first-login page, regardless of
  which route they hit. While the flag is on, only the first-login page, locale switch and
  logout are reachable.
- First-login page (`pages::auth.first-login`): set a new password **and** the three security
  questions in one step, then clear the flag. Reuses the existing hashing and the
  `SecurityQuestionRecovery` question list — the settings page already has both forms, so
  this is the same logic driven from one place.
- The page opens with a plain-language notice: *"Your password was set by an administrator.
  Choose your own password and set your three security questions to continue."* Same wording
  in Arabic, from the lang files.
- Do **not** show that notice on the login screen before the user is authenticated — it would
  tell anyone who types an email that the account is admin-created. The hint appears only
  after a successful login. *Confirmed by the owner.*
- The admin's initial password is handed over out-of-band; it is never emailed.

### 4.6 Help / How it works page

- Route `GET /help` → `pages::help`. Available to **every** authenticated user — no
  permission.
- Sidebar entry in `layouts/nav-links.blade.php`, always visible, new icon.
- Content lives in `lang/en/help.php` and `lang/ar/help.php` as a structured array
  (sections → title, intro, ordered steps, notes) and **one** Blade template renders it. That
  keeps markup in one place and the Arabic page RTL-correct for free.
- Sections mirror the modules: dashboard, schools & grades, academic years, students,
  enrollment, fees & discounts, payments & receipts, refunds & voids, reports, staff & roles,
  account settings & security.
- Both language files change together; this repo keeps `en` and `ar` in sync.
- Optional (deferred): hide sections the signed-in user has no permission for via `@can`.

---

## 5. Enforcement (the part that must not be cosmetic)

1. **Write paths** — every write Action checks its ability at the top, before validating
   input. Actions are the choke point because Livewire methods are callable directly by a
   crafted request; hiding a button proves nothing.
2. **View paths** — each page's `mount()` authorizes the module's `view` ability.
3. **Navigation** — links filtered with `@can`. Cosmetic only; never the security boundary.
4. One source of truth: `App\Enums\Permission` + spatie's `can()`. No parallel permission
   list in Blade or config.

Actions by module (all to be guarded):
- Students: `CreateStudent`, `ArchiveStudent`, `RestoreStudent`
- Enrollments: `EnrollStudent`, `UpdateEnrollmentStatus`, `ApplyDiscountToEnrollment`,
  `RemoveEnrollmentDiscount`, `ApplyMandatoryFeesToEnrollment`, `AddOptionalFeeToEnrollment`,
  `RemoveOptionalFeeFromEnrollment`
- Fees: `CreateFeeType`, `DeleteFeeType`, `CreateGradeFee`, `DeleteGradeFee`, `CreateDiscount`,
  `DeleteDiscount`
- Payments: `RecordPayment`, `RecordRefund`, `VoidPayment`, `VoidRefund`
- Schools: new school create/edit/delete actions
- Auth actions (`RequestPasswordOtp`, `SecurityQuestionRecovery`, …): no permission.

---

## 6. Guards and edge cases

| Guard | Why |
|-------|-----|
| Existing users are promoted to Administrator in the migration | Otherwise every current user is locked out on deploy. |
| Cannot remove the last Administrator | Permanent lockout. |
| Cannot remove your own `staff.manage` | Self-lockout; the Roles page becomes unreachable. |
| Administrator role keeps `staff.manage` un-ticked = impossible | Always a way back into the Roles page. |
| The Administrator role cannot be renamed or deleted | It is the recovery role. |
| A role assigned to users cannot be deleted | Reassign first; otherwise people lose access silently. |
| Deactivated users are blocked at login | Deactivation is meaningless otherwise. |
| School delete blocked when enrollments or grade fees exist | Matches the issue wording; DB `restrictOnDelete` is the backstop, not the UX. |
| School type immutable after creation | Grades would need regenerating under live data. |
| Report label changes go in the same change as the column change | Otherwise reports fall back to raw codes. |
| Read-only users denied `fees.view` must lose the nav link **and** the page | Both, not one. |
| `must_change_password` cannot be bypassed by navigating straight to a route | The middleware runs on every request, not just the post-login redirect. |
| First-login setup requires both the new password **and** all three security answers | Answers are the offline recovery path; skipping them strands the account. |
| The admin-created-account hint never appears on the login screen | It would reveal account state to anyone who types an email. Only after a successful login. |

---

## 7. Migration outline (order matters)

1. `composer require spatie/laravel-permission` + publish its migration/config.
2. Migration: add `users.is_active` (default true) and `users.must_change_password` (default false).
3. Migration: seed roles + permissions; assign every existing user the Administrator role.
4. Migration: add `schools.type`, backfill from `code`, then drop `schools.code`.
5. Switch display + report code from `schools.code` to `schools.name`.
6. Add the `staff.manage`-guarded staff/roles page and nav entry.
7. Add the first-login middleware, page, and `must_change_password` wiring (Staff page + `user:reset-password`).
8. Add the `/help` page, its `lang/{en,ar}/help.php` content, and the nav entry.
9. Guard Actions and page `mount()`s.
10. Tests (see `docs/progress.md`).

---

## 8. Out of scope / deferred

- Per-user permission editing (rejected with Model A, decision D2/D5) — custom roles (D11)
  are the replacement.
- A staff invite/onboarding flow beyond the existing password recovery.
- Auto-generating or migrating grades after creation.
- Changing middle/high grade numbering away from 1–3.

---

## 9. Backlog (open GitHub issues)

Current work is **#12**. These are the next features; each depends on #12 for its permission
checks.

| # | Title | Summary | Depends on #12 for |
|---|-------|---------|--------------------|
| 12 | Add staff roles and permissions | This document. | — |
| 13 | Add a searchable audit trail for record and financial changes | Log who changed what, when, with before/after values. Filter by staff, action, date, record. Never log secrets. | Actor identity (`$user`) and the `staff.manage`/view gate for the log page. |
| 14 | Import students from spreadsheets with validation and duplicate checks | Template download, column mapping, validation preview with row errors and duplicates, explicit confirm, import summary. | `students.manage` to run it; `students.view` for the preview. |
| 15 | Add a reviewed academic-year rollover for enrollments and fees | Pick source/target year, map grades, review students, skip archived, optionally copy fee schedules, preview, confirm, rerun safely. | `enrollments.manage` + `fees.manage`; runs inside one transaction. |
| 16 | Add scheduled backups and a guided restore workflow | Scheduled + on-demand backups, last success/failure, retention, documented restore with confirmation and a pre-restore backup. | `staff.manage` (or a new admin ability) to see status and restore. |

---

## 10. Open questions

- Does the Help page hide sections the current user has no permission for, or show everything?
  (Default: show everything; filtering is deferred.)
- Does the Read-only role need its own page access to Reports, or only to student/enrollment
  records? (Default: reports included.)
- Two schools sharing a name: `schools.name` is currently `unique`, so it would be rejected.
  Keep unique unless a real case appears.
