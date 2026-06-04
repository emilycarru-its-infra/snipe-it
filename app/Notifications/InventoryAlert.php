<?php

namespace App\Notifications;

use AllowDynamicProperties;
use App\Notifications\Concerns\OverridableMailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

#[AllowDynamicProperties]
class InventoryAlert extends Notification
{
    use Queueable, OverridableMailNotification;

    private $params;

    /**
     * Create a new notification instance.
     */
    public function __construct($params, $threshold)
    {
        $this->items = $params;
        $this->threshold = $threshold ?? 0;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array
     */
    public function via()
    {
        return (! empty($this->items) && $this->threshold !== null) ? ['mail'] : [];

    }

    /**
     * Get the mail representation of the notification.
     *
     * @return MailMessage
     */
    public function toMail()
    {
        $items = is_array($this->items) ? $this->items : collect($this->items)->toArray();
        $items = $this->attachPrinterModels($items);

        $data = [
            'items' => $items,
            'groups' => $this->groupedByPrinter($items),
            'count' => count($items),
            'threshold' => $this->threshold,
        ];

        $message = (new MailMessage)
            ->subject($this->overriddenSubject('report.low_inventory', '⚠️ '.trans('mail.Low_Inventory_Report')))
            ->withSymfonyMessage(function (Email $message) {
                $message->getHeaders()->addTextHeader(
                    'X-System-Sender', 'Snipe-IT'
                );
            });

        return $this->applyBody($message, 'report.low_inventory', 'notifications.markdown.report-low-inventory', $data);
    }

    /**
     * Attach the compatible printer model(s) — name, manufacturer, and a count
     * of the physical printers of that model — to each consumable row that
     * doesn't already carry them. Scoped to the (small) low-stock set and only
     * runs when the email is built, so the per-request alert-menu dropdown that
     * also calls Helper::checkLowInventory() keeps its lean query. Rows that
     * already declare `models` (e.g. the Settings → Emails preview sample) skip
     * the lookup entirely, keeping the preview free of DB dependencies.
     */
    protected function attachPrinterModels(array $items): array
    {
        $needIds = [];
        foreach ($items as $item) {
            if (($item['type'] ?? null) === 'consumables' && ! array_key_exists('models', $item)) {
                $needIds[] = $item['id'];
            }
        }

        if (empty($needIds)) {
            return $items;
        }

        $modelsByConsumable = \App\Models\Consumable::query()
            ->with(['compatibleModels' => fn ($q) => $q->withCount('assets')->with('manufacturer')])
            ->whereIn('id', $needIds)
            ->get()
            ->mapWithKeys(fn ($consumable) => [
                $consumable->id => $consumable->compatibleModels->map(fn ($model) => [
                    'name' => $model->name,
                    'manufacturer' => optional($model->manufacturer)->name,
                    'printers_count' => $model->assets_count,
                ])->all(),
            ])
            ->all();

        foreach ($items as $i => $item) {
            if (($item['type'] ?? null) === 'consumables' && ! array_key_exists('models', $item)) {
                $items[$i]['models'] = $modelsByConsumable[$item['id']] ?? [];
            }
        }

        return $items;
    }

    /**
     * Group low-stock rows by the printer model(s) they belong to — mirroring
     * the /consumables dashboard — so the email reads "these printers need
     * toner" rather than a flat, contextless list. Each consumable carries a
     * `models` list (name + manufacturer + printer count) from
     * Helper::checkLowInventory(); a toner compatible with several models shows
     * under each. Anything without a model (other consumables, accessories,
     * licenses, …) collects into a trailing "Other low stock" group.
     *
     * @return array<int, array{model_name:string, manufacturer:?string, printers_count:?int, items:array}>
     */
    protected function groupedByPrinter(array $items): array
    {
        $groups = [];
        $other = [];

        foreach ($items as $item) {
            $models = $item['models'] ?? [];

            if (empty($models)) {
                $other[] = $item;
                continue;
            }

            foreach ($models as $model) {
                $name = $model['name'];
                if (! isset($groups[$name])) {
                    $groups[$name] = [
                        'model_name' => $name,
                        'manufacturer' => $model['manufacturer'] ?? null,
                        'printers_count' => $model['printers_count'] ?? null,
                        'items' => [],
                    ];
                }
                $groups[$name]['items'][] = $item;
            }
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        $grouped = array_values($groups);

        if (! empty($other)) {
            $grouped[] = [
                'model_name' => trans('mail.other_low_stock'),
                'manufacturer' => null,
                'printers_count' => null,
                'items' => $other,
            ];
        }

        return $grouped;
    }
}
