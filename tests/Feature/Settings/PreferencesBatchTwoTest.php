<?php

namespace Tests\Feature\Settings;

use App\Enums\ActionType;
use App\Helpers\Helper;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Contract;
use App\Models\Setting;
use App\Models\Statuslabel;
use App\Models\StoreOrder;
use App\Models\User;
use App\Models\UserAgreement;
use App\Services\ArrivalAllocator;
use App\Services\AssetBuyoutRequester;
use App\Services\FiscalYear;
use App\Services\Leasing\ScheduleIntake;
use App\Services\ProcurementPipeline;
use App\Services\Settings\Preferences;
use App\Services\Teams\TeamsChannels;
use App\Services\UserAgreements\PdfRenderer;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

/**
 * The second batch of runtime preferences: thresholds, lease terms, the
 * agreement PDF, contacts and channels, vocabulary and schedule times. One
 * representative per group shows a saved value reaching the code that used
 * to hard-code it; the defaults are today's values.
 */
class PreferencesBatchTwoTest extends TestCase
{
    private function set(array $values): void
    {
        Preferences::update($values);
    }

    public function test_the_new_keys_default_to_todays_values()
    {
        $this->assertSame(2024, Preferences::get('fiscal.contracts_first_year'));
        $this->assertSame(30, Preferences::get('contracts.expiring_soon_days'));
        $this->assertSame(240, Preferences::get('dashboard.renewal_prompt_days'));
        $this->assertSame(0.05, Preferences::get('procurement.money_match_tolerance'));
        $this->assertSame([12, 24, 36, 48, 60], Preferences::get('procurement.warranty_month_options'));
        $this->assertSame(48, Preferences::get('leasing.term_months.lease_to_return'));
        $this->assertSame(60, Preferences::get('leasing.term_months.lease_to_own'));
        $this->assertSame('ECU-STORE-', Preferences::get('store.order_reference_prefix'));
        $this->assertSame('03:15', Preferences::get('schedule.backfill_lessors'));
        $this->assertSame('Inventory', TeamsChannels::default());
        $this->assertCount(18, TeamsChannels::keys());
    }

    public function test_the_pipeline_season_counts_from_the_fiscal_start_month()
    {
        $this->travelTo('2026-06-15 12:00:00');

        $this->assertSame('ordering', $this->stageFor());

        // A January year puts June five months in: deploying.
        $this->set(['fiscal.start_month' => 1]);
        $this->assertSame('deploying', $this->stageFor());
    }

    private function stageFor(): ?string
    {
        return (fn () => self::activeStage(FiscalYear::current()))->call(new ProcurementPipeline);
    }

    public function test_the_buyout_cooldown_follows_its_preference()
    {
        $asset = Asset::factory()->create();
        $log = new Actionlog(['item_type' => Asset::class, 'item_id' => $asset->getKey(), 'action_type' => ActionType::BuyoutRequested->value]);
        $log->created_at = now()->subDays(10);
        $log->save();

        $requester = app(AssetBuyoutRequester::class);
        $this->assertNotNull($requester->pendingRequest($asset));

        $this->set(['leasing.buyout_request_cooldown_days' => 7]);
        $this->assertNull($requester->pendingRequest($asset));
    }

    public function test_the_contracts_tiles_follow_the_expiring_windows()
    {
        $this->set(['contracts.expiring_soon_days' => 45]);

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('Expiring 45d')
            ->assertSee('Expiring 90d');
    }

    public function test_schedule_terms_name_the_lease_type_from_preferences()
    {
        $leaseType = fn (array $parsed) => (fn () => $this->leaseType($parsed))->call(app(ScheduleIntake::class));

        $this->assertSame('Lease to Return', $leaseType(['term_months' => 48]));
        $this->assertSame('Lease to Own', $leaseType(['term_months' => 60]));

        $this->set(['leasing.term_months.lease_to_return' => 36]);
        $this->assertSame('Lease to Return', $leaseType(['term_months' => 36]));
        $this->assertNull($leaseType(['term_months' => 48]));
    }

    private function agreement(string $type): UserAgreement
    {
        $asset = Asset::factory()->create([
            'serial' => 'SAMPLE-SERIAL-1',
            'model_id' => AssetModel::factory()->create()->getKey(),
            'status_id' => Statuslabel::factory()->rtd()->create()->getKey(),
        ]);

        return UserAgreement::create([
            'user_id' => User::factory()->create()->getKey(),
            'asset_id' => $asset->getKey(),
            'agreement_type' => $type,
            'lifecycle_stage' => 'quoted',
            'base_program_price' => 2000,
            'device_cost' => 3074.03,
            'top_up_amount' => 1074.03,
            'payment_method' => 'payroll_deduction',
        ]);
    }

    /** @param  array<string, string>  $values */
    private function saveSettings(array $values): void
    {
        Setting::getSettings()?->update($values);
        Setting::$_cache = null;
    }

    /** Whether rendered PDF bytes carry $text, in metadata or a (deflated) page stream. */
    private function pdfContains(string $bytes, string $text): bool
    {
        $needles = [$text, mb_convert_encoding($text, 'UTF-16BE')];
        $haystacks = [$bytes];
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $streams);
        foreach ($streams[1] as $stream) {
            $haystacks[] = (string) @gzuncompress($stream);
        }

        foreach ($haystacks as $haystack) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function test_the_pdf_uses_the_saved_agreement_title_and_body()
    {
        $agreement = $this->agreement('pickup');
        $renderer = app(PdfRenderer::class);

        $builtIn = $renderer->render($agreement);
        $this->assertTrue($this->pdfContains($builtIn, 'Faculty Laptop Receipt Acknowledgment'));
        $this->assertFalse($this->pdfContains($builtIn, 'SAMPLEBODYWORD'));

        $this->saveSettings([
            'agreement_pickup_title' => 'SAMPLE Saved Title',
            'agreement_pickup_body' => "SAMPLEBODYWORD for {{serial}}\n\nSecond paragraph.",
        ]);

        $saved = $renderer->render($agreement->fresh());
        $this->assertTrue($this->pdfContains($saved, 'SAMPLE Saved Title'));
        $this->assertTrue($this->pdfContains($saved, 'SAMPLEBODYWORD'));
        $this->assertTrue($this->pdfContains($saved, 'SAMPLE-SERIAL-1'));
    }

    public function test_a_saved_title_alone_keeps_the_built_in_body()
    {
        $this->saveSettings(['agreement_upgrade_title' => 'SAMPLE Upgrade Title', 'agreement_upgrade_body' => '']);

        $bytes = app(PdfRenderer::class)->render($this->agreement('upgrade'));

        $this->assertTrue($this->pdfContains($bytes, 'SAMPLE Upgrade Title'));
        $this->assertTrue($this->pdfContains($bytes, '$44.75'));
    }

    public function test_the_upgrade_instalments_follow_their_preference()
    {
        $this->set(['agreements.loan_installments' => 12]);

        $bytes = app(PdfRenderer::class)->render($this->agreement('upgrade'));

        $this->assertTrue($this->pdfContains($bytes, '$89.50'));
        $this->assertFalse($this->pdfContains($bytes, '$44.75'));
    }

    public function test_the_device_team_defaults_from_config_and_can_be_overridden()
    {
        config(['ecu.device_team_emails' => 'team@example.com, store@example.com']);
        $this->assertSame(['team@example.com', 'store@example.com'], Preferences::get('contacts.device_team'));
        $this->assertSame('team@example.com,store@example.com', Preferences::csv('contacts.device_team'));

        $this->set(['contacts.device_team' => 'other@example.com']);
        $this->assertSame('other@example.com', Preferences::csv('contacts.device_team'));
    }

    public function test_teams_channels_are_edited_as_name_value_lines()
    {
        $this->actingAsForApi(User::factory()->superuser()->create())
            ->patchJson(route('api.settings.preferences.update'), [
                'teams.channels' => "Store = Store — orders\nAlerts",
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame(['Store' => 'Store — orders', 'Alerts' => 'Alerts'], TeamsChannels::keys());
        // The configured default is no longer offered, so the first one is used.
        $this->assertSame('Store', TeamsChannels::default());
    }

    public function test_a_changed_store_prefix_still_recognises_old_references()
    {
        $this->set(['store.order_reference_prefix' => 'SHOP-']);

        $order = new StoreOrder;
        $order->id = 42;
        $this->assertSame('SHOP-42', $order->reference());
        $this->assertSame(42, StoreOrder::idFromReference('SHOP-42'));
        $this->assertSame(42, StoreOrder::idFromReference('ECU-STORE-42'));
        $this->assertNull(StoreOrder::idFromReference('PO-42'));

        $allocator = app(ArrivalAllocator::class);
        $this->assertFalse($allocator->couldBeArrival(new Asset(['order_number' => 'SHOP-7', 'serial' => 'SERIAL-1'])));
        $this->assertFalse($allocator->couldBeArrival(new Asset(['order_number' => 'ECU-STORE-7', 'serial' => 'SERIAL-1'])));
        $this->assertTrue($allocator->couldBeArrival(new Asset(['order_number' => 'PO-7', 'serial' => 'SERIAL-1'])));
    }

    public function test_links_come_from_preferences()
    {
        $this->assertNull(Contract::tdxUrlFor(123));

        $this->set([
            'links.tdx_contract' => 'https://tdx.example.com/contracts?id={id}',
            'links.carrier_tracking' => ['acme' => 'https://track.example.com/?n='],
        ]);

        $this->assertSame('https://tdx.example.com/contracts?id=123', Contract::tdxUrlFor(123));
        $this->assertSame('https://track.example.com/?n=1Z%201', Helper::trackingUrl('ACME Freight', '1Z 1'));
        $this->assertNull(Helper::trackingUrl('UPS', '1Z1'));
    }

    /** @return array<int, Event> */
    private function scheduledEvents(): array
    {
        $schedule = new Schedule;
        (fn () => $this->schedule($schedule))->call(app(Kernel::class));

        return $schedule->events();
    }

    private function eventFor(string $command): ?Event
    {
        foreach ($this->scheduledEvents() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        return null;
    }

    public function test_the_schedule_reads_job_times_from_preferences()
    {
        $this->assertSame('15 3 * * *', $this->eventFor('snipeit:backfill-lessors')?->expression);
        $this->assertSame('*/5 * * * *', $this->eventFor('snipeit:okay-to-pay')?->expression);

        $this->set(['schedule.backfill_lessors' => '04:45', 'schedule.okay_to_pay_minutes' => 10, 'schedule.catalog_sync_apple' => '06:10']);

        $this->assertSame('45 4 * * *', $this->eventFor('snipeit:backfill-lessors')?->expression);
        $this->assertSame('*/10 * * * *', $this->eventFor('snipeit:okay-to-pay')?->expression);
        $this->assertSame('10 6 * * 1', $this->eventFor('catalog:sync-apple')?->expression);
    }

    public function test_bad_times_and_nameless_map_lines_are_refused()
    {
        $this->actingAsForApi(User::factory()->superuser()->create())
            ->patchJson(route('api.settings.preferences.update'), [
                'schedule.backfill_lessors' => '25:00',
                'links.carrier_tracking' => ' = https://track.example.com/',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['messages' => ['schedule.backfill_lessors', 'links.carrier_tracking']]);

        $this->assertSame('03:15', Preferences::get('schedule.backfill_lessors'));
    }
}
