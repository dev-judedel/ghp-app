<?php

namespace App\Http\Middleware;

use App\Models\BenefitPeriod;
use App\Models\Member;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backend half of "a deactivated member is read-only".
 *
 * Applied (alias `member.active`, see routes/web.php) to every route that
 * creates, edits, removes, voids or otherwise PROCESSES a member's records —
 * member edit, dependents, reimbursements, GHP amount adjustments, benefit
 * period generation/correction. If the member (the route's {member}, or the
 * owner of its {benefitPeriod}) has `is_active = false` the request is
 * rejected BEFORE the controller runs, so nothing is created, changed or
 * recalculated, whatever the page showed or however the request was built.
 *
 * It reads the existing `members.is_active` flag — there is no second status
 * field. Viewing, printing, downloading and e-mailing existing records are not
 * behind this middleware, and neither are the status routes that reactivate a
 * member (members.update-status / members.bulk-action).
 *
 * The same message is shown in the member page's banner and button tooltips
 * (MESSAGE), so the UI and the server always say the same thing.
 */
class EnsureMemberIsActive
{
    public const MESSAGE = 'This member is deactivated. All actions and modifications are disabled.';

    public function handle(Request $request, Closure $next): Response
    {
        $member = $this->resolveMember($request);

        if ($member !== null && ! $member->is_active) {
            if ($request->expectsJson()) {
                return response()->json(['message' => self::MESSAGE], 403);
            }

            // Fixed internal destinations (never the Referer header): the
            // Data Quality page for period corrections, the member's own page
            // for everything else.
            $destination = $request->routeIs('benefit-periods.*')
                ? route('data-quality.index')
                : route('members.show', $member);

            return redirect()->to($destination)->with('status', self::MESSAGE);
        }

        return $next($request);
    }

    /**
     * The route parameter may already be a bound model or still a raw id,
     * depending on where SubstituteBindings sits relative to this middleware.
     */
    private function resolveMember(Request $request): ?Member
    {
        $member = $request->route('member');

        if ($member instanceof Member) {
            return $member;
        }

        if ($member !== null) {
            return Member::find($member);
        }

        $period = $request->route('benefitPeriod');

        if ($period instanceof BenefitPeriod) {
            return $period->member;
        }

        if ($period !== null) {
            return BenefitPeriod::find($period)?->member;
        }

        return null;
    }
}
