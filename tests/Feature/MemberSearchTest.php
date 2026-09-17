<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression test for a bug where the search query used the 'ilike'
     * operator (Postgres-only) against a MySQL/SQLite database, causing a
     * QueryException — i.e. the Dashboard's "Search members" button (and the
     * Members page's own live search) errored out instead of returning
     * results as soon as a search term was entered.
     */
    public function test_searching_by_partial_name_returns_matching_members(): void
    {
        $user = User::factory()->create();
        Member::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
        Member::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);

        $response = $this->actingAs($user)->get(route('members.index', ['search' => 'juan']));

        $response->assertOk();
        $response->assertSee('Dela Cruz');
        $response->assertDontSee('Santos');
    }

    public function test_searching_by_member_code_returns_the_matching_member(): void
    {
        $user = User::factory()->create();
        $member = Member::factory()->create(['code' => 'E100']);
        Member::factory()->create(['code' => 'E200']);

        $response = $this->actingAs($user)->get(route('members.index', ['search' => 'E100']));

        $response->assertOk();
        $response->assertSee($member->code);
        $response->assertDontSee('E200');
    }

    public function test_a_search_with_no_matches_shows_the_empty_state(): void
    {
        $user = User::factory()->create();
        Member::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

        $response = $this->actingAs($user)->get(route('members.index', ['search' => 'zzz-no-match']));

        $response->assertOk();
        $response->assertSee('No members found');
    }

    public function test_an_empty_search_term_lists_all_active_members(): void
    {
        $user = User::factory()->create();
        Member::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
        Member::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);

        $response = $this->actingAs($user)->get(route('members.index', ['search' => '']));

        $response->assertOk();
        $response->assertSee('Dela Cruz');
        $response->assertSee('Santos');
    }

    public function test_the_live_search_ajax_request_returns_only_the_results_partial(): void
    {
        $user = User::factory()->create();
        Member::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

        $response = $this->actingAs($user)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('members.index', ['search' => 'juan']));

        $response->assertOk();
        $response->assertSee('Dela Cruz');
        // The full page layout (sidebar) shouldn't be in an AJAX response.
        $response->assertDontSee('sidebar-brand');
    }
}
