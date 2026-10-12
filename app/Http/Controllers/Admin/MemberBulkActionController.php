<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\BulkBenefitPeriodGenerator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberBulkActionController extends Controller
{
    public function store(Request $request, BulkBenefitPeriodGenerator $generator): RedirectResponse
    {
        $validated = $request->validate([
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer', 'exists:members,id'],
            // Bulk activate/deactivate for multi-select, alongside the
            // per-row Action column on the table (MemberController::
            // updateStatus) which handles the single-member case. Both
            // exist side by side on purpose — bulk for "these 20 members
            // just got laid off", per-row for "fix this one record".
            'bulk_action' => ['required', 'in:activate,deactivate,generate_benefit_period'],
        ]);

        $redirect = redirect()->route('members.index', $request->only(['search', 'type']));

        // Bulk generation is ALL OR NOTHING (task.md §2.27) and has its own
        // result shape (a notice listing the affected members), so it is
        // handled apart from the simple activate/deactivate flash message.
        if ($validated['bulk_action'] === 'generate_benefit_period') {
            return $this->flashGeneration($redirect, $generator->generate($validated['member_ids']));
        }

        $members = Member::whereIn('id', $validated['member_ids'])->get();

        $message = match ($validated['bulk_action']) {
            'activate' => $this->setActive($members, true),
            'deactivate' => $this->setActive($members, false),
        };

        return $redirect->with('status', $message);
    }

    /**
     * Read-only pre-check behind the bulk Generate button: tells the page
     * whether the selected batch would be rejected, BEFORE any confirmation is
     * shown. It writes nothing and is purely a convenience — store() repeats
     * the whole check on the server, so skipping or faking this call gains
     * nobody anything.
     */
    public function checkGenerate(Request $request, BulkBenefitPeriodGenerator $generator): JsonResponse
    {
        $validated = $request->validate([
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer', 'exists:members,id'],
        ]);

        $report = $generator->inspect($validated['member_ids']);

        return response()->json([
            'ok' => $report['existing'] === [] && $report['ineligible'] === [],
            'existing' => $report['existing'],
            'ineligible' => $report['ineligible'],
        ]);
    }

    private function flashGeneration(RedirectResponse $redirect, array $result): RedirectResponse
    {
        return match ($result['status']) {
            'generated' => $redirect->with(
                'status',
                "Benefit Period Generated Successfully — Benefit Periods were successfully generated for {$result['count']} selected member(s)."
            ),
            'blocked' => $redirect->with('bulk_generation_report', [
                'existing' => $result['existing'],
                'ineligible' => $result['ineligible'],
            ]),
            default => $redirect->with('status', 'Generation Not Proceeded — '.$result['message']),
        };
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
}
