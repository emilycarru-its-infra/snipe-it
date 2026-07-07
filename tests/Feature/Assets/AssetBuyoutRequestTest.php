<?php

namespace Tests\Feature\Assets;

use App\Mail\AssetBuyoutRequestMail;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\CustomField;
use App\Models\CustomFieldset;
use App\Models\Statuslabel;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AssetBuyoutRequestTest extends TestCase
{
    private CustomFieldset $fieldset;
    private string $ownershipCol;
    private string $endCol;

    protected function setUp(): void
    {
        parent::setUp();

        $ownership = CustomField::factory()->create(['name' => 'Ownership Type', 'format' => 'ANY']);
        $end = CustomField::factory()->create(['name' => 'Lease End Date', 'format' => 'DATE']);

        $this->ownershipCol = $ownership->db_column;
        $this->endCol = $end->db_column;

        $this->fieldset = CustomFieldset::factory()->create();
        $this->fieldset->fields()->attach([$ownership->id, $end->id]);
    }

    private function makeAsset(?string $ownership, ?string $endDate, ?Supplier $lessor, ?User $assignedTo = null): Asset
    {
        $model = AssetModel::factory()->create(['fieldset_id' => $this->fieldset->id]);
        $status = Statuslabel::factory()->rtd()->create();
        $asset = Asset::factory()->create([
            'model_id'  => $model->id,
            'status_id' => $status->id,
            'lessor_id' => $lessor?->id,
        ]);

        DB::table('assets')->where('id', $asset->id)->update([
            $this->ownershipCol => $ownership,
            $this->endCol       => $endDate,
        ]);

        if ($assignedTo) {
            DB::table('assets')->where('id', $asset->id)->update([
                'assigned_to'   => $assignedTo->id,
                'assigned_type' => User::class,
            ]);
        }

        return $asset->fresh();
    }

    private function lessorWithEmail(): Supplier
    {
        return Supplier::factory()->create(['name' => 'CSI Leasing', 'email' => 'rep@csileasing.example']);
    }

    public function test_sends_buyout_request_to_lessor_and_ccs_team_end_user_and_admin(): void
    {
        Mail::fake();

        $admin = User::factory()->superuser()->create(['email' => 'admin@ecuad.example']);
        $endUser = User::factory()->create(['email' => 'enduser@ecuad.example']);
        $lessor = $this->lessorWithEmail();
        $asset = $this->makeAsset('Lease', now()->addYear()->toDateString(), $lessor, $endUser);

        $this->actingAs($admin)
            ->post(route('asset.buyout.request', $asset->id))
            ->assertRedirect(route('hardware.show', $asset->id))
            ->assertSessionHas('success');

        Mail::assertSent(AssetBuyoutRequestMail::class, function ($mail) use ($lessor, $endUser, $admin) {
            return $mail->hasTo($lessor->email)
                && $mail->hasCc(config('leasing.buyout_request_cc'))
                && $mail->hasCc($endUser->email)
                && $mail->hasCc($admin->email);
        });

        $this->assertDatabaseHas('action_logs', [
            'item_id'     => $asset->id,
            'item_type'   => Asset::class,
            'action_type' => 'buyout requested',
        ]);
    }

    public function test_request_is_blocked_for_a_non_leased_asset(): void
    {
        Mail::fake();

        $admin = User::factory()->superuser()->create();
        $asset = $this->makeAsset('Purchase', null, $this->lessorWithEmail());

        $this->actingAs($admin)
            ->post(route('asset.buyout.request', $asset->id))
            ->assertRedirect(route('hardware.show', $asset->id))
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_request_is_blocked_when_lease_has_ended(): void
    {
        Mail::fake();

        $admin = User::factory()->superuser()->create();
        $asset = $this->makeAsset('Lease', now()->subDay()->toDateString(), $this->lessorWithEmail());

        $this->actingAs($admin)
            ->post(route('asset.buyout.request', $asset->id))
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_request_is_blocked_when_lessor_has_no_email(): void
    {
        Mail::fake();

        $admin = User::factory()->superuser()->create();
        $lessor = Supplier::factory()->create(['email' => null]);
        $asset = $this->makeAsset('Lease', now()->addYear()->toDateString(), $lessor);

        $this->actingAs($admin)
            ->post(route('asset.buyout.request', $asset->id))
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }
}
