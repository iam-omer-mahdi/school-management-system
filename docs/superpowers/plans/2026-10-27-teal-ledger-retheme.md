# Teal Ledger (D+B) UI Retheme

## Objective

Apply the approved D+B "Teal Ledger" direction to the Laravel/Livewire school
finance app: D structure and geometry, B's teal/copper palette, light and dark
modes, English and Arabic/RTL, without changing business rules except the
`total_due` label/value mismatch.

The light palette is already approved. The dark palette is Deep Teal + Brass.
No mint (`#5EEAD4`) anywhere.

## Branch and resume point

- Branch: `feature/ui-retheme`
- Base: `main` at `10ba741` (staff roles WIP committed and pushed)
- Resume with `git status`, then open this file and continue at the first task
  whose **Status** is not `done`.
- Each task ends in a commit. A task is only done when its test/build command
  is green and the commit exists.

## Non-goals

- No new dependencies.
- No CDN fonts or CDN Tailwind.
- Keep the bundled Cairo font; do not add Inter or another font family.
- Do not change business logic except the `total_due` label.
- Do not change permission checks, validation, money math, or route names.

## Architecture

All theme colours live in **one file**: `resources/css/theme.css`.

- `:root` holds the light values.
- `.dark` holds the dark values.
- `@theme inline` maps each `--ui-*` value to Tailwind colour utilities.
- `resources/css/app.css` imports `theme.css` and contains only shared layout,
  table, print, RTL, and base-element styles. Future palette changes happen in
  `theme.css` only.
- Blade uses semantic utilities (`bg-surface`, `text-ink`, `border-line`,
  `text-primary`, `bg-negative-surface`, ...). Do not add new `dark:` colour
  variants; the CSS variables switch under `.dark`.
- `dark:hidden` / `dark:block` icon toggles and other non-colour dark variants
  may stay.

### Token contract

| Tailwind utility | Light | Dark |
|---|---|---|
| `bg-canvas` | `#F4F7F5` | `#0B1512` |
| `bg-surface` | `#FFFFFF` | `#13211C` |
| `bg-surface-raised` | `#EDF3F0` | `#1A2C25` |
| `text-ink` | `#0A1F1C` | `#F3F8F5` |
| `text-muted` | `#475569` | `#B6C8C0` |
| `text-subtle` | `#5F6F80` | `#A9BDB5` |
| `border-line` | `#D7E3DF` | `#294039` |
| `border-line-strong` | `#64748B` | `#93AFA5` |
| `bg-primary` / `text-primary` | `#0F4C45` | `#337B6E` |
| `bg-primary-hover` | `#0B3D37` | `#3E8C7D` |
| `text-on-primary` | `#FFFFFF` | `#F8FBF9` |
| `bg-accent` / `text-accent` | `#B45309` | `#C99A5B` |
| `text-on-accent` | `#FFFFFF` | `#0A1F1C` |
| `bg-accent-tile` | `#F0D2B4` | `#C99A5B` |
| `text-on-accent-tile` | `#0A1F1C` | `#0A1F1C` |
| `bg-sidebar` | `#FFFFFF` | `#13211C` |
| `text-sidebar-fg` | `#0A1F1C` | `#F3F8F5` |
| `text-sidebar-muted` | `#475569` | `#B6C8C0` |
| `bg-sidebar-hover` | `#EDF3F0` | `rgba(255,255,255,0.07)` |
| `bg-nav-active` | `#0F4C45` | `#337B6E` |
| `text-nav-active-fg` | `#FFFFFF` | `#F8FBF9` |
| `bg-nav-marker` | `#B45309` | `#C99A5B` |
| `bg-tile-dark` | `#0F2F2A` | `#0E3A33` |
| `text-tile-dark-fg` | `#FFFFFF` | `#F0FDFA` |
| `bg-focus` / `text-focus` | `#0F4C45` | `#8FC9BC` |
| `bg-tab-active` / `text-tab-active` | `#0F4C45` | `#8FC9BC` |
| `bg-chart-bar` | `#0F4C45` | `#4E9C8D` |
| `bg-chart-accent` | `#B45309` | `#C99A5B` |
| `text-positive` / `bg-positive` | `#067647` | `#6BD3A5` |
| `bg-positive-surface` | `#E8F3EC` | `#153129` |
| `text-negative` / `bg-negative` | `#B42318` | `#FF9B92` |
| `bg-negative-surface` | `#FDECEA` | `#3A1D1A` |
| `text-on-negative` | `#FFFFFF` | `#2A0E0B` |
| `text-warning` / `bg-warning` | `#8A4B00` | `#F5C36B` |
| `bg-warning-surface` | `#FFF4E0` | `#33260F` |
| `text-info` / `bg-info` | `#0F4C45` | `#8FC9BC` |
| `bg-info-surface` | `#E6F0ED` | `#16302B` |
| `bg-overlay` | `rgba(10,31,28,0.55)` | `rgba(0,0,0,0.72)` |
| `bg-tint-sage` | `#D3DFE0` | `#1E2C2D` |
| `bg-tint-sand` | `#E8DCC4` | `#2A2418` |
| `bg-tint-rose` | `#F2B8CC` | `#2A1A20` |
| `text-on-tint` | `#0A1F1C` | `#F3F8F5` |

Contrast targets: body/muted text at least 4.5:1; control boundaries and focus
rings at least 3:1; chart bars at least 3:1. Verify before closing Task 9.

### Geometry contract

- Sidebar: 260px, sticky, flat surface, hairline inline-end border.
- Header: 56px, flat surface, hairline bottom border.
- Main: full width up to 1440px, 24–32px padding.
- Panels and controls: 2–4px radii, no shadows.
- Primary controls and icon buttons: at least 44px tall.
- Tabs: underline, no pills, no filled rounded backgrounds.
- Tables: flat header tint, hairlines, no rounded outer cell corners.
- Charts: no gridlines, thin bars/strokes, palette from CSS tokens.
- Arabic: no forced uppercase or letter spacing on Arabic labels.

## Migration map

Apply these semantic replacements to Blade files in Task 4. Work from the
outside in; do not touch `resources/views/vendor`.

| Old class family | New class / rule |
|---|---|
| `text-gray-900`, `text-gray-800`, `text-white` on light surfaces | `text-ink` |
| `text-gray-700`, `text-gray-600`, `text-gray-500` | `text-muted` |
| `text-gray-400`, `text-gray-300` | `text-subtle` or `text-muted` for readable text |
| `bg-white` | `bg-surface` |
| `bg-gray-50`, `bg-gray-100` | `bg-surface-raised` |
| `bg-gray-900`, `bg-gray-950` in the shell | `bg-sidebar` |
| `bg-gray-900/50`, `bg-gray-900/80` | `bg-overlay` |
| `border-gray-100`, `border-gray-200`, `border-gray-300` | `border-line` |
| `border-gray-600`, `border-gray-700`, `border-gray-800` | `border-line` |
| `text-indigo-*`, `bg-indigo-600`, `border-indigo-*` | `text-primary`, `bg-primary`, `border-primary` |
| `ring-indigo-*` | `ring-focus` or `outline-focus` |
| `text-red-*`, `bg-red-*`, `border-red-*` | `text-negative`, `bg-negative-surface`, `border-negative` |
| `text-green-*`, `bg-green-*` | `text-positive`, `bg-positive-surface` |
| `text-yellow-*`, `text-amber-*` | `text-warning` or `text-accent` |
| `bg-blue-*` | `bg-info-surface` |
| `shadow-xs`, `shadow-sm` | remove |
| `rounded-lg`, `rounded-xl`, `rounded-md` | `rounded-sm` (4px) or `rounded-xs` (2px) for controls |
| `rounded-full` on badges/pills | keep only for badges/avatars |
| `focus:ring-4` | `focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus` |
| `dark:` colour variants | remove; semantic tokens switch automatically |

Keep `dark:hidden` / `dark:block` and any non-colour dark variant.

## Tasks

### Task 1 — Theme tokens and shared CSS

**Status:** done

**Files**

- Create `resources/css/theme.css` with the full token contract above and the
  `@theme inline` mappings.
- Edit `resources/css/app.css`:
  - import `theme.css` after Tailwind;
  - remove the old `@theme` colour/font block only if the font token is moved
    without changing the bundled Cairo stack;
  - add base body `background-color: var(--ui-canvas); color: var(--ui-ink)`;
  - add `::selection` using primary/on-primary;
  - add a global `:focus-visible` outline using `--ui-focus`;
  - replace every `var(--color-gray-*)` and `var(--color-white)` in
    `.flowbite-table` and `.report-table` with the matching `--ui-*` token;
  - keep the print overrides black/white;
  - add `html[lang="ar"] .uppercase { text-transform: none; }` and
    `html[lang="ar"] .tracking-wider { letter-spacing: normal; }`.

**Verification**

- `bun run build`
- `grep` the built CSS for the dark tokens to confirm `@theme inline` resolved.
- Confirm no `#5EEAD4` exists in `resources/css` or `resources/views`.

**Commit**

`feat(theme): add Teal Ledger tokens and shared table styles`

---

### Task 2 — Application shell and navigation

**Status:** done

**Files**

- `resources/views/layouts/app.blade.php`
- `resources/views/layouts/nav-links.blade.php`
- `resources/views/layouts/display-controls.blade.php`
- `tests/Feature/AppShellTest.php` only if a test needs a semantic assertion.

**Steps**

1. Body: `bg-canvas text-ink`.
2. Desktop aside: `w-[260px]`, `bg-sidebar text-sidebar-fg`,
   `border-e border-line`, no dark colour variants.
3. Mobile drawer: same surface, 44px close control, border on the correct
   physical side; keep Flowbite data attributes and RTL placement.
4. Nav links: 44px minimum, `rounded-xs`, active
   `bg-nav-active text-nav-active-fg` with a 2px `bg-nav-marker` inline-start
   marker; inactive `text-sidebar-muted hover:bg-sidebar-hover`.
5. Group labels: `text-sidebar-muted text-[11px] uppercase tracking-wider`.
6. Logout: border-line divider, same active/hover treatment.
7. Header: `h-14 border-b border-line bg-surface`, no shadow; email in
   `text-muted`; mobile menu target 44px.
8. Main: `w-full max-w-[1440px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8`.
9. Guest layout: canvas background, surface card, ink title, 44px controls.
10. Display controls: 44px buttons, `border-line-strong bg-surface text-ink`,
    focus outline, no shadow.
11. Keep all route order, permissions, icons, `aria-current`, nav labels, and
    theme bootstrap JavaScript unchanged.

**Verification**

- `php artisan test --compact tests/Feature/AppShellTest.php`
- `bun run build`
- `git diff --check`

**Commit**

`feat(theme): retheme the app shell and navigation`

---

### Task 3 — Shared UI primitives

**Status:** done

**Files**

- `resources/views/components/ui/button.blade.php`
- `resources/views/components/ui/input.blade.php`
- `resources/views/components/ui/select.blade.php`
- `resources/views/components/ui/textarea.blade.php`
- `resources/views/components/ui/checkbox.blade.php`
- `resources/views/components/ui/field.blade.php`
- `resources/views/components/ui/card.blade.php`
- `resources/views/components/ui/page-header.blade.php`
- `resources/views/components/ui/badge.blade.php`
- `resources/views/components/ui/alert.blade.php`
- `resources/views/components/ui/empty-state.blade.php`
- `resources/views/components/ui/modal.blade.php`
- `resources/views/components/ui/table.blade.php`
- `tests/Feature/EnrollmentPanelsTest.php`

**Steps**

1. Buttons: `rounded-xs`, `min-h-11`, no shadow; primary/secondary/danger/
   warning/ghost variants from the tokens; `focus-visible` outline.
2. Inputs/select/textarea: `min-h-11 rounded-xs border-line-strong bg-surface
   text-ink placeholder:text-muted`, primary focus border and focus ring;
   invalid state `border-negative bg-negative-surface text-negative`.
3. Checkbox: primary accent, focus ring, no shadow.
4. Field: ink label, muted hint, negative error; required marker negative.
5. Card: `rounded-sm border-line bg-surface p-5 sm:p-7`; no shadow; footer
   divider.
6. Page header: primary back link, ink title, muted description, 2xl title.
7. Badge: flat tinted styles using positive/negative/warning/info/accent
   surfaces; keep `rounded-full` for badges only.
8. Alert: tinted surfaces and token text; keep roles/live regions.
9. Empty state: surface, hairline, accent-tile icon tile; no shadow.
10. Modal: `bg-overlay`, surface panel, `rounded-sm`, hairline header/footer,
    no shadow; keep focus trap, Escape, and click-to-close behavior.
11. Table wrapper: surface, hairline, `rounded-sm`; loading row muted.
12. Replace the exact heading class assertion in `EnrollmentPanelsTest` with a
    semantic assertion such as `assertSee('<h2', false)` plus the heading text.
    Keep the behavior being tested.

**Verification**

- `php artisan test --compact tests/Feature/EnrollmentPanelsTest.php`
- `php artisan test --compact tests/Feature/AppShellTest.php`
- `bun run build`
- `vendor/bin/pint --dirty --format agent`

**Commit**

`feat(theme): restyle shared UI primitives`

---

### Task 4 — Page markup and chart tokens

**Status:** done

**Files**

- All `resources/views/pages/**/*.blade.php` and
  `resources/views/components/**/*.blade.php` except the files already
  migrated in Tasks 2–3.
- `resources/js/app.js`

**Steps**

1. Apply the migration map to the remaining Blade files. Exclude
   `resources/views/vendor`.
2. Remove remaining `dark:` colour variants. Keep `dark:hidden`/`dark:block`.
3. Replace any `text-white` by context:
   - primary/nav/danger surfaces: `text-on-primary` or `text-on-negative`;
   - dark tiles: `text-tile-dark-fg`;
   - overlays/badges: the matching on-token.
4. Replace `bg-gray-*` row hover classes with no class; the shared
   `.flowbite-table` hover rule covers them.
5. Replace hardcoded chart colors in `resources/js/app.js` with values read
   from computed CSS variables:
   - `--ui-chart-bar` for the main bar/line series;
   - `--ui-chart-accent` for the second bar series;
   - `--ui-line`, `--ui-muted`, `--ui-surface`, `--ui-ink` for axes, legend,
     tooltip, and grid.
6. Bar chart: stacked bars, `borderRadius: 0`, `columnWidth: '35%'`, no grid,
   no toolbar, legend bottom, 12px labels.
7. Line chart: width 2, no markers or small markers, no grid.
8. Donut: token colors, no labels, legend bottom, `stroke.width: 0`.
9. `applyTheme()` must call `renderDashboardCharts()` so charts re-render on
   theme toggle, and the initial render must not run twice.
10. Scan for leftovers:
    `grep -RInE 'gray-[0-9]|indigo-[0-9]|shadow-(xs|sm|md|lg)|rounded-(md|lg|xl)|dark:(text|bg|border|ring)-' resources/views`
    and fix every real colour/geometry leftover. Ignore `vendor` and
    non-colour `dark:hidden`/`dark:block`.

**Verification**

- `php artisan test --compact`
- `bun run build`
- `vendor/bin/pint --dirty --format agent`

**Commit**

`feat(theme): migrate page markup and chart colors to tokens`

---

### Task 5 — Dashboard hierarchy

**Status:** done

**Files**

- `resources/views/pages/dashboard.blade.php`

**Steps**

1. Page wrapper: `space-y-8` or `gap-10` between sections.
2. Page header + academic-year selector: 44px control, aligned inline at
   `sm`.
3. KPI row: 1 column mobile, 2 at `sm`, 4 at `xl`; each KPI uses a flat
   surface panel, a small muted label, a 2xl ink value, and a muted note.
   Keep the business order: grand total, current, still owed, forgone.
4. Use positive, warning, negative semantic colors for current/still owed/
   forgone; do not use literal red/green/amber classes.
5. Charts: 2-column workspace at `xl`; chart panels have a title, hairline
   header, no shadow, and a fixed height.
6. Keep the data attributes and empty states that JS and Livewire rely on.
7. Keep the academic-year Livewire binding and permissions unchanged.

**Verification**

- `php artisan test --compact tests/Feature/ReportsParityTest.php --filter=test_dashboard_renders_year_selector_and_charts`
- `bun run build`

**Commit**

`feat(theme): retheme the dashboard workspace`

---

### Task 6 — Enrollment and student money pages

**Status:** done

**Files**

- `resources/views/pages/enrollments/index.blade.php`
- `resources/views/pages/enrollments/show.blade.php`
- `resources/views/pages/enrollments/receipt.blade.php`
- `resources/views/pages/students/index.blade.php`
- `resources/views/pages/students/show.blade.php`
- `resources/views/pages/students/create.blade.php`
- `resources/views/components/enrollments/show/*.blade.php`
- `lang/en/fees.php`
- `lang/ar/fees.php`

**Steps**

1. Fix the money-label mismatch: the amount rendered is
   `EnrollmentBalance::owed`, so the label must say **Still owed**, not
   **Total due**. Change the `fees.total_due` translation in both languages to
   the existing `reports.still_owed` wording (`Still owed` /
   `لا يزال مستحقًا`). Keep the key so existing tests and sorting names remain
   stable.
2. Apply token classes to filters, tables, detail lists, status panels,
   charge/payment/refund/void modals, and student profile/history.
3. Use `rounded-sm` for panels/rows, `rounded-xs` for controls, no shadow.
4. Keep the total-due sort labels and all money math unchanged.
5. Keep the 44px button targets in modals and action rows.
6. Ensure negative amounts use `text-negative`; paid/positive amounts use
   `text-positive` only when they represent a positive state.

**Verification**

- `php artisan test --compact tests/Feature/EnrollmentsIndexTest.php tests/Feature/EnrollmentShowTest.php tests/Feature/EnrollmentCardTest.php tests/Feature/PaymentPageTest.php`
- `php artisan test --compact tests/Feature/EnrollmentShowRefactorCharacterizationTest.php`
- `bun run build`
- `vendor/bin/pint --dirty --format agent`

**Commit**

`fix(theme): retheme enrollment pages and correct the due label`

---

### Task 7 — Reports and print

**Status:** pending

**Files**

- `resources/views/pages/reports/index.blade.php`
- `resources/views/pages/reports/print.blade.php`
- `resources/views/pages/reports/partials/*.blade.php`
- `resources/views/components/reports/header.blade.php`
- `resources/views/components/reports/footer.blade.php`
- `resources/views/layouts/print.blade.php`

**Steps**

1. Reports tabs: underline only, active `border-tab-active text-ink
   font-semibold`, inactive `text-muted`, `min-h-11`, no rounded tabs.
2. Filter card: token borders, 44px controls, 24px padding.
3. Report tables: keep `.report-table`; ensure headers/totals use
   `surface-raised`, hairlines, ink text.
4. Print layout: keep paper black/white and existing print CSS; only retheme
   the on-screen controls and header. Do not make print dependent on dark
   mode.
5. Keep all report tab keys, order, filters, print params, exports, and
   partial data contracts unchanged.

**Verification**

- `php artisan test --compact tests/Feature/ReportsPageTest.php tests/Feature/ReportsPrintTest.php`
- `bun run build`

**Commit**

`feat(theme): retheme reports and keep print output monochrome`

---

### Task 8 — Auth, settings, staff, and remaining pages

**Status:** pending

**Files**

- `resources/views/pages/auth/*.blade.php`
- `resources/views/pages/settings.blade.php`
- `resources/views/pages/help.blade.php`
- `resources/views/pages/staff/**/*.blade.php`
- `resources/views/components/staff/permission-matrix.blade.php`
- `resources/views/pages/schools/index.blade.php`
- `resources/views/pages/academic-years/index.blade.php`
- `resources/views/pages/discounts/index.blade.php`
- `resources/views/pages/fee-types/index.blade.php`
- `resources/views/pages/fee-schedule/*.blade.php`

**Steps**

1. Apply the semantic tokens to all remaining pages.
2. Guest login/recovery pages: canvas background, surface card, 44px inputs
   and buttons, visible focus.
3. Settings/help: flat panels, hairline dividers, ink headings, muted body.
4. Staff/roles: flat permission matrix with clear row/column hairlines, no
   shadows; keep the existing role guards and form behavior.
5. Fee/school/year pages: flat tables/cards; keep form layouts and
   validation messages.

**Verification**

- `php artisan test --compact tests/Feature/RolesPageTest.php tests/Feature/PagePermissionsTest.php tests/Feature/AuthTest.php tests/Feature/SettingsPageTest.php`
- `bun run build`
- `vendor/bin/pint --dirty --format agent`

**Commit**

`feat(theme): retheme auth, settings, staff, and remaining pages`

---

### Task 9 — RTL, accessibility, and contrast

**Status:** pending

**Files**

- `resources/css/theme.css`
- `resources/css/app.css`
- any Blade file with a remaining forced uppercase, letter spacing, focus
  style, or 3:1 boundary issue.

**Steps**

1. Check Arabic pages for forced uppercase/tracking; the global Arabic rules
   must neutralize them.
2. Check every interactive control has a visible focus state against both
   canvas and surface.
3. Check 44px minimum targets for navigation, buttons, inputs, icon buttons,
   tabs, and modal close buttons.
4. Check input/select borders against surface: at least 3:1.
5. Check body/muted/label text: at least 4.5:1 in both modes.
6. Check alerts/error text in dark mode: no `red-400`-level failures; use
   `text-negative` tokens.
7. Check RTL: sidebar border, drawer placement, tab/table alignment, icons
   that should flip, chart direction.
8. Check `prefers-reduced-motion` is respected by existing transitions; remove
   transitions that do not help if needed.

**Verification**

- `php artisan test --compact`
- `bun run build`
- `vendor/bin/pint --dirty --format agent`
- A manual or Playwright pass at 1440px and 390px in EN/AR and light/dark.

**Commit**

`fix(theme): close RTL and accessibility gaps`

---

### Task 10 — Final verification

**Status:** pending

**Steps**

1. `git status --short` is clean.
2. `php artisan test --compact` passes.
3. `bun run build` passes.
4. `vendor/bin/pint --dirty --format agent` passes.
5. `grep -RIn '#5EEAD4' resources` returns nothing.
6. `grep -RInE 'gray-[0-9]|indigo-[0-9]|shadow-(xs|sm|md|lg)|rounded-(md|lg|xl)' resources/views` returns only intentional non-theme cases.
7. Read the final diff for unrelated changes.
8. Update this plan's statuses and the SDD ledger.
9. Push the branch and open/describe a PR only if the user asks.

**Commit**

Only if step 7 finds a real issue; otherwise no commit.

## Resume notes

- If the session stops mid-task, `git status` must be clean or the task is not
  finished.
- Start from the last green commit on `feature/ui-retheme`.
- Do not rewrite the D+B light palette; it is approved.
- Do not reintroduce `#5EEAD4`, `bg-gray-*`, `text-gray-*`, `bg-indigo-*`,
  or shadows.
- Keep the comparison file deleted.
