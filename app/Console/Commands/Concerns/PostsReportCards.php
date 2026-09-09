<?php

namespace App\Console\Commands\Concerns;

use App\Mail\EmailDelivery;
use App\Services\Teams\ReportCard;
use App\Services\Teams\TeamsNotifier;
use Illuminate\Support\Collection;

/**
 * The scheduled digests, as Teams cards.
 *
 * These reports carry every row rather than a count and a link: the count is
 * the part a reader already has from the heading, and the rows are the work.
 * A listing long enough to exceed what Teams accepts in one card is split by
 * the card builder, never trimmed.
 *
 * Posted synchronously — a console run has no response to defer past, and one
 * that exits before its deferred callbacks fire would post nothing at all.
 */
trait PostsReportCards
{
    /**
     * @param  array<int, string>  $columns
     * @param  iterable<mixed>  $rows
     * @param  callable(mixed): array<int, mixed>  $map
     * @param  array<string, mixed>  $facts
     */
    protected function postReportCard(
        string $key,
        string $title,
        string $accent,
        array $columns,
        iterable $rows,
        callable $map,
        array $facts = [],
        ?string $url = null,
    ): void {
        if (! EmailDelivery::shouldPostToTeams($key)) {
            return;
        }

        $rows = Collection::make($rows);

        if ($rows->isEmpty()) {
            return;
        }

        app(TeamsNotifier::class)->announce(
            $key,
            ReportCard::make($title, $accent, $columns, $rows, $map, $facts, $url),
            defer: false,
        );
    }

    /** Whether this report should still be emailed as well as, or instead of, posted. */
    protected function shouldEmailReport(string $key): bool
    {
        return EmailDelivery::shouldEmail($key);
    }
}
