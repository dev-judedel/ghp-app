@extends('layouts.app')

@section('title', 'Reimbursements — '.$benefitPeriod->from_date->format('M Y').' to '.$benefitPeriod->to_date->format('M Y'))

@section('content')
    @include('members._excess-styles')

    <div style="margin-bottom: 16px;">
        <a href="{{ route('members.show', $member) }}">&larr; Back to {{ $member->full_name }}</a>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow code">{{ $member->code }}</span>
                <h2>{{ $benefitPeriod->from_date->format('M d, Y') }} &ndash; {{ $benefitPeriod->to_date->format('M d, Y') }}</h2>
            </div>
            <div style="display: flex; gap: 8px;">
                <a href="{{ route('members.benefit-periods.reimbursements.pdf', ['member' => $member, 'benefitPeriod' => $benefitPeriod]) }}" class="btn btn-ghost" target="_blank">Print</a>
                <a href="{{ route('members.benefit-periods.reimbursements.pdf', ['member' => $member, 'benefitPeriod' => $benefitPeriod, 'download' => 1]) }}" class="btn btn-primary">Download PDF</a>
                @if (auth()->user()->isAdmin())
                    <button type="button" class="btn btn-ghost" onclick="sendReceiptModal.showModal()">Send</button>
                @endif
            </div>
        </div>

        <div class="ledger-strip">
            <div class="ledger-row">
                <span class="label">Member</span>
                <span class="amount ledger">{{ $member->full_name }}</span>
            </div>
            <div class="ledger-row">
                <span class="label">GHP amount (this period)</span>
                <span class="amount ledger">&#8369;{{ number_format($benefitPeriod->ghp_amount, 2) }}</span>
            </div>
            <div class="ledger-row total">
                <span class="label">Total reimbursed (this period)</span>
                <span class="amount ledger">&#8369;{{ number_format($total, 2) }}</span>
            </div>
            @if ($excessTotal > 0)
                {{-- Automatic excess GHP. Tracking only: not part of the total above, not GHP usage, not subtracted from Available GHP. --}}
                <div class="ledger-row excess-line">
                    <span class="label">Excess deduction (tracking only)</span>
                    <span class="amount ledger">&#8369;{{ number_format($excessTotal, 2) }}</span>
                </div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">{{ $reimbursements->count() }} record(s)</span>
                <h2>Reimbursements</h2>
            </div>
        </div>

        @if ($reimbursements->isEmpty())
            <div class="empty-state">
                <h3>No reimbursement records found for:</h3>
                <p>{{ $benefitPeriod->from_date->format('M d, Y') }} &ndash; {{ $benefitPeriod->to_date->format('M d, Y') }}</p>
            </div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Reimbursement period</th>
                        <th>OR date</th>
                        <th>OR no.</th>
                        <th>Hospital</th>
                        <th class="num">Amount</th>
                        <th class="num">Available GHP</th>
                        <th class="num">Excess Deduction</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reimbursements as $reimbursement)
                        {{-- Red ONLY for an active claim whose automatic excess GHP is above zero. --}}
                        <tr @if ($reimbursement->hasExcess()) data-excess="1" class="excess-row" @endif style="{{ $reimbursement->is_voided ? 'opacity: 0.55;' : '' }}">
                            <td>{{ $reimbursement->or_date->format('F Y') }}</td>
                            <td>{{ $reimbursement->or_date->format('M d, Y') }}</td>
                            <td class="code">{{ $reimbursement->or_no ?: '—' }}</td>
                            <td>{{ $reimbursement->hospital_name ?: '—' }}</td>
                            <td class="num amount" style="{{ $reimbursement->is_voided ? 'text-decoration: line-through;' : '' }}">&#8369;{{ number_format($reimbursement->or_amount, 2) }}</td>
                            <td class="num amount">{{ $reimbursement->available_ghp !== null ? '₱'.number_format($reimbursement->available_ghp, 2) : '—' }}</td>
                            <td class="num amount">
                                @if ($reimbursement->hasExcess())
                                    <span class="excess-badge">&#8369;{{ number_format($reimbursement->excess_amount, 2) }}</span>
                                @else
                                    &#8369;{{ number_format($reimbursement->excess_amount, 2) }}
                                @endif
                                @if ($reimbursement->hasCoveredExcess())
                                    <div style="font-size: 11px; font-weight: normal; color: var(--ink-muted);">
                                        remaining &middot; original &#8369;{{ number_format($reimbursement->original_excess_amount, 2) }}, covered &#8369;{{ number_format($reimbursement->excess_covered_amount, 2) }}
                                    </div>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $reimbursement->is_voided ? 'badge-warn' : 'badge-ok' }}">
                                    {{ $reimbursement->is_voided ? 'Voided' : 'Active' }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="ledger-strip" style="margin-top: 14px;">
                <div class="ledger-row">
                    <span class="label">Total reimbursement records</span>
                    <span class="amount ledger">{{ $reimbursements->count() }}@if($voidedCount) &nbsp;({{ $voidedCount }} voided, excluded from total)@endif</span>
                </div>
                <div class="ledger-row total">
                    <span class="label">Total reimbursement amount</span>
                    <span class="amount ledger">&#8369;{{ number_format($total, 2) }}</span>
                </div>
                <div class="ledger-row {{ $excessTotal > 0 ? 'excess-line' : '' }}">
                    <span class="label">Total excess deduction (remaining)</span>
                    <span class="amount ledger">&#8369;{{ number_format($excessTotal, 2) }}</span>
                </div>
                @if ($excessCoveredTotal > 0)
                    <div class="ledger-row">
                        <span class="label">Excess covered by dependent benefit (original &#8369;{{ number_format($excessOriginalTotal, 2) }})</span>
                        <span class="amount ledger">&#8369;{{ number_format($excessCoveredTotal, 2) }}</span>
                    </div>
                @endif
            </div>

            <p class="hint" style="margin-top: 10px; margin-bottom: 0;">
                Excess deduction = reimbursement amount &minus; the Available GHP at the time it was filed. It is calculated automatically when a reimbursement is filed (nothing is entered by hand) and is recorded for tracking and reporting only: it is not a reimbursement, not a GHP contribution, and does not change GHP usage, the available balance or the original GHP requirements.
            </p>
        @endif
    </div>

    {{-- Older excess deductions that were recorded by hand before excess GHP became automatic. Read-only history: kept, never deleted, never counted as GHP usage. Shown only when this period has any. --}}
    @if ($excessDeductions->isNotEmpty())
        <div class="card excess-card">
            <div class="card-head">
                <div>
                    <span class="eyebrow excess-note">{{ $excessDeductions->count() }} record(s) &middot; historical, tracking only</span>
                    <h2>Previously recorded excess deductions</h2>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Deduction month</th>
                        <th>Benefit period</th>
                        <th class="num">Required</th>
                        <th class="num">Actual deduction</th>
                        <th class="num">Excess</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($excessDeductions as $excessDeduction)
                        <tr class="excess-row" data-excess-record="1">
                            <td>{{ $excessDeduction->deduction_month->format('F Y') }}</td>
                            <td>{{ $benefitPeriod->from_date->format('M Y') }} &ndash; {{ $benefitPeriod->to_date->format('M Y') }}</td>
                            <td class="num amount">&#8369;{{ number_format($excessDeduction->required_amount, 2) }}</td>
                            <td class="num amount">&#8369;{{ number_format($excessDeduction->actual_deduction, 2) }}</td>
                            <td class="num amount"><strong>&#8369;{{ number_format($excessDeduction->excess_amount, 2) }}</strong></td>
                            <td>{{ $excessDeduction->remarks ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Send receipt by email. The recipient is always the email saved on the member's record (read server-side); this modal only shows it. --}}
    @if (auth()->user()->isAdmin())
        @php $memberEmail = trim((string) $member->email); @endphp

        <dialog id="sendReceiptModal" class="modal">
            <form method="POST" action="{{ route('members.benefit-periods.reimbursements.send', ['member' => $member, 'benefitPeriod' => $benefitPeriod]) }}" onsubmit="return lockSendReceiptSubmit();">
                @csrf

                <div class="modal-head">
                    <h2>Send reimbursement receipt</h2>
                    <button type="button" class="modal-close" onclick="sendReceiptModal.close()" aria-label="Close">&times;</button>
                </div>

                <div class="modal-body">
                    @error('note')
                        <p class="error" style="margin-bottom: 10px;">{{ $message }}</p>
                    @enderror

                    <div class="field">
                        <label for="send_receipt_to">To</label>
                        @if ($memberEmail !== '')
                            <input type="email" id="send_receipt_to" value="{{ $memberEmail }}" readonly>
                            <p class="hint">The email saved on {{ $member->code }}'s record. To change it, use Edit member.</p>
                        @else
                            <input type="text" id="send_receipt_to" value="No email on file" readonly>
                            <p class="error">{{ $member->code }} has no email address, so nothing can be sent. Add one under Edit member first.</p>
                        @endif
                    </div>

                    <div class="field">
                        <label>Attachment</label>
                        <div>Reimbursement receipt (PDF) &mdash; {{ $benefitPeriod->from_date->format('M d, Y') }} &ndash; {{ $benefitPeriod->to_date->format('M d, Y') }}</div>
                    </div>

                    <div class="field" style="margin-bottom: 0;">
                        <label for="send_receipt_note">Message (optional)</label>
                        <textarea id="send_receipt_note" name="note" rows="3" maxlength="1000" placeholder="Add a short note to include in the email">{{ old('note') }}</textarea>
                        <p class="hint">The system checks that the email address is valid and that the mail server accepts it. If it doesn't exist or can't be reached, you'll be told and nothing is marked as sent.</p>
                    </div>
                </div>

                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost" onclick="sendReceiptModal.close()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="sendReceiptSubmit" @disabled($memberEmail === '')>Send receipt</button>
                </div>
            </form>
        </dialog>

        <script>
            // Double-click protection while the mail server responds; restored on Back (bfcache) or after a timeout.
            let sendReceiptSubmitting = false;

            function resetSendReceiptSubmit() {
                const submit = document.getElementById('sendReceiptSubmit');

                sendReceiptSubmitting = false;

                if (submit) {
                    submit.disabled = {{ $memberEmail === '' ? 'true' : 'false' }};
                    submit.textContent = 'Send receipt';
                }
            }

            function lockSendReceiptSubmit() {
                if (sendReceiptSubmitting) {
                    return false;
                }

                sendReceiptSubmitting = true;

                const submit = document.getElementById('sendReceiptSubmit');
                submit.disabled = true;
                submit.textContent = 'Sending\u2026';

                setTimeout(resetSendReceiptSubmit, 30000);

                return true;
            }

            window.addEventListener('pageshow', resetSendReceiptSubmit);
        </script>

        @if ($errors->has('note'))
            <script>sendReceiptModal.showModal();</script>
        @endif
    @endif
@endsection
