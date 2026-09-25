# GHP Project — Task & Implementation Log

Tracks everything built, fixed, or left open across this project so far.
Use this as a quick reference before starting new work — check "Not Implemented"
before assuming something doesn't exist, and check "Known Issues" before
re-diagnosing a problem that's already been flagged.

> **Standing rule (per explicit client instruction, 2026-09-24): whenever this
> project changes — a feature, fix, revert, or verification pass — `task.md`,
> `/BRD.md`, and `/Progress.md` must ALL be updated in the same pass, not just
> this file.** `task.md` stays the detailed, chronological log (append a new
> `§2.N` entry per change, exactly as below). `BRD.md` and `Progress.md` are
> summaries derived from it — update whichever of their sections the change
> actually touches (a reverted/superseded section needs its status flipped
> there too, not just here) rather than only appending to this file. This
> applies to every Claude surface working on this project, not just the one
> that made a given change.

**Last updated:** 2026-09-24 (§2.20 — BRD.md/Progress.md synced to current state; doc-sync policy adopted)

---

## 1. Setup checklist (run these if you haven't already)

```bash
php artisan migrate
php artisan storage:link
```

That covers every migration below. No Composer packages are currently required
beyond what's already in `composer.json` (the one that was needed — `phpoffice/phpspreadsheet`
for Excel export — is no longer needed; that feature was built and then removed).

---

## 2. Completed work

### 2.1 Profile page (self-service "My Profile")
- Logged-in user (any role) can view/edit their own name, email, and photo, and change their password.
- Photo falls back to initials avatar when none is set.
- **Files:** `ProfileController`, `UpdateProfileRequest`, `UpdatePasswordRequest`, `profile/edit.blade.php`, migration `profile_photo_path`, topbar link in `layouts/app.blade.php`.
- **Tests:** `ProfileTest.php`

### 2.2 Bug fix — Member search was broken
- Root cause: search query used `'ilike'` (Postgres-only operator) against a MySQL/SQLite database — any non-empty search threw a SQL error.
- Fixed to `'like'` in `MemberController::index()`.
- Along the way: fixed a local test-environment issue (missing `pdo_sqlite` → switched `phpunit.xml` to a MySQL test DB), and fixed a stale, never-updated Laravel scaffold test (`ExampleTest.php` expected `200` at `/`, but `/` has always redirected to `/dashboard`).
- **Tests:** `MemberSearchTest.php`

### 2.3 User Management enhancements
- **Email**: already existed, validation strengthened (RFC + MX/DNS check, skipped automatically in the `testing` environment).
- **Auto-generated User Code**: `ALSC-######`, system-assigned, never admin-entered.
- **Account status (Active/Inactive)**: new `is_active` column, default `true`.
- **Action column**: Deactivate/Reactivate per user, with confirmation. Existing hard-Delete button kept as a separate, secondary action (not removed).
- **Auth enforcement**: inactive accounts blocked at login *and* mid-session (new `EnsureUserIsActive` middleware signs out an already-logged-in user the moment they're deactivated).
- **Files:** `User.php`, `StoreUserRequest`, `UpdateUserRequest`, `UserController`, `EnsureUserIsActive` middleware, `AuthenticatedSessionController` (login check), `users/index.blade.php`, migrations `is_active` + `user_code` (with backfill for existing rows).
- **Tests:** `UserManagementTest.php`

### 2.4 Member Management enhancements
- **Email field**: added to Members (required on create, optional-but-validated on edit — legacy members without one aren't broken).
- **Member code**: auto-generates `ALSC-######` if left blank; admin can also type their own code (validated for uniqueness).
- **Status actions, two levels**:
  - *Individual*: Deactivate/Reactivate button per row in the Action column.
  - *Bulk*: Activate/Deactivate buttons for multi-selected members (checkboxes), alongside the existing "Generate this year's benefit period" bulk action.
- **Files:** `Member.php` (email, `generateUniqueCode()`, shared `filtered()` query scope), `StoreMemberRequest`, `UpdateMemberRequest`, `MemberController` (`store()`, `updateStatus()`), `MemberBulkActionController`, views (`create.blade.php`, `index.blade.php`, `show.blade.php`, `_results.blade.php`).
- **Shared helpers introduced:** `App\Support\UniqueCodeGenerator` (used by both User and Member code generation), `App\Http\Requests\Concerns\HasEmailRule` (used by all 4 Store/Update requests that touch email).
- **Tests:** `MemberManagementTest.php`

### 2.5 Bug fix — individual Deactivate/Reactivate button (405 error)
- Root cause: the per-row action `<form>` was nested inside the outer bulk-select `<form>` — invalid HTML. Browsers silently merged the inner form's fields into the outer one, so clicking it submitted to `/members/bulk-action` (POST-only) instead of `/members/{id}/status`.
- Fixed by replacing the nested `<form>` with a JS function (`submitMemberStatus()`) that builds and submits a standalone form outside the DOM tree.

### 2.6 Department Management
- Found **already fully built** during inspection (not new work this session): `DepartmentController`, `StoreDepartmentRequest`/`UpdateDepartmentRequest`, routes, the `departments.division_id` migration, and the "Department Management" card on the Users page (ID / Department / Division / Action with Edit + Delete-with-confirm).
- Verified the Members page's Division/Department dropdowns already auto-populate from live queries — no extra work needed for a newly added department to appear there.
- **Tests added this session:** `DepartmentManagementTest.php` (was previously untested).

### 2.7a Bug fix — apply date / deduction start date were not required
- `StoreMemberRequest`/`UpdateMemberRequest` had both as `nullable`, and the Add/Edit Member forms had no `required` attribute — a member could be saved with neither date set, which silently breaks benefit-period generation later (`MemberController::generateBenefitPeriod()` already refused to run without `deduction_start_date`, but nothing stopped you from getting into that state).
- Changed both fields to `required` in both Form Requests, and added `required` + a visible `*` to both date inputs on `members/create.blade.php` and the edit-member modal on `members/show.blade.php`.
- Note: this now also applies when *editing* an existing member — a legacy member saved before these fields existed (e.g. via CSV import, which is a separate code path and is unaffected by this change) will need both dates filled in the first time anyone edits them, even for an unrelated field change like fixing a typo'd name.
- The GHP-amount business rules you'd expect alongside this (₱300/mo single → ₱3,600/yr, ₱350/mo with an eligible dependent → ₱4,200/yr, recalculated automatically whenever a dependent is added/edited/removed, and pro-rated by month from `deduction_start_date` within the Apr–Mar/Jun–May coverage year) were already fully implemented in `BenefitAccrualService` — no changes needed there.
- **Tests:** `MemberManagementTest.php` (apply_date/deduction_start_date required on create and update), `BenefitAccrualServiceTest.php` (proration regression case)
- **Unrelated pre-existing bug found while running the suite:** two `MemberManagementTest` tests (`test_an_admin_can_deactivate_an_active_member`, `test_individual_row_action_only_affects_the_one_member`) called the deactivate endpoint without a `resignation_date`, but `UpdateMemberStatusRequest` requires one when deactivating an active member (see 2.5/`MemberDeactivationTest`) — those tests were never updated when that rule was added, so they were failing (asserting the member deactivated when validation had actually rejected the request). Fixed by sending `resignation_date` in both.

### 2.8 Test coverage for the financial-data controllers (Known Issues gap)
- Added feature tests for the three controllers flagged as the riskiest untested gap (they touch GHP fund balances directly):
  - **`ReimbursementController`**: filing, editing, void/unvoid, the benefit-period balance refresh after each action, the OR-date -> benefit-period linking, and the "claim exceeds available balance" capping behavior.
  - **`AmountAdjustmentController`**: setting a manual override (and that it sticks via `ghp_amount_is_manual`), reverting to automatic (base vs. dependent-driven rate), the adjustment-history row written each time, and the benefit-period refresh that follows.
  - **`DataQualityController`**: access control (admin-only), and each of the three live-DB detectors (corrupted benefit-period dates, ₱0-amount active members, active members missing a benefit period) — both the flagged and not-flagged cases.
- Not covered: `ImportLegacyGhpData` (the console import command) — still open, see Known Issues.
- **Tests:** `ReimbursementManagementTest.php`, `AmountAdjustmentManagementTest.php`, `DataQualityReportTest.php`
- **Not yet run** — written from reading the controllers/routes/requests/models directly; run `php artisan test` to confirm they pass before relying on them.

### 2.9 Coverage Year History → Reimbursement drill-down + printable receipt
- Found **already built** (not this session, no prior task.md entry — done on another Claude surface): `reimbursements.benefit_period_id` FK (migration `2026_09_21_100000_add_benefit_period_id_to_reimbursements_table.php`, with backfill), `BenefitPeriodController::reimbursements()` (server-side, ID-filtered drill-down), `resources/views/benefit-periods/reimbursements.blade.php`, and the company-letterhead partial `reports/pdf/partials/company-header.blade.php` (your logo, already base64-embedded).
- **Bug fixed this session**: `BenefitPeriodController::reimbursementsPdf()` referenced `reports.pdf.reimbursement-receipt`, which didn't exist — Print/Download PDF would throw `ViewNotFoundException`. Created `resources/views/reports/pdf/reimbursement-receipt.blade.php` (A4, company header, coverage period, member code/name, date generated, generated-by, reimbursement table, totals summary, empty-state).
- **Not yet run** — `php artisan migrate` needs to be run for the `benefit_period_id` column if it hasn't been already; the Print/PDF fix hasn't been manually verified.

### 2.10 Bug fix — stale tests in `BenefitAccrualServiceTest.php` testing superseded behavior
- Root cause: `countAccruedMonths()` was rewritten during the Sep 2026 Deduction Date rework to be purely calendar-month based (no day-of-month cutoff) — see the SUPERSEDED note in `BenefitAccrualService`'s class docblock — but `tests/Unit/BenefitAccrualServiceTest.php` was never updated: 3 tests still asserted the old "day >= 15" per-month cutoff behavior with stale expected values, and two of them (`..._after_the_15th_prorates...`) fed the service a raw mid-month `deduction_start_date` (e.g. `2026-06-20`), which never happens in real usage — `resolveDeductionStartDate()` always resolves to the 1st of the following month before it's ever stored.
- Fixed by: rewriting the day-of-month test into one that demonstrates day-of-month is now irrelevant (both day 10 and day 20 count their month fully), and changing the two mid-year proration tests to use a correctly-resolved `deduction_start_date` (`2026-07-01`, i.e. what a June 20 start actually resolves to) — expected values (9 months, ₱2,700 / ₱3,150) were already correct once the input matches reality. Also corrected the class-level docblock, which claimed this cutoff rule was validated elsewhere via `php artisan ghp:validate-accrual` instead of with fixtures here — that claim was itself stale (the rule no longer exists to validate).
- **Tests:** `BenefitAccrualServiceTest.php`
- **Not yet run** — fixed from reading the service and test code directly; run `php artisan test` to confirm.

### 2.11 Benefit Period Year History — Void + Delete + search/filter (ADDED, THEN REVERTED)
- **REVERTED 2026-09-24, per explicit client request.** This whole feature — Void action, Delete action, search box, Status filter — was built (as described below), then taken back out the same day. The Benefit Period system is back to a strict **one-generation-per-GHP-cycle** rule: once a cycle's period is generated, there is no void, no delete, no regenerate, no manual reset. It becomes generatable again only when the next Apr–Mar (or Jun–May, Agents) cycle actually starts. See §2.13 below for exactly what the revert touched and why. The description that follows is kept for history/context only — none of it reflects the current system.
- ~~Added a **Void** action for an active/ongoing Benefit Period (member page → "Benefit periods" card): marks it `is_voided` instead of deleting it, so it stays on file for history/audit but no longer counts as "already generated" for that cycle — "Generate this year's benefit period" becomes available again immediately, and generating creates a brand-new active row alongside the voided one (never two active rows for the same cycle).~~
- ~~Added a **Delete** action, but only for already-voided periods — enforced server-side (`BenefitPeriodController::destroy()`, `422` if the target isn't voided), not just by hiding the button. Safe to hard-delete: `reimbursements.benefit_period_id` is `nullOnDelete()`, and `benefit_ledger` has no FK to `benefit_periods` at all — no reimbursement, deduction, or member record is ever touched.~~
- ~~Added a **search box + Status filter** (All / Ongoing / Voided) above the table, implemented client-side.~~
- ~~**Files (now reverted):** migration `2026_09_24_100000_add_void_fields_to_benefit_periods_table.php`, `BenefitPeriod.php` (`void()`, `scopeOngoing`/`scopeVoided`, `voidedBy()`), `VoidBenefitPeriodRequest.php`, `BenefitPeriodController::void()`/`destroy()`, two routes (`members.benefit-periods.void`/`.destroy`), `members/show.blade.php` (Status + Action + Created columns, search/filter bar, Void/Delete confirmation modals).~~

### 2.13 Revert of §2.11 — back to strict one-generation-per-cycle
- Removed everything §2.11 added: the Void button, Delete button, search box, and Status filter are gone from the "Benefit periods" card on the member page, which is back to its original plain table (Period / GHP amount / Used / Available, no Action column, no admin-only gating on it — that gating never existed there before §2.11 either).
- `BenefitPeriodController::void()`/`destroy()` removed entirely. `VoidBenefitPeriodRequest.php` and `tests/Feature/BenefitPeriodManagementTest.php` moved to `_removed-by-claude/` (can't truly delete files from here — see that folder's existing note). The two routes (`members.benefit-periods.void`/`.destroy`) removed from `routes/web.php`.
- `BenefitPeriod.php`: `void()`, `scopeOngoing()`/`scopeVoided()`, `voidedBy()`, and the `is_voided`/`voided_at`/`voided_reason`/`voided_by` fillable/casts/activity-log entries all removed — back to exactly its pre-§2.11 shape.
- `BenefitAccrualService::accrue()`'s `updateOrCreate()` match and `calculate()`'s prior-period (carry-forward) lookup both had their `is_voided = false` filters removed. `ReimbursementController::linkToBenefitPeriod()`'s period match had the same filter removed. `MemberController::show()`'s `$currentBenefitPeriod`/`$currentCyclePeriod` and `generateBenefitPeriod()`'s "already exists" check all had their `is_voided` filters removed — every one of these is back to exactly what it was before §2.11, since the column they were filtering on no longer exists.
- **Database — handled carefully, per "preserve existing data":** the original §2.11 migration's first run had already failed partway through in this environment (MySQL error 1553 dropping the old unique index) — and since MySQL's `ALTER TABLE` statements auto-commit individually rather than as one transaction, the column/FK additions that ran *before* that failure may already be sitting in the live database even though the migration itself was never recorded as completed. To handle that safely regardless of which partial state the DB is actually in:
  1. Rewrote `2026_09_24_100000_add_void_fields_to_benefit_periods_table.php` (same file, same timestamp — it was never recorded as having run) to be fully idempotent: every column/index add or drop now checks first via `Schema::hasColumn()`/`Schema::getIndexes()`, so it safely finishes to a known state (void columns present, old unique dropped) whether it previously ran fully, partially, or not at all.
  2. Added a new migration, `2026_09_24_200000_revert_void_fields_from_benefit_periods_table.php`, that undoes it: restores the `unique(member_id, from_date, to_date)` constraint (this IS the strict one-per-cycle rule at the DB level — no void, delete, or regenerate can ever get around it once it's back), then drops `is_voided`/`voided_at`/`voided_reason`/`voided_by` and their supporting index/FK. Also idempotent throughout, and index-ordering-safe (adds the unique back before dropping the plain index that was standing in as the FK's backing index — the same MySQL 1553 issue in reverse).
  3. **Duplicate handling:** if any member was actually voided-then-regenerated while this feature was live, they'd now have two rows sharing the same `member_id`/`from_date`/`to_date` — which would make restoring the unique constraint fail. The revert migration only removes a voided row when a confirmed ACTIVE sibling exists for the exact same member+cycle (a genuinely superseded, abandoned row); a voided row with no active sibling is left completely untouched and simply becomes an ordinary row once `is_voided` is dropped — correctly blocking that cycle from regeneration under the new strict rule, same as if Void had never existed. Any other ambiguous case (e.g. two rows both still voided) is deliberately left alone rather than guessed at, so the unique-constraint step fails loudly with a clear DB error instead of silently discarding data.
- **Not yet run** — written from reading the current controllers/models/service/migrations directly. Run, in order:
  ```
  php artisan migrate
  php artisan test
  ```
  Both migrations above will run in the same `migrate` call (in filename order, `...100000` then `...200000`) if neither has run yet. If `...100000` already succeeded on its own in this environment before this revert, only `...200000` will apply. Watch specifically for a unique-constraint error from `...200000` — if that happens, it means the ambiguous-duplicate case above was hit and needs a manual look at the flagged `member_id`/`from_date`/`to_date` group before the migration can complete. Also worth re-running `ReimbursementManagementTest`/`AmountAdjustmentManagementTest`, since `BenefitAccrualService` changed again.

### 2.12 Dependent eligibility now waits for the next benefit period
- **Business rule changed (intentional):** adding a dependent no longer raises the member's GHP amount immediately. Previously, `DependentController::store()` recalculated `members.ghp_amount` the instant a dependent was saved, based only on the age/relation rule (`Dependent::is_eligible`) — no awareness of *when* it was added. Now a dependent added during an ongoing benefit period is recorded and shown right away, but only starts counting toward the higher (₱4,200) rate once the *next* coverage cycle begins.
- **How it works:** `dependents` gets two new nullable columns, `date_added` and `eligibility_date`. `DependentController::store()` sets `date_added` = today and `eligibility_date` = the start of the *next* coverage cycle (via new `BenefitAccrualService::resolveDependentEligibilityDate()`, which reuses the existing `coveragePeriod()` Apr–Mar/Jun–May logic). `BenefitAccrualService::resolveGhpAmount()` now takes an optional `$asOf` and checks each dependent through new `Dependent::isGhpEligibleAsOf($asOf)` (age/relation rule AND, if set, `$asOf >= eligibility_date`) instead of the age rule alone. `calculate()` passes its own `$asOf`; `requiredAmountForCycle()` evaluates at the cycle's own end date, since eligibility is an all-or-nothing-per-cycle rule, not a daily one.
- **Nothing retroactive, nothing backfilled on purpose:** `eligibility_date` is left `NULL` for every dependent already on file, and for any dependent created directly via Eloquent rather than through the real "Add dependent" form (tests, factories, `ImportLegacyGhpData`). `isGhpEligibleAsOf()` treats `NULL` as "not time-gated" — always eligible, exactly like before this change. Only dependents added through the app from now on get the pending-until-next-cycle treatment. No migration backfill was needed as a result.
- **Why no `benefit_period_id` FK on Dependent:** eligibility is purely calendar/cycle-boundary math (same Apr–Mar/Jun–May logic as everything else), independent of whether a `BenefitPeriod` row has actually been generated yet for that cycle — linking to a specific row would have created null-period edge cases for no benefit.
- **UI:** Dependents table on the member page now shows Date added, a Status badge (Eligible / Pending Eligibility / Not eligible), and Eligible period columns, plus a note on the Add/Edit dependent modal.
- **One existing test intentionally changed:** `MemberBenefitSetupTest::test_adding_an_eligible_dependent_after_saving_recalculates_ghp_amount_without_resetting_dates` asserted the *old* (now-incorrect) immediate-bump behavior — replaced with `test_adding_a_dependent_mid_cycle_does_not_immediately_raise_the_ghp_amount` plus a companion test proving the same dependent becomes eligible once evaluated in the next cycle. All other dependent-touching tests (`AmountAdjustmentManagementTest`, `BenefitAccrualServiceTest`'s existing cases) create dependents directly via Eloquent, so they're unaffected by the `NULL`-safe default.
- **Files:** migration `2026_09_24_110000_add_eligibility_fields_to_dependents_table.php`, `Dependent.php` (`date_added`/`eligibility_date` casts+fillable, `isGhpEligibleAsOf()`, `eligibility_status` accessor), `BenefitAccrualService.php` (`resolveGhpAmount($asOf)`, `resolveDependentEligibilityDate()`, `calculate()`/`requiredAmountForCycle()` updated), `DependentController::store()`, `members/show.blade.php` (Dependents table + modal hint).
- **Tests:** `MemberBenefitSetupTest.php` (updated + new), `BenefitAccrualServiceTest.php` (new: eligibility-date calculation for both member types, gating before/after the date, multiple-pending-dependents, `NULL`-eligibility-date safety net).
- **Not yet run** — written from reading the controllers/models/service/views directly; run `php artisan migrate` (new migration) then `php artisan test` to confirm, paying particular attention to `MemberBenefitSetupTest` and `BenefitAccrualServiceTest`.

### 2.14 Immediate Eligibility for dependents + spouse via Edit Member
- **Immediate Eligibility button** on the Dependents table: an administrator-controlled exception to the normal "wait for the next Benefit Period" rule. Shown only while a dependent's status is `pending`; opens a confirmation modal (dependent, relationship, current benefit period, normal vs. immediate eligibility) and changes nothing until confirmed.
- **How it works:** no second eligibility mechanism. Granting sets that one dependent's existing `eligibility_date` to today, so `Dependent::isGhpEligibleAsOf()` / `BenefitAccrualService::resolveGhpAmount()` pick it up through the existing path. New nullable columns `immediate_eligibility_at` / `immediate_eligibility_by` record that (and by whom) the waiting rule was bypassed; status shows as "Immediate Eligible".
- **Backend validation** (`DependentEligibilityService::grantImmediate()`, under a row lock): admin only, dependent must belong to the member, must still be `pending` (already-eligible, already-immediate and age-ineligible dependents are rejected). One activity-log entry is written (action, dependent, member, processed by/at, current benefit period, normal vs. new eligibility date).
- **Benefit periods untouched:** no generate/reset/reopen, no change to Coverage Year History, Generate button rule unchanged. Only `members.ghp_amount` is re-synced (same as Add Dependent); the existing `benefit_periods` row is not rewritten. Reimbursement balance checks use the live calculation, so they see the new eligibility straight away.
- **Edit Member modal:** choosing Civil status = Married reveals a "Dependent Spouse" section (name, birthday, relationship, Normal/Immediate eligibility — Normal by default). Required only when the edit is what changes the member to Married. Saved as an ordinary dependent (relation = Spouse) with the normal eligibility rule, in the same transaction as the member. An existing spouse is never duplicated (message shown instead); changing away from Married never deletes the spouse.
- **Fields reused:** the Dependents schema has a single `name`, `relation`, `birthdate` — the spouse uses exactly those; no separate spouse structure.
- **Files:** migration `2026_09_24_120000_add_immediate_eligibility_to_dependents_table.php`, `Dependent.php`, new `Services/DependentEligibilityService.php`, `DependentController` (`immediateEligibility()`; `store()` now uses the shared service, behavior unchanged), `UpdateMemberRequest`, `MemberController::update()`, `routes/web.php` (`members.dependents.immediate-eligibility`), `members/show.blade.php`.
- **Tests:** `ImmediateEligibilityTest.php`.
- **Not yet run** — run `php artisan migrate` then `php artisan test`.

### 2.15 Fix — "Generate this year's benefit period" button not clickable
- **Root cause:** the enabled button called `generateBenefitPeriodModal.showModal()` but no `<dialog id="generateBenefitPeriodModal">` existed anywhere in `members/show.blade.php` (or the layout) — the click threw a `ReferenceError` and did nothing. The enabled/disabled condition itself (`$currentCyclePeriod`, exact Apr–Mar range match) was correct. The confirmation modal was evidently lost in an earlier edit/revert.
- **Fix (view):** restored the confirmation modal (only rendered while the current cycle has no period), added double-submit protection ("Generating…", ignores repeat submits, auto-restores on Back/bfcache or after 20s if the request never completes). After a successful POST the page reloads and the button is disabled from the DATABASE state; after a rejected/failed POST it stays enabled for a retry. Non-admins now see a disabled button instead of one that could only 403.
- **Second cause fixed (backend):** `AmountAdjustmentController::refreshCurrentPeriod()` called `accrue()` (`updateOrCreate`), so adjusting/reverting a GHP amount silently GENERATED the current period and disabled the button. It now calls new `BenefitAccrualService::refreshCurrentPeriodIfGenerated()`, which only refreshes a period that already exists.
- **Duplicate prevention (unchanged core, one backstop added):** `MemberController::generateBenefitPeriod()` already checked for an existing period for the exact cycle inside a locked transaction, backed by the unique index on `(member_id, from_date, to_date)`; it now also catches `UniqueConstraintViolationException` and reports it as "already generated".
- **Tests:** new `GenerateBenefitPeriodButtonTest.php`; in `AmountAdjustmentManagementTest.php` the old `..._refreshes_the_current_benefit_period` test (which asserted the side-effect creation) is now two tests — refresh an existing period / never create a missing one.
- **Known, deliberately NOT changed (out of scope):** `ReimbursementController::refreshCurrentPeriod()` still uses `accrue()`, so filing/editing/voiding a reimbursement can still create the current period; the daily `ghp:auto-generate-benefit-periods` job and the Members-list bulk "Generate" action also create periods by design — if the scheduler is running, every active member's current period will already exist and the button will correctly show disabled.
- **Not yet run** — run `php artisan test`.

### 2.16 (SUPERSEDED — see §2.17) Fix — Available GHP was retroactively inflated when a dependent became GHP-eligible mid-period
> **2026-09-24: the calculation change described in this section was reverted the same day, per explicit client request — see §2.17 below. This section is kept for history only; it does not describe current behavior.**
- **Root cause:** `BenefitAccrualService::calculate()` computed the whole coverage period's accrued fund as `(ghp_amount / 12) * months_accrued` — a single FLAT rate (whatever `resolveGhpAmount()` returned as of TODAY) multiplied across every month elapsed so far. The moment a dependent became GHP-eligible (normal next-cycle rollover, or an admin's Immediate Eligibility grant), every already-elapsed month silently got recalculated at the new, higher rate too — e.g. 3 months already accrued at ₱300/mo (₱900 total) would jump to ₱1,050 (3 × ₱350) the instant eligibility changed, even though nothing about those 3 months' history had actually changed. This is exactly the bug described in the "Fix Benefit Balance, Dependents, and Benefit Period GHP Calculation" request: **Required GHP is allowed to increase; Available GHP must never increase merely because a dependent was added/became eligible.**
- **The fix:** new private `BenefitAccrualService::accruedAmount()` walks the coverage period one calendar month at a time and asks `resolveGhpAmount($member, $monthCutoff)` what rate was ACTUALLY in effect as of THAT month's own end, summing each month at its own historically-correct rate instead of one flat rate for the whole span. `calculate()` now calls this instead of the flat multiplication. Nothing else in the accrual math changed — carry-forward, reimbursement "used" totals, and the negative-balance floor are untouched.
- **How Required GHP is calculated:** unchanged — `requiredAmountForCycle()` (member page's "GHP monthly amount" / "Applicable months" / "Required GHP amount" ledger strip) evaluates `resolveGhpAmount()` as of the CYCLE'S OWN END, since eligibility is all-or-nothing-per-cycle — this is deliberately allowed to reflect a dependent's new eligibility going forward.
- **How Available GHP is now preserved:** `calculate()`'s `accrued`/`available` figures (member page's "Benefit balance" ledger strip, and every reimbursement-gating check via `availableBalanceFor()`) only ever apply a rate change from the month it actually took effect onward — already-elapsed months keep whatever rate was true for them at the time, forever. No backfill, no retroactive top-up, nothing added to a persisted `BenefitPeriod` row just because eligibility changed.
- **How dependent eligibility (Normal path) and Immediate Eligibility both interact with this:** neither mechanism needed to change — both simply set/advance a dependent's existing `eligibility_date` (see §2.12/§2.14 below), and `accruedAmount()`'s month-by-month evaluation of `isGhpEligibleAsOf()` automatically produces the correct "only from the effective month onward" result for either path without any special-casing.
- **Files modified this pass:** `DependentController::store()/update()/destroy()` — wrapped the dependent write + `syncGhpAmount()` re-sync in `DB::transaction()` (per the request's transaction-safety requirement) so a member is never left with a saved/edited/removed dependent but a stale `ghp_amount` if the second write ever failed; this is the only functional change made in this pass. The `accruedAmount()` calculation fix itself, the `requiredAmountForCycle()` split from `calculate()`, and the Benefit Balance UI split (GHP amount/Used/Available vs. GHP monthly amount/Applicable months/Required GHP amount, both in `members/show.blade.php`) were already in place from earlier work (§2.12–§2.15) — this pass verified they fully satisfy the request and closed the one gap found (transaction wrapping).
- **Database changes:** none required — no new fields, no backfill. The fix is calculation-only.
- **Tests:** already covered in full before this pass — `BenefitAccrualServiceTest::test_dependent_becoming_eligible_mid_period_does_not_retroactively_inflate_already_accrued_months` and `::test_available_balance_for_reimbursement_does_not_inflate_when_a_dependent_becomes_eligible_today` are dedicated regression tests reproducing the spec's exact worked example (₱900 → ₱1,950 correct, NOT ₱2,100 buggy); `ImmediateEligibilityTest::test_granting_does_not_touch_the_benefit_period_or_reopen_generation` covers the spec's Test 7 (Benefit Period untouched); `MemberBenefitSetupTest` covers the no-immediate-bump / next-cycle-eligibility pair; multiple-pending-dependents is covered in `BenefitAccrualServiceTest`. No new test file was needed for this pass — only the transaction-wrapping change above, which is implicitly covered by the existing `DependentController`-exercising tests continuing to pass (they assert the end state, which is unchanged by wrapping the same two writes in a transaction).
- **Not yet run** (the transaction-wrapping change specifically) — run `php artisan test` to confirm; everything else in this section was already verified passing as part of §2.12–§2.15.

### 2.17 Revert of §2.16 — restored the flat-rate accrual calculation, per explicit request
- **Request:** "Revert Recent Benefit Balance / Dependent GHP Calculation Update" — explicitly asked to undo §2.16 and restore the calculation that existed before it, confirmed after I flagged that §2.16 was logged as a deliberate bug fix (I asked directly whether the retroactive-inflation behavior was really wanted back; confirmed "yes").
- **What changed back:** `BenefitAccrualService::accruedAmount()` (private, called from `calculate()`) no longer walks the coverage period month-by-month at each month's own historically-correct rate. It's back to a single flat calculation: `(resolveGhpAmount($member, $asOf) / 12) * countAccruedMonths(...)` — TODAY's rate applied across every elapsed month. Known, confirmed-intentional consequence: the moment a dependent becomes GHP-eligible (normal cycle rollover or an Immediate Eligibility grant), Available GHP for the CURRENT, in-progress coverage period jumps up to reflect the new rate applied retroactively across already-elapsed months — e.g. ₱900 accrued at ₱300/mo over 3 months becomes ₱1,050 the instant eligibility changes, not because anything new was earned, but because the flat-rate formula recomputes the whole span at the new rate.
- **What did NOT change:** `requiredAmountForCycle()` (Required GHP / GHP monthly amount / Applicable months) — this was already evaluated at the cycle's own end date before §2.16 and still is; it was never part of what §2.16 changed. `resolveGhpAmount()`, `coveragePeriod()`, `carryForward()`, `countAccruedMonths()`, the reimbursement "used" sum, and the negative-balance floor are all untouched — only the one accrual-summing calculation inside `accruedAmount()` changed. Benefit Period generation (one-per-cycle rule, §2.15's fix), Immediate Eligibility (§2.14), Normal dependent eligibility timing (§2.12), spouse/Married-status handling (§2.14), and the scrollable Amount Adjusted History / Recent Activity UI are all untouched — none of them depend on which accrual formula `calculate()` uses internally.
- **Database changes:** none. Calculation-only, exactly as §2.16 was.
- **Files:** `app/Services/BenefitAccrualService.php` (`accruedAmount()` body + surrounding comments), `tests/Unit/BenefitAccrualServiceTest.php` (the two §2.16 regression tests were inverted to assert the restored flat-rate figures — ₱2,100 instead of ₱1,950, ₱1,050 instead of ₱950 — rather than deleted, so a future accidental reintroduction of the month-by-month calculation still fails a test immediately).
- **Verification:** re-read `resolveGhpAmount()`, `requiredAmountForCycle()`, `DependentEligibilityService::grantImmediate()` (confirmed it only calls `syncGhpAmount()`, which touches `members.ghp_amount` only — it does not call `accrue()`/`calculate()`, so granting Immediate Eligibility does not itself recompute an existing `BenefitPeriod` row; the new flat-rate figure is only realized the next time something already calls `accrue()`, e.g. the Generate button, a reimbursement, or an amount adjustment on an already-generated period) and `ImmediateEligibilityTest`/`MemberBenefitSetupTest` (confirmed neither asserts on `accrued`/`available` figures, so the revert doesn't break them) before making the change.
- **Not yet run** — run `php artisan test`, specifically `BenefitAccrualServiceTest` and `AmountAdjustmentManagementTest` (both exercise `calculate()`/`accrue()` directly).

### 2.20 Documentation sync — BRD.md/Progress.md brought current, standing sync policy adopted
- **Request:** confirm the Available GHP request (§2.19's request, resubmitted) is handled, and going forward keep `BRD.md`/`Progress.md`/`task.md` in sync with every project change.
- **Verified §2.19's finding again independently** before touching documentation: re-read `BenefitAccrualService.php` in full and `tests/Unit/BenefitAccrualServiceTest.php` in full. Confirmed `accruedAmount()` is the flat-rate formula (`resolveGhpAmount($member, $asOf) / 12 × countAccruedMonths(...)`) and that `test_available_ghp_equals_months_rendered_times_applicable_monthly_rate_scenarios_a_through_e` and `test_available_ghp_increases_by_exactly_150_when_a_dependent_becomes_eligible_after_3_months_rendered` already assert exactly the request's worked examples (months × rate, dependent count never multiplying, the +₱150 case). No code or test changes made this pass — purely a verification + documentation pass.
- **`BRD.md` and `Progress.md` were stale** — both were last written 2026-09-24 but before §2.14–§2.19 happened (Immediate Eligibility, the Generate-button-not-clickable fix, the §2.16→§2.17 accrual-formula reversal, and the §2.18/§2.19 verification passes). Updated both to reflect current reality: `BRD.md` — new FR-DEP-006 (Immediate Eligibility), new BR-020/BR-021 (flat-rate Available GHP formula, dependent count never multiplies the tier), FR-BEN-005/FR-BEN-007 status notes. `Progress.md` — Completed Features, Current Tasks, and Testing Status sections updated to include §2.14–§2.19; the "Available GHP does not increase" framing left over from when §2.16 was the latest state was corrected to the current, opposite, confirmed-intentional behavior.
- **Adopted a standing rule** (see the note at the top of this file): every future change to this project updates all three docs in the same pass, not `task.md` alone. This applies regardless of which Claude surface (chat, Claude Code, etc.) makes the change.
- **Files:** `/BRD.md`, `/Progress.md`, `task.md` (this entry + the standing-rule note at top). No application code touched.
- **Not applicable to "not yet run"** — this was a documentation-only pass; the outstanding `php artisan migrate`/`php artisan test` items from earlier sections are unchanged and still pending.

### 2.7 Removed (per explicit request)
- **Member export (CSV/PDF/Excel)**: was built in full, then removed at your request. Routes, view buttons, and the controller/PDF view were deleted from active use — the controller and PDF view are sitting in `_removed-by-claude/` at the project root (I can't truly delete files, only move/overwrite them; delete that folder yourself whenever convenient).
- The shared `StreamsCsv` trait was **kept** — it's still used by the pre-existing Reports page exports (Annual GHP, Reimbursements), which were never part of this request.

### 2.18 Verified — GHP amount was never multiplied by dependent count (no code change needed)
- **Request:** "correct" the Benefit Balance/Dependents logic so the ₱4,200 GHP tier is never multiplied by dependent count (2 dependents ≠ ₱8,400, etc.) — the concern was that a previous instruction may have caused this.
- **Finding, after tracing every place the GHP amount could possibly be computed** (`BenefitAccrualService::resolveGhpAmount()` — the single call site for this decision, plus `DependentEligibilityService::syncGhpAmount()`, `DependentController`, `AmountAdjustmentController::revertToAutomatic()`, `ReportController` (reads persisted `ghp_amount`/`ghp_available` only, never recomputes), and `MemberCsvImportService`/`ImportLegacyGhpData` (take `ghp_amount` straight from source data, no formula at all)): **the multiplication bug does not exist and never did.** `resolveGhpAmount()` has always used `Collection::contains()` — a boolean "is at least one dependent eligible" check — not `count() * 4200`. There is exactly one call site for this decision app-wide; nothing duplicates or diverges from it.
- **No production code changed.** Per the request's own "Test Cases" section, added permanent regression coverage that didn't exist yet (previous tests only covered 0, 1, and *multiple-but-simultaneously-PENDING* dependents — never multiple dependents eligible AT THE SAME TIME, never a repeated-evaluation/determinism check, never a removal-down-to-zero or removal-of-one-of-several scenario):
  - 2, then 3-and-5, simultaneously eligible dependents → still exactly ₱4,200, explicitly asserting NOT ₱8,400/₱12,600/₱21,000
  - `resolveGhpAmount()` called 5x in a row for the same state → identical ₱4,200 every time (deterministic, no accumulation)
  - Two eligible dependents → remove one → still ₱4,200 (one remains) → remove the last → falls back to ₱3,600
  - A child dependent aging out past 21 while a spouse remains eligible → stays ₱4,200
- **Files:** `tests/Unit/BenefitAccrualServiceTest.php` only (5 new test methods). No app code, no migration.
- **Not yet run** — run `php artisan test` to confirm.

### 2.19 Verified — Available GHP already equals months rendered × applicable monthly rate (no code change needed)
- **Request:** correct Available GHP so it's calculated as `Months Rendered × Applicable Monthly GHP` (₱300 or ₱350 depending on dependent eligibility), preserving the existing April–March period and month-rendering rules, including the worked example where 3 already-rendered months at ₱300 become 3 × ₱350 = ₱1,050 (a +₱150 jump) the instant a dependent becomes eligible.
- **Finding:** this is precisely the current, live calculation — the same flat-rate formula restored in §2.17 (`accruedAmount()`: `round((resolveGhpAmount($member, $asOf) / 12) * countAccruedMonths(...), 2)`), which already is `months rendered × applicable monthly rate` using the project's one existing month-rendering calculation (`countAccruedMonths()`, clamped to `deduction_start_date`/cycle start) and the one existing tier check (`resolveGhpAmount()`, boolean — see §2.18). No second/duplicate calculation exists anywhere. **No production code changed.**
- **Added regression coverage tracing directly to the request's own worked examples** (previously the flat-rate tests covered the general mechanism but not these specific numbers):
  - Scenarios A–E (1 month/0 dependents=₱300; 3 months/0 dependents=₱900; 3 months with 1, 2, and 5 simultaneously-eligible dependents all =₱1,050, never ₱2,100/₱5,250) via `calculate()` end-to-end, not just the tier check in isolation.
  - The exact "+₱150" example: 3 months rendered at the base rate (₱900) re-evaluated at the SAME point in time after a dependent becomes eligible → exactly ₱1,050, an increase of exactly ₱150 (`3 × (350−300)`).
- **Files:** `tests/Unit/BenefitAccrualServiceTest.php` only (2 new test methods). No app code, no migration.
- **Not yet run** — run `php artisan test` to confirm.

---

## 3. Known issues / technical debt (not yet fixed)

These were flagged during analysis but intentionally left alone (out of scope for the request that surfaced them, or too invasive to fix as a drive-by change):

| Issue | Where | Notes |
|---|---|---|
| No rate limiting on login | `routes/web.php` | `POST /login` has no `throttle` middleware — brute-force risk |
| `.env.example` missing legacy import vars | `.env.example` | `LEGACY_DB_*` vars used by `ImportLegacyGhpData`/`config/database.php` aren't documented |
| No self-service "forgot password" flow | Auth | Only login exists; a locked-out user needs an admin to reset them manually |
| Password change doesn't invalidate other sessions | `ProfileController::updatePassword`, `UserController` | A stolen session stays valid after a password reset |
| `email_verified_at` column is unused | `users` table | Present in schema, nothing sets or checks it |
| Thin test coverage | `ImportLegacyGhpData` | No tests yet — the console import command. `DataQualityController`, `AmountAdjustmentController`, and `ReimbursementController` now have feature test coverage (see 2.8 below); this command is what's left of the original gap |
| `agent_positions.member_code` is a string match, not a FK | `AgentPosition` | Silent breakage risk if a code is reformatted/typo'd |
| `dependents.relation` is free text, not an enum | `Dependent` | Known legacy-data bridge; a typo could silently affect eligibility logic |
| `.card-head` doesn't wrap on narrow screens | `public/css/app.css` | Deliberately not touched (shared across many pages) — worth a dedicated pass |
| Division has no admin management UI | — | Department Management (above) lets you assign an *existing* division to a department, but there's no Add/Edit/Delete for Divisions themselves — only seeded/manually-inserted ones are selectable |

---

## 4. Not implemented (no work done, flagged only)

- Division CRUD (see table above)
- Self-service password reset ("forgot password" email flow)
- Rate limiting on login
- Session invalidation on password change
- Any export functionality for Users or Departments (only Members ever had one, and it was removed)

---

## 5. File map for recent work

```
app/
  Http/
    Controllers/
      ProfileController.php
      UserController.php
      MemberController.php
      DepartmentController.php          (pre-existing, verified)
      Admin/MemberBulkActionController.php
    Middleware/EnsureUserIsActive.php
    Requests/
      UpdateProfileRequest.php, UpdatePasswordRequest.php
      StoreUserRequest.php, UpdateUserRequest.php
      StoreMemberRequest.php, UpdateMemberRequest.php
      StoreDepartmentRequest.php, UpdateDepartmentRequest.php  (pre-existing)
      Concerns/HasEmailRule.php
  Models/User.php, Member.php, Department.php, Division.php, BenefitPeriod.php, Dependent.php
  Services/BenefitAccrualService.php, DependentEligibilityService.php
  Support/UniqueCodeGenerator.php

resources/views/
  profile/edit.blade.php
  users/index.blade.php
  members/{create,index,show,_results}.blade.php
  layouts/app.blade.php

database/migrations/  (profile_photo_path, users.is_active, users.user_code,
                        members.email, departments.division_id,
                        benefit_periods void fields ADDED then REVERTED —
                        see task.md §2.11/§2.13; net effect is a no-op
                        schema-wise, original unique constraint restored)

tests/Feature/
  ProfileTest.php, MemberSearchTest.php, UserManagementTest.php,
  MemberManagementTest.php, DepartmentManagementTest.php,
  ReimbursementManagementTest.php, AmountAdjustmentManagementTest.php,
  DataQualityReportTest.php

_removed-by-claude/   (orphaned export controller + PDF view, plus
                        VoidBenefitPeriodRequest.php and
                        BenefitPeriodManagementTest.php from the reverted
                        §2.11 Void/Delete feature — safe to delete this
                        whole folder)
```
