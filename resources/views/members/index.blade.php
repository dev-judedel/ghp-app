@extends('layouts.app')

@section('title', 'Members')

@php
    // 'active' is the implicit default (see MemberController::index), so it
    // shouldn't count as a "filter applied" badge — only deviations from it do.
    $activeFilterCount = collect([$type, $departmentId, $divisionId, $status !== 'active' ? $status : null])->filter()->count();
    $hasMemberCreationErrors = $errors->any() && ($errors->has('email') || $errors->has('code'));
    $hasImportErrors = $errors->any() && $errors->has('csv_file');
    $importReport = session('import_report');
@endphp

@section('content')
    <div class="card">
        <form method="GET" action="{{ route('members.index') }}" style="display: flex; gap: 10px; align-items: flex-end;">
            <div class="field" style="flex: 1; margin-bottom: 0;">
                <label for="search">Search</label>
                <input type="text" id="search" name="search" value="{{ $search }}" placeholder="Name or member code&hellip;">
            </div>

            {{-- Filters other than search are applied via the modal, but must still be
                 submitted with the search form so a plain "Search" click doesn't drop them. --}}
            <input type="hidden" name="type" value="{{ $type }}">
            <input type="hidden" name="department" value="{{ $departmentId }}">
            <input type="hidden" name="division" value="{{ $divisionId }}">
            <input type="hidden" name="status" value="{{ $status }}">

            <button type="submit" class="btn btn-primary">Search</button>

            <button type="button" class="btn btn-ghost filter-trigger" onclick="filterModal.showModal()">
                Filters
                @if ($activeFilterCount > 0)
                    <span class="filter-badge">{{ $activeFilterCount }}</span>
                @endif
            </button>

            @if ($search || $activeFilterCount > 0)
                <a href="{{ route('members.index') }}" class="btn btn-ghost">Clear all</a>
            @endif

            @if (auth()->user()->isAdmin())
                <button type="button" class="btn btn-primary" style="margin-left: auto;" onclick="addMemberModal.showModal()">+ Add member</button>
            @endif
        </form>
    </div>

    {{-- Filter modal --}}
    <dialog id="filterModal" class="modal">
        <form method="GET" action="{{ route('members.index') }}">
            <input type="hidden" name="search" value="{{ $search }}">

            <div class="modal-head">
                <h2>Filter members</h2>
                <button type="button" class="modal-close" onclick="filterModal.close()" aria-label="Close">&times;</button>
            </div>

            <div class="modal-body">
                <div class="field">
                    <label>Type</label>
                    <div class="radio-group">
                        <label><input type="radio" name="type" value="" @checked(!$type)> All members</label>
                        <label><input type="radio" name="type" value="employee" @checked($type === 'employee')> Employees</label>
                        <label><input type="radio" name="type" value="agent" @checked($type === 'agent')> Agents</label>
                    </div>
                </div>

                <div class="field">
                    <label for="modal-department">Department</label>
                    <select id="modal-department" name="department">
                        <option value="">All departments</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected((string) $departmentId === (string) $department->id)>
                                {{ $department->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="modal-division">Division</label>
                    <select id="modal-division" name="division">
                        <option value="">All divisions</option>
                        @foreach ($divisions as $division)
                            <option value="{{ $division->id }}" @selected((string) $divisionId === (string) $division->id)>
                                {{ $division->name }} ({{ $division->member_type === \App\Models\Member::MEMBER_TYPE_AGENT ? 'Agent' : 'Employee' }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="field" style="margin-bottom: 0;">
                    <label>Status</label>
                    <div class="radio-group">
                        <label><input type="radio" name="status" value="active" @checked($status === 'active')> Active only <span style="color: var(--ink-muted);">(default)</span></label>
                        <label><input type="radio" name="status" value="inactive" @checked($status === 'inactive')> Inactive only</label>
                        <label><input type="radio" name="status" value="all" @checked($status === 'all')> All (active + inactive)</label>
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <a href="{{ route('members.index', ['search' => $search]) }}" class="btn btn-ghost">Reset filters</a>
                <button type="submit" class="btn btn-primary">Apply filters</button>
            </div>
        </form>
    </dialog>

    {{-- Add member modal — two tabs sharing one dialog: single-record form
         (unchanged from before) and CSV bulk import (new). Each tab is its
         own independent <form>, not nested inside the other — nesting a
         <form> inside another <form> is invalid HTML and silently breaks
         submission (see the fix on the per-row Deactivate button earlier). --}}
    @if (auth()->user()->isAdmin())
        <dialog id="addMemberModal" class="modal" style="max-width: 640px;">
            <div class="modal-head">
                <h2>Add member</h2>
                <button type="button" class="modal-close" onclick="addMemberModal.close()" aria-label="Close">&times;</button>
            </div>

            <div style="padding: 0 24px 12px; display: flex; gap: 8px; border-bottom: 1px solid var(--border);">
                <button type="button" id="tab-single" class="btn btn-ghost" onclick="showAddMemberTab('single')">Add single member</button>
                <button type="button" id="tab-bulk" class="btn btn-ghost" onclick="showAddMemberTab('bulk')">Bulk import (CSV)</button>
            </div>

            <div id="single-add-section">
                <form method="POST" action="{{ route('members.store') }}">
                    @csrf

                    <div class="modal-body" style="max-height: 65vh; overflow-y: auto;">
                    @if ($hasMemberCreationErrors)
                        <div class="field">
                            <p class="error" style="font-weight: 600;">Please fix the following:</p>
                            <ul style="margin: 4px 0 0; padding-left: 18px; color: var(--danger); font-size: 13px;">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <h3 style="margin-bottom: 10px;">Identity</h3>
                    <div style="display: flex; gap: 12px;">
                        <div class="field" style="flex: 1;">
                            <label for="code">Member code (optional)</label>
                            <input type="text" id="code" name="code" value="{{ old('code') }}" placeholder="Auto-generate">
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

                    <div style="display: flex; gap: 12px;">
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

                    <div style="display: flex; gap: 12px;">
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

                    <h3 style="margin: 18px 0 10px;">Assignment</h3>
                    <div style="display: flex; gap: 12px;">
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

                    <h3 style="margin: 18px 0 10px;">Benefit setup</h3>
                    <div style="display: flex; gap: 12px;">
                        <div class="field" style="flex: 1;">
                            <label for="apply_date">Apply date</label>
                            <input type="date" id="apply_date" name="apply_date" value="{{ old('apply_date') }}">
                        </div>
                        <div class="field" style="flex: 1;">
                            <label for="deduction_start_date">Deduction start</label>
                            <input type="date" id="deduction_start_date" name="deduction_start_date" value="{{ old('deduction_start_date') }}">
                        </div>
                        <div class="field" style="flex: 1;">
                            <label for="ghp_amount">GHP amount</label>
                            <input type="number" id="ghp_amount" name="ghp_amount" value="{{ old('ghp_amount', 3600) }}" step="0.01" min="0" required>
                        </div>
                    </div>
                    <p class="hint" style="margin-top: -8px; margin-bottom: 12px;">Deduction start date is required before a benefit period can be generated. Base amount is ₱3,600; ₱4,200 if the member has an eligible dependent (add dependents after saving).</p>

                    <div class="field" style="margin-bottom: 0;">
                        <label for="remarks">Remarks</label>
                        <textarea id="remarks" name="remarks" rows="2">{{ old('remarks') }}</textarea>
                    </div>
                </div>

                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost" onclick="addMemberModal.close()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save member</button>
                </div>
                </form>
            </div>

            <div id="bulk-import-section" style="display: none;">
                <form method="POST" action="{{ route('members.import') }}" enctype="multipart/form-data">
                    @csrf

                    <div class="modal-body" style="max-height: 65vh; overflow-y: auto;">
                        @if ($hasImportErrors)
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
                            <label>1. Download the template</label>
                            <p class="hint" style="margin-top: 0;">Fill it in, then upload it below. <strong>CSV only</strong> — Excel/XLSX files aren't supported.</p>
                            <a href="{{ route('members.import.template') }}" class="btn btn-ghost">Download CSV template</a>
                        </div>

                        <div class="field">
                            <label>Required columns</label>
                            <p class="hint" style="margin-top: 0;">
                                <code>email</code>, <code>member_type</code> (Employee/Agent), <code>last_name</code>,
                                <code>first_name</code>, and <code>ghp_amount</code> must be filled in for every row —
                                a row missing any of these is skipped and reported below, the rest of the file still
                                imports. <code>code</code> is optional: leave it blank to auto-generate ALSC-######.
                                Everything else (middle_name, address, birthdate, civil_status, division, department,
                                apply_date, deduction_start_date, old_code, is_active) is optional too.
                            </p>
                        </div>

                        <div class="field" style="{{ $importReport ? '' : 'margin-bottom: 0;' }}">
                            <label for="csv_file">2. Upload your CSV</label>
                            <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
                        </div>

                        @if ($importReport)
                            <div class="field" style="margin-bottom: 0;">
                                <label>Import result</label>
                                <p class="hint" style="margin-top: 0;">
                                    <strong style="color: var(--success);">{{ $importReport['created'] }}</strong> member(s) imported
                                    out of {{ $importReport['total'] }} row(s) read.
                                    @if (count($importReport['skipped']) > 0)
                                        <strong style="color: var(--danger);">{{ count($importReport['skipped']) }}</strong> row(s) skipped.
                                    @endif
                                    @if ($importReport['truncated'] ?? false)
                                        <br><strong style="color: var(--danger);">File was larger than 5,000 rows — only the first 5,000 were processed.</strong> Split the file and re-import the rest.
                                    @endif
                                </p>
                                @if (count($importReport['skipped']) > 0)
                                    <ul style="margin: 4px 0 0; padding-left: 18px; font-size: 12px; color: var(--ink-muted); max-height: 160px; overflow-y: auto;">
                                        @foreach ($importReport['skipped'] as $skip)
                                            <li>Row {{ $skip['row'] }}: {{ $skip['reason'] }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="modal-foot">
                        <button type="button" class="btn btn-ghost" onclick="addMemberModal.close()">Close</button>
                        <button type="submit" class="btn btn-primary">Import</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif

    <div class="card" id="members-results">
        @include('members._results')
    </div>

    @if (auth()->user()->isAdmin())
        <script>
            function toggleAll(source) {
                document.querySelectorAll('.member-checkbox').forEach(cb => cb.checked = source.checked);
                updateSelectedCount();
            }

            function updateSelectedCount() {
                const checked = document.querySelectorAll('.member-checkbox:checked').length;
                document.getElementById('selected-count').textContent = checked;
            }

            function submitBulk(action) {
                const checked = document.querySelectorAll('.member-checkbox:checked').length;

                if (checked === 0) {
                    alert('Select at least one member first.');
                    return;
                }

                const labels = {
                    activate: `Activate ${checked} member(s)?`,
                    deactivate: `Deactivate ${checked} member(s)? They won't be able to have benefit periods generated while inactive.`,
                    generate_benefit_period: `Generate this year's benefit period for ${checked} member(s)? Inactive members will be skipped.`,
                };

                if (! confirm(labels[action])) {
                    return;
                }

                document.getElementById('bulk_action').value = action;
                document.getElementById('bulk-form').submit();
            }

            const memberStatusUrlBase = '{{ url('members') }}';
            const csrfToken = '{{ csrf_token() }}';

            /**
             * Switches between the two tabs sharing #addMemberModal. Both
             * sections are always in the DOM (just hidden) rather than
             * swapped in/out, so the browser's native file-picker state and
             * any typed-in single-add fields survive a tab switch.
             */
            function showAddMemberTab(tab) {
                document.getElementById('single-add-section').style.display = tab === 'single' ? 'block' : 'none';
                document.getElementById('bulk-import-section').style.display = tab === 'bulk' ? 'block' : 'none';
                document.getElementById('tab-single').classList.toggle('btn-primary', tab === 'single');
                document.getElementById('tab-single').classList.toggle('btn-ghost', tab !== 'single');
                document.getElementById('tab-bulk').classList.toggle('btn-primary', tab === 'bulk');
                document.getElementById('tab-bulk').classList.toggle('btn-ghost', tab !== 'bulk');
            }

            /**
             * Builds and submits a standalone form for the Action column,
             * rather than a <form> in the Blade markup: that form sits
             * inside #bulk-form (needed for the checkboxes/bulk buttons),
             * and a <form> nested inside another <form> is invalid HTML —
             * browsers silently drop the inner tag and merge its inputs into
             * the outer form, so submitting it here would have gone to
             * members.bulk-action instead of members.update-status.
             */
            function submitMemberStatus(memberId, isActive, code) {
                const verb = isActive ? 'Deactivate' : 'Reactivate';

                if (! confirm(`${verb} ${code}?`)) {
                    return;
                }

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = memberStatusUrlBase + '/' + memberId + '/status';
                form.style.display = 'none';

                const fields = {
                    _token: csrfToken,
                    _method: 'PATCH',
                    search: @json($search),
                    type: @json($type),
                    department: @json($departmentId),
                    division: @json($divisionId),
                    status: @json($status),
                };

                for (const [name, value] of Object.entries(fields)) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value ?? '';
                    form.appendChild(input);
                }

                document.body.appendChild(form);
                form.submit();
            }

            @if ($hasMemberCreationErrors)
                showAddMemberTab('single');
                addMemberModal.showModal();
            @elseif ($hasImportErrors || $importReport)
                showAddMemberTab('bulk');
                addMemberModal.showModal();
            @else
                showAddMemberTab('single');
            @endif
        </script>
    @endif

    <script>
        // ---- Live search: results update as you type, no page reload ----
        // Available to everyone (admin or not) since browsing/searching members
        // isn't an admin-only action — only Add/Edit/bulk actions are.
        const searchInput = document.getElementById('search');
        const resultsContainer = document.getElementById('members-results');
        let searchDebounce = null;

        function currentFilterParams() {
            const params = new URLSearchParams();
            params.set('search', searchInput.value);
            @if ($type) params.set('type', @json($type)); @endif
            @if ($departmentId) params.set('department', @json($departmentId)); @endif
            @if ($divisionId) params.set('division', @json($divisionId)); @endif
            params.set('status', @json($status));
            return params;
        }

        async function liveSearch() {
            const params = currentFilterParams();
            const url = '{{ route('members.index') }}?' + params.toString();

            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (! response.ok) {
                return;
            }

            resultsContainer.innerHTML = await response.text();
            window.history.replaceState({}, '', url);
        }

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                clearTimeout(searchDebounce);
                searchDebounce = setTimeout(liveSearch, 300);
            });

            // Enter still works instantly instead of waiting for the debounce.
            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(searchDebounce);
                    liveSearch();
                }
            });
        }
    </script>
@endsection
