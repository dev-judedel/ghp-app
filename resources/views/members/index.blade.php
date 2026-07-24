@extends('layouts.app')

@section('title', 'Members')

@section('content')
    <div class="card">
        <form method="GET" action="{{ route('members.index') }}" style="display: flex; gap: 10px; align-items: flex-end;">
            <div class="field" style="flex: 1; margin-bottom: 0;">
                <label for="search">Search</label>
                <input type="text" id="search" name="search" value="{{ $search }}" placeholder="Name or member code&hellip;">
            </div>
            <div class="field" style="width: 180px; margin-bottom: 0;">
                <label for="type">Type</label>
                <select id="type" name="type">
                    <option value="" @selected(!$type)>All members</option>
                    <option value="employee" @selected($type === 'employee')>Employees</option>
                    <option value="agent" @selected($type === 'agent')>Agents</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Search</button>
            @if ($search || $type)
                <a href="{{ route('members.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </form>
    </div>

    <div class="card">
        @if ($members->isEmpty())
            <div class="empty-state">
                <h3>No members found</h3>
                <p>Try a different name, code, or clear your filters.</p>
            </div>
        @else
            @if (auth()->user()->isAdmin())
                <form id="bulk-form" method="POST" action="{{ route('members.bulk-action') }}">
                    @csrf
                    <input type="hidden" name="search" value="{{ $search }}">
                    <input type="hidden" name="type" value="{{ $type }}">
                    <input type="hidden" name="bulk_action" id="bulk_action" value="">

                    <div class="bulk-bar">
                        <span class="count"><span id="selected-count">0</span> selected</span>
                        <button type="button" class="btn btn-ghost" onclick="submitBulk('activate')">Mark Active</button>
                        <button type="button" class="btn btn-ghost" onclick="submitBulk('deactivate')">Mark Inactive</button>
                        <button type="button" class="btn btn-primary" onclick="submitBulk('generate_benefit_period')">Generate this year's benefit period</button>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th class="checkbox-col"><input type="checkbox" id="select-all" onclick="toggleAll(this)"></th>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Division</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th class="num">GHP amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($members as $member)
                                <tr onclick="window.location='{{ route('members.show', $member) }}'" style="cursor: pointer;">
                                    <td class="checkbox-col" onclick="event.stopPropagation()">
                                        <input type="checkbox" name="member_ids[]" value="{{ $member->id }}" class="member-checkbox" onchange="updateSelectedCount()">
                                    </td>
                                    <td class="code">{{ $member->code }}</td>
                                    <td><a href="{{ route('members.show', $member) }}">{{ $member->full_name }}</a></td>
                                    <td>
                                        <span class="badge {{ $member->member_type === \App\Models\Member::MEMBER_TYPE_AGENT ? 'badge-agent' : 'badge-employee' }}">
                                            {{ $member->member_type === \App\Models\Member::MEMBER_TYPE_AGENT ? 'Agent' : 'Employee' }}
                                        </span>
                                    </td>
                                    <td>{{ $member->division->name ?? '—' }}</td>
                                    <td>{{ $member->department->name ?? '—' }}</td>
                                    <td>
                                        <span class="badge {{ $member->is_active ? 'badge-ok' : 'badge-warn' }}">
                                            {{ $member->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td class="num amount">&#8369;{{ number_format($member->ghp_amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </form>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Division</th>
                            <th>Department</th>
                            <th class="num">GHP amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($members as $member)
                            <tr onclick="window.location='{{ route('members.show', $member) }}'" style="cursor: pointer;">
                                <td class="code">{{ $member->code }}</td>
                                <td><a href="{{ route('members.show', $member) }}">{{ $member->full_name }}</a></td>
                                <td>
                                    <span class="badge {{ $member->member_type === \App\Models\Member::MEMBER_TYPE_AGENT ? 'badge-agent' : 'badge-employee' }}">
                                        {{ $member->member_type === \App\Models\Member::MEMBER_TYPE_AGENT ? 'Agent' : 'Employee' }}
                                    </span>
                                </td>
                                <td>{{ $member->division->name ?? '—' }}</td>
                                <td>{{ $member->department->name ?? '—' }}</td>
                                <td class="num amount">&#8369;{{ number_format($member->ghp_amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <div class="pagination">
                {{ $members->links() }}
            </div>
        @endif
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
                    activate: `Mark ${checked} member(s) as Active?`,
                    deactivate: `Mark ${checked} member(s) as Inactive?`,
                    generate_benefit_period: `Generate this year's benefit period for ${checked} member(s)? Inactive members will be skipped.`,
                };

                if (! confirm(labels[action])) {
                    return;
                }

                document.getElementById('bulk_action').value = action;
                document.getElementById('bulk-form').submit();
            }
        </script>
    @endif
@endsection
