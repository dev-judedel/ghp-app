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
            'memberCount' => Member::count(),
            'employeeCount' => Member::employees()->count(),
            'agentCount' => Member::agents()->count(),
            'reimbursementCount' => Reimbursement::count(),
        ]);
    }
}
