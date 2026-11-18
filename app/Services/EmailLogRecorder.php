<?php

namespace App\Services;

use App\Models\EmailLog;
use Carbon\Carbon;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;

/**
 * Writes the email log: one row per email Snipe hands to the mail relay,
 * and rows imported from the mail system's delivery trace for mail sent
 * before the log existed. Recording never blocks or fails a send.
 */
class EmailLogRecorder
{
    public function handle(MessageSent $event): void
    {
        try {
            $this->record($event);
        } catch (\Throwable $e) {
            Log::warning('Email log could not record a sent email: '.$e->getMessage());
        }
    }

    public function record(MessageSent $event): ?EmailLog
    {
        $message = $event->message;
        $to = self::addresses($message->getTo());
        $cc = self::addresses($message->getCc());
        $bcc = self::addresses($message->getBcc());
        $messageId = self::messageId($event);

        return DB::transaction(function () use ($event, $message, $to, $cc, $bcc, $messageId) {
            $log = EmailLog::create([
                'message_id' => $messageId,
                'mailable' => $event->data['__laravel_mailable'] ?? $event->data['__laravel_notification'] ?? null,
                'subject' => mb_substr((string) $message->getSubject(), 0, 500),
                'from_address' => self::addresses($message->getFrom())[0] ?? null,
                'to_addresses' => $to,
                'cc_addresses' => $cc,
                'bcc_addresses' => $bcc,
                'source' => EmailLog::SOURCE_SNIPE,
                'status' => 'sent',
                'sent_at' => now(),
                'created_by' => auth()->id(),
            ]);

            EmailLogLinker::attach($log, array_merge(
                EmailLogLinker::fromData($event->data),
                EmailLogLinker::fromRecipients(array_merge($to, $cc, $bcc)),
            ));

            return $log;
        });
    }

    /**
     * Import messages from a delivery trace. One entry per message; an entry
     * whose message id is already logged only fills in what was missing.
     *
     * @param  array<int, array{message_id?: string, sent_at: string, from?: string, to?: array<int, string>, subject?: string, status?: string}>  $entries
     * @return array{created: int, updated: int, linked: int}
     */
    public function import(array $entries, string $source = EmailLog::SOURCE_EXCHANGE): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'linked' => 0];

        foreach ($entries as $entry) {
            $messageId = self::normaliseId($entry['message_id'] ?? null);
            $to = array_values(array_unique(array_map('strtolower', $entry['to'] ?? [])));
            $sentAt = Carbon::parse($entry['sent_at']);

            DB::transaction(function () use ($entry, $messageId, $to, $sentAt, $source, &$counts) {
                $log = $messageId ? EmailLog::where('message_id', $messageId)->first() : null;

                if ($log) {
                    $log->to_addresses = array_values(array_unique(array_merge($log->to_addresses ?? [], $to)));
                    $log->status ??= $entry['status'] ?? null;
                    $log->save();
                    $counts['updated']++;
                } else {
                    $log = EmailLog::create([
                        'message_id' => $messageId,
                        'subject' => mb_substr((string) ($entry['subject'] ?? ''), 0, 500),
                        'from_address' => isset($entry['from']) ? strtolower($entry['from']) : null,
                        'to_addresses' => $to,
                        'source' => $source,
                        'status' => $entry['status'] ?? null,
                        'sent_at' => $sentAt,
                        'created_by' => auth()->id(),
                    ]);
                    $counts['created']++;
                }

                $users = EmailLogLinker::fromRecipients($to);
                $before = $log->links()->count();

                EmailLogLinker::attach($log, array_merge(
                    $users,
                    EmailLogLinker::fromSubject($log->subject),
                    EmailLogLinker::fromStoreEvent($log->subject, $users, $sentAt),
                ));

                $counts['linked'] += $log->links()->count() - $before;
            });
        }

        return $counts;
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, string>
     */
    private static function addresses(array $addresses): array
    {
        return array_values(array_unique(array_map(fn (Address $a) => strtolower($a->getAddress()), $addresses)));
    }

    private static function messageId(MessageSent $event): ?string
    {
        try {
            return self::normaliseId($event->sent->getMessageId());
        } catch (\Throwable) {
            return null;
        }
    }

    /** Message ids with or without angle brackets compare equal. */
    private static function normaliseId(?string $id): ?string
    {
        $id = trim((string) $id, " \t\n\r\0\x0B<>");

        return $id === '' ? null : mb_substr($id, 0, 191);
    }
}
