<?php

namespace Tests\Feature\Exhibits;

use App\Models\ExhibitEmailTemplate;
use App\Models\User;
use Tests\TestCase;

class ExhibitEmailTemplateEditTest extends TestCase
{
    public function test_edit_page_renders_and_saves(): void
    {
        $template = ExhibitEmailTemplate::create([
            'key' => 'edit_page_test',
            'name' => 'Edit Page Test',
            'subject' => 'Old subject',
            'body' => 'Old body',
            'enabled' => true,
        ]);
        $admin = User::factory()->superuser()->create();

        // The merge-variable list prints literal double braces; written the
        // wrong way it stops the whole page from compiling.
        $this->actingAs($admin)
            ->get(route('exhibit-email-templates.edit', $template))
            ->assertOk()
            ->assertSee('{{student_name}}', false);

        $this->actingAs($admin)
            ->put(route('exhibit-email-templates.update', $template), [
                'subject' => 'New subject',
                'body' => 'New body',
                'enabled' => 1,
            ])
            ->assertRedirect();

        $this->assertSame('New subject', $template->refresh()->subject);
        $this->assertSame('New body', $template->body);
    }
}
