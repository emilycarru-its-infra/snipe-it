<?php

namespace App\Services\Teams;

use App\Mail\EmailDelivery;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posts an Adaptive Card to Teams through Relay, the estate's bot.
 *
 * Relay is fronted by an HTTP ingress on commits-functions — POST /api/post-card
 * with {channel, card} — so this app needs no webhook URL, no Power Automate
 * flow and no Key Vault secret. Adding Relay to a channel in Teams is the whole
 * onboarding, and the bot's own credentials never leave that function app.
 *
 * The endpoint has two independent gates, and both are deployment facts rather
 * than anything this class can arrange: the caller's managed identity must hold
 * the PostCard.Send app role on the bot registration, and its object id must be
 * in POST_CARD_ALLOWED_CALLERS. Missing either is a 403, which is logged as the
 * configuration problem it is.
 *
 * A failure here is logged and swallowed. Nobody's checkout should fail because
 * a notification could not be posted, and call sites defer the post past the
 * response for the same reason.
 */
class TeamsNotifier
{
    private const DEFAULT_TIMEOUT = 8;

    /**
     * The App Service managed-identity token endpoint speaks this version.
     * Not the IMDS one — a container app has IDENTITY_ENDPOINT instead.
     */
    private const IDENTITY_API_VERSION = '2019-08-01';

    /**
     * Post a card. Returns true when every card was accepted — false when the
     * integration is off, unconfigured, or the post failed.
     */
    public function send(TeamsCard $card, ?string $channel = null, ?string $key = null): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $channel = TeamsChannels::resolve($channel);
        $url = trim((string) config('ecu.teams.post_card_url'));

        if ($url === '') {
            $this->audit('skipped', $channel, $key, null, ['reason' => 'no post-card endpoint configured']);

            return false;
        }

        $token = $this->token();

        if ($token === null) {
            $this->audit('skipped', $channel, $key, null, ['reason' => 'no managed identity available']);

            return false;
        }

        $cards = $card->cards();

        foreach ($cards as $index => $content) {
            if (! $this->post($url, $token, $channel, $content, $index + 1, count($cards), $key)) {
                return false;
            }
        }

        return $cards !== [];
    }

    /**
     * Post one card and swallow whatever goes wrong, logging at a level that
     * matches whose problem it is.
     */
    private function post(string $url, string $token, string $channel, array $content, int $part, int $parts, ?string $key = null): bool
    {
        $context = ['channel' => $channel];

        if ($parts > 1) {
            $context['part'] = $part.'/'.$parts;
        }

        try {
            $response = Http::withToken($token)
                ->asJson()
                ->timeout($this->timeout())
                ->post($url, ['channel' => $channel, 'card' => $content])
                ->throw();

            // Unlike a Power Automate webhook, Relay answers after it has
            // actually posted — so a 2xx here really is delivery.
            $this->audit('posted', $channel, $key, $content, ['status' => $response->status()] + $context);

            return true;
        } catch (RequestException $e) {
            $status = $e->response->status();
            $body = substr($e->response->body(), 0, 500);

            $this->audit('refused', $channel, $key, $content, ['status' => $status, 'body' => $body] + $context);

            if ($status === 401 || $status === 403) {
                // Both gates on the endpoint look like this. Neither is
                // something a retry fixes, and both need a person.
                Log::error('Relay refused the card ('.$status.') — check the PostCard.Send role assignment and POST_CARD_ALLOWED_CALLERS.', $context + ['body' => $body]);
            } elseif ($status < 500) {
                Log::error('Relay rejected the card ('.$status.').', $context + ['error' => $e->getMessage(), 'body' => $body]);
            } else {
                Log::error('Relay server error.', $context + ['status' => $status, 'error' => $e->getMessage()]);
            }
        } catch (ConnectException $e) {
            $this->audit('failed', $channel, $key, $content, ['error' => $e->getMessage()] + $context);
            Log::warning('Relay connection failed.', $context + ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->audit('failed', $channel, $key, $content, ['error' => $e->getMessage()] + $context);
            Log::error('Relay post failed unexpectedly.', $context + ['error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * A managed-identity token for the bot registration.
     *
     * App Service injects IDENTITY_ENDPOINT and IDENTITY_HEADER; outside it
     * there is no identity to borrow, which is why local and dev post nothing
     * rather than failing.
     */
    private function token(): ?string
    {
        $audience = trim((string) config('ecu.teams.audience'));
        $endpoint = trim((string) config('ecu.teams.identity_endpoint'));
        $header = (string) config('ecu.teams.identity_header');

        if ($audience === '' || $endpoint === '') {
            Log::info('Teams card not sent: no managed identity or audience available for Relay.');

            return null;
        }

        try {
            $token = Http::withHeaders(['X-IDENTITY-HEADER' => $header])
                ->timeout($this->timeout())
                ->get($endpoint, [
                    'resource' => $audience,
                    'api-version' => self::IDENTITY_API_VERSION,
                ])
                ->throw()
                ->json('access_token');
        } catch (\Throwable $e) {
            Log::error('Could not get a managed-identity token for Relay: '.$e->getMessage());

            return null;
        }

        if (! is_string($token) || $token === '') {
            Log::error('Managed-identity token endpoint returned no access_token for Relay.');

            return null;
        }

        return $token;
    }

    /**
     * Post the card for one of the registry's notification keys, on the channel
     * Settings → Emails routes it to, and only if it routes it to Teams at all.
     *
     * The source is either a card or anything that can build one. This is the
     * one call every migrated send site makes; the matching
     * `if (EmailDelivery::shouldEmail())` around the email stays at the call
     * site, because only the call site knows how to send its own mail.
     */
    public function announce(string $key, object $source, bool $defer = true): void
    {
        if (! EmailDelivery::shouldPostToTeams($key)) {
            return;
        }

        if (! $source instanceof TeamsCard) {
            if (! method_exists($source, 'toTeamsCard')) {
                return;
            }

            $source = $source->toTeamsCard();
        }

        $channel = EmailDelivery::channelFor($key);

        // Scheduled commands post synchronously: deferring past the response
        // means nothing when there is no response, and a console run that exits
        // before its callbacks fire would post nothing at all.
        $defer ? $this->sendLater($source, $channel, $key) : $this->send($source, $channel, $key);
    }

    /**
     * Post after the response has gone out. Every event-driven call site uses
     * this: a card is never worth holding a request open for.
     *
     * defer() only runs if the InvokeDeferredCallbacks middleware is registered
     * — this fork keeps a legacy Http\Kernel, where it was a silent no-op until
     * that was fixed. TeamsCardDeferralOverHttpTest guards it.
     */
    public function sendLater(TeamsCard $card, ?string $channel = null, ?string $key = null): void
    {
        defer(fn () => $this->send($card, $channel, $key));
    }

    private function timeout(): int
    {
        return (int) (config('ecu.teams.timeout') ?: self::DEFAULT_TIMEOUT);
    }

    private function enabled(): bool
    {
        return (bool) (config('ecu.teams.enabled') ?? true);
    }

    /**
     * One line per card, on its own channel, whatever LOG_LEVEL says.
     *
     * This is the audit trail: what was posted, where, on whose behalf, and
     * whether it landed. It records the card's title rather than the card,
     * because the point is to answer "did the check-in on Tuesday announce
     * itself" without keeping a copy of every table ever sent.
     *
     * @param  array<string, mixed>|null  $content
     * @param  array<string, mixed>  $context
     */
    private function audit(string $outcome, ?string $channel, ?string $key, ?array $content, array $context = []): void
    {
        Log::channel('teams')->info($outcome, array_filter([
            'channel' => $channel,
            'notification' => $key,
            'title' => TeamsCard::titleOf($content),
        ] + $context, fn ($v) => $v !== null && $v !== ''));
    }
}
