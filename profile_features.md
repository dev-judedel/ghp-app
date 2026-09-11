# Profile Feature — Development Log

Tracks the plan, decisions, and status for the "My Profile" self-service page.
Feature request: logged-in user can view their profile, edit name/email/photo,
and change their password, using the existing GHP design system and auth setup.

## Status: Implemented — pending migration + storage link on your machine

---

## 1. Findings from the existing codebase (before writing code)

- **Auth system**: session-based (`Auth::attempt`), single `AuthenticatedSessionController`
  (login/logout only — no registration, no password-reset flow). Roles are a plain
  `role` string column (`admin` | `user`) on `users`, checked via `User::isAdmin()`.
- **Password changes already exist**, but only through the admin-only "Users"
  screen (`UserController::update`, gated by the `admin` middleware). There was
  **no self-service way** for a logged-in user (admin or staff) to change their
  own password or update their own name/email without going through that screen
  — and staff (non-admin) accounts can't reach that screen at all.
- **No profile photo support** — `users` table had no photo column, no avatar
  UI anywhere. Added as a small additive migration (see below).
- **UI conventions** (matched exactly, no new patterns introduced):
  - Single-record pages (`members/show.blade.php`) use a `card` with a
    `card-head` containing a title + action button(s) that open a
    `<dialog class="modal">` for editing — not separate edit pages.
  - Modals follow `modal-head` / `modal-body` / `modal-foot`, `@csrf`,
    `@method('PUT')`, `field` wrappers, and an error summary block shown when
    `$errors->has(...)` matches that modal's fields, with a `<script>` that
    calls `.showModal()` on validation failure (see `editMemberModal` pattern).
  - Styling is the hand-written `public/css/app.css` (design tokens, no
    Tailwind classes in Blade) — new CSS was **appended**, nothing existing
    was rewritten.
  - `spatie/laravel-activitylog` is already on every editable model
    (`Member`, `Reimbursement`, `User`, ...) via `LogsActivity`; the member
    show page renders `$member->activities()` as a "Recent activity" card.
    The profile page reuses the same relation on `User`.

## 2. Decisions

- **Role is not editable from the profile page.** Promoting/demoting stays
  admin-only via the existing Users screen — a user changing their own role
  is a separate, more sensitive concern than editing their own name/email,
  and `UserController` already has last-admin-safety checks that only make
  sense in that admin context.
- **Password change requires the current password** (`current_password`
  validation rule) since this is self-service and there's no admin
  double-check the way there is on the Users screen.
- **Photo storage**: Laravel's standard `public` disk (already configured in
  `config/filesystems.php`, nothing changed there). Files saved under
  `storage/app/public/profile-photos/`. Falls back to an initials avatar
  (e.g. "JD") when no photo is set — no dependency on any external service.
- **No new package added.** Everything uses what's already in `composer.json`
  (Laravel's own validation, hashing, filesystem, and Spatie activity log).

## 3. Files added

- `database/migrations/2026_09_11_100000_add_profile_photo_path_to_users_table.php`
- `app/Http/Requests/UpdateProfileRequest.php`
- `app/Http/Requests/UpdatePasswordRequest.php`
- `app/Http/Controllers/ProfileController.php`
- `resources/views/profile/edit.blade.php`
- `tests/Feature/ProfileTest.php`

## 4. Files modified

- `app/Models/User.php` — added `profile_photo_path` to the fillable list,
  added `profilePhotoUrl` / `initials` accessors, added the photo field to
  the activity log's `logOnly`.
- `routes/web.php` — added `profile.edit` / `profile.update` /
  `profile.update-password` routes inside the existing `auth` middleware
  group (not admin-only).
- `resources/views/layouts/app.blade.php` — the topbar user name is now a
  link to the profile page, with a small avatar/initials badge. Logout
  button and everything else in the layout is unchanged.
- `public/css/app.css` — appended an "Avatar / profile header" section at
  the end of the file. No existing rule was edited or removed.

## 5. Things you need to run locally

This filesystem connector can read/write files but can't run shell commands
on your machine, so please run these yourself:

```bash
php artisan migrate
php artisan storage:link   # only if storage/public isn't linked yet
```

## 6. Manual test checklist

- [ ] Log in as a **staff** (non-admin) user → "My Profile" link works, no
      access to `/users` needed.
- [ ] Edit name/email → saved, activity log entry appears.
- [ ] Upload a photo (jpg/png/webp) → shows in header and topbar; old file
      is deleted when replaced.
- [ ] Check "Remove photo" → reverts to initials avatar.
- [ ] Change password with wrong current password → validation error, modal
      re-opens with the error.
- [ ] Change password correctly → success message, can log in with new
      password next time.
- [ ] Confirm nothing on `/members`, `/reports`, `/users`, etc. changed.
- [ ] Check the page on a narrow (mobile) viewport — header wraps cleanly,
      buttons stack, modals remain usable.
