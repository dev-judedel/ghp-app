<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $logName = $request->query('log_name');

        $activities = Activity::query()
            ->with(['causer', 'subject'])
            ->when($logName, fn ($query) => $query->where('log_name', $logName))
            ->orderByDesc('created_at')
            ->paginate(40)
            ->withQueryString();

        $logNames = Activity::query()
            ->select('log_name')
            ->distinct()
            ->orderBy('log_name')
            ->pluck('log_name');

        return view('activity.index', [
            'activities' => $activities,
            'logNames' => $logNames,
            'logName' => $logName,
        ]);
    }
}
