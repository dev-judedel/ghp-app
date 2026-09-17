<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for a pre-existing bug (not introduced by this
 * session's work, just found while fixing MemberImportController's
 * template download): streamCsv() was declared to return
 * Illuminate\Http\Response, but Response::streamDownload() actually
 * returns Symfony\Component\HttpFoundation\StreamedResponse — a sibling
 * type, not a subtype — so every CSV export controller-wide threw a
 * TypeError the moment it tried to return. This had no prior test
 * coverage, which is why it went unnoticed.
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_annual_ghp_csv_export_does_not_throw_a_type_error(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->get(route('reports.annual-ghp.csv'));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_reimbursements_csv_export_does_not_throw_a_type_error(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->get(route('reports.reimbursements.csv', [
            'from' => now()->subMonth()->format('Y-m-d'),
            'to' => now()->format('Y-m-d'),
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }
}
