<?php

namespace Tests\Feature\LeaseDecisions;

use App\Models\LeaseDecision;
use App\Models\User;
use Tests\TestCase;

class LeaseDecisionTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_index_page_renders()
    {
        $this->actingAs($this->superuser())
            ->get(route('lease-decisions.index'))
            ->assertOk();
    }

    public function test_a_lease_decision_can_be_created()
    {
        $this->actingAs($this->superuser())
            ->post(route('lease-decisions.store'), [
                'contract_reference' => 'ECI-TEST-1',
                'decision_type' => 'buyout',
                'status' => 'pending',
                'amount' => 12000,
            ])
            ->assertRedirect(route('lease-decisions.index'));

        $this->assertDatabaseHas('lease_decisions', [
            'contract_reference' => 'ECI-TEST-1',
            'decision_type' => 'buyout',
        ]);
    }

    public function test_a_lease_decision_can_be_updated()
    {
        $decision = LeaseDecision::factory()->create(['status' => 'pending']);

        $this->actingAs($this->superuser())
            ->put(route('lease-decisions.update', ['lease_decision' => $decision->id]), [
                'contract_reference' => $decision->contract_reference,
                'decision_type' => $decision->decision_type,
                'status' => 'approved',
            ])
            ->assertRedirect(route('lease-decisions.index'));

        $this->assertDatabaseHas('lease_decisions', [
            'id' => $decision->id,
            'status' => 'approved',
        ]);
    }

    public function test_a_lease_decision_can_be_deleted()
    {
        $decision = LeaseDecision::factory()->create();

        $this->actingAs($this->superuser())
            ->delete(route('lease-decisions.destroy', ['lease_decision' => $decision->id]))
            ->assertRedirect(route('lease-decisions.index'));

        $this->assertSoftDeleted('lease_decisions', ['id' => $decision->id]);
    }
}
