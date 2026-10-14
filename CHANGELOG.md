# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added

- Enrollments section with a gated list (school, grade and year, then apply) and per-enrollment pages.
- Payments against enrollment fees with split submissions, printable receipts, voids with reasons and a single balance definition.
- Refunds of paid fees on ended enrollments; refunds never change charges or per-fee paid, only refunded, net paid, cash received and closed-without-payment.
- Payment hardening: minor-unit-only action contract with server-recomputed remaining and refundable caps, live Pay gate with block reasons, enrollment-scoped voids, locked page ids and validated status changes.
- Administrator password reset via `php artisan user:reset-password {email}`.
- Flowbite month pickers for academic-year start and end months.
- Strict finance seeder: seeds only in local and testing environments.

### Fixed

- Ended enrollments now reject discount and optional-fee removals server-side, and the remove buttons are hidden.
- Discounts that would make paid exceed charged are rejected; removing a paid charge fails with a clear message instead of a database error.
- Voided payments have disabled receipt checkboxes, keep per-row printing with the VOID mark, and are excluded from multi-select receipt totals.
- Switching the payment method clears transaction-number validation errors on both keys.

### Changed

- Charged enrollments now owe charged − discount − net paid, so a refund followed by reactivation restores the owed amount and payment cap.
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
