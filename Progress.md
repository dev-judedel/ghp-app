# Project Progress & Task Tracking

**Project:** GHP — Group Health Plan Management System (`ghp-app`)
**Document version:** 1.0
**Last updated:** 2026-09-24
**Primary sources:** `task.md`, `profile_features.md`, actual source code (controllers, models, migrations, requests, services), and the `tests/` directory.

> **Status-verification note:** Per `task.md`, several of the most recent changes (§2.8–§2.13 below) are explicitly marked **"Not yet run"** by the developer who made them — meaning the migrations/tests were written from direct code inspection but have **not been confirmed to execute successfully** in this environment. This document treats those items as ⚠️ **Needs Review**, not ✅ Completed, until `php artisan migrate` and `php artisan test` have actually been run and confirmed passing.

---

## Project Progress Summary

GHP is a substantially complete, actively-developed Laravel 13 application administering a Group Health Plan benefit fund. Core functionality — member/dependent management, benefit accrual calculation, reimbursement filing with balance enforcement, user management, departments, reporting, and audit logging — is implemented and covered by feature/unit tests. Two significant pieces of scope were **explicitly built and then removed** at client request (Member export, and the Benefit Period Void/Delete/Search/Filter feature) — both are fully reverted in the active codebase; only orphaned files remain in `_removed-by-claude/` for reference.

The most recent working session (2026-09-24) made a business-rule change to dependent eligibility timing, added test coverage for previously-undertested financial controllers, fixed several bugs (a `ViewNotFoundException` on the reimbursement PDF receipt, stale test assertions, a 405 error on member status toggling, a PostgreSQL-only `ilike` operator breaking search and `EXTRACT/::int` syntax breaking Reports), and then reverted the Void/Delete/Search/Filter feature per an explicit client decision — including a carefully idempotent migration pair to make sure that revert is safe regardless of which partial state the live database was left in.

**As of this update, the top priority before anything else is operational, not development work:** run `php artisan migrate` then `php artisan test` to confirm the pending migrations and the newly-written/updated tests actually pass in this environment, since several were written from code inspection alone and are flagged as unverified.

---

## Completed Features

Features confirmed actually implemented and working based on the current code (not merely mentioned in documentation):

- ✅ Self-service Profile page (view/edit name, email, photo with initials fallback; change password)
- ✅ Member search (bug-fixed: `ilike` → `like` for MySQL/SQLite compatibility)
- ✅ User Management: auto-generated user code, Active/Inactive status, deactivate/reactivate with mid-session enforcement, strengthened email validation
- ✅ Member Management: email field, auto/manual member code, individual + bulk Activate/Deactivate, required `apply_date`/`start_date`
- ✅ Department Management (pre-existing, verified functional; free-text Division assignment)
- ✅ Dependent management with mid-cycle eligibility gating (dependents added mid-cycle wait until the next coverage cycle to raise the GHP amount)
- ✅ Benefit accrual engine (`BenefitAccrualService`) — coverage-year logic, 3,600/4,200 GHP amount rule, monthly proration, 10% unused carry-forward
- ✅ Strict one-benefit-period-per-cycle enforcement (after the Void/Delete feature's full revert)
- ✅ Reimbursement filing/editing with balance-enforcement (cannot exceed available GHP), void/unvoid with reason
- ✅ Coverage Year History → Reimbursement drill-down, with printable/downloadable PDF receipt (bug-fixed: missing Blade view)
- ✅ Manual GHP amount adjustment / revert-to-automatic, with structured history
- ✅ Reports: Annual GHP (PDF/CSV), Reimbursements (PDF/CSV), Member Data Record (PDF) — bug-fixed: PostgreSQL-only `EXTRACT(...)::int` syntax replaced with portable Eloquent
- ✅ Data Quality Report (corrupted dates, ₱0 active members, missing current-cycle periods, legacy orphan counts)
- ✅ Activity Log (admin-viewable, filterable)
- ✅ Member CSV import (template download + import with per-row validation/skip reporting)
- ✅ Legacy PostgreSQL data import console command (`ghp:import-legacy`)
- ✅ Accrual engine validation console command (`ghp:validate-accrual`, read-only)
- ✅ Daily scheduled benefit-period auto-generation job (registered; external trigger still required — see Pending)

---

## Current Tasks

Per `task.md`, work is currently at a **stabilization / verification checkpoint** rather than mid-feature. The primary open task is:

| Task | Source | Status |
|---|---|---|
| Run `php artisan migrate` to apply the two most recent migrations (`2026_09_24_100000_...` idempotent rewrite, `2026_09_24_200000_...` revert, plus `2026_09_24_110000_...` dependent eligibility fields, and `2026_09_21_100000_...` benefit_period_id backfill if not already applied) | task.md §2.9, §2.12, §2.13 | ⏳ Pending — not confirmed run in this environment |
| Run `php artisan test` to confirm all recently written/updated tests actually pass | task.md §2.8, §2.9, §2.10, §2.12, §2.13 | ⏳ Pending — not confirmed run in this environment |
| Watch specifically for a unique-constraint error from the `..._200000_revert...` migration (would indicate an ambiguous voided-duplicate benefit-period row needing manual review) | task.md §2.13 | ⚠️ Needs Review if/when migration is run |
| Re-run `ReimbursementManagementTest` / `AmountAdjustmentManagementTest` specifically, since `BenefitAccrualService` changed again after those tests were written | task.md §2.13 | ⏳ Pending |
| Delete the `_removed-by-claude/` folder once its contents are confirmed no longer needed (orphaned export controller/view, reverted Void feature's request/test) | task.md §2.7, §2.13 | ⏳ Pending (manual cleanup decision, not automatic) |

---

## In Progress

Nothing is currently mid-implementation. The most recently touched feature (dependent eligibility timing, §2.12) is code-complete but its migration/tests are unverified (see Current Tasks above) — categorized here as **partially confirmed** rather than "in progress" in the traditional sense, since no further code changes are expected, only verification.

| Item | What's done | What's outstanding |
|---|---|---|
| Dependent mid-cycle eligibility gating | Migration written, model/service/controller logic written, view updated, tests written | Migration + test run not yet confirmed |
| Reimbursement PDF receipt fix | View created, controller reference corrected | Manual print/PDF verification not yet performed |

---

## Pending

Features/tasks with **no implementation work done yet**, explicitly flagged as out of scope for now (`task.md §4`):

- ❌ Division CRUD (dedicated Add/Edit/Delete UI) — currently only indirectly manageable via the Department form
- ❌ Self-service "forgot password" (email-based reset flow)
- ❌ Rate limiting on the login route
- ❌ Session invalidation when a password is changed (self-service or admin-issued)
- ❌ Any export functionality for Users or Departments (only Members ever had export, and it was removed)
- ❌ Documenting `LEGACY_DB_*` variables in `.env.example`
- ❌ Automated test coverage for `ImportLegacyGhpData` (the console import command — the last remaining gap from the original test-coverage review)
- ❌ Business-owner decision on normalizing legacy-typo dependent `relation` values into a formal enum
- ❌ Evaluation of merging `benefit_periods` and `benefit_ledger` tables

**Operational (not code) tasks required to run the app, per the setup checklist:**
- `php artisan migrate`
- `php artisan storage:link` (required for profile photo uploads to display)
- An OS-level scheduler trigger (e.g. Windows Task Scheduler on this Laragon environment) configured to run `php artisan schedule:run` regularly, or the daily benefit-period auto-generation job will never actually fire despite being correctly registered in code

---

## Issues / Bugs

### Fixed this tracked history
| Bug | Root cause | Fix | Status |
|---|---|---|---|
| Member search threw a SQL error on any non-empty search | `'ilike'` (Postgres-only) used against MySQL/SQLite | Changed to `'like'` in `MemberController::index()` | ✅ Fixed |
| `GET /reports` threw a SQL syntax error on every load | `EXTRACT(YEAR FROM from_date)::int` — PostgreSQL-only syntax | Replaced with portable Eloquent/Carbon logic | ✅ Fixed |
| Individual member Deactivate/Reactivate button returned HTTP 405 | Per-row `<form>` nested inside the outer bulk-select `<form>`; browsers merged the fields, submitting to the wrong (POST-only) bulk-action endpoint | Replaced nested form with a JS function building a standalone out-of-DOM form | ✅ Fixed |
| `BenefitPeriodController::reimbursementsPdf()` threw `ViewNotFoundException` | Referenced Blade view `reports.pdf.reimbursement-receipt` never existed | Created the missing view (A4, company header, tables, totals, empty-state) | ✅ Fixed — **not yet manually verified** (task.md explicitly flags this) |
| Two `MemberManagementTest` tests failed | Called the deactivate endpoint without a `resignation_date`, which `UpdateMemberStatusRequest` requires when deactivating an active member — tests were never updated when that validation rule was added | Both tests updated to send `resignation_date` | ✅ Fixed |
| 3 tests in `BenefitAccrualServiceTest` asserted stale "day ≥ 15 cutoff" behavior no longer implemented (calendar-month-only counting supersedes it) | Tests never updated after the Sep 2026 Deduction Date rework | Rewrote the day-of-month test to demonstrate day-of-month no longer matters; corrected two mid-year proration tests to use a correctly-resolved `deduction_start_date` | ✅ Fixed |
| `MemberBenefitSetupTest` asserted the *old*, now-incorrect immediate-GHP-bump-on-dependent-add behavior | Business rule changed (§2.12) — behavior intentionally changed, not a regression | Replaced with tests asserting the new pending-until-next-cycle behavior | ✅ Fixed (intentional behavior change, test updated to match) |
| A local test-environment issue: missing `pdo_sqlite` extension | Environment configuration gap | Switched `phpunit.xml` to a dedicated MySQL test database (`alsc_ghp_db_testing`) | ✅ Fixed |
| Stale Laravel scaffold test `ExampleTest.php` asserted HTTP 200 at `/` | `/` has always redirected to `/dashboard` in this app; test never updated | Corrected the assertion | ✅ Fixed |

### Known, intentionally unfixed (see Known Limitations in BRD.md §11)
| Issue | Notes |
|---|---|
| No rate limiting on `POST /login` | Flagged, out of scope for the request that surfaced it |
| `agent_positions.member_code` is a string match, not an FK | Silent-breakage risk if a code is reformatted/typo'd |
| `dependents.relation` is free text, not an enum | Legacy-data bridge; typo risk on eligibility logic |
| `.card-head` CSS doesn't wrap on narrow screens | Shared CSS file — deliberately not touched |
| `users.email_verified_at` unused | Present in schema, nothing sets or checks it |
| Password change doesn't invalidate other sessions | Stolen-session risk after a reset |

### Unverified fixes (explicitly flagged "Not yet run" in `task.md`)
These were written correctly per code review by the developer, but their actual execution has not been confirmed:
- The two-migration Void-feature revert pair (`2026_09_24_100000...`, `2026_09_24_200000...`)
- The `benefit_period_id` backfill migration (`2026_09_21_100000...`)
- The dependent eligibility fields migration (`2026_09_24_110000...`)
- `ReimbursementManagementTest.php`, `AmountAdjustmentManagementTest.php`, `DataQualityReportTest.php` (written from reading controllers/requests/models directly, not yet executed)
- `BenefitAccrualServiceTest.php`'s newest eligibility-date test cases

---

## Database Progress

| Area | Status |
|---|---|
| Core schema (users, members, divisions, departments, dependents, benefit_periods, benefit_ledger, benefit_amount_adjustments, reimbursements, agent_positions) | ✅ Implemented, stable |
| `spatie/laravel-activitylog` tables | ✅ Implemented |
| Profile photo support (`users.profile_photo_path`) | ✅ Implemented |
| User Active/Inactive + auto-generated `user_code` (with backfill for existing rows) | ✅ Implemented |
| Member email, resignation date, start date, `ghp_amount_is_manual` | ✅ Implemented |
| Department ↔ Division FK | ✅ Implemented |
| `reimbursements.benefit_period_id` link + one-time backfill | ✅ Implemented — **not yet confirmed run** in this environment |
| Dependent `date_added` / `eligibility_date` (nullable, deliberately not backfilled) | ✅ Implemented — **not yet confirmed run** |
| Benefit Period Void/Delete fields (`is_voided`, `voided_at`, `voided_reason`, `voided_by`) | ❌ **Fully reverted** — added then removed same day (§2.11 → §2.13); the reverting migration also restores the original `unique(member_id, from_date, to_date)` constraint that enforces the strict one-generation-per-cycle rule |
| Division management (dedicated CRUD) | ❌ Not implemented — no schema gap, purely a missing UI layer |

**Data-safety note on the revert:** the revert migration (`2026_09_24_200000_...`) only removes a voided benefit-period row when a confirmed active sibling exists for the exact same member+cycle (i.e., a genuinely superseded row from a void-then-regenerate cycle). A voided row with no active sibling is left untouched. Any other ambiguous duplicate is deliberately left alone so the unique-constraint step fails loudly rather than silently discarding data — this needs a human look if it happens.

---

## UI/UX Progress

| Area | Status |
|---|---|
| Topbar profile link + avatar/initials badge | ✅ Implemented |
| Member list: live/AJAX search, filters, pagination | ✅ Implemented |
| Member page: modal-based add/edit patterns (consistent with the rest of the app) for dependents, reimbursements, amount adjustments | ✅ Implemented |
| Dependents table: Date Added, Status badge (Eligible / Pending Eligibility / Not Eligible), Eligible Period columns | ✅ Implemented |
| Benefit periods card on member page | ✅ Implemented — **back to its original plain table** (Period / GHP amount / Used / Available) after the Void/Delete feature revert; no Action column, no search/filter bar |
| Users page: Department Management card (ID / Department / Division / Action) | ✅ Implemented (pre-existing, verified working) |
| Reports page filters (type/department/division/status/year, date range) | ✅ Implemented |
| Data Quality Report page | ✅ Implemented |
| Activity Log page (filterable by log name) | ✅ Implemented |
| Reimbursement receipt (company letterhead PDF) | ✅ Implemented — view fixed this session, **print/PDF not yet manually verified** |
| Narrow/mobile viewport layout | ⚠️ Needs Review — `.card-head` known not to wrap cleanly; not addressed |

---

## Testing Status

**Test framework:** PHPUnit-style `TestCase` classes under `tests/Feature` and `tests/Unit` (Pest is present as a dependency but the existing suite is written in classic PHPUnit class style). Configured against a dedicated MySQL database (`alsc_ghp_db_testing`) per `phpunit.xml`.

### Existing test files
| File | Covers | Status |
|---|---|---|
| `tests/Feature/ProfileTest.php` | Self-service profile edit/password change | ✅ Present |
| `tests/Feature/MemberSearchTest.php` | Member search (post-`ilike` fix) | ✅ Present |
| `tests/Feature/UserManagementTest.php` | User CRUD, status toggling, last-admin safeguards | ✅ Present |
| `tests/Feature/MemberManagementTest.php` | Member CRUD, email, code generation, status, required dates | ✅ Present, updated this session |
| `tests/Feature/MemberDeactivationTest.php` | Member deactivate/reactivate flow, resignation date rule | ✅ Present |
| `tests/Feature/MemberFilterPersistenceTest.php` | Filter/search state persistence | ✅ Present |
| `tests/Feature/MemberImportTest.php` | CSV import (valid/invalid rows, skip reporting) | ✅ Present |
| `tests/Feature/DepartmentManagementTest.php` | Department CRUD | ✅ Present (added this session — was previously untested despite the feature pre-existing) |
| `tests/Feature/ReimbursementManagementTest.php` | Filing, editing, void/unvoid, balance enforcement, OR-date linking | ✅ Present (added this session) — ⚠️ **not yet run** |
| `tests/Feature/AmountAdjustmentManagementTest.php` | Manual override, revert-to-automatic, history | ✅ Present (added this session) — ⚠️ **not yet run** |
| `tests/Feature/DataQualityReportTest.php` | Admin-only access, all three live-DB detectors | ✅ Present (added this session) — ⚠️ **not yet run** |
| `tests/Feature/MemberBenefitSetupTest.php` | Dependent-driven GHP amount recalculation, including new eligibility-timing behavior | ✅ Present, updated this session — ⚠️ **not yet run** (new cases) |
| `tests/Feature/ReportExportTest.php` | Report PDF/CSV export | ✅ Present |
| `tests/Feature/ExampleTest.php` | Laravel scaffold smoke test | ✅ Present, corrected this session |
| `tests/Unit/BenefitAccrualServiceTest.php` | Core accrual math: coverage periods, proration, carry-forward, eligibility gating | ✅ Present, updated this session — ⚠️ **not yet run** (new/changed cases) |
| `tests/Unit/ExampleTest.php` | Scaffold unit smoke test | ✅ Present |

### Confirmed gaps
- ❌ **`ImportLegacyGhpData`** (the console import command) has **no automated test coverage** — the single largest remaining testing gap, explicitly called out in `task.md` as still open.
- The Benefit Period Void/Delete feature's test (`BenefitPeriodManagementTest.php`) was **moved to `_removed-by-claude/`** along with the feature's own revert — it is not part of the active test suite and should not be restored unless the feature itself is reinstated.

### Immediate next testing action
Run, in order, in this environment:
```bash
php artisan migrate
php artisan test
```
Pay particular attention to `MemberBenefitSetupTest` and `BenefitAccrualServiceTest` (both touched by the dependent-eligibility-timing change), and to `ReimbursementManagementTest`/`AmountAdjustmentManagementTest` (both dependent on `BenefitAccrualService`, which changed again after they were written).

---

## Documentation Status

| Document | Status |
|---|---|
| `task.md` | ✅ Actively maintained, detailed, last updated 2026-09-24 — treated as the primary source of truth for recent history in this Progress.md |
| `profile_features.md` | ✅ Complete standalone log for the Profile feature specifically; superseded/summarized (not replaced) by this Progress.md and BRD.md |
| `README.md` | ⚠️ Generic Laravel framework boilerplate — contains no project-specific information about GHP |
| `BRD.md` (this update) | ✅ Created/updated 2026-09-24, based on actual code + `task.md` |
| `Progress.md` (this update) | ✅ Created/updated 2026-09-24, based on actual code + `task.md` |
| `.env.example` | ⚠️ Needs Review — missing documentation for `LEGACY_DB_*` variables actually required by the legacy import command |

---

## Next Recommended Development Tasks

Ordered by what `task.md` itself flags as most urgent, strictly grounded in the current project state:

1. **Run `php artisan migrate` and `php artisan test`** in this environment and resolve any failures — this is a prerequisite for trusting anything else on this list, since several recent changes are explicitly unverified.
2. **Manually verify the reimbursement receipt Print/Download PDF** (`BenefitPeriodController::reimbursementsPdf()`), since the missing-view bug fix has not been manually confirmed.
3. **Watch for and resolve any unique-constraint error** from the Void-feature revert migration, which would indicate an ambiguous voided-duplicate benefit-period row that needs a manual data decision.
4. **Add automated test coverage for `ImportLegacyGhpData`** — the last open item from the original "thin test coverage" review.
5. **Decide on and clean up `_removed-by-claude/`** — either permanently delete it, or explicitly confirm nothing in it (the orphaned Member export feature, the reverted Void/Delete feature) is coming back before doing so.
6. **Document `LEGACY_DB_*` in `.env.example`** so the legacy import command's configuration isn't undiscoverable without reading source.
7. Consider (business-owner decision required, not a unilateral dev task): a Division CRUD screen, self-service password reset, login rate limiting, and session invalidation on password change — all explicitly flagged as not-yet-implemented in `task.md §4`.

---

*This document was generated by analyzing `task.md`, `profile_features.md`, and the actual `ghp-app` source code (migrations, controllers, models, services, tests) as of 2026-09-24. Status markers reflect verified implementation state, distinguishing "code exists" from "confirmed working/tested" wherever `task.md` itself flagged that distinction.*
