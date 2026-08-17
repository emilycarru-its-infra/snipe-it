<?php

namespace Tests\Feature\Procurement;

use App\Models\DeploymentWave;
use App\Models\User;
use Tests\TestCase;

/**
 * The procurement navigation round: the allocation workbench on its own
 * page, forms living under /admin, the agreements ledger opening on the
 * current program year, and waves naming their own intake form.
 */
class ProcurementNavRoundTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_unmatched_arrivals_live_on_their_own_page()
    {
        $this->actingAs($this->superuser())
            ->get(route('orders.unmatched'))
            ->assertOk()
            ->assertSee(trans('admin/orders/general.allocation_heading'));

        // The orders list itself no longer carries the panel.
        $this->actingAs($this->superuser())
            ->get(route('orders.index'))
            ->assertOk()
            ->assertDontSee(trans('admin/orders/general.allocation_intro'));
    }

    public function test_forms_moved_to_admin_and_old_urls_redirect()
    {
        $this->assertStringContainsString('/admin/forms', route('forms.index'));

        // Emailed links keep working: the old prefix walks to the new one.
        $this->actingAs($this->superuser())
            ->get('/procurement/forms/faculty-program')
            ->assertRedirect('/admin/forms/faculty-program');

        $this->actingAs($this->superuser())
            ->get('/procurement/forms')
            ->assertRedirect('/admin/forms');
    }

    public function test_agreements_ledger_opens_on_the_current_fiscal_year()
    {
        // An agreement in the current program year makes the FY available.
        $sy = now()->month >= 4 ? now()->year : now()->year - 1;
        $currentFy = sprintf('FY%d-%02d', $sy, ($sy + 1) % 100);

        $response = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.user-agreement-ledger'))
            ->assertOk();

        $this->assertEquals($currentFy, $response->viewData('selectedFy'));

        // An explicit "all" still wins.
        $all = $this->actingAs($this->superuser())
            ->get(route('reports.procurement.user-agreement-ledger', ['fiscal_year' => 'all']))
            ->assertOk();
        $this->assertNull($all->viewData('selectedFy'));
    }

    public function test_a_wave_names_its_form_and_the_announcement_links_it()
    {
        $wave = DeploymentWave::create(['name' => 'Form Wave', 'fiscal_year' => 'FY2026-27']);

        $this->actingAs($this->superuser())
            ->post(route('deployment-waves.update', $wave), [
                '_method' => 'PUT',
                'field' => 'form_key',
                'value' => 'faculty-program',
            ]);

        $this->assertEquals('faculty-program', $wave->fresh()->form_key);

        $announcer = new \App\Services\Deployments\WaveAnnouncer;
        $context = $announcer->context($wave->fresh(), User::factory()->create(), collect());
        $this->assertStringContainsString('/admin/forms/faculty-program', $context['form_url']);
    }
}
