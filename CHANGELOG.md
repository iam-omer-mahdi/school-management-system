# Changelog

All notable changes to this project will be documented in this file.

## [0.3.0] - 2026-10-15

### Added

- Financial reports (`/reports`): year summary, tuition vs other fees, outstanding call list (sortable by name/still owed), collections by date range with voided audit, discounts, forgone by leavers (by-grade summary plus per-student details), refunds and student account statements. Filters by academic year, school, grade and status with an Apply pattern and charged/ended presets; status breakdowns when unfiltered; profile links on student names (screen only).
- Native Excel export for every report (numeric money, percent ratios, real dates, formula-injection guard, RTL sheets, streamed) and printable views (A4, landscape option, print header/footer).
- Dashboard financials: year selector, grand/current/still-owed/forgone cards and four offline charts (collected vs remaining per grade, status split, monthly collections, tuition vs other fees) served from the same `FinancialSummary` query layer.
- Money inputs group thousands on blur and store raw digits.

- Enrollments section with a gated list (school, grade and year, then apply) and per-enrollment pages.
- Payments against enrollment fees with split submissions, printable receipts, voids with reasons and a single balance definition.
- Refunds of paid fees on ended enrollments; refunds never change charges or per-fee paid, only refunded, net paid, cash received and closed-without-payment.
- Payment hardening: minor-unit-only action contract with server-recomputed remaining and refundable caps, live Pay gate with block reasons, enrollment-scoped voids, locked page ids and validated status changes.
- Administrator password reset via `php artisan user:reset-password {email}`.
- Flowbite month pickers for academic-year start and end months.
- Strict finance seeder: seeds only in local and testing environments.

### Fixed

- Ended-only status selection on the outstanding call list returned unfiltered results; it now yields an empty list.
- Receipt print values follow the locale direction instead of forced LTR.

- Ended enrollments now reject discount and optional-fee removals server-side, and the remove buttons are hidden.
- Discounts that would make paid exceed charged are rejected; removing a paid charge fails with a clear message instead of a database error.
- Voided payments have disabled receipt checkboxes, keep per-row printing with the VOID mark, and are excluded from multi-select receipt totals.
- Switching the payment method clears transaction-number validation errors on both keys.

### Changed

- Enrollment show page now has a single charges section and a single payments section (Add button plus history together; the modal opens via event).
- Report tables have no shadow with a gray-700 outer border; sidebar order is dashboard, schools, academic years, students, enrollments, fees, reports, settings.
- Added database indexes for report filters and date columns on payments, refunds, enrollments and enrollment fees (migration edits, no production data yet).

- Charged enrollments now owe charged − discount − net paid, so a refund followed by reactivation restores the owed amount and payment cap.
- Enrollment show page refactored from one 1,104-line Livewire component into a slim parent (`pages/enrollments/show`, header + summary) with six nested components (`status-panel`, `charges-panel`, `add-payment-modal`, `refund-modal`, `payments-panel`, `void-modal`), a `PaymentForm`/`RefundForm` pair and a pure `PaymentCalculation` helper. No behavior, UI or wording changes.
- Enrollment show query budget (measured, MySQL): page load 16 → 51 (exact ceiling in suite; constant regardless of fee/payment/refund volume, no N+1); payment-total keystroke 14 → 7 (only the modal's graph runs); saves: payment 15, refund 15, void-confirm 3, status change 12–14; every sibling refresh re-runs its own graph exactly once. The single refunds section lives in refund-modal (refundable + trigger + history).
- Security questions replaced; previously stored answers were cleared.
- CI builds assets with Bun on PHP 8.5 with Larastan checks.
- Database schema changed: status now lives on enrollments and students have an archive flag; recreate local databases with `php artisan migrate:fresh --seed`.

## [0.2.0] - 2026-10-12

### Added

- Enrollment status lifecycle (active, suspended, transferred, expelled, left) with reactivation that applies missing mandatory fees.
- Student archive (inactive) with guarded restore and latest-enrollment column on the student list.
- Fee types, per-grade fee schedules and a named discount catalog with snapshotted enrollment discounts.
- Shared name normalizer for fee type and discount names.

### Known limitations

- Amounts and discounts are create-only; a mistaken fee amount cannot be corrected once any enrollment has been charged.

## [0.1.0] - 2026-10-11

### Added

- Phase 1 foundation: authentication with OTP and security-question recovery, schools and grades, academic years, students and enrollment, English/Arabic RTL interface with light and dark themes, and a reusable Blade UI component library.
