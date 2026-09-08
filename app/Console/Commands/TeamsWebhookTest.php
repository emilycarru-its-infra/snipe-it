<?php

namespace App\Console\Commands;

use App\Services\Teams\TeamsCard;
use App\Services\Teams\TeamsChannels;
use App\Services\Teams\TeamsNotifier;
use Illuminate\Console\Command;

/**
 * Posts sample cards to a Teams channel so the rendering can be checked before
 * a real notification depends on it.
 *
 * The digest sample matters most: report cards use an Adaptive Cards 1.5 Table
 * and carry every row, so this is what proves both that the Workflows renderer
 * accepts a Table and that the size-splitting lands where it should.
 */
class TeamsWebhookTest extends Command
{
    protected $signature = 'snipeit:teams-test
                            {channel=default : Channel key — default, devices, procurement, reports, requests}
                            {--digest : Post a full-size report card instead of an event card}
                            {--rows=120 : How many rows the digest sample carries}
                            {--dry : Print the payload instead of posting it}';

    protected $description = 'Post a sample Adaptive Card to a Teams channel to check rendering.';

    public function handle(TeamsNotifier $notifier): int
    {
        $channel = (string) $this->argument('channel');

        if (! TeamsChannels::isKnown($channel)) {
            $this->error('Unknown channel "'.$channel.'". Known: '.implode(', ', array_keys(TeamsChannels::keys())));

            return 1;
        }

        $card = $this->option('digest')
            ? $this->digestCard((int) $this->option('rows'))
            : $this->eventCard();

        $payloads = $card->payloads();
        $this->line(count($payloads).' card(s), '.implode(' + ', array_map(
            fn ($p) => strlen((string) json_encode($p)).' bytes',
            $payloads
        )));

        if ($this->option('dry')) {
            $this->line(json_encode($payloads[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if (TeamsChannels::url($channel) === null) {
            $this->error('Channel "'.$channel.'" has no webhook URL configured.');

            return 1;
        }

        if (! $notifier->send($card, $channel)) {
            $this->error('Post failed — check the log for the reason.');

            return 1;
        }

        $this->info('Accepted by the Workflows trigger. A 202 means accepted, not delivered — confirm in the channel.');

        return 0;
    }

    private function eventCard(): TeamsCard
    {
        $url = rtrim((string) config('app.url'), '/');

        return TeamsCard::make('Asset checked out')
            ->accent('accent')
            ->subtitle('Sample iMac — iMac (24-inch, 2024, Four ports)')
            ->facts([
                'Asset tag' => 'SAMPLE-01',
                'Serial' => 'SAMPLE-SERIAL',
                'Assigned to' => 'Sample Team',
                'Checked out from' => 'IT Storage',
                'Status' => 'Ready to Deploy → Deployed',
            ])
            ->note('Sample card from snipeit:teams-test — nothing was checked out.')
            ->action('View asset', $url.'/hardware/1')
            ->action('View location', $url.'/locations/1')
            ->footer('Sample · '.now()->format('D, M j Y \a\t g:ia T'));
    }

    private function digestCard(int $rows): TeamsCard
    {
        $sample = [];
        for ($i = 1; $i <= max(1, $rows); $i++) {
            $sample[] = [
                'A'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'Sample Device '.$i,
                'MacBook Pro 14-inch (M4 Pro)',
                'Sample Person '.$i,
                now()->addDays($i)->format('Y-m-d'),
            ];
        }

        return TeamsCard::make('Expiring assets')
            ->accent('warning')
            ->subtitle(count($sample).' assets with warranties expiring in the next 60 days')
            ->facts(['Threshold' => '60 days', 'Assets' => count($sample)])
            ->table(['Tag', 'Name', 'Model', 'Assigned to', 'Expires'], $sample)
            ->footer('Sample · '.now()->format('D, M j Y \a\t g:ia T'));
    }
}
