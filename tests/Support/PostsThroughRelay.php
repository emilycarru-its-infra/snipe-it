<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * Stands a test up as a deployment that can reach Relay: config pointed at the
 * post-card ingress, a fake managed-identity endpoint, and both faked.
 *
 * The identity endpoint is faked rather than mocked away because it is a real
 * hop — a token that cannot be minted is the most likely reason a card does not
 * post, and a test suite that skips it would not notice.
 */
trait PostsThroughRelay
{
    private string $relayUrl = 'https://commits-functions.example.test/api/post-card';

    private string $identityUrl = 'http://169.254.130.1/msi/token';

    /**
     * Http::fake() *merges* stubs rather than replacing them, and the first
     * registered match wins — so a test wanting a failure response has to say
     * so here rather than stacking a second fake on top.
     */
    protected function fakeRelay(int $status = 200, mixed $body = ['result' => 'posted']): void
    {
        config()->set('ecu.teams', [
            'enabled' => true,
            'timeout' => 8,
            'post_card_url' => $this->relayUrl,
            'audience' => 'api://relay-bot',
            'default_channel' => 'Inventory',
            'identity_endpoint' => $this->identityUrl,
            'identity_header' => 'test-identity-header',
        ]);

        Http::fake([
            $this->identityUrl.'*' => Http::response(['access_token' => 'test-token'], 200),
            $this->relayUrl => Http::response($body, $status),
        ]);
    }

    /** Every card body posted to Relay, in order. */
    protected function postedCards(): array
    {
        $cards = [];

        Http::recorded(fn ($request) => $request->url() === $this->relayUrl)
            ->each(function ($pair) use (&$cards) {
                $cards[] = $pair[0]->data()['card'];
            });

        return $cards;
    }

    /** The most recent card posted — what a test that triggered several wants. */
    protected function lastPostedCard(): array
    {
        $cards = $this->postedCards();

        $this->assertNotEmpty($cards, 'No card was posted to Relay.');

        return end($cards);
    }

    /** The channel each card was posted to, in order. */
    protected function postedChannels(): array
    {
        $channels = [];

        Http::recorded(fn ($request) => $request->url() === $this->relayUrl)
            ->each(function ($pair) use (&$channels) {
                $channels[] = $pair[0]->data()['channel'];
            });

        return $channels;
    }

    /**
     * A posted card's FactSet as label => value.
     *
     * Found by block type rather than index: the card's shape changes when a
     * note or a table is present, and a test that counted blocks would break
     * for reasons that have nothing to do with what it is checking.
     *
     * @param  array<string, mixed>  $card
     * @return array<string, string>
     */
    protected function cardFacts(array $card): array
    {
        foreach ($card['body'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'FactSet') {
                return array_combine(
                    array_column($block['facts'], 'title'),
                    array_column($block['facts'], 'value')
                );
            }
        }

        return [];
    }

    /** A posted card's title. */
    protected function cardTitle(array $card): string
    {
        return $card['body'][0]['text'] ?? '';
    }

    protected function assertNoCardPosted(): void
    {
        $this->assertSame([], $this->postedCards(), 'A card was posted to Relay when none was expected.');
    }
}
