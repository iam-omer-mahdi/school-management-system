# Staff Roles & Permissions — Progress

Companion to `docs/plan.md` (the design and decisions). **Read `docs/plan.md` first.**

Last updated: 2026-10-18 by the execution session (tasks 1–6b landed on `feature/roles-permissions`).

---

## Interim work: audit fixes (2026-10-18)

Not part of issue #12, but landed before it. `php artisan test --compact`: 444 passed.

| # | Finding | Fix |
|---|---------|-----|
| 1 | `Money::format(-125050)` dropped the cents (`-1,250 SDG`) | `app/Support/Money.php` now takes the sign off first; negative amounts show cents |
| 2 | "Is this a future date?" used the server's UTC day, not the school's | New `app/Support/BusinessDate::today()` + `config('app.school_timezone')` (`SCHOOL_TIMEZONE` in `.env.example`, default `Africa/Khartoum`). Used by `RecordPayment`, `RecordRefund`, `PaymentForm`, `RefundForm` |
| 3 | Duplicate enrollment threw an uncaught unique-key 500 | `EnrollStudent` catches `UniqueConstraintViolationException` → `students.errors.already_enrolled`; the check-then-insert pre-check was deleted (the unique key is the race-free guard) |
| 4 | Report totals loaded every row to add them up | New `ReportQueries::callListTotals()` / `refundsTotals()` (one aggregate each); the reports page calls them |
| 5 | Dead `queryCount()` in `students/index` | Deleted |
| 6 | "Refund sign differs between day rows and the total" | **Not a bug.** `ReportQueries::collections()` already stores refunds negative (`$row['refunds'] -= $amount`), so day rows and the total already agree |
| 7 | Found while reviewing the components: `fee-schedule/create::preview()` loaded every enrollment of a grade-year to count them | One `groupBy('status')` count |

Component review also noted (not fixed): the print page's `statuses[]` query parameter is not
intersected with `EnrollmentStatus` values (cosmetic only — escaped, parameterised); report
partials repeat a sort of the eager-loaded `enrollments` per row; several report row loops
have no `wire:key` (Livewire 4 `smart_wire_keys` covers them).

---

## Resume here

**Next action:** **T7 is done.** Continue with **T8** — the Help page: `Route::livewire('/help',
'pages::help')` open to every authenticated user, `lang/{en,ar}/help.php` as one structured
array (sections → title, intro, steps, notes), a single Blade template rendering it, and a
sidebar entry in `resources/views/layouts/nav-links.blade.php`. Then **T9** (guard every write
Action and every page `mount()`), then **T10** (pint + full suite). Full task list and hashes:
see **Ledger** below.

Tasks 1–6 are on the branch. Run `git log --oneline -12` to see them; this file's ledger has
the hashes and the rulings that matter.

## How to resume (any session, any model)

1. Read `docs/plan.md`, then this file.
2. `git log --oneline -10` and `git status` to see what actually landed vs. what this file claims.
3. Find the first unchecked box in **Task checklist** below and continue from there.
4. Conventions that matter here:
   - New files via `php artisan make:...`, not by hand.
   - Every change ships with tests for the behavior it changes.
   - `vendor/bin/pint --dirty --format agent` before finishing.
   - `php artisan test --compact <path>` to run the narrow set.

---

## Task checklist

Legend: `[ ]` todo · `[~]` in progress · `[x]` done

### Setup
- [ ] `composer require spatie/laravel-permission` and publish migration/config
- [ ] `App\Enums\Permission` with every ability key + module/action metadata
- [ ] `App\Enums\StaffRole` with the four roles, labels, and default permission lists
- [ ] Seeder for roles + permissions (idempotent, safe to re-run)

### Users
- [ ] Migration: `users.is_active` (default true)
- [ ] Migration: assign existing users the Administrator role
- [ ] Block deactivated users at login
- [ ] `User` model: `HasRoles`, `is_active` cast, helpers (`isActive()`, `hasAbility()` if needed)

### First login
- [ ] Migration: `users.must_change_password` (default false)
- [ ] Middleware (web group, next to `SetLocale`) redirecting flagged users to first-login on every request
- [ ] `pages::auth.first-login`: set new password **and** all three security answers, then clear the flag
- [ ] First-login notice: "your password was set by an administrator" + what to do (en + ar)
- [ ] Set the flag when the Staff page creates an account
- [ ] Set the flag in `user:reset-password`

### Help page
- [ ] Route `GET /help` → `pages::help`, open to every authenticated user
- [ ] Nav entry in `layouts/nav-links.blade.php`, always visible
- [ ] `lang/en/help.php` + `lang/ar/help.php` section content
- [ ] Blade template rendering the structured sections (RTL-safe)

### Roles page
- [ ] Route `GET /staff` → `pages::staff.index`, guarded by `staff.manage`
- [ ] Nav entry visible only with `staff.manage`
- [ ] Staff table: name, email, roles, status
- [ ] Add-staff modal (name, email, roles)
- [ ] Edit-access modal (role multi-select + active toggle)
- [ ] Roles list + permission matrix, saved with `syncPermissions`
- [ ] New role (name + optional "copy permissions from") and rename
- [ ] Delete role, blocked while assigned to any user
- [ ] Guards: last administrator, self-demotion, self-deactivation, Administrator keeps `staff.manage`, Administrator role cannot be renamed or deleted

### Schools
- [ ] `App\Enums\SchoolType` (Elementary 1–6, Middle 1–3, High 1–3) + labels
- [ ] Migration: add `schools.type`, backfill from `code`, drop `code`
- [ ] Create school → auto-create grades in one transaction
- [ ] Edit school name (type read-only)
- [ ] Delete guarded by enrollments / grade fees
- [ ] Replace `__('schools.'.$code)` with school name (schools index, students create/show, enrollments index, dashboard)
- [ ] Report layer: `schools.code AS school_code` → school name (`ReportQueries`, `FinancialSummary`, `ReportExport`)
- [ ] Update `SchoolSeeder` and `School` model fillable

### Enforcement
- [ ] Guard every Action listed in `docs/plan.md` §5
- [ ] Authorize each page `mount()` for its module `view` ability
- [ ] Filter nav links with `@can`
- [ ] Hide in-page write controls (payments, refunds, voids, discounts, enrollment status) from view-only users

### Verification
- [ ] Tests: each role × each module, both allowed and denied
- [ ] Test: deactivated user cannot log in
- [ ] Test: last administrator cannot be demoted or deactivated
- [ ] Test: read-only user gets 403 on a direct Livewire write call, not just a hidden button
- [ ] Test: school with enrollments/grade fees cannot be deleted
- [ ] Test: creating a school generates the expected grade numbers
- [ ] Test: a flagged user is redirected to first-login and cannot reach other pages
- [ ] Test: first-login requires the password **and** the three security answers
- [ ] Test: the login page does not reveal the admin-created hint before authentication
- [ ] Test: the help page renders in both `en` and `ar`
- [ ] `vendor/bin/pint --dirty --format agent`
- [ ] `php artisan test --compact`

---

## Status log

| Date | What happened |
|------|---------------|
| 2026-10-18 | Recon of the codebase; issue #12 reviewed; design agreed in conversation. |
| 2026-10-18 | Decisions recorded in `docs/plan.md` §3 (Spatie, Model A, four roles, fixed school type, enums, DB name, grade numbering). |
| 2026-10-18 | Confirmed admins can create/rename/delete roles and assign them — added as decision D11; role creation moved from "deferred" into scope. |
| 2026-10-18 | Admin sets the initial password; new users change it and set security questions on first login (D12). Added the bilingual Help page (D13). |
| 2026-10-18 | Added the "password was set by an administrator" notice on first login (never on the login screen, to avoid leaking account state). |
| 2026-10-18 | Open: Help page section filtering (`docs/plan.md` §10). |
| 2026-10-18 | Audit fixes landed (`Money` negatives, school-timezone "today", duplicate-enrollment 500, SQL report totals, dead `queryCount()`, fee-schedule preview count). Finding 6 (refund sign) retracted — the data was already consistent. Full suite: 444 passed. Not committed. |

---

## Notes for the next session

- Issue #12 is the current task; #13–#16 are the backlog, listed in `docs/plan.md` §9.
- Enforcement must sit inside Actions, not only in Blade or page mounts — Livewire methods
  are directly callable.
- Do not add a per-user permission UI; Model A was chosen deliberately (D2/D5).

---

## Ledger — issue #12 execution

Tracks `docs/plan.md` only. Branch `feature/roles-permissions`, cut from `main` at `138d1e2`.

### Pre-flight: shared interfaces

| Producer | Consumer | Produces vs. consumes | Finding |
|---|---|---|---|
| T3 permission enum | T6 roles page, T9 action guards | `App\Enums\Permission` names | aligned with §4.1 |
| T4 `schools.type` | T5 display + report switch | column replacing `code` | T5 must land after T4 in one change (§6) |
| T2 `must_change_password` | T6 staff page, `user:reset-password`, T7 middleware | the flag | aligned |
| T6 role presets | T9 guards | which role may call which Action | §4.2 is the authority |

Pre-flight rulings (binding authority = §3 decisions over §4/§10 prose, which is older):

- Ruling: §4.3's "invite by email-code flow" text is stale; **D12 wins** — the Staff page sets
  an initial password and flags `must_change_password`, no invite machinery.
  Cost if wrong: one password field to delete.
- Ruling: Read-only keeps `reports.view` (§10 open question, its stated default).
  Cost if wrong: one checkbox to change.
- Ruling: the Help page renders every section; no `@can` filtering (§4.6 marks it optional).
  Cost if wrong: help mentions pages a user cannot open.
- Ruling: `schools.name` stays `unique` (§10 default). Cost if wrong: two schools cannot share a name.

Rulings made while executing:

- Ruling: plan §7 steps 4 and 5 (the `schools.code` column change and the display/report
  switch) are **one commit**, because §6 forbids them landing apart and neither half passes
  the suite alone. Cost if wrong: a larger commit to review.
- Ruling: `SchoolSeeder` writes schools and grades directly instead of going through the
  guarded `CreateSchool` action — a seeder has no authenticated user, so the access check
  would refuse it. The grade structure still comes from `SchoolType::gradeNumbers()`, so
  there is only one definition of it. Cost if wrong: seed-time writes bypass `schools.manage`.
- Ruling: the suite is moving to **Pest** (owner's request), because it runs the same engine
  but needs far less ceremony. Pest 4 could only be installed by downgrading
  `phpunit/phpunit` 12.5.40 → 12.5.33 (`-W`); Pest caps at 12.5.33, and `laravel/pao` — already
  a dependency, it produces the `--compact` output — refuses any Pest below 4.6.3. New and
  in-flight tests are written in Pest; the pre-existing PHPUnit files are untouched (the
  skill says not to rewrite tests that follow the current convention) and still run, since
  Pest executes PHPUnit classes. Cost if wrong: two test styles side by side until converted.
- Dropped from the roles page: "copy permissions from another role" (§4.3 prose). The matrix
  already starts from the presets and role creation is rare. Cost if wrong: one dropdown.

### Tasks

1. T1 install `spatie/laravel-permission`, publish migration + config
2. T2 migration `users.is_active`, `users.must_change_password`
3. T3 `App\Enums\Permission` + `App\Enums\Role`, seed them, promote existing users
4. T4 `SchoolType` enum + `schools.type` migration + switch every reader to `schools.name`
5. T5 schools admin page: add / rename / delete, auto-created grades, `schools.manage` guard
6. T6 staff + roles page (D11): staff CRUD, role CRUD, permission matrix
7. T7 first-login middleware + page, `must_change_password` wiring
8. T8 help page + `lang/{en,ar}/help.php` + nav entry
9. T9 guard every write Action and every page `mount()`
10. T10 final: pint + full suite

### Completion log

- Task 1: complete (commit `59ea9b2`; verification: `php artisan migrate` then the five
  Spatie tables present in `school_management`).
- Task 2: complete (commit `781065b`; test `tests/Feature/StaffAccessTest.php` → 2 passed).
- Task 3: complete (commit `4be0128`; test `tests/Feature/RolesAndPermissionsTest.php` → 6
  passed; full suite 452 passed; dev database's single existing user promoted to
  Administrator).
- Task 4: complete (commit `b851da3`; tests `SchoolTypeTest`, `SchoolStructureTest`,
  `ReportsQueriesTest` → 9 passed; full suite 456 passed; verified the migration reverses
  and re-applies on the dev database with `type`/`name` intact).
- Task 5: complete (commit `f7ab66a`; test `tests/Feature/SchoolsAdminTest.php` → 12 passed;
  full suite 467 passed; `/schools` stays readable by every signed-in user — only writes need
  `schools.manage`, recorded as an open question in `docs/plan.md` §10).
- Task 6a (staff): complete (commit `05afe82`; test `tests/Feature/StaffPageTest.php` → 15
  passed; full suite 483 passed; the Staff link is filtered by `staff.manage`, deactivated
  users are refused at login through the credentials array).
- Task 6b (roles): complete (commit `a2c81a4`; test `tests/Feature/RolesPageTest.php` → 16
  passed; full suite 499 passed; `SaveRole` covers create and rename, `DeleteRole` refuses a
  role anyone holds, Administrator keeps its name and `staff.manage`; `Role::create` throws
  Spatie's `RoleAlreadyExists` before the DB constraint, so both are caught).
- Task 7 (first login): complete (commit `4f0df05`; tests `tests/Feature/FirstLoginTest.php`
  → 11 passed; full suite 510 passed). `RequirePasswordChange` runs on every `web` request and
  sends flagged accounts to `pages::auth.first-login`, allowing only the form, logout, the
  locale switch and **Livewire's update endpoint** — that last one is mandatory, because a
  Livewire form posts to `default-livewire.update` (in the `web` group), not to its own page.
  The setup page clears the flag, sets the password and the three answers in one transaction;
  an unflagged account is bounced off the page so it cannot become a password change without
  knowing the old one. `user:reset-password` sets the flag too. The app layout shows the bare
  card while the flag is on, so nobody clicks a sidebar of dead links.
