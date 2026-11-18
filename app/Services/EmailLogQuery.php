<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\EmailLog;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\StoreOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Reads the email log for the report page, the record tabs and the API. */
class EmailLogQuery
{
    /** The record types the log can be filtered by, by their short name. */
    public const TYPES = [
        'asset' => Asset::class,
        'user' => User::class,
        'order' => Order::class,
        'purchase_order' => PurchaseOrder::class,
        'store_order' => StoreOrder::class,
    ];

    public static function build(?string $type = null, ?int $id = null, ?string $search = null): Builder
    {
        $query = EmailLog::query()->orderByDesc('sent_at')->orderByDesc('id');

        if ($type && $id && isset(self::TYPES[$type])) {
            $class = self::TYPES[$type];
            $morph = (new $class)->getMorphClass();
            $query->whereHas('links', fn (Builder $links) => $links
                ->where('linkable_type', $morph)
                ->where('linkable_id', $id));
        }

        if ($search !== null && trim($search) !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
            $query->where(fn (Builder $q) => $q
                ->where('subject', 'like', $term)
                ->orWhere('to_addresses', 'like', $term)
                ->orWhere('cc_addresses', 'like', $term)
                ->orWhere('bcc_addresses', 'like', $term)
                ->orWhere('message_id', 'like', $term));
        }

        return $query;
    }

    /** The API shape of one row. */
    public static function present(EmailLog $log): array
    {
        $types = array_flip(array_map(fn ($class) => (new $class)->getMorphClass(), self::TYPES));

        return [
            'id' => $log->id,
            'sent_at' => $log->sent_at?->toIso8601String(),
            'subject' => $log->subject,
            'from' => $log->from_address,
            'to' => $log->to_addresses ?? [],
            'cc' => $log->cc_addresses ?? [],
            'bcc' => auth()->user()?->hasAccess('admin') ? ($log->bcc_addresses ?? []) : [],
            'kind' => $log->kind(),
            'source' => $log->source,
            'status' => $log->status,
            'message_id' => $log->message_id,
            'links' => $log->links
                ->map(fn (\App\Models\EmailLogLink $link) => ['type' => $types[$link->linkable_type] ?? $link->linkable_type, 'id' => $link->linkable_id])
                ->values()
                ->all(),
        ];
    }
}
