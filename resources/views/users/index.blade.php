@extends('layouts.app')

@section('title', 'Users')

@php
    $hasUserErrors = $errors->any() && $errors->has('email');
@endphp

@section('content')
    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">{{ $users->count() }} account(s)</span>
                <h2>Staff accounts</h2>
            </div>
            <button type="button" class="btn btn-primary" onclick="openAddUser()">+ Add user</button>
        </div>

        <table>
            <thead>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Added</th><th></th></tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td>{{ $user->name }} @if ($user->id === auth()->id()) <span class="hint">(you)</span> @endif</td>
                        <td>{{ $user->email }}</td>
                        <td>
                            <span class="badge {{ $user->role === 'admin' ? 'badge-agent' : 'badge-employee' }}">
                                {{ $user->role === 'admin' ? 'Admin' : 'Staff' }}
                            </span>
                        </td>
                        <td>{{ $user->created_at->format('M d, Y') }}</td>
                        <td style="white-space: nowrap;">
                            <button type="button" class="btn btn-ghost" style="padding: 4px 10px; font-size: 12px;"
                                onclick="openEditUser({{ $user->id }}, {{ json_encode($user->name) }}, {{ json_encode($user->email) }}, {{ json_encode($user->role) }})">
                                Edit
                            </button>
                            @if ($user->id !== auth()->id())
                                <form method="POST" action="{{ route('users.destroy', $user) }}" style="display: inline;" onsubmit="return confirm('Delete {{ $user->name }}\'s account? This can\'t be undone.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-ghost" style="padding: 4px 10px; font-size: 12px; color: var(--danger);">Delete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Add/Edit user modal (shared) --}}
    <dialog id="userModal" class="modal">
        <form method="POST" action="{{ route('users.store') }}" id="userForm">
            @csrf
            <input type="hidden" name="_method" id="userFormMethod" value="POST">

            <div class="modal-head">
                <h2 id="userModalTitle">Add user</h2>
                <button type="button" class="modal-close" onclick="userModal.close()" aria-label="Close">&times;</button>
            </div>

            <div class="modal-body">
                @if ($hasUserErrors)
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
                    <label for="user_name">Name</label>
                    <input type="text" id="user_name" name="name" value="{{ old('name') }}" required>
                </div>

                <div class="field">
                    <label for="user_email">Email</label>
                    <input type="email" id="user_email" name="email" value="{{ old('email') }}" required>
                </div>

                <div class="field">
                    <label for="user_password" id="user_password_label">Password</label>
                    <input type="password" id="user_password" name="password" autocomplete="new-password">
                    <p class="hint" id="user_password_hint">Minimum 8 characters.</p>
                </div>

                <div class="field" style="margin-bottom: 0;">
                    <label>Role</label>
                    <div class="radio-group" style="flex-direction: row; gap: 20px;">
                        <label><input type="radio" name="role" value="user" checked> Staff</label>
                        <label><input type="radio" name="role" value="admin"> Admin</label>
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="userModal.close()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="userFormSubmit">Save user</button>
            </div>
        </form>
    </dialog>

    <script>
        const userStoreUrl = '{{ route('users.store') }}';
        const userUpdateUrlBase = '{{ url('users') }}';

        function openAddUser() {
            document.getElementById('userModalTitle').textContent = 'Add user';
            document.getElementById('userForm').action = userStoreUrl;
            document.getElementById('userFormMethod').value = 'POST';
            document.getElementById('userFormSubmit').textContent = 'Save user';
            document.getElementById('user_name').value = '';
            document.getElementById('user_email').value = '';
            document.getElementById('user_password').value = '';
            document.getElementById('user_password').required = true;
            document.getElementById('user_password_label').textContent = 'Password';
            document.getElementById('user_password_hint').textContent = 'Minimum 8 characters.';
            document.querySelector('input[name="role"][value="user"]').checked = true;
            userModal.showModal();
        }

        function openEditUser(id, name, email, role) {
            document.getElementById('userModalTitle').textContent = 'Edit user';
            document.getElementById('userForm').action = userUpdateUrlBase + '/' + id;
            document.getElementById('userFormMethod').value = 'PUT';
            document.getElementById('userFormSubmit').textContent = 'Update user';
            document.getElementById('user_name').value = name;
            document.getElementById('user_email').value = email;
            document.getElementById('user_password').value = '';
            document.getElementById('user_password').required = false;
            document.getElementById('user_password_label').textContent = 'New password (optional)';
            document.getElementById('user_password_hint').textContent = 'Leave blank to keep their current password.';
            document.querySelector('input[name="role"][value="' + role + '"]').checked = true;
            userModal.showModal();
        }

        @if ($hasUserErrors)
            userModal.showModal();
        @endif
    </script>
@endsection
