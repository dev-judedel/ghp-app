{{--
    ONE row of the "Reimbursement records" table (Coverage year history): one reimbursement RECORD
    (task.md §2.32 / §2.33). The same coverage year / period can hold several of these rows; each is its
    own record with its own Used / Available snapshot, status and Claim | Revert, and none is ever merged
    into or reused after another.
    Expects: $member, $record, $recordNo, $period (BenefitPeriod|null), $year, $memberLocked, $lockedNotice.
--}}
@php
    $recordStatus = $record->recordStatus();
    $recordUsed = $record->recordUsed();
    $recordAvailable = $record->recordAvailableAfter();
@endphp
<tr data-record-id="{{ $record->id }}" data-record-status="{{ strtolower($recordStatus) }}" style="{{ $record->is_voided ? 'opacity: 0.55;' : '' }}">
    <td class="code">{{ $year }}@if ($period) <span class="hint" style="margin: 0;">({{ $period->from_date->format('M Y') }} &ndash; {{ $period->to_date->format('M Y') }})</span>@endif</td>
    <td>#{{ $recordNo }}</td>
    <td>{{ $record->or_date->format('M d, Y') }}</td>
    <td class="code">{{ $record->or_no ?: '—' }}</td>
    <td class="num amount" style="{{ $record->is_voided ? 'text-decoration: line-through;' : '' }}">&#8369;{{ number_format($record->or_amount, 2) }}</td>
    {{-- Used / Available: what THIS record drew from the one GHP fund when it was filed, and what was left right after it (filing-time snapshot — never moves once the record exists, never adds entitlement). A legacy row with no snapshot shows a dash. --}}
    <td class="num amount">
        @if ($recordUsed !== null)
            &#8369;{{ number_format($recordUsed, 2) }}
        @else
            &mdash;
        @endif
    </td>
    <td class="num amount">
        @if ($recordAvailable !== null)
            &#8369;{{ number_format($recordAvailable, 2) }}
        @else
            &mdash;
        @endif
    </td>
    <td class="num amount">&#8369;{{ number_format($record->excess_amount, 2) }}</td>
    <td>
        @if ($recordStatus === 'Claimed')
            <span class="badge badge-ok" title="Claimed on {{ $record->claimed_at->format('M d, Y h:i A') }}. Locked.">Claimed</span>
        @elseif ($recordStatus === 'Reverted')
            <span class="badge badge-agent" title="Reverted {{ $record->reverted_at->format('M d, Y h:i A') }}{{ $record->revertedBy ? ' by '.$record->revertedBy->name : '' }}">Reverted</span>
        @elseif ($recordStatus === 'Voided')
            <span class="badge badge-warn">Voided</span>
        @else
            {{-- "Filed" is the stored/model state (data-record-status above); it is shown to the user as Unclaimed. --}}
            <span class="badge badge-warn" title="Filed, not yet claimed.">Unclaimed</span>
        @endif
    </td>
    @if (auth()->user()->isAdmin())
        <td style="white-space: nowrap;">
            {{-- Claim | Revert for THIS record only. Claim is offered while it is Unclaimed/Reverted; Revert only while it is Claimed (behind a confirmation modal). A voided record offers neither. The server re-checks everything under a row lock (ReimbursementClaimController) — these buttons are only a convenience. --}}
            <form method="POST" action="{{ route('members.reimbursements.claim', [$member, $record]) }}" style="display: inline;"
                onsubmit="if (! confirm('Mark Record #{{ $recordNo }} ({{ $year }}) as Claimed? It will be locked.')) { return false; } this.querySelector('button').disabled = true;">
                @csrf
                <button type="submit" class="btn btn-primary" data-record-action="claim"
                    @if ($memberLocked) disabled aria-disabled="true" title="{{ $lockedNotice }}" @elseif ($record->is_voided) disabled aria-disabled="true" title="{{ \App\Http\Controllers\ReimbursementClaimController::VOIDED }}" @elseif ($record->isClaimed()) disabled aria-disabled="true" title="This record is already Claimed." @endif
                    style="padding: 4px 10px; font-size: 12px;">Claim</button>
            </form>
            @if ($memberLocked)
                <button type="button" class="btn btn-ghost" data-record-action="revert" disabled aria-disabled="true" title="{{ $lockedNotice }}" style="padding: 4px 10px; font-size: 12px; color: var(--danger);">Revert</button>
            @elseif (! $record->isClaimed())
                <button type="button" class="btn btn-ghost" data-record-action="revert" disabled aria-disabled="true"
                    title="{{ $record->is_voided ? \App\Http\Controllers\ReimbursementClaimController::VOIDED : ($record->isReverted() ? \App\Http\Controllers\ReimbursementClaimController::ALREADY_REVERTED : \App\Http\Controllers\ReimbursementClaimController::NOTHING_TO_REVERT) }}"
                    style="padding: 4px 10px; font-size: 12px; color: var(--danger);">Revert</button>
            @else
                <button type="button" class="btn btn-ghost" data-record-action="revert" style="padding: 4px 10px; font-size: 12px; color: var(--danger);"
                    onclick="openRevertBenefitPeriod({{ $record->id }}, {{ json_encode([
                        'member' => $member->full_name,
                        'code' => $member->code,
                        'coverageYear' => (string) $year,
                        'period' => 'Record #'.$recordNo,
                        'from' => $record->or_date->format('F d, Y').($record->or_no ? ' · OR '.$record->or_no : ''),
                        'to' => '₱'.number_format($record->or_amount, 2),
                        'status' => 'Claimed',
                    ]) }})">Revert</button>
            @endif
        </td>
    @endif
</tr>
