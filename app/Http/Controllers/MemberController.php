<?php

namespace App\Http\Controllers;

use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MemberController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $type = $request->query('type'); // 'employee' | 'agent' | null

        $members = Member::query()
            ->with(['division', 'department'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'ilike', "%{$search}%")
                        ->orWhere('last_name', 'ilike', "%{$search}%")
                        ->orWhere('first_name', 'ilike', "%{$search}%");
                });
            })
            ->when($type === 'employee', fn ($query) => $query->employees())
            ->when($type === 'agent', fn ($query) => $query->agents())
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(25)
            ->withQueryString();

        return view('members.index', [
            'members' => $members,
            'search' => $search,
            'type' => $type,
        ]);
    }

    public function show(Member $member): View
    {
        $member->load([
            'division',
            'department',
            'dependents',
            'benefitPeriods' => fn ($q) => $q->orderByDesc('from_date'),
            'reimbursements' => fn ($q) => $q->orderByDesc('or_date')->limit(20),
            'amountAdjustments' => fn ($q) => $q->orderByDesc('requested_at'),
        ]);

        $currentBenefitPeriod = $member->benefitPeriods->first();

        return view('members.show', [
            'member' => $member,
            'currentBenefitPeriod' => $currentBenefitPeriod,
        ]);
    }
}
