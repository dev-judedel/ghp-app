<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsCsv;
use App\Http\Requests\StoreMemberImportRequest;
use App\Services\MemberCsvImportService;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberImportController extends Controller
{
    use StreamsCsv;

    public function template(): StreamedResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        return $this->streamCsv(
            'member-import-template.csv',
            MemberCsvImportService::TEMPLATE_HEADERS,
            collect([
                [
                    '', 'juan.delacruz@example.com', 'Employee', 'Dela Cruz', 'Juan', 'Santos',
                    '123 Main St, Quezon City', '1990-05-14', 'Single', '', '',
                    '2026-01-01', '2026-01-01', '3600', '', 'Active',
                ],
            ])
        );
    }

    public function import(StoreMemberImportRequest $request, MemberCsvImportService $importer): RedirectResponse
    {
        $report = $importer->import($request->file('csv_file'));

        activity('import')
            ->causedBy($request->user())
            ->withProperties($report)
            ->log("Imported {$report['created']} member(s) via CSV (".count($report['skipped']).' skipped)');

        return redirect()
            ->route('members.index')
            ->with('import_report', $report);
    }
}
