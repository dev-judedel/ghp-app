<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the exact scenarios from the filter-persistence bug report:
 * reactivating/deactivating a member must NOT change the admin's selected
 * status filter — only the member's own status changes.
 */
class MemberFilterPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_reactivating_from_the_inactive_filter_redirects_back_to_the_inactive_filter(): void
    {
        $member = Member::factory()->create(['is_active' => false, 'resignation_date' => '2026-01-01']);

        $response = $this->actingAs($this->admin())
            ->patch(route('members.update-status', $member), ['status' => 'inactive']);

        $response->assertRedirect(route('members.index', ['status' => 'inactive']));
    }

    public function test_after_reactivating_the_inactive_filtered_page_no_longer_lists_that_member(): void
    {
        $admin = $this->admin();
        $member = Member::factory()->create(['is_active' => false, 'resignation_date' => '2026-01-01', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
        $stillInactive = Member::factory()->create(['is_active' => false, 'resignation_date' => '2026-01-01', 'first_name' => 'Pedro', 'last_name' => 'Santos']);

        $this->actingAs($admin)->patch(route('members.update-status', $member), ['status' => 'inactive']);

        $page = $this->actingAs($admin)->get(route('members.index', ['status' => 'inactive']));

        $page->assertOk();
        $page->assertDontSee('Dela Cruz, Juan'); // reactivated — no longer inactive
        $page->assertSee('Santos, Pedro');        // still inactive — still listed
    }

    public function test_deactivating_from_the_active_filter_redirects_back_to_the_active_filter(): void
    {
        $member = Member::factory()->create(['is_active' => true]);

        $response = $this->actingAs($this->admin())
            ->patch(route('members.update-status', $member), [
                'status' => 'active',
                'resignation_date' => '2026-09-18',
            ]);

        $response->assertRedirect(route('members.index', ['status' => 'active']));
    }

    public function test_after_deactivating_the_active_filtered_page_no_longer_lists_that_member(): void
    {
        $admin = $this->admin();
        $member = Member::factory()->create(['is_active' => true, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
        $stillActive = Member::factory()->create(['is_active' => true, 'first_name' => 'Maria', 'last_name' => 'Garcia']);

        $this->actingAs($admin)->patch(route('members.update-status', $member), [
            'status' => 'active',
            'resignation_date' => '2026-09-18',
        ]);

        $page = $this->actingAs($admin)->get(route('members.index', ['status' => 'active']));

        $page->assertOk();
        $page->assertDontSee('Dela Cruz, Juan'); // deactivated — no longer active
        $page->assertSee('Garcia, Maria');        // still active — still listed
    }

    public function test_the_all_filter_is_preserved_after_reactivating(): void
    {
        $member = Member::factory()->create(['is_active' => false, 'resignation_date' => '2026-01-01']);

        $response = $this->actingAs($this->admin())
            ->patch(route('members.update-status', $member), ['status' => 'all']);

        $response->assertRedirect(route('members.index', ['status' => 'all']));
    }

    public function test_the_all_filter_is_preserved_after_deactivating(): void
    {
        $member = Member::factory()->create(['is_active' => true]);

        $response = $this->actingAs($this->admin())
            ->patch(route('members.update-status', $member), [
                'status' => 'all',
                'resignation_date' => '2026-09-18',
            ]);

        $response->assertRedirect(route('members.index', ['status' => 'all']));
    }

    public function test_search_term_is_preserved_alongside_the_filter_after_reactivating(): void
    {
        $member = Member::factory()->create(['is_active' => false, 'resignation_date' => '2026-01-01']);

        $response = $this->actingAs($this->admin())
            ->patch(route('members.update-status', $member), [
                'status' => 'inactive',
                'search' => 'Juan',
            ]);

        $response->assertStatus(302);

        // Comparing query params individually rather than the whole URL as
        // one string — $request->only([...]) in the controller returns keys
        // in a fixed order ('search' before 'status'), which produces a
        // functionally identical but differently-ordered query string
        // ('?search=Juan&status=inactive') than a naive expected-URL string
        // would assume. Order never matters for query params; this checks
        // what actually matters: both values are present and correct.
        $query = [];
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY) ?? '', $query);

        $this->assertSame('inactive', $query['status'] ?? null);
        $this->assertSame('Juan', $query['search'] ?? null);
    }

    public function test_the_status_filter_radio_button_stays_selected_on_the_redirected_page(): void
    {
        $admin = $this->admin();
        $member = Member::factory()->create(['is_active' => false, 'resignation_date' => '2026-01-01']);

        $this->actingAs($admin)->patch(route('members.update-status', $member), ['status' => 'inactive']);

        $page = $this->actingAs($admin)->get(route('members.index', ['status' => 'inactive']));

        // The filter modal's "Inactive only" radio should render as checked.
        $page->assertSee('name="status" value="inactive" checked', false);
    }
}
