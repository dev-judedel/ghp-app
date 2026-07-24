<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\BenefitAccrualService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberBulkActionController extends Controller
{
    public function store(Request $request, BenefitAccrualService $accrualService): RedirectResponse
    {
        $validated = $request->validate([
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer', 'exists:members,id'],
            'bulk_action' => ['required', 'in:activate,deactivate,generate_benefit_period'],
        ]);

        $members = Member::whereIn('id', $validated['member_ids'])->get();

        $message = match ($validated['bulk_action']) {
            'activate' => $this->setActive($members, true),
            'deactivate' => $this->setActive($members, false),
            'generate_benefit_period' => $this->generateBenefitPeriods($members, $accrualService),
        };

        return redirect()
            ->route('members.index', $request->only(['search', 'type']))
            ->with('status', $message);
    }

    private function setActive(Collection $members, bool $active): string
    {
        $count = 0;

        DB::transaction(function () use ($members, $active, &$count) {
            foreach ($members as $member) {
                $member->update(['is_active' => $active]);
                $count++;
            }
        });

        $label = $active ? 'activated' : 'deactivated';

        return "{$count} member(s) {$label}.";
    }

    private function generateBenefitPeriods(Collection $members, BenefitAccrualService $accrualService): string
    {
        $created = 0;
        $skippedInactive = 0;
        $skippedNoDedStart = 0;

        DB::transaction(function () use ($members, $accrualService, &$created, &$skippedInactive, &$skippedNoDedStart) {
            foreach ($members as $member) {
                if (! $member->is_active) {
                    $skippedInactive++;

                    continue;
                }

                if ($member->deduction_start_date === null) {
                    $skippedNoDedStart++;

                    continue;
                }

                $member->loadMissing('dependents', 'reimbursements', 'benefitPeriods');
                $accrualService->accrue($member);
                $created++;
            }
        });

        $message = "Generated/updated this year's benefit period for {$created} member(s).";

        if ($skippedInactive > 0) {
            $message .= " Skipped {$skippedInactive} inactive.";
        }

        if ($skippedNoDedStart > 0) {
            $message .= " Skipped {$skippedNoDedStart} with no deduction start date.";
        }

        return $message;
    }
}
