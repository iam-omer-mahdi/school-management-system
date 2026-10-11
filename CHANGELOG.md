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

## [0.1.0] - 2026-10-11

### Added

- Phase 1 foundation: authentication with OTP and security-question recovery, schools and grades, academic years, students and enrollment, English/Arabic RTL interface with light and dark themes, and a reusable Blade UI component library.
