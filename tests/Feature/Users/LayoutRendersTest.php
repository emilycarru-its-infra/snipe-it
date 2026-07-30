<?php

namespace Tests\Feature\Users;

use App\Models\Statuslabel;
use App\Models\User;
use Tests\TestCase;

/**
 * That the main layout compiles and renders.
 *
 * It exists because a formatter run turned an inline PHP block in the sidebar
 * into one carrying a `use` statement, which PHP only permits at top-level
 * file scope. The compiled view was a parse error, so every authenticated page
 * returned 500 — while every existing test still passed, because they had been
 * run before the formatter touched the file.
 *
 * A template only has to compile once to be worth a test that renders it.
 */
class LayoutRendersTest extends TestCase
{
    public function test_the_layout_renders_for_a_superuser_with_status_navs()
    {
        // The block that broke sits behind the asset-view gate and only
        // renders with a show_in_nav status label present, so a faculty
        // render alone would not have exercised it.
        Statuslabel::factory()->create(['name' => 'Deployed', 'show_in_nav' => 1]);

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Deployed', false);
    }
}
