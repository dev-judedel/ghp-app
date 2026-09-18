@extends('layouts.app')

@section('title', 'Data Quality')

@php
    $hasCorrectionErrors = $errors->any() && $errors->has('from_date');
@endphp

@section('content')
    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">{{ $badDatePeriods->count() }} flagged</span>
                <h2>Benefit periods with implausible dates</h2>
            </div>
        </div>
        <p class="hint" style="margin-bottom: 12px;">A period whose start date doesn't match the expected coverage-year start for the member's type (Apr 1 for Employees, Jun 1 for Agents), or falls outside a plausible year range. The peso amounts on these rows are usually legitimate — just mis-dated.</p>

        @if ($badDatePeriods->isEmpty())
            <div class="empty-state"><p>None found.</p></div>
        @else
            <table>
                <thead>
                    <tr><th>Member</th><th>From</th><th>To</th><th class="num">GHP amount</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($badDatePeriods as $period)
                        <tr>
                            <td><a href="{{ route('members.show', $period->member) }}">{{ $period->member->code }} &middot; {{ $period->member->full_name }}</a></td>
                            <td class="ledger">{{ $period->from_date->format('M d, Y') }}</td>
                            <td class="ledger">{{ $period->to_date->format('M d, Y') }}</td>
                            <td class="num amount">&#8369;{{ number_format($period->ghp_amount, 2) }}</td>
                            <td>
                                <button type="button" class="btn btn-ghost" style="padding: 4px 10px; font-size: 12px;"
                                    onclick="openCorrectPeriod({{ $period->id }}, {{ json_encode($period->from_date->toDateString()) }}, {{ json_encode($period->to_date->toDateString()) }}, {{ $period->ghp_amount }}, {{ $period->ghp_used }}, {{ $period->ghp_available }}, {{ json_encode($period->member->code) }})">
                                    Correct
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">{{ $zeroAmountActive->count() }} flagged</span>
                <h2>Active members with &#8369;0 GHP amount</h2>
            </div>
        </div>
        <p class="hint" style="margin-bottom: 12px;">Usually means a member who left the company but was never marked Inactive. Review and update their status on their member page.</p>

        @if ($zeroAmountActive->isEmpty())
            <div class="empty-state"><p>None found.</p></div>
        @else
            <table>
                <thead>
                    <tr><th>Code</th><th>Name</th><th>Type</th></tr>
                </thead>
                <tbody>
                    @foreach ($zeroAmountActive as $member)
                        <tr onclick="window.location='{{ route('members.show', $member) }}'" style="cursor: pointer;">
                            <td class="code">{{ $member->code }}</td>
                            <td><a href="{{ route('members.show', $member) }}">{{ $member->full_name }}</a></td>
                            <td>{{ $member->member_type === \App\Models\Member::MEMBER_TYPE_AGENT ? 'Agent' : 'Employee' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">{{ $missingPeriods->count() }} flagged</span>
                <h2>Active members with no benefit period on record</h2>
            </div>
        </div>
        <p class="hint" style="margin-bottom: 12px;">Has a deduction start date but no benefit period was ever generated. The daily auto-generation job should catch these going forward — use "Generate this year's benefit period" on their page to create one now.</p>

        @if ($missingPeriods->isEmpty())
            <div class="empty-state"><p>None found.</p></div>
        @else
            <table>
                <thead>
                    <tr><th>Code</th><th>Name</th><th>Deduction start</th></tr>
                </thead>
                <tbody>
                    @foreach ($missingPeriods as $member)
                        <tr onclick="window.location='{{ route('members.show', $member) }}'" style="cursor: pointer;">
                            <td class="code">{{ $member->code }}</td>
                            <td><a href="{{ route('members.show', $member) }}">{{ $member->full_name }}</a></td>
                            <td>{{ $member->deduction_start_date->format('M d, Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">Reference only</span>
                <h2>Orphaned legacy records (never imported)</h2>
            </div>
        </div>

        @if ($legacyOrphans === null)
            <p class="hint">The legacy staging database isn't reachable right now (this is expected once you're done reviewing history — it's not needed for normal operation). Counts from the original import are documented in the migration analysis instead.</p>
        @else
            <p class="hint" style="margin-bottom: 12px;">
                These row counts reference the legacy <code>ghp_legacy</code> staging database. They were skipped during import because their member code has no matching record here — recovering any of them means deciding whether that member should be reconstructed, which is a business call, not something this report does automatically.
            </p>
            <table>
                <thead><tr><th>Legacy table</th><th class="num">Orphaned rows</th></tr></thead>
                <tbody>
                    @foreach ($legacyOrphans as $table => $count)
                        <tr><td class="code">{{ $table }}</td><td class="num ledger">{{ $count }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Correct benefit period modal --}}
    <dialog id="correctPeriodModal" class="modal">
        <form method="POST" id="correctPeriodForm">
            @csrf
            @method('PUT')

            <div class="modal-head">
                <h2>Correct benefit period &mdash; <span id="correctPeriodMemberCode"></span></h2>
                <button type="button" class="modal-close" onclick="correctPeriodModal.close()" aria-label="Close">&times;</button>
            </div>

            <div class="modal-body">
                @if ($hasCorrectionErrors)
                    <div class="field">
                        <p class="error" style="font-weight: 600;">Please fix the following:</p>
                        <ul style="margin: 4px 0 0; padding-left: 18px; color: var(--danger); font-size: 13px;">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div style="display: flex; gap: 12px;">
                    <div class="field" style="flex: 1;">
                        <label for="cp_from_date">From date</label>
                        <input type="date" id="cp_from_date" name="from_date" required>
                    </div>
                    <div class="field" style="flex: 1;">
                        <label for="cp_to_date">To date</label>
                        <input type="date" id="cp_to_date" name="to_date" required>
                    </div>
                </div>

                <div style="display: flex; gap: 12px;">
                    <div class="field" style="flex: 1;">
                        <label for="cp_ghp_amount">GHP amount</label>
                        <input type="number" id="cp_ghp_amount" name="ghp_amount" step="0.01" min="0" required>
                    </div>
                    <div class="field" style="flex: 1;">
                        <label for="cp_ghp_used">Used</label>
                        <input type="number" id="cp_ghp_used" name="ghp_used" step="0.01" min="0" required>
                    </div>
                    <div class="field" style="flex: 1; margin-bottom: 0;">
                        <label for="cp_ghp_available">Available</label>
                        <input type="number" id="cp_ghp_available" name="ghp_available" step="0.01" min="0" required>
                    </div>
                </div>
                <p class="hint">This corrects the row directly — it does not go through the accrual calculation, since the goal is fixing broken data, not recalculating a live balance.</p>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" onclick="correctPeriodModal.close()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save correction</button>
            </div>
        </form>
    </dialog>

    <script>
        const benefitPeriodUpdateUrlBase = '{{ url('benefit-periods') }}';

        function openCorrectPeriod(id, fromDate, toDate, ghpAmount, ghpUsed, ghpAvailable, memberCode) {
            document.getElementById('correctPeriodForm').action = benefitPeriodUpdateUrlBase + '/' + id;
            document.getElementById('correctPeriodMemberCode').textContent = memberCode;
            document.getElementById('cp_from_date').value = fromDate;
            document.getElementById('cp_to_date').value = toDate;
            document.getElementById('cp_ghp_amount').value = ghpAmount;
            document.getElementById('cp_ghp_used').value = ghpUsed;
            document.getElementById('cp_ghp_available').value = ghpAvailable;
            correctPeriodModal.showModal();
        }

        @if ($hasCorrectionErrors)
            correctPeriodModal.showModal();
        @endif
    </script>
@endsection
