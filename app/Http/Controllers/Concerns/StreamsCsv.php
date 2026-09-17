<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait StreamsCsv
{
    /**
     * Streams a CSV using plain fputcsv() — no package needed. Shared by
     * ReportController and MemberImportController so both export/download
     * the exact same way (UTF-8 BOM so Excel doesn't mangle the ₱ sign, etc.).
     *
     * Return type is StreamedResponse (Symfony), not Illuminate\Http\Response
     * — Response::streamDownload() actually returns the former, and they are
     * siblings (not parent/child), so declaring the latter here throws a
     * TypeError at runtime the moment this method returns.
     */
    private function streamCsv(string $filename, array $headers, Collection $rows): StreamedResponse
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
