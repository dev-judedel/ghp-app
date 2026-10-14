# GHP — Group Health Plan Management System

`ghp-app` administers a company **Group Health Plan (GHP)** benefit fund for Employees and Agents. It replaces a legacy PostgreSQL-based system with a Laravel application on a normalized MySQL schema.

It tracks members and dependents, accrues each member's annual GHP entitlement month by month, records medical reimbursement claims against the available balance, and produces reports and printable receipts.

**Last updated:** 2026-10-14

## What it does

- **Members and dependents** — Employee / Agent records, Active / Inactive status, live search and filters, CSV bulk import with duplicate detection. A deactivated member is read-only.
- **Benefit accrual** — ₱3,600 a year, ₱4,200 with an eligible dependent, prorated by month over the member's coverage cycle (Employees Apr–Mar and Agents Jun–May by default, or a configured 12-month cycle), with a 10% carry-forward of a fully unused period.
- **Benefit Period records** — a coverage cycle is generated once but can hold several records. One Unclaimed record collects every reimbursement filed until an administrator **Claims** it, which locks that record only. The next reimbursement filed for the same cycle opens a new record. A new record never grants a new entitlement. **Revert** reopens the latest Claimed record.
- **Reimbursements** — filing needs a positive Available GHP but the amount has no cap. A claim above the Available GHP gets an automatic **Excess GHP** (shown in red). A dependent becoming eligible or a GHP amount increase first covers outstanding excess. Void / unvoid, printable and emailable PDF receipts.
- **GHP amount adjustments** — manual override with a reason, revert to automatic, or revert only the latest adjustment.
- **Reports** — Annual GHP (PDF/CSV), Reimbursements (PDF/CSV), Member Data Record (PDF), Data Quality Report, Activity Log.
- **Accounts** — admin and staff roles, self-service profile and password change, user management with last-admin safeguards.
- **Departments and Divisions** — managed on the Users page. Names are not unique: two records may share a name and are told apart by id (a repeated name shows with its "#id" in dropdowns).

## Stack

PHP ^8.3 · Laravel ^13 · MySQL (SQLite for local use) · Blade with a hand-written stylesheet (`public/css/app.css`) · `barryvdh/laravel-dompdf` · `spatie/laravel-activitylog` · Vite.

## Setup (Laragon / Windows)

```bash
composer install
copy .env.example .env        # then set DB_*, and MAIL_* if receipts must be emailed
php artisan key:generate
php artisan migrate
php artisan storage:link      # profile photos
npm install && npm run build  # or `npm run dev` while developing
```

- **Scheduler:** the daily job `ghp:auto-generate-benefit-periods` is registered but only fires if an OS-level trigger runs `php artisan schedule:run` regularly (Windows Task Scheduler on Laragon).
- **Email:** `MAIL_MAILER=log` (the default) delivers nothing; set real SMTP values for the "Send" receipt button.
- **Legacy import:** `php artisan ghp:import-legacy` needs the `LEGACY_DB_*` variables (not yet documented in `.env.example`). `php artisan ghp:validate-accrual` is a read-only check of the accrual engine.
- **Vite hot file:** if `public/hot` exists but `npm run dev` is not running, delete it or assets will fail to load.

## Tests

The suite uses a dedicated MySQL database (`alsc_ghp_db_testing`, see `phpunit.xml`).

```bash
php artisan test
php artisan test --filter=BenefitPeriodRecordTest   # Benefit Period records (task.md §2.35)
```

**Status:** none of the work since 2026-09-24 has been run in the environment that wrote it. Run `php artisan migrate` and the full suite first — see `Progress.md`.

## Project documents

| File | Purpose |
|---|---|
| `task.md` | Running implementation log, §2.1–§2.35, plus known issues and technical debt. The primary source of history. |
| `Progress.md` | Current status, pending work, test status and next steps. |
| `BRD.md` | Business requirements: objectives, roles, functional requirements, business rules, data model, workflows. |
| `profile_features.md` | Log of the self-service Profile feature. |
| `_removed-by-claude/` | Files taken out of the active app (member export, the reverted benefit-period Void feature, the superseded per-reimbursement claim design). Do not restore without checking `task.md`. Safe to delete once no longer needed. |

## Roles

- **Admin** — everything: members, dependents, reimbursements, claims, adjustments, departments, users, imports, data quality, activity log.
- **Staff** — view the dashboard, members, reports, receipts; edit their own profile.

## Notes

Not implemented yet: "forgot password", login rate limiting, session invalidation on password change, tests for the legacy import command. See `task.md` §3 and `BRD.md` §11–§12.
