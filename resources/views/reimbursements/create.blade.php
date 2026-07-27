@extends('layouts.app')

@section('title', 'File Reimbursement')

@section('content')
    <div style="margin-bottom: 16px;">
        <a href="{{ route('members.show', $member) }}">&larr; Back to {{ $member->full_name }}</a>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow code">{{ $member->code }}</span>
                <h2>{{ $member->full_name }}</h2>
            </div>
        </div>
        <div class="ledger-strip">
            <div class="ledger-row">
                <span class="label">Coverage period</span>
                <span class="amount ledger">{{ $balance['from']->format('M d, Y') }} &ndash; {{ $balance['to']->format('M d, Y') }}</span>
            </div>
            <div class="ledger-row total">
                <span class="label">Available balance</span>
                <span class="amount ledger">&#8369;{{ number_format($balance['available'], 2) }}</span>
            </div>
        </div>
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

    <form method="POST" action="{{ route('members.reimbursements.store', $member) }}">
        @csrf

        <div class="card">
            <h2 style="margin-bottom: 16px;">Receipt details</h2>

            <div style="display: flex; gap: 16px;">
                <div class="field" style="flex: 1;">
                    <label for="or_no">OR number</label>
                    <input type="text" id="or_no" name="or_no" value="{{ old('or_no') }}">
                </div>
                <div class="field" style="flex: 1;">
                    <label for="or_date">OR date</label>
                    <input type="date" id="or_date" name="or_date" value="{{ old('or_date', now()->toDateString()) }}" required>
                </div>
                <div class="field" style="flex: 1;">
                    <label for="or_amount">Amount</label>
                    <input type="number" id="or_amount" name="or_amount" value="{{ old('or_amount') }}" step="0.01" min="0.01" required>
                    <p class="hint">Available balance: &#8369;{{ number_format($balance['available'], 2) }}. Amounts above this will still be recorded in full — see note after submitting.</p>
                </div>
            </div>

            <div class="field">
                <label for="hospital_name">Hospital / clinic</label>
                <input type="text" id="hospital_name" name="hospital_name" value="{{ old('hospital_name') }}">
            </div>

            <div class="field">
                <label for="description">Description</label>
                <input type="text" id="description" name="description" value="{{ old('description') }}">
            </div>

            <div class="field" style="margin-bottom: 0;">
                <label for="remarks">Remarks</label>
                <textarea id="remarks" name="remarks" rows="3">{{ old('remarks') }}</textarea>
            </div>
        </div>

        <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn btn-primary">Record reimbursement</button>
            <a href="{{ route('members.show', $member) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
@endsection
