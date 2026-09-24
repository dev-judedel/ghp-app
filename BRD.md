# Business Requirements Document (BRD)

**Project:** GHP — Group Health Plan Management System (`ghp-app`)
**Document version:** 1.0
**Last updated:** 2026-09-24
**Source of truth:** Actual application code, database migrations, routes, and `task.md` as of the date above.

---

## 1. Project Overview

### 1.1 Project Name
GHP Management System (internally referred to as "GHP", codebase folder `ghp-app`).

### 1.2 Project Purpose
The system administers a company **Group Health Plan (GHP)** benefit fund for **Employees** and **Agents**. It replaces a legacy system (referenced throughout the code as "the legacy system", backed by a PostgreSQL database with tables such as `t_member`, `t_process`, `t_dependents`, `t_avail_used_ghp`, `t_reimbursement`, `t_division`, `t_avp_vp`) with a modern Laravel application on a normalized MySQL schema.

The application:
- Tracks each covered member's annual GHP benefit allowance.
- Accrues that allowance monthly according to a coverage-year cycle.
- Tracks dependents and how they affect the benefit amount.
- Records and validates medical reimbursement claims against the accrued balance.
- Produces reports and printable records (Annual GHP report, Reimbursement report, Member Data Record, reimbursement receipts).
- Provides administrative tooling for user accounts, member records, departments, and data-quality auditing.

### 1.3 Business Problem Being Addressed
- The legacy system had data-integrity problems (corrupted dates, orphaned records, inconsistent/duplicated calculation logic across three different code paths, at least one confirmed calculation bug) and used a PostgreSQL-specific `ilike` operator inherited into the new MySQL-based application (later fixed — see Progress.md §Bugs Fixed).
- There was no structured audit trail for manual GHP amount overrides — history lived only as free-text remarks (e.g. `"GHP amount changed from 4200 to 26775 with IT request form dtd 2019-05-21"`).
- There was no user self-service (profile editing, password changes) and no per-account active/inactive control.
- There was no structured Department/Division management layer with FK integrity.
- There was no automated safeguard preventing a reimbursement claim from exceeding a member's remaining GHP balance.

### 1.4 Target Users / Stakeholders
| Role | Description |
|---|---|
| **Administrator** (`role = admin`) | Full access: manages members, dependents, reimbursements, amount adjustments, departments, users, benefit-period generation, CSV import, data-quality review, and reports. |
| **Staff / User** (`role = user`) | Authenticated, non-admin. Can view the dashboard, member list, member profiles, reports, coverage-year reimbursement drill-downs/receipts, and manage their own profile (name, email, photo, password). Cannot create/edit/delete members, dependents, reimbursements, users, or departments. |
| **HR / Benefits administration staff** (business stakeholder, off-system) | The intended real-world users of the Administrator role — they manage plan membership, process reimbursement claims, and respond to IT requests for manual GHP amount adjustments. |
| **Plan members (Employees and Agents)** | Not system users; they are the subject records (`members` table) whose benefits are administered on their behalf. |

### 1.5 General System Description
GHP is a server-rendered Laravel 13 (PHP 8.3) web application using Blade views (no SPA framework), a hand-written CSS design system (`public/css/app.css`, no Tailwind in Blade markup — Tailwind is present as a dependency but not actively used in views per `profile_features.md`), MySQL (production/testing) or SQLite (default local `.env.example`) as the database, `barryvdh/laravel-dompdf` for PDF report generation, and `spatie/laravel-activitylog` for a system-wide audit trail.

---

## 2. Business Objectives

Based on the current implementation, the system's objectives are to:

1. **BO-1** — Maintain an accurate, centralized digital record of all GHP plan members (Employees and Agents) and their dependents.
2. **BO-2** — Automatically and consistently calculate each member's annual/coverage-year GHP benefit entitlement, replacing three inconsistent legacy calculation implementations with one authoritative engine (`BenefitAccrualService`).
3. **BO-3** — Prevent reimbursement claims from exceeding a member's available GHP balance, enforced at the database-transaction level (not just client-side).
4. **BO-4** — Provide a structured, queryable audit trail for manual GHP amount overrides (`benefit_amount_adjustments`), replacing free-text remarks.
5. **BO-5** — Provide role-based administrative control over member records, user accounts, and organizational lookups (departments/divisions), including active/inactive lifecycle management.
6. **BO-6** — Give every authenticated user self-service control over their own account profile and password without requiring administrator intervention.
7. **BO-7** — Provide management-facing reporting (Annual GHP report, Reimbursement report, Member Data Record, coverage-year reimbursement receipts) in both on-screen and PDF/CSV form.
8. **BO-8** — Surface known data-quality problems (corrupted historical dates, zero-amount active members, members missing a current benefit period) for manual review rather than silently ignoring or auto-correcting them.
9. **BO-9** — Preserve a complete, timestamped activity log of who changed what, across members, dependents, reimbursements, benefit periods, departments, and user accounts.
10. **BO-10** — Support bulk, migration-safe import of the legacy PostgreSQL dataset into the new schema, and CSV-based bulk import of new members going forward.

---

## 3. System Scope

### 3.1 In Scope (currently implemented)

| Area | Feature |
|---|---|
| Authentication | Session-based login/logout, deactivated-account blocking (at login and mid-session), auth activity logging |
| Self-service profile | View/edit own name, email, photo (with initials-avatar fallback); self-service password change requiring current password |
| Member management | Create/edit members, auto-generated or manual unique member code (`ALSC-######`), Employee/Agent type, division/department assignment, soft-delete-capable schema (not exposed as a delete UI), Active/Inactive status with resignation date, individual and bulk Activate/Deactivate |
| Member search & filtering | Live (AJAX) search by code/last name/first name; filters by type, department, division, status; results paginated (25/page) |
| Member CSV import | Bulk member creation from an uploaded CSV against a documented template, per-row validation, skipped-row reporting, 5,000-row cap |
| Dependent management | Add/edit/delete dependents per member; age/relation-based eligibility rule; "pending until next coverage cycle" eligibility gating for dependents added mid-cycle |
| Benefit period ("Coverage Year") accrual | Automatic monthly accrual calculation, one benefit period per member per coverage cycle (strict, DB-enforced), manual "Generate benefit period" trigger, scheduled daily auto-generation job |
| GHP amount rules | ₱3,600/year base, ₱4,200/year with an eligible dependent, pro-rated by month from the deduction start date, 10% carry-forward of a prior fully-unused period |
| Manual GHP amount adjustment | Admin can override the computed GHP amount with a reason/reference, sticks through future recalculation until explicitly reverted to automatic; full adjustment history retained |
| Reimbursement management | File/edit reimbursement claims, OR number/date/amount/hospital/description/remarks, server-enforced "cannot exceed available balance" rule, void/unvoid (with reason, keeps the record visible), automatic linking to the correct coverage-year benefit period |
| Coverage Year History drill-down | View and print/download (PDF, company letterhead) the reimbursements tied to one specific benefit period |
| Department management | Create/edit/delete departments, free-text division assignment (auto-creates or reuses a Division by name) |
| Division data | Divisions exist as a lookup table (Employee/Agent-scoped) but have **no dedicated CRUD UI** — see §3.2 |
| User management (admin) | Create/edit users, auto-generated user code, role (`admin`/`user`), Active/Inactive status, hard delete, last-remaining-admin safeguards |
| Reports | Annual GHP report (PDF + CSV, filterable by type/department/division/status/year), Reimbursement report (PDF + CSV, date range + filters), Member Data Record (PDF, per-member) |
| Data Quality Report | Admin-only report flagging corrupted benefit-period dates, ₱0-amount active members, active members missing a current-cycle benefit period, and (best-effort) legacy orphan-record counts |
| Activity Log | Admin-viewable, filterable-by-log-name feed of all logged model changes and auth events |
| Legacy data import | Console command (`ghp:import-legacy`) to migrate the restored legacy PostgreSQL dump into the new schema, with best-effort extraction of amount-adjustment history from legacy free-text remarks |
| Accrual validation tooling | Console command (`ghp:validate-accrual`) comparing the new accrual engine's output against imported historical data, read-only |

### 3.2 Out of Scope (explicitly not implemented, per `task.md`)

| Feature | Status |
|---|---|
| Division CRUD (dedicated Add/Edit/Delete UI for Divisions) | Not implemented — only assignable by name via Department Management, or seeded/created via Tinker |
| Self-service "forgot password" (password-reset email flow) | Not implemented — only login exists; a locked-out user needs an admin to reset their password via the Users screen |
| Rate limiting on the login route | Not implemented — `POST /login` has no throttle middleware |
| Session invalidation on password change | Not implemented — an old session remains valid after a user changes their password |
| Member export (CSV/PDF/Excel from the Members list) | **Built, then removed** at explicit client request; the orphaned controller/view sit in `_removed-by-claude/` for potential future reference, not part of the active application |
| Export for Users or Departments | Never implemented |
| Benefit Period Void/Delete/Search/Filter (Coverage Year History) | **Built, then fully reverted** at explicit client request; the system enforces a strict one-generation-per-GHP-cycle rule with no way to void, delete, or regenerate a period once created |
| Email verification | `users.email_verified_at` column exists in the schema but nothing sets or checks it — not a functioning feature |
| Per-permission (fine-grained) authorization | Only simple two-role (`admin`/`user`) authorization exists; no permission-level granularity (e.g. `spatie/laravel-permission`) |

Do not assume any of the "Out of Scope" items above exist in the running application, even if referenced historically in code comments or removed files.

---

## 4. User Roles and Permissions

The system has exactly two roles, stored as a plain string column (`users.role`, values `admin` | `user`), checked via `User::isAdmin()`. There is no fine-grained permission system.

| Capability | Admin | Staff (`user`) |
|---|:---:|:---:|
| Log in / log out | ✅ | ✅ |
| View dashboard | ✅ | ✅ |
| Edit own profile (name/email/photo) & change own password | ✅ | ✅ |
| View member list, search/filter | ✅ | ✅ |
| View member profile / benefit details | ✅ | ✅ |
| View reports (Annual GHP, Reimbursements) & MDR, and export them | ✅ | ✅ |
| View Coverage Year History reimbursement drill-down + printable receipt | ✅ | ✅ |
| Create / edit members | ✅ | ❌ |
| Activate / deactivate members (individual or bulk) | ✅ | ❌ |
| Import members via CSV | ✅ | ❌ |
| Generate a member's benefit period | ✅ | ❌ |
| Add / edit / delete dependents | ✅ | ❌ |
| File / edit / void reimbursements | ✅ | ❌ |
| Unvoid a reimbursement | ✅ (explicitly gated by `isAdmin()` inside the controller, in addition to route middleware) | ❌ |
| Create / adjust manual GHP amount override, revert to automatic | ✅ | ❌ |
| Manage departments (create/edit/delete) | ✅ | ❌ |
| Manage user accounts (create/edit/activate/deactivate/delete) | ✅ | ❌ |
| View Activity Log | ✅ | ❌ |
| View / correct Data Quality Report | ✅ | ❌ |
| Correct a benefit period directly (data-quality fix) | ✅ | ❌ |

**Additional role-related business rules actually enforced in code:**
- An admin cannot remove their own admin role, deactivate their own account, or delete their own account while logged in.
- The system refuses any action that would leave **zero admin accounts** (role change, deletion) or **zero active admin accounts** (deactivation).
- A deactivated user is signed out immediately, even mid-session, not just blocked at their next login attempt (`EnsureUserIsActive` middleware runs on every authenticated route).

---

## 5. Functional Requirements

Requirement IDs are grouped by module. "Status" reflects the **actual current implementation**, cross-checked against `task.md` and the code — not merely what documentation claims.

### 5.1 Authentication & Session (AUTH)

| ID | Requirement | Module | Status |
|---|---|---|---|
| FR-AUTH-001 | Users log in with email + password via a session-based guard. | Auth | ✅ Implemented |
| FR-AUTH-002 | A deactivated account is blocked at login with a clear message, without revealing whether the credentials themselves were valid. | Auth | ✅ Implemented |
| FR-AUTH-003 | A deactivated account's active session is force-logged-out immediately, on any subsequent authenticated request. | Auth | ✅ Implemented |
| FR-AUTH-004 | Successful logins, failed logins, blocked (deactivated) logins, and logouts are recorded in the Activity Log. | Auth | ✅ Implemented |
| FR-AUTH-005 | The login route is protected against brute-force attempts (rate limiting). | Auth | ❌ Not implemented (known gap) |
| FR-AUTH-006 | A user who forgets their password can reset it without administrator help. | Auth | ❌ Not implemented (known gap) |

### 5.2 Self-Service Profile (PROFILE)

| ID | Requirement | Module | Status |
|---|---|---|---|
| FR-PROF-001 | Any logged-in user can view and edit their own name and email. | Profile | ✅ Implemented |
| FR-PROF-002 | Any logged-in user can upload a profile photo (jpg/jpeg/png/webp, max 2MB) or remove it, falling back to an initials avatar when unset. | Profile | ✅ Implemented |
| FR-PROF-003 | Any logged-in user can change their own password, required to supply their current password first. | Profile | ✅ Implemented |
| FR-PROF-004 | A user cannot change their own role from the profile page. | Profile | ✅ Implemented (enforced by omission — the request has no `role` field) |
| FR-PROF-005 | Changing a password invalidates other active sessions for that account. | Profile | ❌ Not implemented (known gap) |

### 5.3 Member Management (MEM)

| ID | Requirement | Module | Status |
|---|---|---|---|
| FR-MEM-001 | An admin can create a new member (Employee or Agent), with required last name, first name, email, apply date, start date, and GHP amount. | Member Mgmt | ✅ Implemented |
| FR-MEM-002 | A new member's code is auto-generated (`ALSC-######`) unless the admin supplies one, which must be unique. | Member Mgmt | ✅ Implemented |
| FR-MEM-003 | A member's `deduction_start_date` is always system-computed (first day of the month after `start_date`) and is never directly editable. | Member Mgmt | ✅ Implemented |
| FR-MEM-004 | Member email is optional on existing/legacy records but required and validated (RFC format + MX record check outside testing) on new members; unique across members. | Member Mgmt | ✅ Implemented |
| FR-MEM-005 | An admin can edit an existing member's details. | Member Mgmt | ✅ Implemented |
| FR-MEM-006 | An admin can deactivate (with a required resignation date) or reactivate (date automatically cleared) a member, individually or in bulk. | Member Mgmt | ✅ Implemented |
| FR-MEM-007 | The member list supports live/AJAX search (by code, last name, first name), and filtering by type, department, division, and status (active/inactive/all; defaults to active). | Member Mgmt | ✅ Implemented |
| FR-MEM-008 | The member list is paginated (25 per page) and preserves filter/search state via the query string. | Member Mgmt | ✅ Implemented |
| FR-MEM-009 | Both `apply_date` and `deduction_start_date`-driving `start_date` are required fields on create and edit (previously optional — hardened to prevent unusable member records). | Member Mgmt | ✅ Implemented |
| FR-MEM-010 | An admin can bulk-import members from a CSV file against a documented template; invalid rows are skipped and reported individually rather than failing the whole import. | Member Mgmt / Import | ✅ Implemented |
| FR-MEM-011 | An admin can export the member list (CSV/PDF/Excel). | Member Mgmt / Export | ❌ Removed at client request — not in active application |

### 5.4 Dependent Management (DEP)

| ID | Requirement | Module | Status |
|---|---|---|---|
| FR-DEP-001 | An admin can add a dependent to a member (name, relation, birthdate). | Dependents | ✅ Implemented |
| FR-DEP-002 | An admin can edit or delete an existing dependent. | Dependents | ✅ Implemented |
| FR-DEP-003 | A dependent counts toward GHP eligibility if their relation is Son/Daughter/Child and they are under 21, or if their relation is anything else (e.g. spouse). | Dependents | ✅ Implemented |
| FR-DEP-004 | A dependent added mid-cycle does **not** immediately raise the member's GHP amount; it becomes effective only from the start of the **next** coverage cycle. | Dependents | ✅ Implemented |
| FR-DEP-005 | Dependents already on file before this timing rule existed, or created outside the standard "Add dependent" flow (e.g. via import), are always treated as immediately eligible (not retroactively time-gated). | Dependents | ✅ Implemented |

### 5.5 Benefit Period / Accrual (BEN)

| ID | Requirement | Module | Status |
|---|---|---|---|
| FR-BEN-001 | The system computes a member's coverage year as Apr 1–Mar 31 for Employees and Jun 1–May 31 for Agents. | Benefit Accrual | ✅ Implemented |
| FR-BEN-002 | The base GHP amount is ₱3,600/year; ₱4,200/year if the member has at least one GHP-eligible dependent as of the evaluation date. | Benefit Accrual | ✅ Implemented |
| FR-BEN-003 | Accrual is pro-rated monthly from the member's `deduction_start_date`, counting whole calendar months elapsed within the current cycle. | Benefit Accrual | ✅ Implemented |
| FR-BEN-004 | A prior coverage year's unused GHP amount carries forward at 10%, but only if that prior period had zero usage. | Benefit Accrual | ✅ Implemented |
| FR-BEN-005 | An admin can manually trigger generation of the current cycle's benefit period for a member (individually or in bulk). | Benefit Accrual | ✅ Implemented |
| FR-BEN-006 | Exactly one benefit period may exist per member per coverage cycle; the system rejects (does not silently overwrite) attempts to generate a second one for an already-generated cycle. | Benefit Accrual | ✅ Implemented, enforced at the database level (unique constraint) and application level (locked-transaction check) |
| FR-BEN-007 | Benefit periods for all active members with a deduction start date are automatically kept current by a scheduled daily job. | Benefit Accrual | ✅ Implemented (registered; depends on an external OS-level scheduler trigger — see NFR/Technical notes) |
| FR-BEN-008 | An admin can void a benefit period and regenerate a fresh one for the same cycle, and/or delete a voided period. | Benefit Accrual | ❌ **Built, then fully reverted** at explicit client request — not in the active application |
| FR-BEN-009 | An admin can directly correct a benefit period's stored figures (e.g. to fix corrupted historical data), independent of the accrual engine. | Benefit Accrual / Data Quality | ✅ Implemented (via Data Quality Report) |

### 5.6 Reimbursement Management (REIMB)

| ID | Requirement | Module | Status |
|---|---|---|---|
| FR-REIMB-001 | An admin can file a reimbursement claim for a member (OR date, OR amount required; OR number, hospital, description, remarks optional). | Reimbursements | ✅ Implemented |
| FR-REIMB-002 | A reimbursement's amount can never be filed or edited to exceed the member's available GHP balance for the coverage period containing its OR date. | Reimbursements | ✅ Implemented, enforced inside a locked database transaction (race-condition safe) |
| FR-REIMB-003 | An admin can void a reimbursement with a required reason; a voided reimbursement stays visible on record but no longer counts against the fund. | Reimbursements | ✅ Implemented |
| FR-REIMB-004 | An admin can unvoid a previously voided reimbursement, restoring it against the fund balance. | Reimbursements | ✅ Implemented |
| FR-REIMB-005 | Editing a voided reimbursement is blocked until it is unvoided first. | Reimbursements | ✅ Implemented |
| FR-REIMB-006 | Each reimbursement is automatically linked to the benefit period whose coverage range contains its OR date, when that period exists. | Reimbursements | ✅ Implemented |
| FR-REIMB-007 | Any authenticated user can view and print/download (PDF, company letterhead) the reimbursement history for one specific coverage-year benefit period. | Reimbursements | ✅ Implemented |

### 5.7 GHP Amount Adjustment (ADJ)

| ID | Requirement | Module | Status |
|---|---|---|---|
| FR-ADJ-001 | An admin can manually override a member's computed GHP amount, recording a reason, optional request reference, and requested-at date. | Amount Adjustment | ✅ Implemented |
| FR-ADJ-002 | A manual override persists through future recalculation (dependent changes, benefit-period generation) until explicitly reverted. | Amount Adjustment | ✅ Implemented |
| FR-ADJ-003 | An admin can revert a member from a manual override back to the automatically computed amount. | Amount Adjustment | ✅ Implemented |
| FR-ADJ-004 | Every adjustment (manual and revert-to-automatic) is recorded in a structured, queryable history table. | Amount Adjustment | ✅ Implemented |

### 5.8 Department & Division (DEPT)

| ID | Requirement | Module | Status |
|---|---|---|---|
| FR-DEPT-001 | An admin can create, edit, and delete a Department. | Departments | ✅ Implemented |
| FR-DEPT-002 | A Department can be assigned to a Division by free-text name; an unrecognized name auto-creates a new (Employee-type-defaulted) Division. | Departments | ✅ Implemented |
| FR-DEPT-003 | Deleting a Department un-assigns (does not delete) any members currently assigned to it. | Departments | ✅ Implemented (`nullOnDelete` FK) |
| FR-DEPT-004 | An admin can directly create, edit, and delete Divisions (dedicated management screen). | Divisions | ❌ Not implemented — divisions can currently only be created indirectly via the Department form or seeded/created manually |

### 5.9 User Management (USR)

| ID | Requirement | Module | Status |
|---|---|---|---|
| FR-USR-001 | An admin can create a user account with name, email, password, and role. | User Mgmt | ✅ Implemented |
| FR-USR-002 | A new user's code (`ALSC-######`) is always system-generated and starts Active. | User Mgmt | ✅ Implemented |
| FR-USR-003 | An admin can edit a user's name, email, role, and (optionally) reset their password. | User Mgmt | ✅ Implemented |
| FR-USR-004 | An admin can activate/deactivate a user account, subject to last-active-admin safeguards and a self-action block. | User Mgmt | ✅ Implemented |
| FR-USR-005 | An admin can permanently delete a user account, subject to last-admin safeguards and a self-action block. | User Mgmt | ✅ Implemented |

### 5.10 Reporting (RPT)

| ID | Requirement | Module | Status |
|---|---|---|---|
| FR-RPT-001 | An authenticated user can generate an Annual GHP report (PDF and CSV), filterable by member type, department, division, status, and coverage year. | Reports | ✅ Implemented |
| FR-RPT-002 | An authenticated user can generate a Reimbursement report (PDF and CSV) for a required date range, filterable by type/department/division. | Reports | ✅ Implemented |
| FR-RPT-003 | An authenticated user can generate a per-member Member Data Record (PDF). | Reports | ✅ Implemented |

### 5.11 Data Quality & Activity Log (DQ / LOG)

| ID | Requirement | Module | Status |
|---|---|---|---|
| FR-DQ-001 | An admin can view a Data Quality Report flagging benefit periods with implausible/corrupted dates. | Data Quality | ✅ Implemented |
| FR-DQ-002 | An admin can view a Data Quality Report flagging active members with a ₱0 GHP amount. | Data Quality | ✅ Implemented |
| FR-DQ-003 | An admin can view a Data Quality Report flagging active members with a deduction start date but no benefit period on record. | Data Quality | ✅ Implemented |
| FR-DQ-004 | The Data Quality Report shows a best-effort count of legacy records that had no matching member during import, when the legacy database connection is still reachable. | Data Quality | ✅ Implemented (degrades gracefully to "unavailable" once the legacy DB is gone) |
| FR-LOG-001 | An admin can view a chronological, filterable-by-category Activity Log of system changes. | Activity Log | ✅ Implemented |

### 5.12 Legacy Data Import (IMPORT)

| ID | Requirement | Module | Status |
|---|---|---|---|
| FR-IMP-001 | The system can bulk-import members, dependents, benefit periods, ledger entries, reimbursements, divisions, departments, and agent positions from the restored legacy PostgreSQL database via a console command. | Legacy Import | ✅ Implemented (`ghp:import-legacy`) |
| FR-IMP-002 | The import command can optionally truncate target tables first (`--fresh`) for a clean re-import. | Legacy Import | ✅ Implemented |
| FR-IMP-003 | The import best-effort-extracts structured GHP amount-adjustment history from legacy free-text remarks, flagged for manual review. | Legacy Import | ✅ Implemented |
| FR-IMP-004 | The legacy import path has automated test coverage. | Legacy Import | ❌ Not implemented (known gap) |

---

## 6. Business Rules

| ID | Rule |
|---|---|
| BR-001 | Coverage year: **Employees** run Apr 1–Mar 31; **Agents** run Jun 1–May 31 (hardcoded business calendar, not configurable). |
| BR-002 | Base GHP amount is **₱3,600/year**; with at least one GHP-eligible dependent it is **₱4,200/year** — unless a manual override is in effect. |
| BR-003 | A dependent's relation of **Son, Daughter, or Child** is GHP-eligible only while under **age 21**; any other relation (e.g. spouse) is always eligible, subject to BR-004. |
| BR-004 | A dependent added **mid-cycle** does not raise the GHP amount until the **start of the next coverage cycle** — there is no partial-cycle credit for a newly added dependent. |
| BR-005 | `deduction_start_date` is always the **first day of the calendar month immediately following** `start_date` — the enrollment month itself is never deducted, and this is never directly editable by an admin. |
| BR-006 | Monthly accrual = `ghp_amount / 12`, credited for each whole calendar month from `deduction_start_date` (or the cycle start, whichever is later) through the evaluation date, capped at the cycle end date. |
| BR-007 | **10% carry-forward**: a prior coverage period's `ghp_amount` carries forward at 10% into the next period, but **only if** that prior period had zero usage (`ghp_used == 0`). |
| BR-008 | **Reimbursement Rule**: a reimbursement's `or_amount` can never be saved (on create or edit) if it would exceed the member's available GHP balance for the coverage period containing its `or_date`. Enforced inside a row-locked database transaction so two near-simultaneous submissions cannot jointly overdraw the balance. |
| BR-009 | A **voided** reimbursement is excluded entirely from the "used" calculation (not merely capped) but remains visible on the member's record with its void reason and who voided it. |
| BR-010 | The displayed "used" figure is capped at the available fund balance as a defensive floor (should not bind under normal operation given BR-008), while the full claimed amount is always preserved on the reimbursement record itself. |
| BR-011 | **Strict one-generation-per-cycle rule**: once a benefit period exists for a member's current coverage cycle, it cannot be voided, deleted, or regenerated — the "Generate benefit period" action becomes available again only when the next cycle actually begins. Enforced by a unique database constraint on `(member_id, from_date, to_date)`. |
| BR-012 | A **manual GHP amount override** (`ghp_amount_is_manual = true`) causes the accrual engine to use the stored `ghp_amount` as-is, ignoring the dependent-based 3,600/4,200 calculation, until explicitly reverted to automatic. |
| BR-013 | A member's or user's **code** (`ALSC-######`) is always system-generated unless an admin explicitly supplies one on member creation (members only — user codes are always system-generated with no override path). |
| BR-014 | Deactivating a member **requires** a resignation date; reactivating a member **always clears** the resignation date regardless of what (if anything) is submitted. |
| BR-015 | The system will not allow an action that would leave **zero admin accounts**, or **zero active admin accounts**, in the system. An admin cannot demote, deactivate, or delete their own account while logged in. |
| BR-016 | A deactivated user account is signed out **immediately**, even mid-session — not only blocked at the next login attempt. |
| BR-017 | Member email is required and validated for new members but remains optional (nullable) for legacy/existing member records so that unrelated edits are not blocked. |
| BR-018 | Deleting a Department does not delete its assigned members — they become unassigned (`department_id` set to `null`). |
| BR-019 | An unrecognized Division name entered on the Department form auto-creates a new Division, always defaulted to member type **Employee** (there is no UI to specify Agent at creation time via this path). |

---

## 7. Data Requirements

### 7.1 Core Entities

| Entity (table) | Purpose | Key fields |
|---|---|---|
| `users` | System login accounts (Admin/Staff) | `user_code` (ALSC-######, unique), `name`, `email` (unique), `role` (admin/user), `is_active`, `profile_photo_path`, `password` |
| `members` | Plan members (Employees/Agents) | `code` (ALSC-###### or legacy code, unique), `email` (nullable, unique), `member_type` (0=Employee,1=Agent), `last_name`, `first_name`, `middle_name`, `address`, `birthdate`, `civil_status`, `apply_date`, `start_date`, `deduction_start_date`, `ghp_amount`, `ghp_amount_is_manual`, `is_active`, `resignation_date`, `division_id`, `department_id`, `old_code`, `remarks`; soft-deletable |
| `dependents` | Members' dependents | `member_id`, `name`, `relation` (free text), `birthdate`, `date_added`, `eligibility_date` |
| `divisions` | Division lookup (Employee/Agent scoped) | `member_type`, `name`; unique on `(member_type, name)` |
| `departments` | Department lookup | `name` (unique), `division_id` |
| `benefit_periods` | One row per member per coverage cycle — the authoritative accrual record | `member_id`, `from_date`, `to_date`, `ghp_amount`, `ghp_available`, `ghp_used`, `member_type`, `division_id`, `department_id`, `remarks`; unique on `(member_id, from_date, to_date)` |
| `benefit_ledger` | Legacy-parallel ledger table (imported 1:1 from `t_avail_used_ghp`); kept separate from `benefit_periods` for import fidelity — flagged as a likely future merge candidate, not actively written to by the current application logic beyond import | `member_id`, `from_date`, `to_date`, `ghp_amount`, `available_amount`, `used_amount`; unique on `(member_id, from_date, to_date)` |
| `benefit_amount_adjustments` | Structured audit trail for manual GHP amount overrides | `member_id`, `old_amount`, `new_amount`, `reason`, `request_reference`, `requested_at`, `recorded_by` |
| `reimbursements` | Medical reimbursement claims | `member_id`, `benefit_period_id`, `or_no`, `or_date`, `or_amount`, `hospital_name`, `description`, `remarks`, `is_voided`, `voided_at`, `voided_reason`, `voided_by`; soft-deletable |
| `agent_positions` | Agent VP/AVP position lookup (legacy-imported; matched by name, not FK'd to `members`) | `member_code`, `name`, `position` |
| `activity_log` | System-wide audit trail (Spatie Activitylog) | logged on `User`, `Member`, `Dependent`, `Reimbursement`, `Department`, `BenefitPeriod`, plus unattached `auth`/`import`/`benefit_period` (scheduler) log entries |

### 7.2 Key Relationships
- `Member` 1—* `Dependent`, `BenefitPeriod`, `BenefitLedger`, `Reimbursement`, `BenefitAmountAdjustment`.
- `Member` *—1 `Division`, *—1 `Department` (both nullable, `nullOnDelete`).
- `Department` *—1 `Division` (nullable, `nullOnDelete`).
- `Reimbursement` *—1 `BenefitPeriod` (nullable, `nullOnDelete`) — the Coverage Year History link.
- `Reimbursement` *—1 `User` via `voided_by` (nullable, `nullOnDelete`).
- `BenefitPeriod`, `BenefitLedger`, `BenefitAmountAdjustment` all `restrictOnDelete` against their `Member` — a member cannot be hard-deleted while historical financial records reference it (soft delete is used instead).
- `AgentPosition.member_code` is a **plain string**, intentionally not a foreign key to `members.code` (data-quality risk noted in Known Limitations).

### 7.3 Legacy Data Notes (carried into the schema deliberately)
- `dependents.relation` is free text (not an enum) because live legacy data contains typos and non-English variants (e.g. "Daugther", "Anak") that an enum would reject on import.
- `members.code` supports mixed legacy formats (e.g. `'20068'`, `'T005'`, `'A083'`) in addition to the new `ALSC-######` format.
- `members.old_code` retains a pre-renumbering legacy ID for traceability where applicable.

---

## 8. Workflow / Process

### 8.1 Member Onboarding & Benefit Setup
1. Admin creates a member (Members → Add Member), providing name, type, apply date, start date, GHP details.
2. System computes `deduction_start_date` = 1st of the month after `start_date`.
3. System assigns a unique member code unless one is supplied.
4. Member's initial GHP amount reflects the base rate (₱3,600) unless an eligible dependent is added or a manual adjustment is made.
5. Admin (or the daily scheduled job) generates the member's first benefit period once eligible.

### 8.2 Benefit Period Generation ("Generate this year's benefit period")
1. Admin clicks Generate (or the daily job runs) for an active member with a `deduction_start_date`.
2. System determines the member's current coverage cycle (BR-001).
3. System locks the member row and checks whether a period already exists for that exact cycle.
4. If one exists → request is rejected with a status message; no changes made (BR-011).
5. If none exists → `BenefitAccrualService::accrue()` computes GHP amount, months accrued, carry-forward, used, and available, and persists a new `benefit_periods` row.

### 8.3 Filing a Reimbursement
1. Admin opens a member's profile and files a reimbursement (OR date, amount, optional OR number/hospital/description/remarks).
2. System locks the member row inside a transaction.
3. System computes the member's available balance for the coverage period containing the OR date.
4. If the requested amount exceeds the available balance → validation error; nothing saved (BR-008).
5. Otherwise, the reimbursement is created, the member's current benefit period is refreshed, and the reimbursement is linked to the matching benefit period.

### 8.4 Voiding / Unvoiding a Reimbursement
1. Admin voids a reimbursement with a required reason → record flagged `is_voided`, excluded from "used" going forward, benefit period refreshed.
2. Admin can later unvoid it → flag cleared, amount counted again, benefit period refreshed.

### 8.5 Dependent Eligibility Timing
1. Admin adds a dependent → `date_added` = today, `eligibility_date` = start of the **next** coverage cycle.
2. Dependent is visible immediately on the member's profile with a "Pending Eligibility" status.
3. The GHP amount does **not** change yet.
4. Once the next coverage cycle begins (and a new benefit period is generated/evaluated as-of that cycle), the dependent becomes "Eligible" and the higher GHP amount applies if applicable.

### 8.6 Manual GHP Amount Adjustment
1. Admin submits a new amount + reason (+ optional reference/date) for a member.
2. System records the adjustment (old → new amount) in `benefit_amount_adjustments`.
3. System sets `ghp_amount` to the new value and `ghp_amount_is_manual = true`.
4. The member's current benefit period is refreshed to reflect the new amount.
5. This override persists through all future dependent changes and generation attempts until an admin explicitly reverts to automatic (recomputing from current dependents and clearing the manual flag).

### 8.7 Legacy Data Import (one-time / re-runnable migration operation)
1. Operator restores the legacy PostgreSQL dump to a database reachable via the `legacy` connection.
2. Operator runs `php artisan ghp:import-legacy` (optionally `--fresh` to truncate first).
3. Command imports, in order: divisions → departments → members → dependents → benefit periods → benefit ledger → reimbursements → agent positions → best-effort amount-adjustment extraction from remarks.
4. Operator is instructed to manually review auto-extracted adjustment rows and member remarks.

### 8.8 Data Quality Review
1. Admin opens Data Quality Report.
2. System runs four independent checks (corrupted period dates, ₱0 active members, missing current-cycle periods, legacy orphan counts) and displays flagged records.
3. Admin manually corrects flagged benefit periods via the report's correction action, or addresses member records directly elsewhere in the app.

---

## 9. Non-Functional Requirements

| ID | Category | Requirement | Status |
|---|---|---|---|
| NFR-001 | Security | All administrative write actions require the `admin` role, enforced both by route middleware (`admin` group) and, on the most sensitive actions, an additional `abort_unless(auth()->user()->isAdmin(), 403)` check inside the controller itself. | ✅ Implemented |
| NFR-002 | Security | All authenticated routes require an active account (`active` middleware), re-checked on every request, not just at login. | ✅ Implemented |
| NFR-003 | Security | New/edited member and user emails are validated for RFC compliance and (outside the testing environment) a live MX/DNS record check. | ✅ Implemented |
| NFR-004 | Security | Password changes require the current password when self-service; admin-issued resets do not require the old password (admin context is trusted). | ✅ Implemented |
| NFR-005 | Security | Login attempts are rate-limited to deter brute-force attacks. | ❌ Not implemented (known gap) |
| NFR-006 | Security | A password change invalidates other active sessions for that account. | ❌ Not implemented (known gap) |
| NFR-007 | Data Integrity | Reimbursement and benefit-period writes that could race (concurrent submissions) are protected by row-level locking (`lockForUpdate()`) inside database transactions. | ✅ Implemented |
| NFR-008 | Data Integrity | Financial history (`benefit_periods`, `benefit_ledger`, `benefit_amount_adjustments`) cannot be lost via member deletion — `restrictOnDelete` foreign keys and member soft-deletes protect this. | ✅ Implemented |
| NFR-009 | Data Integrity | Exactly one benefit period may exist per member per coverage cycle, enforced by a database unique constraint (not just application logic). | ✅ Implemented |
| NFR-010 | Auditability | All create/update/delete-relevant changes to members, dependents, reimbursements, departments, benefit periods, and users are recorded with a timestamped, causer-attributed activity log entry (dirty-fields only, no-op changes not logged). | ✅ Implemented |
| NFR-011 | Auditability | Password values themselves are never written into the activity log, even as a hash. | ✅ Implemented |
| NFR-012 | Usability | The member list supports live/AJAX search without a full page reload. | ✅ Implemented |
| NFR-013 | Usability | Filter/search state on the member list persists across pagination via the query string. | ✅ Implemented |
| NFR-014 | Usability | All primary-record edit interactions follow a single, consistent modal-based UI pattern (no separate "edit page" navigation). | ✅ Implemented |
| NFR-015 | Compatibility | The application runs correctly on MySQL (and SQLite for local/dev use); PostgreSQL-only SQL syntax (`ilike`, `::int` casts) has been identified and removed from application query paths. | ✅ Implemented |
| NFR-016 | Reliability | A large/malformed CSV member import cannot indefinitely tie up a request — capped at 5,000 data rows, with bad rows skipped and reported rather than aborting the whole import. | ✅ Implemented |
| NFR-017 | Reliability | The daily benefit-period auto-generation job is idempotent and safe to run more than once per day or be re-run manually. | ✅ Implemented (job logic); actual triggering depends on an external OS-level scheduler — see §10 |
| NFR-018 | Maintainability | Shared validation/query logic (email rules, unique code generation, member filtering) is centralized in reusable traits/services rather than duplicated per controller. | ✅ Implemented |

---

## 10. Technical Requirements

| Layer | Technology | Notes |
|---|---|---|
| Language / Runtime | PHP ^8.3 | |
| Framework | Laravel ^13.8 | |
| Database (app) | MySQL (production/testing configuration observed in `phpunit.xml`); SQLite supported as the framework default (`.env.example`) | `config/database.php` defines `mysql`, `mariadb`, `pgsql`, `sqlite`, `sqlsrv` connections |
| Database (legacy source) | PostgreSQL, via a dedicated `legacy` connection | Used only by `ghp:import-legacy` and the Data Quality Report's best-effort orphan-count check |
| PDF generation | `barryvdh/laravel-dompdf` ^3.1 | Annual GHP report, Reimbursement report, MDR, reimbursement receipts |
| Audit logging | `spatie/laravel-activitylog` ^4.12 | |
| Schema tooling | `doctrine/dbal` ^4.4 | Used for certain schema-altering migrations |
| Frontend build | Vite ^8, `laravel-vite-plugin`, Tailwind CSS ^4 (dependency present; **not** the active styling approach in Blade views — see §1.5) | Hand-written CSS design system in `public/css/app.css` is the actual styling layer |
| Testing | PHPUnit ^12.5 (test suite structure/class style used), Pest ^4.7 and `pestphp/pest` present as a dev dependency but the existing test suite is written in classic PHPUnit `TestCase` class style | `phpunit.xml` configures a dedicated `alsc_ghp_db_testing` MySQL database for the test run |
| Dev tooling | Laravel Breeze (auth scaffolding baseline), Laravel Pint (code style), Laravel Pail (log tailing), Mockery, Collision, Faker | |
| Scheduling | Laravel's `Schedule` facade registers `ghp:auto-generate-benefit-periods` daily at 01:00 | Requires an OS-level trigger (`php artisan schedule:run` on a cron/Task Scheduler) — **not automatically active** on a bare install, explicitly noted as requiring Windows Task Scheduler setup on this Laragon/Windows environment |
| File storage | Laravel's `public` disk (`storage/app/public`, requires `php artisan storage:link`) | Used for profile photos |

---

## 11. Current Limitations

Carried directly from the project's own tracked technical debt (`task.md §3`), verified still applicable against the current code:

| Limitation | Location | Impact |
|---|---|---|
| No rate limiting on `POST /login` | `routes/web.php` | Brute-force login risk |
| `.env.example` does not document the `LEGACY_DB_*` variables actually used by `ImportLegacyGhpData`/`config/database.php` | `.env.example` | Operators must discover these variables by reading code |
| No self-service "forgot password" flow | Auth | A locked-out user must wait for an admin to reset their password |
| A password reset does not invalidate other active sessions | `ProfileController::updatePassword`, `UserController::update` | A stolen/compromised session survives a password change |
| `users.email_verified_at` exists but is unused | `users` table | Dead column; no email verification actually occurs |
| `ImportLegacyGhpData` has no automated test coverage | Console command | The riskiest untested code path, since it writes bulk historical financial data |
| `agent_positions.member_code` is a string match, not a foreign key | `AgentPosition` | A reformatted/typo'd code silently breaks the agent-position lookup used by the agent reimbursement report |
| `dependents.relation` is free text, not an enum | `Dependent` | A typo in a relation value could silently affect eligibility calculations |
| `.card-head` CSS does not wrap cleanly on narrow screens | `public/css/app.css` | Minor layout issue on some pages at small viewport widths; deliberately left untouched to avoid a shared-CSS regression |
| No dedicated Division management UI | — | Divisions can only be created indirectly (via the Department form, always defaulted to Employee type) or manually via Tinker/seeder |
| Several recent changes are marked "**Not yet run**" in `task.md` | Multiple (see Progress.md) | Migrations and/or their accompanying tests were written from code inspection but not confirmed to actually execute successfully in this environment as of the last update |

---

## 12. Future Enhancements

Only items explicitly identified in `task.md`'s "Not implemented" list or clearly flagged as intentionally deferred are listed — nothing here is speculative:

1. **Division CRUD** — a dedicated Add/Edit/Delete management screen for Divisions (currently only indirectly manageable).
2. **Self-service password reset ("forgot password")** email-based flow.
3. **Rate limiting on the login route.**
4. **Session invalidation on password change**, for both self-service and admin-issued resets.
5. **Automated test coverage for `ImportLegacyGhpData`**, the one remaining untested controller/command from the original testing-gap review.
6. Documenting the `LEGACY_DB_*` environment variables in `.env.example`.
7. Possible merge of `benefit_periods` and `benefit_ledger` — explicitly flagged in code comments as "likely mergeable... revisit once real usage patterns are confirmed with the business owner," not yet acted on.
8. A business-owner decision on whether legacy-typo dependent relation values (e.g. "Daugther", "Anak") should be normalized/tightened into a formal enum.

---

*This document was generated by analyzing the actual `ghp-app` codebase (routes, controllers, models, migrations, form requests, services, and `task.md`) as of 2026-09-24. It supersedes any prior undocumented assumptions and should be re-validated against the codebase whenever significant feature work occurs.*
