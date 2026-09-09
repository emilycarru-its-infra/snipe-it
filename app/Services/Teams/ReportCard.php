<?php

namespace App\Services\Teams;

use Illuminate\Support\Collection;

/**
 * The card for a scheduled digest.
 *
 * One builder, used by both the commands that send these and the Settings →
 * Emails preview. They were built separately at first and had already drifted
 * — the preview pluralised its heading differently and cased a column
 * differently from the card that actually went out, which makes the preview
 * worse than useless.
 *
 * Every row is carried. A digest that says only "12 assets" sends the reader
 * looking for the twelve, which is the work the card was meant to save; a
 * listing too long for one card is split by TeamsCard rather than trimmed.
 */
class ReportCard
{
    /**
     * @param  array<int, string>  $columns
     * @param  iterable<mixed>  $rows
     * @param  callable(mixed): array<int, mixed>  $map
     * @param  array<string, mixed>  $facts
     */
    public static function make(
        string $title,
        string $accent,
        array $columns,
        iterable $rows,
        callable $map,
        array $facts = [],
        ?string $url = null,
    ): TeamsCard {
        $rows = Collection::make($rows);

        return TeamsCard::make($title)
            ->accent($accent)
            ->subtitle(trans_choice('general.teams_report_subtitle', $rows->count(), ['count' => $rows->count()]))
            ->facts($facts)
            ->table($columns, $rows->map($map)->all())
            ->action(trans('general.teams_view_report'), $url)
            ->footer(now()->format('D, M j Y'));
    }
}
