<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response as ResponseFacade;

trait StreamsCsv
{
    /**
     * Streams a CSV using plain fputcsv() — no package needed. Shared by
     * ReportController and MemberExportController so both export the exact
     * same way (UTF-8 BOM so Excel doesn't mangle the ₱ sign, etc.).
     */
    private function streamCsv(string $filename, array $headers, Collection $rows): Response
    {
        return ResponseFacade::streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM so Excel doesn't mangle the ₱ sign or special characters.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
