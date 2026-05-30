<?php

namespace Tests\Feature\Contracts\Api;

use App\Models\Contract;
use App\Models\User;
use Tests\TestCase;

class UpsertSourceGuardTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_upsert_refuses_to_overwrite_manual_source_contract(): void
    {
        // A manual contract that somehow also has a tdx_id — simulates
        // a future bug where TDX assigns an id to a hand-curated row.
        $contract = Contract::create([
            'tdx_id'          => 999,
            'name'            => 'Devices Leases FY25-26 #1',
            'contract_number' => 'EXISTING-1',
            'source'          => 'manual',
            'is_active'       => true,
            'end_date'        => '2025-08-01',
        ]);

        $payload = [
            'tdx_id'          => 999,
            'name'            => 'CLOBBERED BY TDX',
            'contract_number' => 'TDX-XXX',
            'end_date'        => '2031-01-01',
        ];

        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.contracts.upsert'), $payload)
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $fresh = $contract->fresh();
        $this->assertSame('Devices Leases FY25-26 #1', $fresh->name);
        $this->assertSame('manual', $fresh->source);
        $this->assertSame('2025-08-01', $fresh->end_date?->toDateString());
    }

    public function test_upsert_still_writes_a_tdx_source_contract(): void
    {
        $contract = Contract::create([
            'tdx_id'          => 1001,
            'name'            => 'TDX original',
            'contract_number' => 'TDX-1001',
            'source'          => 'tdx',
            'is_active'       => true,
        ]);

        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.contracts.upsert'), [
                'tdx_id'          => 1001,
                'name'            => 'TDX updated',
                'contract_number' => 'TDX-1001',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame('TDX updated', $contract->fresh()->name);
    }
}
