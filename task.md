# GHP Project — Task & Implementation Log

Tracks everything built, fixed, or left open across this project so far.
Use this as a quick reference before starting new work — check "Not Implemented"
before assuming something doesn't exist, and check "Known Issues" before
re-diagnosing a problem that's already been flagged.

**Last updated:** 2026-09-17

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

### 2.7 Removed (per explicit request)
- **Member export (CSV/PDF/Excel)**: was built in full, then removed at your request. Routes, view buttons, and the controller/PDF view were deleted from active use — the controller and PDF view are sitting in `_removed-by-claude/` at the project root (I can't truly delete files, only move/overwrite them; delete that folder yourself whenever convenient).
- The shared `StreamsCsv` trait was **kept** — it's still used by the pre-existing Reports page exports (Annual GHP, Reimbursements), which were never part of this request.

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
| Thin test coverage | `DataQualityController`, `AmountAdjustmentController`, `ReimbursementController`, `ImportLegacyGhpData` | No tests at all on these — riskiest gap given they touch financial data |
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
  Models/User.php, Member.php, Department.php, Division.php
  Support/UniqueCodeGenerator.php

resources/views/
  profile/edit.blade.php
  users/index.blade.php
  members/{create,index,show,_results}.blade.php
  layouts/app.blade.php

database/migrations/  (profile_photo_path, users.is_active, users.user_code,
                        members.email, departments.division_id)

tests/Feature/
  ProfileTest.php, MemberSearchTest.php, UserManagementTest.php,
  MemberManagementTest.php, DepartmentManagementTest.php

_removed-by-claude/   (orphaned export controller + PDF view — safe to delete)
```
