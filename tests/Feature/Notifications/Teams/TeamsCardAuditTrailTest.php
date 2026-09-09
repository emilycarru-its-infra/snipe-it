<?php

namespace Tests\Feature\Notifications\Teams;

use App\Mail\EmailDelivery;
use App\Models\EmailTemplate;
use App\Services\Teams\TeamsCard;
use App\Services\Teams\TeamsNotifier;
use Illuminate\Support\Facades\Log;
use Tests\Support\PostsThroughRelay;
use Tests\TestCase;

/**
 * Every card this app posts leaves a line, on a channel of its own.
 *
 * The default channel is no use for this: LOG_LEVEL defaults to warning in
 * production, so a Log::info there is discarded and grepping laravel.log for
 * evidence a card went out always came back empty whether or not it had.
 */
class TeamsCardAuditTrailTest extends TestCase
{
    use PostsThroughRelay;

    private function card(): TeamsCard
    {
        return TeamsCard::make('Asset checked in')->fact('Asset Tag', 'SAMPLE-01');
    }

    public function test_a_posted_card_is_recorded_with_where_and_what_it_was()
    {
        $this->fakeRelay();

        Log::shouldReceive('channel')->with('teams')->once()->andReturnSelf();
        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn ($outcome, $context) => $outcome === 'posted'
                && $context['channel'] === 'Inventory'
                && $context['notification'] === 'checkin.asset'
                && $context['title'] === 'Asset checked in'
                && $context['status'] === 200);

        app(TeamsNotifier::class)->send($this->card(), 'Inventory', 'checkin.asset');
    }

    public function test_the_trail_writes_to_its_own_channel_not_the_default_one()
    {
        // Its own file, at info, regardless of what LOG_LEVEL is set to.
        $this->assertSame('daily', config('logging.channels.teams.driver'));
        $this->assertSame('info', config('logging.channels.teams.level'));
        $this->assertStringEndsWith('teams-cards.log', config('logging.channels.teams.path'));
        $this->assertNotSame(
            config('logging.channels.single.path'),
            config('logging.channels.teams.path')
        );
    }

    public function test_a_refusal_is_recorded_too()
    {
        $this->fakeRelay(403, 'caller not allowlisted');

        Log::shouldReceive('channel')->with('teams')->once()->andReturnSelf();
        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn ($outcome, $context) => $outcome === 'refused' && $context['status'] === 403);
        Log::shouldReceive('error')->once();

        app(TeamsNotifier::class)->send($this->card(), 'Inventory', 'checkin.asset');
    }

    public function test_a_card_that_could_not_even_be_attempted_is_recorded_as_skipped()
    {
        $this->fakeRelay();
        config()->set('ecu.teams.post_card_url', '');

        Log::shouldReceive('channel')->with('teams')->once()->andReturnSelf();
        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn ($outcome, $context) => $outcome === 'skipped'
                && $context['channel'] === 'Inventory'
                && str_contains($context['reason'], 'endpoint'));

        app(TeamsNotifier::class)->send($this->card(), 'Inventory', 'checkin.asset');
    }

    public function test_every_card_of_a_split_report_gets_its_own_line()
    {
        $this->fakeRelay();

        $rows = array_map(
            fn ($i) => ['A'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'Device '.$i, 'MacBook Pro 14-inch (M4 Pro)'],
            range(1, 400)
        );
        $card = TeamsCard::make('Expiring assets')->table(['Tag', 'Name', 'Model'], $rows);
        $parts = count($card->cards());

        $this->assertGreaterThan(1, $parts);

        Log::shouldReceive('channel')->with('teams')->times($parts)->andReturnSelf();
        Log::shouldReceive('info')
            ->times($parts)
            ->withArgs(fn ($outcome, $context) => $outcome === 'posted' && isset($context['part']));

        app(TeamsNotifier::class)->send($card, 'Automations', 'report.expiring_assets');
    }

    public function test_announce_names_the_notification_and_its_resolved_channel()
    {
        $this->fakeRelay();
        EmailTemplate::updateOrCreate(['key' => 'report.low_inventory'], ['teams_channel' => 'PaperCut']);
        $this->assertSame('PaperCut', EmailDelivery::channelFor('report.low_inventory'));

        Log::shouldReceive('channel')->with('teams')->once()->andReturnSelf();
        Log::shouldReceive('info')
            ->once()
            ->withArgs(fn ($outcome, $context) => $context['notification'] === 'report.low_inventory'
                && $context['channel'] === 'PaperCut');

        app(TeamsNotifier::class)->announce('report.low_inventory', $this->card(), defer: false);
    }
}
