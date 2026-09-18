<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBenefitPeriodRequest;
use App\Models\BenefitPeriod;
use Illuminate\Http\RedirectResponse;

class BenefitPeriodController extends Controller
{
    /**
     * Direct manual correction — used from the Data Quality Report to fix
     * corrupted historical rows (e.g. a period dated 1943-09-10). This does
     * NOT go through BenefitAccrualService, deliberately: it's fixing
     * broken data, not recalculating a live balance.
     */
    public function update(UpdateBenefitPeriodRequest $request, BenefitPeriod $benefitPeriod): RedirectResponse
    {
        $benefitPeriod->update($request->validated());

        return redirect()->route('data-quality.index')
            ->with('status', "Benefit period corrected for {$benefitPeriod->member->code}.");
    }
}
