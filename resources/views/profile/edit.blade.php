@extends('layouts.app')

@section('title', 'My Profile')

@php
    $hasProfileErrors = $errors->any() && ($errors->has('name') || $errors->has('email') || $errors->has('photo'));
    $hasPasswordErrors = $errors->any() && ($errors->has('current_password') || $errors->has('password'));
@endphp

@section('content')
    <div class="card">
        <div class="card-head profile-head">
            @if ($user->profile_photo_path)
                <img src="{{ $user->profile_photo_url }}" alt="" class="avatar avatar-lg">
            @else
                <span class="avatar avatar-lg avatar-initials">{{ $user->initials }}</span>
            @endif

            <div>
                <span class="eyebrow">{{ $user->role === 'admin' ? 'Admin' : 'Staff' }}</span>
                <h2>{{ $user->name }}</h2>
                <div class="hint">{{ $user->email }}</div>
            </div>

            <div class="profile-actions">
                <button type="button" class="btn btn-ghost" onclick="changePasswordModal.showModal()">Change password</button>
                <button type="button" class="btn btn-primary" onclick="editProfileModal.showModal()">Edit profile</button>
            </div>
        </div>

        <table>
            <tbody>
                <tr>
                    <th style="width: 180px;">Full name</th>
                    <td>{{ $user->name }}</td>
                    <th style="width: 180px;">Email</th>
                    <td>{{ $user->email }}</td>
                </tr>
                <tr>
                    <th>Role</th>
                    <td>
                        <span class="badge {{ $user->role === 'admin' ? 'badge-agent' : 'badge-employee' }}">
                            {{ $user->role === 'admin' ? 'Admin' : 'Staff' }}
                        </span>
                    </td>
                    <th>Member since</th>
                    <td>{{ $user->created_at->format('M d, Y') }}</td>
                </tr>
            </tbody>
        </table>

        <p class="hint" style="margin-top: 14px; margin-bottom: 0;">
            Role isn't editable here — ask an admin to change it from the Users screen.
        </p>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">Last 15</span>
                <h2>Recent activity</h2>
            </div>
        </div>

        @if ($activityFeed->isEmpty())
            <div class="empty-state"><p>No account changes on record yet.</p></div>
        @else
            <table>
                <thead>
                    <tr><th style="width: 170px;">When</th><th>What changed</th></tr>
                </thead>
                <tbody>
                    @foreach ($activityFeed as $activity)
                        <tr>
                            <td>{{ $activity->created_at->format('M d, Y g:i A') }}</td>
                            <td>
                                <div>{{ $activity->description }}</div>
                                @if ($activity->properties->has('attributes'))
                                    <ul style="margin: 4px 0 0; padding-left: 16px; font-size: 12px; color: var(--ink-muted);">
                                        @foreach ($activity->properties->get('attributes') as $field => $newValue)
                                            @if ($field !== 'profile_photo_path')
                                                @php $oldValue = $activity->properties->get('old')[$field] ?? null; @endphp
                                                <li>
                                                    <strong>{{ $field }}</strong>:
                                                    @if ($activity->properties->has('old'))
                                                        {{ $oldValue ?? '—' }} &rarr; {{ $newValue ?? '—' }}
                                                    @else
                                                        {{ $newValue ?? '—' }}
                                                    @endif
                                                </li>
                                            @endif
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Edit profile modal --}}
    <dialog id="editProfileModal" class="modal">
        <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')

            <div class="modal-head">
                <h2>Edit profile</h2>
                <button type="button" class="modal-close" onclick="editProfileModal.close()" aria-label="Close">&times;</button>
            </div>

            <div class="modal-body">
                @if ($hasProfileErrors)
                    <div class="field">
                        <p class="error" style="font-weight: 600;">Please fix the following:</p>
                        <ul style="margin: 4px 0 0; padding-left: 18px; color: var(--danger); font-size: 13px;">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="field">
                    <label for="profile_name">Name</label>
                    <input type="text" id="profile_name" name="name" value="{{ old('name', $user->name) }}" required>
                </div>

                <div class="field">
                    <label for="profile_email">Email</label>
                    <input type="email" id="profile_email" name="email" value="{{ old('email', $user->email) }}" required autocomplete="username">
                </div>

                <div class="field" style="{{ $user->profile_photo_path ? '' : 'margin-bottom: 0;' }}">
                    <label for="profile_photo">Profile photo</label>
                    <input type="file" id="profile_photo" name="photo" accept="image/png,image/jpeg,image/webp">
                    <p class="hint">JPG, PNG, or WEBP. Max 2 MB. Leave blank to keep your current photo.</p>
                </div>

                @if ($user->profile_photo_path)
                    <div class="field" style="margin-bottom: 0;">
                        <label><input type="checkbox" name="remove_photo" value="1" style="width: auto; margin-right: 6px;"> Remove current photo</label>
                    </div>
                @endif
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="editProfileModal.close()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save changes</button>
            </div>
        </form>
    </dialog>

    {{-- Change password modal --}}
    <dialog id="changePasswordModal" class="modal">
        <form method="POST" action="{{ route('profile.update-password') }}">
            @csrf
            @method('PUT')

            <div class="modal-head">
                <h2>Change password</h2>
                <button type="button" class="modal-close" onclick="changePasswordModal.close()" aria-label="Close">&times;</button>
            </div>

            <div class="modal-body">
                @if ($hasPasswordErrors)
                    <div class="field">
                        <p class="error" style="font-weight: 600;">Please fix the following:</p>
                        <ul style="margin: 4px 0 0; padding-left: 18px; color: var(--danger); font-size: 13px;">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="field">
                    <label for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                </div>

                <div class="field">
                    <label for="new_password">New password</label>
                    <input type="password" id="new_password" name="password" required autocomplete="new-password" minlength="8">
                    <p class="hint">Minimum 8 characters.</p>
                </div>

                <div class="field" style="margin-bottom: 0;">
                    <label for="new_password_confirmation">Confirm new password</label>
                    <input type="password" id="new_password_confirmation" name="password_confirmation" required autocomplete="new-password" minlength="8">
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="changePasswordModal.close()">Cancel</button>
                <button type="submit" class="btn btn-primary">Update password</button>
            </div>
        </form>
    </dialog>

    <script>
        @if ($hasProfileErrors)
            editProfileModal.showModal();
        @endif

        @if ($hasPasswordErrors)
            changePasswordModal.showModal();
        @endif
    </script>
@endsection
