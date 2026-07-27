<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\Reimbursement;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('dashboard', [
            'activeMemberCount' => Member::active()->count(),
            'employeeCount' => Member::active()->employees()->count(),
            'agentCount' => Member::active()->agents()->count(),
            'inactiveMemberCount' => Member::inactive()->count(),
            'reimbursementCount' => Reimbursement::count(),
        ]);
    }
}
