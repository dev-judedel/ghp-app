<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsCsv;
use App\Models\Member;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Response as ResponseFacade;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class MemberExportController extends Controller
{
    use StreamsCsv;

    public function csv(Request $request): Response
    {
        $members = $this->filteredMembers($request);

        $this->logExport('CSV', $members->count(), $request);

        return $this->streamCsv(
            'members-'.now()->format('Y-m-d').'.csv',
            $this->headers(),
            $members->map(fn (Member $member) => $this->row($member))
        );
    }

    public function pdf(Request $request): Response
    {
        $members = $this->filteredMembers($request);

        $this->logExport('PDF', $members->count(), $request);

        $pdf = Pdf::loadView('reports.pdf.members', [
            'members' => $members,
            'generatedAt' => now(),
            'filters' => $request->only(['type', 'department', 'division', 'status']),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('members-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * Requires phpoffice/phpspreadsheet (composer require phpoffice/phpspreadsheet)
     * — the only export format here that isn't already covered by a package
     * already in composer.json (CSV needs none, PDF reuses dompdf).
     */
    public function excel(Request $request): Response
    {
        $members = $this->filteredMembers($request);

        $this->logExport('Excel', $members->count(), $request);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Members');

        $sheet->fromArray($this->headers(), null, 'A1');

        $rowNumber = 2;
        foreach ($members as $member) {
            $sheet->fromArray($this->row($member), null, 'A'.$rowNumber);
            $rowNumber++;
        }

        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $filename = 'members-'.now()->format('Y-m-d').'.xlsx';

        return ResponseFacade::streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Same filters as the Members index page (search/type/department/
     * division/status) via Member::filtered(), so an export always matches
     * whatever the admin currently has on screen.
     */
    private function filteredMembers(Request $request)
    {
        return Member::filtered($request->only(['search', 'type', 'department', 'division', 'status']))
            ->with(['division', 'department'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * @return list<string>
     */
    private function headers(): array
    {
        return ['Code', 'Name', 'Email', 'Type', 'Division', 'Department', 'Status', 'Date Created', 'GHP Amount'];
        // No password/authentication fields — Member has none of its own,
        // and this deliberately never touches the Users table.
    }

    /**
     * @return list<string>
     */
    private function row(Member $member): array
    {
        return [
            $member->code,
            $member->full_name,
            $member->email ?? '',
            $member->member_type === Member::MEMBER_TYPE_AGENT ? 'Agent' : 'Employee',
            $member->division->name ?? '',
            $member->department->name ?? '',
            $member->is_active ? 'Active' : 'Inactive',
            $member->created_at->format('Y-m-d'),
            $member->ghp_amount,
        ];
    }

    private function logExport(string $format, int $count, Request $request): void
    {
        activity('export')
            ->causedBy($request->user())
            ->withProperties([
                'format' => $format,
                'count' => $count,
                'filters' => $request->only(['search', 'type', 'department', 'division', 'status']),
            ])
            ->log("Exported {$count} member(s) to {$format}");
    }
}
