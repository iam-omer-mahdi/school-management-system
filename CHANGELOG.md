# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added

- Administrator password reset via `php artisan user:reset-password {email}`.
- Flowbite month pickers for academic-year start and end months.
- Strict finance seeder: seeds only in local and testing environments.

### Changed

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
