@extends('layouts.app')

@section('title', 'Add Member')

@section('content')
    <div style="margin-bottom: 16px;">
        <a href="{{ route('members.index') }}">&larr; Back to members</a>
    </div>

    @if ($errors->any())
        <div class="card" style="border-color: var(--danger);">
            <p class="error" style="margin: 0 0 6px; font-weight: 600;">Please fix the following:</p>
            <ul style="margin: 0; padding-left: 18px; color: var(--danger); font-size: 13px;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('members.store') }}">
        @csrf

        <div class="card">
            <h2 style="margin-bottom: 16px;">Identity</h2>

            <div style="display: flex; gap: 16px;">
                <div class="field" style="flex: 1;">
                    <label for="code">Member code (optional)</label>
                    <input type="text" id="code" name="code" value="{{ old('code') }}" placeholder="Leave blank to auto-generate">
                </div>
                <div class="field" style="flex: 1;">
                    <label for="email">Email account</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required>
                </div>
                <div class="field" style="flex: 1;">
                    <label for="old_code">Old code (optional)</label>
                    <input type="text" id="old_code" name="old_code" value="{{ old('old_code') }}">
                </div>
            </div>
            <p class="hint" style="margin-top: -8px;">Leave the member code blank to auto-generate one (ALSC-######), or type your own.</p>

            <div class="field">
                <label>Member type</label>
                <div class="radio-group" style="flex-direction: row; gap: 20px;">
                    <label><input type="radio" name="member_type" value="0" @checked(old('member_type', '0') == '0')> Employee</label>
                    <label><input type="radio" name="member_type" value="1" @checked(old('member_type') == '1')> Agent</label>
                </div>
            </div>

            <div style="display: flex; gap: 16px;">
                <div class="field" style="flex: 1;">
                    <label for="last_name">Last name</label>
                    <input type="text" id="last_name" name="last_name" value="{{ old('last_name') }}" required>
                </div>
                <div class="field" style="flex: 1;">
                    <label for="first_name">First name</label>
                    <input type="text" id="first_name" name="first_name" value="{{ old('first_name') }}" required>
                </div>
                <div class="field" style="flex: 1;">
                    <label for="middle_name">Middle name</label>
                    <input type="text" id="middle_name" name="middle_name" value="{{ old('middle_name') }}">
                </div>
            </div>

            <div class="field">
                <label for="address">Address</label>
                <input type="text" id="address" name="address" value="{{ old('address') }}">
            </div>

            <div style="display: flex; gap: 16px;">
                <div class="field" style="flex: 1;">
                    <label for="birthdate">Birthdate</label>
                    <input type="date" id="birthdate" name="birthdate" value="{{ old('birthdate') }}">
                </div>
                <div class="field" style="flex: 1;">
                    <label>Civil status</label>
                    <div class="radio-group" style="flex-direction: row; gap: 20px; padding-top: 9px;">
                        <label><input type="radio" name="civil_status" value="0" @checked(old('civil_status', '0') == '0')> Single</label>
                        <label><input type="radio" name="civil_status" value="1" @checked(old('civil_status') == '1')> Married</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-bottom: 16px;">Assignment</h2>

            <div style="display: flex; gap: 16px;">
                <div class="field" style="flex: 1;">
                    <label for="division_id">Division</label>
                    <select id="division_id" name="division_id">
                        <option value="">— None —</option>
                        @foreach ($divisions as $division)
                            <option value="{{ $division->id }}" @selected(old('division_id') == $division->id)>
                                {{ $division->name }} ({{ $division->member_type === \App\Models\Member::MEMBER_TYPE_AGENT ? 'Agent' : 'Employee' }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="flex: 1;">
                    <label for="department_id">Department</label>
                    <select id="department_id" name="department_id">
                        <option value="">— None —</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>
                                {{ $department->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="field">
                <label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', true)) style="width: auto; margin-right: 6px;"> Active</label>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-bottom: 16px;">Benefit setup</h2>

            <div style="display: flex; gap: 16px;">
                <div class="field" style="flex: 1;">
                    <label for="apply_date">GHP apply date <span class="error">*</span></label>
                    <input type="date" id="apply_date" name="apply_date" value="{{ old('apply_date', $defaultApplyDate->toDateString()) }}" required>
                    <p class="hint">Defaults to the current GHP cycle's start date — editable if needed.</p>
                </div>
                <div class="field" style="flex: 1;">
                    <label for="start_date">Member start date <span class="error">*</span></label>
                    <input type="date" id="start_date" name="start_date" value="{{ old('start_date', now()->toDateString()) }}" required>
                    <p class="hint">When the member started/was added — kept separate from Apply Date. The first deduction is automatically the 1st of the following month.</p>
                </div>
                <div class="field" style="flex: 1;">
                    <label for="ghp_amount">GHP amount</label>
                    <input type="number" id="ghp_amount" name="ghp_amount" value="{{ old('ghp_amount', 3600) }}" step="0.01" min="0" required>
                    <p class="hint">Base is ₱3,600; ₱4,200 if the member has an eligible dependent (add dependents after saving).</p>
                </div>
            </div>

            <p class="hint">GHP cycle: {{ $currentCycleStart->format('M d, Y') }} &ndash; {{ $currentCycleEnd->format('M d, Y') }}</p>

            <div class="field" style="margin-bottom: 0;">
                <label for="remarks">Remarks</label>
                <textarea id="remarks" name="remarks" rows="3">{{ old('remarks') }}</textarea>
            </div>
        </div>

        <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn btn-primary">Save member</button>
            <a href="{{ route('members.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
@endsection
