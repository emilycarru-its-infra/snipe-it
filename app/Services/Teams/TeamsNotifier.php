<?php

namespace App\Services\Teams;

use App\Mail\EmailDelivery;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posts an Adaptive Card to a Teams channel through its Power Automate
 * incoming webhook.
 *
 * Uses the Http facade rather than the vendor package's own Guzzle client, so
 * the payload is assertable under Http::fake() — nothing about the cards this
 * app sent could be tested before.
 *
 * A failure here is logged and swallowed. Nobody's checkout should fail
 * because a Power Automate flow was slow, and call sites defer the post past
 * the response for the same reason.
 *
 * One thing worth knowing when reading the logs: the Workflows trigger
 * returns 202 the moment it accepts the request and runs the flow afterwards.
 * A 202 therefore means accepted, never delivered — a flow that fails every
 * run still answers 202, so this logs "accepted" and leaves proof of delivery
 * to the flow's own run history.
 */
class TeamsNotifier
{
    private const DEFAULT_TIMEOUT = 8;

    /**
     * Post a card. Returns true when every payload was accepted — false when
     * the channel is unconfigured, Teams is switched off, or the post failed.
     */
    public function send(TeamsCard $card, string $channel = TeamsChannels::DEFAULT): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $url = TeamsChannels::url($channel);

        if ($url === null) {
            Log::info('Teams card not sent: no webhook URL for channel "'.$channel.'".');

            return false;
        }

        $payloads = $card->payloads();
        $sent = 0;

        foreach ($payloads as $index => $payload) {
            if (! $this->post($url, $payload, $channel, $index + 1, count($payloads))) {
                return false;
            }

            $sent++;
        }

        return $sent > 0;
    }

    /**
     * Post one card and swallow whatever goes wrong, logging at a level that
     * matches whose problem it is: a 4xx is our payload, a 5xx or a refused
     * connection is theirs.
     */
    private function post(string $url, array $payload, string $channel, int $part, int $parts): bool
    {
        $context = ['channel' => $channel];

        if ($parts > 1) {
            $context['part'] = $part.'/'.$parts;
        }

        try {
            $response = Http::asJson()
                ->timeout((int) (config('ecu.teams.timeout') ?: self::DEFAULT_TIMEOUT))
                ->post($url, $payload)
                ->throw();

            Log::info('Teams card accepted ('.$response->status().') on channel "'.$channel.'".', $context);

            return true;
        } catch (RequestException $e) {
            $status = $e->response?->status();

            // 4xx is a card Teams would not take — that is ours to fix, and it
            // will keep happening until someone reads it.
            if ($status !== null && $status < 500) {
                Log::error('Teams rejected the card ('.$status.').', $context + [
                    'error' => $e->getMessage(),
                    'body' => substr((string) $e->response?->body(), 0, 500),
                ]);
            } else {
                Log::error('Teams webhook server error.', $context + [
                    'status' => $status,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (ConnectException $e) {
            Log::warning('Teams webhook connection failed.', $context + ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('Teams webhook failed unexpectedly.', $context + ['error' => $e->getMessage()]);
        }

        return false;
    }


    /**
     * Post the card for one of the registry's notification keys, on the
     * channel Settings → Emails routes it to, and only if it routes it to
     * Teams at all.
     *
     * The source is either a card, or anything that can build one — every
     * notification with a toTeamsCard() qualifies. This is the one call every
     * migrated send site makes; the matching `if (EmailDelivery::shouldEmail())`
     * around the email stays at the call site, because only the call site
     * knows how to send its own mail.
     */
    public function announce(string $key, object $source): void
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

        $this->sendLater($source, EmailDelivery::channelFor($key));
    }

    /**
     * Post after the response has gone out. Every event-driven call site uses
     * this: a card is never worth holding a request open for.
     *
     * defer() only runs if the InvokeDeferredCallbacks middleware is
     * registered — this fork keeps a legacy Http\Kernel, where it was a silent
     * no-op until that was fixed. TeamsNotifierDeferralTest guards it.
     */
    public function sendLater(TeamsCard $card, string $channel = TeamsChannels::DEFAULT): void
    {
        defer(fn () => $this->send($card, $channel));
    }

    private function enabled(): bool
    {
        return (bool) (config('ecu.teams.enabled') ?? true);
    }
}
