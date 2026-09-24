@extends('layouts.app')

@section('title', 'Users')

@php
    $hasUserErrors = $errors->any() && $errors->has('email');
    $hasDepartmentErrors = $errors->any() && old('_form') === 'department';
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
                <tr>
                    <th>User code</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Added</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td class="code">{{ $user->user_code }}</td>
                        <td>{{ $user->name }} @if ($user->id === auth()->id()) <span class="hint">(you)</span> @endif</td>
                        <td>{{ $user->email }}</td>
                        <td>
                            <span class="badge {{ $user->role === 'admin' ? 'badge-agent' : 'badge-employee' }}">
                                {{ $user->role === 'admin' ? 'Admin' : 'Staff' }}
                            </span>
                        </td>
                        <td>
                            <span class="badge {{ $user->is_active ? 'badge-ok' : 'badge-warn' }}">
                                {{ $user->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td>{{ $user->created_at->format('M d, Y') }}</td>
                        <td style="white-space: nowrap;">
                            <button type="button" class="btn btn-ghost" style="padding: 4px 10px; font-size: 12px;"
                                onclick="openEditUser({{ $user->id }}, {{ json_encode($user->name) }}, {{ json_encode($user->email) }}, {{ json_encode($user->role) }})">
                                Edit
                            </button>
                            @if ($user->id !== auth()->id())
                                <form method="POST" action="{{ route('users.update-status', $user) }}" style="display: inline;"
                                    onsubmit="return confirm('{{ $user->is_active ? 'Deactivate' : 'Reactivate' }} {{ $user->name }}\'s account?{{ $user->is_active ? ' They will be logged out and won\'t be able to sign back in until reactivated.' : '' }}');">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-ghost"
                                        style="padding: 4px 10px; font-size: 12px; {{ $user->is_active ? '' : 'color: var(--success);' }}">
                                        {{ $user->is_active ? 'Deactivate' : 'Reactivate' }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('users.destroy', $user) }}" style="display: inline;" onsubmit="return confirm('Delete {{ $user->name }}\'s account permanently? This can\'t be undone \u2014 consider Deactivate instead if you just want to remove access.');">
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

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">{{ $departments->count() }} department(s)</span>
                <h2>Department Management</h2>
            </div>
            <button type="button" class="btn btn-primary" onclick="openAddDepartment()">+ Add department</button>
        </div>

        @if ($departments->isEmpty())
            <div class="empty-state"><p>No departments yet.</p></div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Department</th>
                        <th>Division</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($departments as $department)
                        <tr>
                            <td class="code">{{ $department->id }}</td>
                            <td>{{ $department->name }}</td>
                            <td>{{ $department->division->name ?? '—' }}</td>
                            <td style="white-space: nowrap;">
                                <button type="button" class="btn btn-ghost" style="padding: 4px 10px; font-size: 12px;"
                                    onclick="openEditDepartment({{ $department->id }}, {{ json_encode($department->name) }}, {{ json_encode($department->division->name ?? '') }})">
                                    Edit
                                </button>
                                <form method="POST" action="{{ route('departments.destroy', $department) }}" style="display: inline;"
                                    onsubmit="return confirm('Delete the {{ $department->name }} department?{{ $department->members()->exists() ? ' '.$department->members()->count().' member(s) are currently assigned to it — they will be unassigned, not deleted.' : '' }}');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-ghost" style="padding: 4px 10px; font-size: 12px; color: var(--danger);">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
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

    {{-- Add/Edit department modal (shared) --}}
    <dialog id="departmentModal" class="modal">
        <form method="POST" action="{{ route('departments.store') }}" id="departmentForm">
            @csrf
            <input type="hidden" name="_form" value="department">
            <input type="hidden" name="_method" id="departmentFormMethod" value="POST">

            <div class="modal-head">
                <h2 id="departmentModalTitle">Add department</h2>
                <button type="button" class="modal-close" onclick="departmentModal.close()" aria-label="Close">&times;</button>
            </div>

            <div class="modal-body">
                @if ($hasDepartmentErrors)
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
                    <label for="department_name">Department name</label>
                    <input type="text" id="department_name" name="name" value="{{ old('name') }}" required>
                </div>

                <div class="field" style="margin-bottom: 0;">
                    <label for="department_division">Division</label>
                    <input type="text" id="department_division" name="division" list="division-options" value="{{ old('division') }}" placeholder="Type a division name, or leave blank">
                    <datalist id="division-options">
                        @foreach ($divisions as $division)
                            <option value="{{ $division->name }}">
                        @endforeach
                    </datalist>
                    <p class="hint" style="margin-top: 4px;">Existing names show as suggestions as you type. A name that doesn't match anything creates a new division automatically (as an Employee division — there's no way to set Agent from this field yet).</p>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="departmentModal.close()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="departmentFormSubmit">Save department</button>
            </div>
        </form>
    </dialog>

    <script>
        const departmentStoreUrl = '{{ route('departments.store') }}';
        const departmentUpdateUrlBase = '{{ url('departments') }}';

        function openAddDepartment() {
            document.getElementById('departmentModalTitle').textContent = 'Add department';
            document.getElementById('departmentForm').action = departmentStoreUrl;
            document.getElementById('departmentFormMethod').value = 'POST';
            document.getElementById('departmentFormSubmit').textContent = 'Save department';
            document.getElementById('department_name').value = '';
            document.getElementById('department_division').value = '';
            departmentModal.showModal();
        }

        function openEditDepartment(id, name, divisionName) {
            document.getElementById('departmentModalTitle').textContent = 'Edit department';
            document.getElementById('departmentForm').action = departmentUpdateUrlBase + '/' + id;
            document.getElementById('departmentFormMethod').value = 'PUT';
            document.getElementById('departmentFormSubmit').textContent = 'Update department';
            document.getElementById('department_name').value = name;
            document.getElementById('department_division').value = divisionName || '';
            departmentModal.showModal();
        }

        @if ($hasDepartmentErrors)
            departmentModal.showModal();
        @endif
    </script>
@endsection
