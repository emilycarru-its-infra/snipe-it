<?php

namespace Tests\Feature\Contracts\Api;

use App\Models\Contract;
use App\Models\User;
use Tests\TestCase;

class UpsertSsotGateTest extends TestCase
{
    private function superuser(): User
    {
        return User::factory()->superuser()->create();
    }

    public function test_upsert_skips_contract_whose_ssot_is_snipe(): void
    {
        // A TDX-sourced contract flipped to Snipe ownership: the ingest
        // must stand down even though source is still 'tdx'.
        $contract = Contract::create([
            'tdx_id'          => 2001,
            'name'            => 'Authored in Snipe',
            'contract_number' => 'FLIPPED-1',
            'source'          => 'tdx',
            'ssot'            => 'snipe',
            'is_active'       => true,
        ]);

        $response = $this->actingAsForApi($this->superuser())
            ->postJson(route('api.contracts.upsert'), [
                'tdx_id'          => 2001,
                'name'            => 'CLOBBERED BY TDX',
                'contract_number' => 'TDX-XXX',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        // Payload still carries the row id so the Azure Function keeps it
        // in its reconciliation state map.
        $this->assertSame($contract->id, $response->json('payload.id'));
        $this->assertSame('Authored in Snipe', $contract->fresh()->name);
    }

    public function test_upsert_writes_contract_whose_ssot_is_tdx_even_with_snipe_source(): void
    {
        // The reverse flip: a Snipe-authored row handed to TDX ownership.
        $contract = Contract::create([
            'tdx_id'          => 2002,
            'name'            => 'Was snipe authored',
            'contract_number' => 'FLIPPED-2',
            'source'          => 'snipe',
            'ssot'            => 'tdx',
            'is_active'       => true,
        ]);

        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.contracts.upsert'), [
                'tdx_id'          => 2002,
                'name'            => 'TDX now owns this',
                'contract_number' => 'FLIPPED-2',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame('TDX now owns this', $contract->fresh()->name);
    }

    public function test_upsert_defaults_ssot_to_tdx_on_create_and_never_fills_it(): void
    {
        $this->actingAsForApi($this->superuser())
            ->postJson(route('api.contracts.upsert'), [
                'tdx_id'          => 2003,
                'name'            => 'Fresh from TDX',
                'contract_number' => 'NEW-1',
                'ssot'            => 'snipe', // must be ignored
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $row = Contract::where('tdx_id', 2003)->first();
        $this->assertSame('tdx', $row->ssot);
    }
}
