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
            <input type="hidden" name="department" value="{{ $departmentId }}">
            <input type="hidden" name="division" value="{{ $divisionId }}">
            <input type="hidden" name="status" value="{{ $status }}">
            <input type="hidden" name="bulk_action" id="bulk_action" value="">

            {{-- Bulk actions for multi-select. The per-row "Deactivate"/
                 "Reactivate" button in the Action column (further down) is
                 for a single member — this bar acts on everything currently
                 checked at once. --}}
            <div class="bulk-bar">
                <span class="count"><span id="selected-count">0</span> selected</span>
                <button type="button" class="btn btn-ghost" onclick="submitBulk('activate')">Activate</button>
                <button type="button" class="btn btn-ghost" onclick="submitBulk('deactivate')">Deactivate</button>
                <button type="button" class="btn btn-primary" onclick="submitBulk('generate_benefit_period')">Generate this year's benefit period</button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th class="checkbox-col"><input type="checkbox" id="select-all" onclick="toggleAll(this)"></th>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Type</th>
                        <th>Division</th>
                        <th>Department</th>
                        <th>Status</th>
                        <th class="num">GHP amount</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($members as $member)
                        <tr>
                            <td class="checkbox-col">
                                <input type="checkbox" name="member_ids[]" value="{{ $member->id }}" class="member-checkbox" onchange="updateSelectedCount()">
                            </td>
                            <td class="code"><a href="{{ route('members.show', $member) }}">{{ $member->code }}</a></td>
                            <td><a href="{{ route('members.show', $member) }}">{{ $member->full_name }}</a></td>
                            <td>{{ $member->email ?? '—' }}</td>
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
                            <td style="white-space: nowrap;">
                                <button type="button" class="btn btn-ghost" style="padding: 4px 10px; font-size: 12px; {{ $member->is_active ? '' : 'color: var(--success);' }}"
                                    onclick="submitMemberStatus({{ $member->id }}, {{ $member->is_active ? 'true' : 'false' }}, {{ json_encode($member->code) }})">
                                    {{ $member->is_active ? 'Deactivate' : 'Reactivate' }}
                                </button>
                            </td>
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
                    <th>Email</th>
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
                        <td>{{ $member->email ?? '—' }}</td>
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
