<?php

namespace App\Services;

use App\Mail\BaseMailable;
use App\Models\EmailLog;
use App\Models\PurchaseOrder;
use App\Models\StoreOrder;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Works out which records an email was about. A sent email carries the
 * models its mailable was built from; an imported one only has a subject,
 * recipients and a time, so those are matched against the records instead.
 */
class EmailLogLinker
{
    private const MAX_LINKS = 25;

    /** The timestamp each store lifecycle email follows, for matching imported mail. */
    private const STORE_EVENT_COLUMNS = [
        'requested' => 'created_at',
        'approved' => 'decided_at',
        'declined' => 'decided_at',
        'ordered' => 'vendor_sent_at',
        'quoted' => 'quote_received_at',
        'confirmed' => 'confirmed_at',
        'shipped' => 'shipped_at',
        'arrived' => 'arrived_at',
    ];

    /** How far an imported email's time may sit from the event it reports. */
    private const STORE_EVENT_WINDOW_HOURS = 36;

    /**
     * The models in a mailable's view data: public properties and anything
     * passed to the view, one level into arrays and collections.
     *
     * @return array<int, Model>
     */
    public static function fromData(array $data): array
    {
        $models = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && (str_starts_with($key, '__') || $key === 'message')) {
                continue;
            }

            foreach (self::modelsIn($value) as $model) {
                $models[] = $model;
            }
        }

        return self::unique($models);
    }

    /**
     * The users the email was addressed to.
     *
     * @param  array<int, string>  $addresses
     * @return array<int, Model>
     */
    public static function fromRecipients(array $addresses): array
    {
        $addresses = array_values(array_filter(array_map(fn ($a) => strtolower(trim((string) $a)), $addresses)));

        if ($addresses === []) {
            return [];
        }

        return User::whereIn('email', $addresses)->limit(self::MAX_LINKS)->get()->all();
    }

    /**
     * Records named in a subject line: store order references and purchase
     * order numbers.
     *
     * @return array<int, Model>
     */
    public static function fromSubject(?string $subject): array
    {
        $subject = (string) $subject;

        if ($subject === '') {
            return [];
        }

        $models = [];

        foreach (StoreOrder::referencePrefixes() as $prefix) {
            if (preg_match_all('/'.preg_quote($prefix, '/').'(\d+)\b/i', $subject, $m)) {
                foreach (StoreOrder::whereIn('id', $m[1])->get() as $order) {
                    $models[] = $order;
                }
            }
        }

        if (preg_match_all('/\b[A-Z]{1,4}\d{5,}\b/', $subject, $m)) {
            foreach (PurchaseOrder::whereIn('po_number', array_unique($m[0]))->get() as $po) {
                $models[] = $po;
            }
        }

        return self::unique($models);
    }

    /**
     * For an imported store lifecycle email ("Your order has shipped"),
     * the recipient's one store order whose matching event happened close to
     * the send time. Nothing when the subject is not a store email or more
     * than one order fits.
     *
     * @param  array<int, Model>  $recipients
     * @return array<int, Model>
     */
    public static function fromStoreEvent(?string $subject, array $recipients, ?CarbonInterface $sentAt): array
    {
        $event = self::storeEventFor((string) $subject);
        $userIds = collect($recipients)->filter(fn ($m) => $m instanceof User)->map->getKey()->all();

        if (! $event || ! $sentAt || $userIds === []) {
            return [];
        }

        $column = self::STORE_EVENT_COLUMNS[$event];
        $window = self::STORE_EVENT_WINDOW_HOURS;

        $orders = StoreOrder::whereIn('user_id', $userIds)
            ->whereBetween($column, [$sentAt->copy()->subHours($window), $sentAt->copy()->addHours($window)])
            ->limit(2)
            ->get();

        return $orders->count() === 1 ? [$orders->first()] : [];
    }

    /** Attach every model to the log, capped. */
    public static function attach(EmailLog $log, array $models): void
    {
        foreach (array_slice(self::unique($models), 0, self::MAX_LINKS) as $model) {
            $log->linkTo($model);
        }
    }

    /** Which store event a subject belongs to, by the subject each event sends today. */
    private static function storeEventFor(string $subject): ?string
    {
        $subject = trim(preg_replace('/^(re|fw|fwd):\s*/i', '', trim($subject)));

        foreach (array_keys(self::STORE_EVENT_COLUMNS) as $event) {
            $default = trans('mail.store_order_'.$event.'_subject');
            $current = (new class extends BaseMailable
            {
                public function subjectFor(string $key, string $default): string
                {
                    return $this->overriddenSubject($key, $default);
                }
            })->subjectFor('store.'.$event, $default);

            if (strcasecmp($subject, $current) === 0 || strcasecmp($subject, $default) === 0) {
                return $event;
            }
        }

        return null;
    }

    /** @return array<int, Model> */
    private static function modelsIn(mixed $value): array
    {
        if ($value instanceof Model) {
            return [$value];
        }

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            return array_values(array_filter(
                array_slice($value, 0, self::MAX_LINKS),
                fn ($item) => $item instanceof Model,
            ));
        }

        return [];
    }

    /**
     * @param  array<int, Model>  $models
     * @return array<int, Model>
     */
    private static function unique(array $models): array
    {
        $seen = [];

        return array_values(array_filter($models, function (Model $model) use (&$seen) {
            $key = $model->getMorphClass().':'.$model->getKey();

            if (! $model->getKey() || isset($seen[$key])) {
                return false;
            }

            return $seen[$key] = true;
        }));
    }
}
