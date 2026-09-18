@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">Overview &middot; active members</span>
                <h2>Fund summary</h2>
            </div>
        </div>
        <div class="ledger-strip">
            <div class="ledger-row total">
                <span class="label">Active members</span>
                <span class="amount ledger">{{ number_format($activeMemberCount) }}</span>
            </div>
            <div class="ledger-row">
                <span class="label">Active employees</span>
                <span class="amount ledger">{{ number_format($employeeCount) }}</span>
            </div>
            <div class="ledger-row">
                <span class="label">Active agents</span>
                <span class="amount ledger">{{ number_format($agentCount) }}</span>
            </div>
            <div class="ledger-row">
                <span class="label">Inactive members (hidden from list by default)</span>
                <span class="amount ledger">{{ number_format($inactiveMemberCount) }}</span>
            </div>
            <div class="ledger-row">
                <span class="label">Reimbursements filed (all time)</span>
                <span class="amount ledger">{{ number_format($reimbursementCount) }}</span>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <span class="eyebrow">Quick action</span>
                <h2>Find a member</h2>
            </div>
        </div>
        <form method="GET" action="{{ route('members.index') }}">
            <div class="field" style="margin-bottom: 12px;">
                <input type="text" name="search" placeholder="Search by name or member code&hellip;" autofocus>
            </div>
            <button type="submit" class="btn btn-primary">Search members</button>
        </form>
    </div>
@endsection
