<?php

namespace Tests\Feature\Notifications\Teams;

use App\Models\Asset;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('notifications')]
class TeamsCardUponAuditTest extends TestCase
{
    private const DEVICES = 'https://prod-1.westus.logic.azure.com/workflows/devices/triggers/manual/paths/invoke';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();
        Http::fake([self::DEVICES => Http::response('', 202)]);
        config()->set('ecu.teams', [
            'enabled' => true,
            'timeout' => 8,
            'channels' => ['default' => '', 'devices' => self::DEVICES],
        ]);
    }

    private function sentCard(): array
    {
        $card = null;
        Http::assertSent(function ($request) use (&$card) {
            $card = $request['attachments'][0]['content'];

            return true;
        });

        return $card;
    }

    public function test_an_audit_posts_a_card_naming_the_asset_the_location_and_the_auditor()
    {
        $auditor = User::factory()->superuser()->create(['first_name' => 'Sample', 'last_name' => 'Auditor']);
        $this->actingAs($auditor);

        $location = Location::factory()->create(['name' => 'Sample Studio']);
        $asset = Asset::factory()->laptopMbp()->create([
            'asset_tag' => 'TEST-0002',
            'serial' => 'TESTSERIAL2',
        ]);

        $asset->logAudit('Found on the bench', $location->id);

        $card = $this->sentCard();
        $facts = array_combine(
            array_column($card['body'][2]['facts'], 'title'),
            array_column($card['body'][2]['facts'], 'value')
        );

        $this->assertStringContainsString('audited', strtolower($card['body'][0]['text']));
        $this->assertSame('TEST-0002', $facts['Asset Tag']);
        $this->assertSame('TESTSERIAL2', $facts['Serial']);
        $this->assertSame('Sample Studio', $facts['Location']);
        $this->assertContains(route('hardware.show', $asset->id), array_column($card['actions'], 'url'));

        $footer = end($card['body']);
        $this->assertStringContainsString('Sample Auditor', $footer['text']);
    }

    public function test_an_audit_of_something_with_no_admin_behind_it_still_posts_a_card()
    {
        // The static builder this replaces returned null for a missing item
        // and the caller immediately indexed into it. Nothing here can be
        // dereferenced off the end.
        $asset = Asset::factory()->laptopMbp()->create();

        $asset->logAudit('Console audit', null);

        $this->assertNotEmpty($this->sentCard()['body'][0]['text']);
    }
}
