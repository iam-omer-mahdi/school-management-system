# School Management System

A modern, bilingual (English / Arabic) web application for running the administrative side of a school: academic structure, students and enrollment today, with fees, payments, reporting and more planned.

The system is being built as a complete product, delivered in well-defined phases. Each phase ships tested, documented and usable on its own. See the [Roadmap](#roadmap).

<!-- Replace <your-username> and <repo> once the repository exists -->
[![CI](https://github.com/<your-username>/<repo>/actions/workflows/ci.yml/badge.svg)](https://github.com/<your-username>/<repo>/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![Livewire](https://img.shields.io/badge/Livewire-4-FB70A9)
![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-4-06B6D4?logo=tailwindcss&logoColor=white)

<!--
Add screenshots here. Suggested set:
  docs/screenshots/login.png
  docs/screenshots/students-list.png
  docs/screenshots/students-list-dark.png
  docs/screenshots/students-list-ar-rtl.png
-->

## Table of contents

- [Features](#features)
- [Tech stack](#tech-stack)
- [Getting started](#getting-started)
- [Running the tests](#running-the-tests)
- [Architecture](#architecture)
- [Data model](#data-model)
- [Internationalization and RTL](#internationalization-and-rtl)
- [Security](#security)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [License](#license)

## Features

Available now (Phase 1: Foundation):

**Academic structure**
- Schools, each with its own set of grades
- Academic years with an auto-generated name (for example `2026/2027`), one per start year, create-only, protected from deletion once enrollments exist

**Students and enrollment**
- Student records with auto-generated student numbers
- Contact, guardian and personal details
- Enroll a student into a grade for an academic year (one enrollment per student per year)
- Student status lifecycle: active, transferred, expelled, left, suspended, with notes and change timestamps
- Search by name, student number or phone, status filter and pagination

**Accounts and security**
- Email and password sign-in
- Password recovery by emailed one-time code, or by security questions
- Self-service settings: change email (verified with a code sent to the new address), change password, update security questions

**Experience**
- Full English and Arabic interface with a proper right-to-left layout
- Light and dark themes
- Responsive layout with a mobile drawer and SPA-style navigation
- A reusable Blade UI component library (`x-ui`)

## Tech stack

| Layer | Choice |
| --- | --- |
| Language | PHP 8.5 |
| Framework | Laravel 13 |
| UI | Livewire 4 (single-file page components) |
| Styling | Tailwind CSS v4, Flowbite |
| Database | SQLite (default) |
| Tests | PHPUnit 12 |
| Code style | Laravel Pint |

## Getting started

### Requirements

- PHP 8.5 with the extensions Laravel requires
- Composer
- Node.js and npm

### Installation

```bash
git clone https://github.com/<your-username>/<repo>.git
cd <repo>

composer install
npm install

cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate --seed

npm run build
```

### Run the app

```bash
composer run dev
```

Then open the URL printed in the terminal.

### Demo account

`php artisan migrate --seed` creates an initial administrator account (see `database/seeders/UserSeeder.php`). It exists for local development only. **Change the password immediately** and never run the seeder against a production database.

### Email in development

The default `.env.example` uses the `log` mailer, so password-reset and email-change codes are written to `storage/logs/laravel.log` instead of being sent. Configure a real SMTP provider in `.env` for production.

## Running the tests

```bash
php artisan test
```

Code style:

```bash
./vendor/bin/pint --test   # check
./vendor/bin/pint          # fix
```

The suite uses an in-memory SQLite database and covers authentication, the app shell, localization, school structure, academic years, student enrollment, password reset, email change and translated validation messages.

## Architecture

```text
app/
  Actions/        Single-purpose domain operations (CreateStudent, EnrollStudent, ...)
  Enums/          DisplayLocale, StudentStatus
  Livewire/Forms/ Form objects (AcademicYearForm, StudentForm)
  Mail/           Mailables (reset codes)
  Models/         Eloquent models
  Services/       Cross-cutting services (PasswordResetService)
resources/
  views/pages/    Livewire single-file page components, routed with Route::livewire
  views/components/ui/   Reusable UI components
lang/             en and ar translation files
tests/Feature/    Feature tests
```

Conventions:

- Business rules live in **Actions** and **Services**, not in components.
- Validation and authorization happen inside the action that performs the change.
- Strict types, explicit return types and constructor property promotion throughout.
- Formatting is enforced with Pint.

## Data model

Phase 1 domain:

```mermaid
erDiagram
    SCHOOL ||--o{ GRADE : has
    GRADE ||--o{ ENROLLMENT : contains
    ACADEMIC_YEAR ||--o{ ENROLLMENT : scopes
    STUDENT ||--o{ ENROLLMENT : has

    SCHOOL { string code UK
             string name UK }
    GRADE { int school_id FK
            int number }
    ACADEMIC_YEAR { string name UK
                    date starts_on
                    date ends_on }
    STUDENT { string student_number UK
              string name
              string status }
    ENROLLMENT { int student_id FK
                 int academic_year_id FK
                 int grade_id FK }
```

Key constraints: a grade number is unique per school, and a student has at most one enrollment per academic year. Foreign keys use `restrict` so history cannot be orphaned.

## Internationalization and RTL

- English and Arabic, switchable at any time. The choice is stored in the session and on the user profile.
- The layout flips to right-to-left for Arabic using logical CSS properties.
- Numbers are displayed with Western digits in both languages.
- Validation messages and attribute names are translated. A test fails if Arabic validation messages are missing.
- The Cairo font is bundled locally, so no external font requests are made.

## Security

- Passwords are hashed. Password-reset and email-change codes are stored hashed, expire in 10 minutes and are invalidated after 5 failed attempts.
- Sending codes and verifying them is rate limited.
- Password reset uses single-use, server-issued tokens that expire in 15 minutes. Completing a reset rotates the remember-me token.
- Security-question answers are normalized and stored hashed. The questions are the same for every email address, so the recovery flow does not reveal whether an account exists.
- Changing the email address requires proving access to the new address. Changing the password requires the current password.

To report a vulnerability, please open a private security advisory on GitHub rather than a public issue.

## Roadmap

The product is delivered phase by phase. Scope for later phases is a plan, not a commitment, and will be refined as the earlier phases land.

| Phase | Focus | Status |
| --- | --- | --- |
| 1. Foundation | Authentication and account security, schools and grades, academic years, students, enrollment, English/Arabic with RTL, light/dark themes, UI component library | Done |
| 2. Fees and discounts | Fee structures per school, grade and academic year; installment plans; discount types and rules (sibling, scholarship, staff); per-student fee assignment | Planned |
| 3. Payments | Recording payments, receipts, balances and arrears, refunds and adjustments | Planned |
| 4. Reports and dashboards | Collection, outstanding balances and enrollment reports; filters by school, grade and year; export to PDF and Excel | Planned |
| 5. Roles and auditing | Role-based access (finance, registrar, administrator), activity log, backups | Planned |
| 6. Wider school management | Additional modules such as attendance, staff and academic records, to be defined once the finance core is complete | To be scoped |

## Contributing

Contributions and suggestions are welcome.

1. Fork the repository and create a branch from `main`.
2. Make your change with tests.
3. Run `php artisan test` and `./vendor/bin/pint`.
4. Open a pull request describing the change and why.

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/) (for example `feat(students): add status filter`).

## License

Released under the [MIT License](LICENSE).
