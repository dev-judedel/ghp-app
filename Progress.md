# Project Progress & Task Tracking

**Project:** GHP — Group Health Plan Management System (`ghp-app`)
**Document version:** 2.0
**Last updated:** 2026-10-14
**Primary sources:** `task.md` (§2.1–§2.35), `BRD.md`, `profile_features.md`, the source code (controllers, models, migrations, requests, services, views) and the `tests/` directory.

> **Status-verification note.** Nothing written since 2026-09-24 has been confirmed to run in this environment: the migrations were not applied and the tests were not executed (the assistant that wrote them can only read and edit files). This document therefore uses three markers:
> ✅ = implemented and either pre-dating the unverified work or confirmed by reading the code; ⚠️ = code and tests written, **migration / tests not yet run**; ⏳ = an operational step still to be done by you.

---

## Project Progress Summary

GHP is a substantially complete Laravel 13 application that administers a Group Health Plan benefit fund. Member and dependent management, the benefit-accrual engine, reimbursement filing with automatic Excess GHP, GHP amount adjustments, user management, departments, reporting, bulk import/generation and audit logging are all implemented.

**Latest work (2026-10-12) — Benefit Period RECORDS (task.md §2.35).** The claim workflow was redesigned twice in the last week and now settles on this model:

- A coverage cycle is **generated once**, but it can hold **several Benefit Period records** (`benefit_periods.record_no` 1, 2, 3 …).
- One **Unclaimed** record collects every reimbursement filed until it is **Claimed**. Claim locks that record only. The next reimbursement filed for the same cycle opens a **new** record (never created empty or in advance).
- The entitlement is the cycle's and is computed once; a new record is never a new grant. `BenefitPeriod::summarizeCycle()` is the single way to read "the cycle's figures" (entitlement once, Used summed, Available from the last record).
- This **supersedes** the per-reimbursement claim design of §2.32–§2.34. During this pass the code was checked against the specification and four defects were fixed (see Issues / Bugs): the member page referenced a variable and routes that no longer existed, reports showed one record's share of Used, GHP adjustments measured the increase from a stale amount, and a refresh reported a phantom change.

**Top priority now is operational, not development:** run `php artisan migrate` and `php artisan test`, then walk the 8-step workflow in the browser. A large amount of recent code (§2.14 – §2.35) is unverified.

---

## Completed Features

Implemented in code (⚠️ = written but migration/tests not yet run):

**Accounts and members**
- ✅ Self-service Profile (name, email, photo with initials fallback, password change)
- ✅ User Management (auto user code, Active/Inactive with mid-session enforcement, last-admin safeguards)
- ✅ Member Management: auto/manual code, email, individual + bulk Activate/Deactivate, required apply/start dates, live search and filters, pagination
- ⚠️ Deactivated member is read-only — every modifying route sits behind `member.active` middleware, buttons disabled, modals not rendered (§2.23–§2.24)
- ⚠️ Editable GHP Benefit Setup: Coverage year, Apply date, End date, Start date (deductions), GHP amount; configurable 12-month cycle (§2.18)
- ⚠️ Bulk CSV import rebuilt on the real `StoreMemberRequest` rules via a shared `MemberCreationService`, with database and in-file duplicate detection and BOM handling (§2.26)
- ✅ Department Management; ✅ Data Quality Report; ✅ Activity Log
- ⚠️ **Duplicate Department / Division names allowed + Division Management** (2026-10-14, §2.36): names are no longer unique, records are told apart by id (dropdowns submit ids and label a repeated name with "#id"); Add / Edit / Delete Divisions on the Users page; the Department form picks a division by id
- ✅ Legacy import (`ghp:import-legacy`) and read-only `ghp:validate-accrual`

**Dependents**
- ✅ Dependent management with mid-cycle eligibility gating (next cycle) (§2.12)
- ⚠️ Immediate Eligibility, spouse via Edit member (§2.14); the current period recalculates in place when a dependent becomes eligible (§2.16)
- ⚠️ A dependent's removal is blocked while the member has an active reimbursement (§2.22)

**Benefit periods and reimbursements**
- ✅ Accrual engine (`BenefitAccrualService`): coverage cycle, ₱3,600 / ₱4,200 annual amount, monthly proration, 10% carry-forward
- ⚠️ **Benefit Period records** (§2.35): several records per cycle, `record_no`, unique key `(member_id, from_date, to_date, record_no)`; filing goes into the open record or opens the next one only when every record is Claimed
- ⚠️ **Claim | Revert per record** (`BenefitPeriodClaimController`, routes `members.benefit-periods.claim / .revert`): admin-only, member row lock then record lock, Claim refused for an empty record, Revert only for the cycle's latest record, one audit entry per action
- ⚠️ A Claimed record is locked everywhere: its reimbursements can't be edited, voided or un-voided; later GHP increases never cover its excess; reverting an adjustment that covered a Claimed record is refused
- ⚠️ **Coverage Year History** rebuilt as one row per record: Period | GHP amount | Used GHP | Available GHP | Status | Claim / Revert, with each record's reimbursements listed under it
- ✅ Reimbursement filing: a positive Available GHP is required, the amount has no cap; edit, void/unvoid; one-time `submission_token` + unique index against double filing; transaction + member row lock
- ⚠️ **Automatic Excess GHP** (§2.20) and **dependent increase / GHP adjustment covers outstanding excess** (§2.21, §2.29), shown in red on every screen, export and email; the manual Record Excess Deduction feature was removed (old rows kept read-only)
- ⚠️ Adjust GHP amount: revert to automatic **and** revert just the latest active adjustment (§2.29, §2.31), with an `excess_coverages` ledger
- ⚠️ Bulk "Generate this year's benefit period" is all-or-nothing, with a pre-check and a "Generation Not Proceeded" dialog (§2.27)
- ✅ Reimbursement drill-down per record, printable / downloadable PDF receipt; ⚠️ Send receipt by email (§2.17, needs real SMTP in `.env`)
- ✅ Reports: Annual GHP (PDF/CSV), Reimbursements (PDF/CSV), Member Data Record (PDF). ⚠️ The Annual report and the MDR now report one set of figures per cycle however many records it has
- ✅ Daily auto-generation job (registered; needs an external scheduler trigger)

---

## Current Tasks

| # | Task | Source | Status |
|---|---|---|---|
| 1 | `php artisan migrate`. Pending migrations include `2026_09_29_100000` (coverage setup), `2026_09_30_100000 / 110000 / 120000` (excess), `2026_10_07_100000 / 100001`, `2026_10_08_100000 / 110000` (adjustment ledger, revert fields), `2026_10_09_100000` (claim fields, now superseded but still in the chain) and **`2026_10_10_100000_support_multiple_benefit_period_records`** | §2.18–§2.35 | ⏳ Not confirmed run |
| 1b | Same `php artisan migrate` also applies **`2026_10_14_100000_allow_duplicate_department_and_division_names`** (drops the two unique name indexes only), then `php artisan test --filter=DuplicateNamesTest` and `--filter=DepartmentManagementTest` | §2.36 | ⏳ Not run |
| 2 | `php artisan test --filter=BenefitPeriodRecordTest` (new, covers the 8-step specification and the edge cases) | §2.35 | ⏳ Not run |
| 3 | `php artisan test` (full suite). Watch `CoverageSetupTest` and `GenerateBenefitPeriodButtonTest`, which were not read against the new design and may still assume one period per cycle | §2.35 | ⏳ Not run |
| 4 | Manually run the 8-step workflow in the browser (500 / 700 / 800 → Claim → 600 → 400 → Claim → Revert) and check that the member page, the period drill-down, the receipt PDF and the Annual report agree | §2.35 | ⏳ Not done |
| 5 | Check the migration backfill on a copy of live data: a period becomes Claimed only when every active reimbursement was claimed; partly-claimed periods stay Unclaimed and need an admin to claim them | §2.35 | ⏳ Not done |
| 6 | Try filing above the available balance (allowed, red excess), within it, and with ₱0 available (blocked) | §2.20 | ⏳ Not done |
| 7 | Try Add / Edit member Benefit setup on screen (Employee vs Agent defaults, Coverage year ↔ End date, live preview) | §2.18 | ⏳ Not done |
| 8 | Set real SMTP values in `.env` (the default `MAIL_MAILER=log` delivers nothing) and test "Send" on a receipt | §2.17 | ⏳ Not done |
| 9 | Clean up `_removed-by-claude/` once nothing in it is needed (it now also holds the superseded per-reimbursement claim controller, view partial and tests, plus an old `BenefitPeriodClaimController.php` with the same name as the live one — do not move it back) | §2.7, §2.35 | ⏳ Manual decision |

---

## In Progress

Nothing is mid-implementation. The Benefit Period record work is code-complete but unverified (Current Tasks 1–5).

| Item | Done | Outstanding |
|---|---|---|
| Benefit Period records (§2.35) | Migration, accrual service, filing, claim/revert controller, member page, reports, tests written; four defects fixed | Migration + tests not run; browser walk-through not done |
| Superseded record design (§2.32–§2.34) | Marked SUPERSEDED in `task.md`; files parked | Delete the parked files when ready |

---

## Pending

**Not implemented (explicitly out of scope so far):**
- ❌ Self-service "forgot password"
- ❌ Rate limiting on login
- ❌ Session invalidation on password change
- ❌ Export for Users or Departments (Member export was built then removed)
- ❌ `LEGACY_DB_*` documented in `.env.example`
- ❌ Automated tests for `ImportLegacyGhpData`
- ❌ Business decision on a formal enum for dependent `relation`
- ❌ Evaluate merging `benefit_periods` and `benefit_ledger`

**Open items created by the recent work:**
- A dependent-driven excess cover is still **not reversible** if the dependent is later removed. The `dependent_fund_increases` table exists but has no model or code.
- The Reimbursements card on the member page still shows the **latest 20** only; the complete history is in the Coverage Year History rows and the period drill-down.
- Comments in `BulkBenefitPeriodGenerator` and `AutoGenerateBenefitPeriods` still describe the old 3-column unique key.
- The legacy `reimbursements.claimed_at / claimed_by / reverted_at / reverted_by` columns are kept as history and are no longer read or written.

**Operational:** `php artisan storage:link` (profile photos) and an OS-level scheduler (Windows Task Scheduler on this Laragon setup) running `php artisan schedule:run`, otherwise the daily auto-generation job never fires.

---

## Issues / Bugs

### Fixed in this pass (2026-10-12, §2.35)
| Bug | Root cause | Fix |
|---|---|---|
| Member page would throw on load | `members/show.blade.php` still used the removed `$historyRows` variable and the removed `members.reimbursements.claim / .revert` routes and `ReimbursementClaimController` constants | Coverage Year History rebuilt per Benefit Period record on the live routes and `BenefitPeriodClaimController` constants |
| Reports under-reported Used when a cycle had several records | Annual GHP report, MDR and the member page's Benefit balance card used `->first()` of the member's periods | All three use `BenefitPeriod::summarizeCycle()` |
| GHP adjustment measured the increase from a stale amount | `AmountAdjustmentService::apply()` took `->first()` (record 1, frozen at its claim) as the "before" amount | Uses the cycle's open record, or the member's amount before the change when every record is Claimed |
| A refresh reported a change on every call | `refreshCurrentPeriodIfAmountChanged()` compared the member's amount with a Claimed record's frozen amount | Returns null when every record is Claimed |
| Claiming an empty record would lock nothing | No check for active reimbursements | `NOTHING_TO_CLAIM`, in the UI and on the server |

### Fixed earlier (see task.md for detail)
Member search `ilike` → `like`; `GET /reports` PostgreSQL-only SQL; 405 on the member status toggle (nested form); missing PDF receipt view; stale tests (`MemberManagementTest`, `BenefitAccrualServiceTest`, `MemberBenefitSetupTest`, `ExampleTest`, `DependentExcessCoverageTest` CSV column label); un-clickable Generate button and phantom period creation by amount adjustments (§2.15); `fgetcsv` BOM corrupting the first import header (§2.26); dead inactive-member branch in `generateBenefitPeriod()` (§2.24); `pdo_sqlite` environment gap (tests use MySQL).

### Known, intentionally unfixed
| Issue | Notes |
|---|---|
| No rate limiting on `POST /login` | Out of scope so far |
| `agent_positions.member_code` is a string match | Silent breakage risk if a code is reformatted |
| `dependents.relation` is free text | Legacy bridge; typo risk on eligibility |
| `.card-head` doesn't wrap on narrow screens | Shared CSS, untouched |
| `users.email_verified_at` unused | Nothing sets or checks it |
| Password change doesn't invalidate other sessions | Stolen-session risk |

### Unverified (never run in this environment)
Every migration listed in Current Tasks #1, every test file marked ⚠️ in Testing Status, the Print/PDF receipt and the Send-receipt email, and all of the on-screen behaviour described above.

---

## Database Progress

| Area | Status |
|---|---|
| Core schema (users, members, divisions, departments, dependents, benefit_periods, benefit_ledger, benefit_amount_adjustments, reimbursements, agent_positions) and Spatie activity log | ✅ Stable |
| Member / user fields (email, resignation date, start date, `ghp_amount_is_manual`, `user_code`, profile photo) | ✅ |
| `reimbursements.benefit_period_id` link + backfill | ✅ written, ⏳ not confirmed run |
| `dependents.date_added / eligibility_date / immediate_eligibility_*` | ⚠️ |
| `members.coverage_year / coverage_end_date`, `benefit_periods.coverage_year` (+ backfill) | ⚠️ |
| `reimbursements.available_ghp / excess_amount / original_excess_amount / excess_covered_amount / submission_token (unique)` | ⚠️ |
| `excess_deductions` (read-only history), `excess_coverages` (reversal ledger), `benefit_amount_adjustments` tracking columns | ⚠️ |
| `benefit_periods.claimed_at / claimed_by / reverted_at / reverted_by` | ⚠️ now the live claim state, per record |
| **`benefit_periods.record_no`; unique key `(member_id, from_date, to_date, record_no)`** | ⚠️ migration `2026_10_10_100000`, idempotent; every existing row becomes record 1 |
| `reimbursements.claimed_at / claimed_by / reverted_at / reverted_by` | ⚠️ retired — kept as history, not read or written |
| `dependent_fund_increases` | ⚠️ table only, no code uses it |
| `departments.name` and `divisions (member_type, name)` unique indexes | ⚠️ dropped by `2026_10_14_100000` (plain indexes added; rows, keys and foreign keys untouched) |
| Benefit Period Void/Delete fields | ❌ fully reverted (§2.13) |

**Data-safety notes.** The Void-feature revert migration removes a voided row only when an active sibling exists for the same member and cycle, and fails loudly otherwise. The §2.35 migration never deletes data; its `down()` refuses while any cycle has several records.

---

## UI/UX Progress

| Area | Status |
|---|---|
| Topbar profile link + avatar | ✅ |
| Member list (live search, filters, pagination); Add member modal with Benefit setup; CSV import with duplicate reporting | ✅ / ⚠️ |
| Member page: Benefit balance card (cycle figures via `summarizeCycle()`), Dependents table with eligibility status and Immediate Eligibility, Reimbursements card with Remaining GHP / Excess columns and Claimed/Reverted badges | ⚠️ |
| **Coverage Year History**: one row per Benefit Period record — status badge, reimbursements grouped under the record, Claim / Revert, Revert confirmation modal | ⚠️ |
| Amount adjustment history with per-adjustment Revert | ⚠️ |
| Deactivated-member banner, disabled buttons, modals omitted | ⚠️ |
| Bulk Generate pre-check and "Generation Not Proceeded" dialog | ⚠️ |
| Reimbursement records page, receipt PDF, report PDFs, MDR, email — red Excess GHP marking | ⚠️ |
| Reports page, Data Quality page, Activity Log | ✅ |
| Narrow / mobile layout | ⚠️ needs review (`.card-head`) |
| Add member standalone page `members/create.blade.php` | not updated for Benefit setup (not routed) |

---

## Testing Status

**Framework:** classic PHPUnit `TestCase` classes under `tests/Feature` and `tests/Unit`, run against the dedicated MySQL database `alsc_ghp_db_testing` (`phpunit.xml`). No test was executed in this environment since 2026-09-24.

| File | Covers | Status |
|---|---|---|
| `BenefitPeriodRecordTest` | **New (§2.35).** 8-step specification workflow; no new entitlement; excess on a new record; zero balance creates no record; empty record can't be claimed; only the latest record can be reverted; a reverted record takes the next filing; server-side admin / ownership / deactivation rules; claimed reimbursement locked; duplicate token; GHP adjustment / dependent increase vs Claimed excess; adjustment revert refused; page rendering; Annual report counts a cycle once; MDR and receipts; generation once per cycle; 4-column unique key | ⚠️ |
| `InactiveMemberLockTest` | Deactivated member read-only (already updated to the record-based claim / revert routes) | ⚠️ |
| `BulkBenefitPeriodGenerationTest`, `GenerateBenefitPeriodButtonTest`, `CoverageSetupTest` | Generation rules, configurable cycle. The bulk test looks compatible with record numbers; the other two were **not read** against §2.35 | ⚠️ |
| `ReimbursementManagementTest`, `ExcessDeductionTest`, `DependentExcessCoverageTest`, `DependentRemovalGuardTest`, `DependentBenefitRecalculationTest`, `ImmediateEligibilityTest` | Filing rule, automatic excess, dependent covers, removal guard, recalculation | ⚠️ |
| `AmountAdjustmentManagementTest`, `AmountAdjustmentExcessCoverageTest`, `AmountAdjustmentFollowUpTest`, `AmountAdjustmentIndividualRevertTest` | Adjustments, excess cover, per-adjustment revert | ⚠️ |
| `MemberImportTest`, `SendReimbursementReceiptTest`, `DataQualityReportTest`, `MemberBenefitSetupTest`, `BenefitAccrualServiceTest` (Unit) | Import, email, data quality, dependent timing, accrual math | ⚠️ |
| `DuplicateNamesTest` (new), `DepartmentManagementTest` (updated) | Same-name departments / divisions, edit / delete one duplicate, member assignment by id, dropdown labels, CSV lowest-id, unique indexes gone | ⚠️ |
| `ProfileTest`, `MemberSearchTest`, `UserManagementTest`, `MemberManagementTest`, `MemberDeactivationTest`, `MemberFilterPersistenceTest`, `DepartmentManagementTest`, `ReportExportTest`, `ExampleTest` ×2 | Older coverage | ✅ present |

**Superseded and parked in `_removed-by-claude/` (do not restore):** `ReimbursementRecordTest` (per-reimbursement design), `BenefitPeriodClaimTest` (per-period claim design of §2.28–§2.30), `BenefitPeriodManagementTest` (reverted Void/Delete).

**Gaps:** `ImportLegacyGhpData` has no automated tests. The migration backfill and a truly concurrent double submit are not tested (sequential only).

**Run, in order:**
```bash
php artisan migrate
php artisan test --filter=BenefitPeriodRecordTest
php artisan test
```

---

## Documentation Status

| Document | Status |
|---|---|
| `task.md` | ✅ Updated 2026-10-12 — §2.35 added; §2.32–§2.34 marked SUPERSEDED |
| `Progress.md` (this file) | ✅ Updated 2026-10-12 |
| `BRD.md` | ✅ Updated 2026-10-12 |
| `README.md` | ✅ Replaced the Laravel boilerplate with a GHP-specific overview |
| `profile_features.md` | ✅ Still accurate (Profile feature only); status line refreshed |
| `.env.example` | ⚠️ Still missing `LEGACY_DB_*` |

---

## Next Recommended Development Tasks

1. Run `php artisan migrate`, then the tests above; fix whatever fails (start with `BenefitPeriodRecordTest`, `CoverageSetupTest`, `GenerateBenefitPeriodButtonTest`).
2. Walk the 8-step workflow in the browser and compare the member page, drill-down, receipt PDF and Annual report.
3. Check the migration backfill on a copy of live data.
4. Decide what to do with the remaining items from the specification review: show the full reimbursement history on the member page (currently the latest 20), and finish or drop the unused `dependent_fund_increases` table.
5. Update the stale comments in `BulkBenefitPeriodGenerator` / `AutoGenerateBenefitPeriods`.
6. Add tests for `ImportLegacyGhpData`; document `LEGACY_DB_*` in `.env.example`.
7. Delete `_removed-by-claude/` once nothing in it is needed.
8. Business-owner decisions still open: Division CRUD, forgot-password, login rate limiting, session invalidation on password change.

---

*Generated from `task.md`, `BRD.md`, `profile_features.md` and the `ghp-app` source as of 2026-10-12. "Implemented" means the code exists; "not yet run" means it has not been confirmed to execute.*
