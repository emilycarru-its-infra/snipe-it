<?php

namespace Tests\Unit\Services\Teams;

use App\Services\Teams\TeamsCard;
use PHPUnit\Framework\TestCase;

class TeamsCardTest extends TestCase
{
    /** The card content, unwrapped from the message envelope. */
    private function content(array $payload): array
    {
        return $payload['attachments'][0]['content'];
    }

    private function blocks(array $payload, string $type): array
    {
        return array_values(array_filter(
            $this->content($payload)['body'],
            fn ($block) => $block['type'] === $type
        ));
    }

    public function testWrapsTheCardInTheWorkflowsMessageEnvelope()
    {
        // The Workflows "post card" action reads attachments[0].content and
        // fails the run outright when it is missing, so the envelope is not
        // decoration — it is the contract.
        $payload = TeamsCard::make('Asset checked in')->payload();

        $this->assertSame('message', $payload['type']);
        $this->assertSame(
            'application/vnd.microsoft.card.adaptive',
            $payload['attachments'][0]['contentType']
        );
        $this->assertSame('AdaptiveCard', $this->content($payload)['type']);
    }

    public function testDropsFactsWithNoValue()
    {
        // The card this replaces rendered "Notes" and "Checked into" as bare
        // labels with nothing beside them whenever the value was null.
        $payload = TeamsCard::make('Asset checked in')
            ->facts([
                'Asset' => 'SAMPLE-01',
                'Checked into' => null,
                'Notes' => '',
                'Status' => 'Active',
            ])
            ->payload();

        $facts = $this->blocks($payload, 'FactSet')[0]['facts'];

        $this->assertSame(
            ['Asset', 'Status'],
            array_column($facts, 'title')
        );
    }

    public function testRendersAChangeAsOldToNew()
    {
        $payload = TeamsCard::make('Asset updated')
            ->change('Status', 'Ready to Deploy', 'Deployed')
            ->change('Location', 'IT Storage', 'IT Storage')
            ->payload();

        $facts = $this->blocks($payload, 'FactSet')[0]['facts'];

        $this->assertSame('Ready to Deploy → Deployed', $facts[0]['value']);
        $this->assertSame('IT Storage', $facts[1]['value']);
    }

    public function testDecodesHtmlEncodedDisplayNames()
    {
        // Snipe stores display names HTML-encoded; a card that prints them raw
        // shows "Devices &amp; Systems".
        $payload = TeamsCard::make('Checked out')
            ->fact('Assigned to', 'Devices &amp; Systems')
            ->payload();

        $this->assertSame(
            'Devices & Systems',
            $this->blocks($payload, 'FactSet')[0]['facts'][0]['value']
        );
    }

    public function testAddsAbsoluteLinksAsButtonsAndSkipsRelativeOnes()
    {
        $payload = TeamsCard::make('Checked out')
            ->action('View asset', 'https://inventory.example.test/hardware/1')
            ->action('View user', '/users/2')
            ->action('Nowhere', null)
            ->payload();

        $actions = $this->content($payload)['actions'];

        $this->assertCount(1, $actions);
        $this->assertSame('Action.OpenUrl', $actions[0]['type']);
        $this->assertSame('https://inventory.example.test/hardware/1', $actions[0]['url']);
    }

    public function testOmitsTheActionsKeyEntirelyWhenThereAreNoLinks()
    {
        $this->assertArrayNotHasKey('actions', $this->content(TeamsCard::make('Checked out')->payload()));
    }

    public function testCardsWithATableDeclareTheSchemaVersionTheTableNeeds()
    {
        $plain = TeamsCard::make('Checked out')->payload();
        $tabled = TeamsCard::make('Expiring assets')
            ->table(['Tag', 'Name'], [['A1', 'One']])
            ->payload();

        $this->assertSame('1.4', $this->content($plain)['version']);
        $this->assertSame('1.5', $this->content($tabled)['version']);
    }

    public function testTableCarriesAHeaderRowThenEveryDataRow()
    {
        $payload = TeamsCard::make('Expiring assets')
            ->table(['Tag', 'Name'], [['A1', 'One'], ['A2', null]])
            ->payload();

        $table = $this->blocks($payload, 'Table')[0];

        $this->assertTrue($table['firstRowAsHeaders']);
        $this->assertCount(3, $table['rows']);
        $this->assertSame('Tag', $table['rows'][0]['cells'][0]['items'][0]['text']);
        $this->assertSame('One', $table['rows'][1]['cells'][1]['items'][0]['text']);
        // An empty cell still needs to occupy its column.
        $this->assertSame('—', $table['rows'][2]['cells'][1]['items'][0]['text']);
    }

    public function testAShortReportIsASingleCard()
    {
        $payload = TeamsCard::make('Expiring assets')
            ->table(['Tag'], array_map(fn ($i) => ['A'.$i], range(1, 20)))
            ->payloads();

        $this->assertCount(1, $payload);
        $this->assertStringNotContainsString(' of ', $this->content($payload[0])['body'][0]['text']);
    }

    public function testALongReportSplitsIntoNumberedCardsThatKeepEveryRow()
    {
        // Rod asked for the whole inventory in the card, and Teams caps a card
        // at about 28 KB — so a long report has to split, never truncate.
        $rows = array_map(
            fn ($i) => ['A'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'Device '.$i, 'MacBook Pro 14-inch (M4 Pro)', 'Person '.$i],
            range(1, 400)
        );

        $payloads = TeamsCard::make('Expiring assets')
            ->table(['Tag', 'Name', 'Model', 'Assigned to'], $rows)
            ->payloads();

        $this->assertGreaterThan(1, count($payloads));

        $carried = 0;
        foreach ($payloads as $index => $payload) {
            $this->assertLessThanOrEqual(24576, strlen((string) json_encode($payload)));
            $this->assertSame(
                'Expiring assets ('.($index + 1).' of '.count($payloads).')',
                $this->content($payload)['body'][0]['text']
            );
            // -1 for the header row, which every card repeats.
            $carried += count($this->blocks($payload, 'Table')[0]['rows']) - 1;
        }

        $this->assertSame(400, $carried);
    }

    public function testOnlyTheFirstCardOfASplitReportCarriesTheFacts()
    {
        $rows = array_map(fn ($i) => ['A'.$i, str_repeat('x', 200)], range(1, 300));

        $payloads = TeamsCard::make('Expiring assets')
            ->facts(['Threshold' => '60 days'])
            ->table(['Tag', 'Name'], $rows)
            ->payloads();

        $this->assertNotEmpty($this->blocks($payloads[0], 'FactSet'));
        $this->assertEmpty($this->blocks($payloads[1], 'FactSet'));
    }
}
