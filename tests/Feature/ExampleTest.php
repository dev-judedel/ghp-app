<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root path has no page of its own — it always redirects to the
     * dashboard (see routes/web.php). This replaces Laravel's default
     * scaffold test, which asserted a 200 here and had been failing/unused
     * ever since this app was built (the app never had a "home page" at
     * '/' that would return 200 directly).
     */
    public function test_the_root_path_redirects_to_the_dashboard(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('dashboard'));
    }
}
