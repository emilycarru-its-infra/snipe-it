<?php

namespace App\Services\Teams;

/**
 * Builds an Adaptive Card for a Power Automate ("Workflows") incoming webhook.
 *
 * The Workflows trigger replaced the retired Office 365 connectors and will not
 * accept the bare {"text": ...} body those took: its "post card in a chat or
 * channel" action reads attachments[0].content and fails the run outright when
 * that is missing. So every payload this builds is the full message envelope,
 * never a loose card.
 *
 * The card this replaces came from osa-eg/laravel-teams-notification, which
 * renders a bold title over a stack of two-column rows and nothing else: no
 * links, no timestamp, and a visible empty row wherever a value happened to be
 * null. The rules here exist to fix exactly that — a fact with no value is
 * dropped rather than rendered, actions become real buttons, and every card
 * carries who did it and when.
 *
 * Long content is handled by splitting rather than truncating: Teams rejects a
 * card payload over roughly 28 KB, so a report that lists a whole inventory is
 * emitted as a sequence of cards, each under the limit and titled "(n of m)".
 */
class TeamsCard
{
    /**
     * Teams rejects an attachment payload larger than about 28 KB. Cards are
     * split well under that — the encoded envelope is measured, but a card
     * that squeaks past the check here would still be at the mercy of how the
     * flow re-encodes it.
     */
    private const MAX_PAYLOAD_BYTES = 24576;

    /**
     * Adaptive Cards 1.5 is what Teams renders today and what the Table
     * element needs. Cards without a table are emitted at 1.4, which every
     * Teams surface has supported for years.
     */
    private const SCHEMA_VERSION_WITH_TABLE = '1.5';

    private const SCHEMA_VERSION = '1.4';

    private string $title = '';

    private string $accent = 'default';

    private ?string $subtitle = null;

    private ?string $icon = null;

    /** @var array<int, array{title: string, value: string}> */
    private array $facts = [];

    /** @var array<int, array{label: string, url: string}> */
    private array $actions = [];

    private ?string $footer = null;

    private ?string $note = null;

    /** @var array<int, string> */
    private array $tableColumns = [];

    /** @var array<int, array<int, string>> */
    private array $tableRows = [];

    public static function make(string $title): self
    {
        return (new self)->title($title);
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Card accent, using the Adaptive Card colour names Teams honours:
     * good, warning, attention, accent, dark, light, default.
     */
    public function accent(string $accent): self
    {
        $this->accent = in_array($accent, ['default', 'dark', 'light', 'accent', 'good', 'warning', 'attention'], true)
            ? $accent
            : 'default';

        return $this;
    }

    /**
     * Lead the title with a symbol and set it on a tinted band. For cards that
     * come in opposing pairs — checked out, checked in — where colour alone
     * left the two reading alike at a glance. The symbol stays clear of arrows
     * and chevrons, which is what the Relay bot's own avatar is made of.
     */
    public function banner(string $icon): self
    {
        $this->icon = self::clean($icon);

        return $this;
    }

    public function subtitle(?string $subtitle): self
    {
        $this->subtitle = self::clean($subtitle);

        return $this;
    }

    /**
     * A single labelled fact. An empty value is dropped: a card row reading
     * "Notes" with nothing beside it tells the reader less than no row at all.
     */
    public function fact(string $label, $value): self
    {
        $value = self::clean($value);

        if ($value !== null) {
            $this->facts[] = ['title' => $label, 'value' => $value];
        }

        return $this;
    }

    /** @param  array<string, mixed>  $facts  label => value, empties dropped. */
    public function facts(array $facts): self
    {
        foreach ($facts as $label => $value) {
            $this->fact((string) $label, $value);
        }

        return $this;
    }

    /**
     * A fact that describes a change. Renders "old → new" when the value
     * actually moved, and the plain value when it did not.
     */
    public function change(string $label, $old, $new): self
    {
        $old = self::clean($old);
        $new = self::clean($new);

        if ($old !== null && $new !== null && $old !== $new) {
            return $this->fact($label, $old.' → '.$new);
        }

        return $this->fact($label, $new ?? $old);
    }

    /**
     * Free text shown under the facts — a checkout note, a decline reason.
     * Kept out of the fact list so it can wrap over several lines.
     */
    public function note(?string $note): self
    {
        $this->note = self::clean($note);

        return $this;
    }

    /**
     * A deep link rendered as a button. Relative URLs are skipped: Teams
     * renders Action.OpenUrl only for an absolute http(s) address.
     */
    public function action(string $label, ?string $url): self
    {
        $url = self::clean($url);

        if ($url !== null && preg_match('#^https?://#i', $url)) {
            $this->actions[] = ['label' => $label, 'url' => $url];
        }

        return $this;
    }

    /**
     * The full row set for a report card. Every row is carried — the card is
     * split across several messages rather than trimmed.
     *
     * @param  array<int, string>  $columns
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function table(array $columns, array $rows): self
    {
        $this->tableColumns = array_values(array_map('strval', $columns));
        $this->tableRows = array_values(array_map(
            fn ($row) => array_values(array_map(fn ($cell) => self::clean($cell) ?? '—', (array) $row)),
            $rows
        ));

        return $this;
    }

    public function footer(?string $footer): self
    {
        $this->footer = self::clean($footer);

        return $this;
    }

    /**
     * The message payloads to post, in order. Usually one; a card whose table
     * would exceed the Teams size limit comes back as several, each titled
     * "(n of m)" so a reader knows the listing continues.
     *
     * @return array<int, array<string, mixed>>
     */
    public function payloads(): array
    {
        $single = $this->envelope($this->body($this->title, $this->tableRows));

        if ($this->tableRows === [] || self::fits($single)) {
            return [$single];
        }

        $chunks = $this->chunkRows();
        $total = count($chunks);

        $payloads = [];
        foreach ($chunks as $index => $rows) {
            $title = $this->title.' ('.($index + 1).' of '.$total.')';
            $payloads[] = $this->envelope($this->body($title, $rows, $index > 0));
        }

        return $payloads;
    }

    /** The first payload — the whole card whenever it fits in one. */
    public function payload(): array
    {
        return $this->payloads()[0];
    }

    /**
     * Just the Adaptive Cards, without the message envelope.
     *
     * Relay takes the card itself and builds its own envelope; the envelope in
     * payloads() is only for a raw Power Automate webhook, which is the
     * fallback path rather than the normal one.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cards(): array
    {
        return array_map(
            fn ($payload) => $payload['attachments'][0]['content'],
            $this->payloads()
        );
    }

    /**
     * Split the rows so each card stays under the size limit. Measured against
     * a real encoded payload rather than an estimate, because column headers
     * and the fact list are part of every chunk's overhead.
     *
     * @return array<int, array<int, array<int, string>>>
     */
    private function chunkRows(): array
    {
        $chunks = [];
        $current = [];

        foreach ($this->tableRows as $row) {
            $candidate = array_merge($current, [$row]);

            if ($current !== [] && ! self::fits($this->envelope($this->body($this->title.' (n of m)', $candidate)))) {
                $chunks[] = $current;
                $current = [$row];

                continue;
            }

            $current = $candidate;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private static function fits(array $payload): bool
    {
        return strlen((string) json_encode($payload)) <= self::MAX_PAYLOAD_BYTES;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function body(string $title, array $rows, bool $continuation = false): array
    {
        $heading = [[
            'type' => 'TextBlock',
            'size' => $this->icon !== null ? 'Large' : 'Medium',
            'weight' => 'Bolder',
            'color' => $this->accent,
            'wrap' => true,
            'text' => $this->icon !== null ? $this->icon.'  '.$title : $title,
        ]];

        if ($this->subtitle !== null) {
            $heading[] = [
                'type' => 'TextBlock',
                'spacing' => 'None',
                'isSubtle' => true,
                'wrap' => true,
                'text' => $this->subtitle,
            ];
        }

        // Container styles share names with text colours except that there is
        // no "dark" or "light" band; those fall back to the neutral emphasis.
        $body = $this->icon === null ? $heading : [[
            'type' => 'Container',
            'style' => in_array($this->accent, ['good', 'warning', 'attention', 'accent'], true) ? $this->accent : 'emphasis',
            'bleed' => true,
            'items' => $heading,
        ]];

        // A continuation card repeats the heading and the table so the listing
        // reads on, but not the facts — they describe the run, not the rows.
        if (! $continuation && $this->facts !== []) {
            $body[] = ['type' => 'FactSet', 'facts' => $this->facts];
        }

        if (! $continuation && $this->note !== null) {
            $body[] = [
                'type' => 'TextBlock',
                'wrap' => true,
                'separator' => true,
                'text' => $this->note,
            ];
        }

        if ($rows !== [] && $this->tableColumns !== []) {
            $body[] = $this->tableElement($rows);
        }

        if ($this->footer !== null) {
            $body[] = [
                'type' => 'TextBlock',
                'wrap' => true,
                'isSubtle' => true,
                'size' => 'Small',
                'spacing' => 'Medium',
                'text' => $this->footer,
            ];
        }

        return $body;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array<string, mixed>
     */
    private function tableElement(array $rows): array
    {
        // Every property here is repeated once per cell, so a report of a few
        // hundred rows pays for each one several thousand times over — and
        // paying for it in bytes means paying for it in extra cards. Only what
        // changes the rendering is emitted.
        $cell = function (string $text, bool $header = false) {
            $block = ['type' => 'TextBlock', 'text' => $text, 'wrap' => true];

            if ($header) {
                $block['weight'] = 'Bolder';
            }

            return ['type' => 'TableCell', 'items' => [$block]];
        };

        $table = [
            'type' => 'Table',
            'firstRowAsHeaders' => true,
            'gridStyle' => 'accent',
            'columns' => array_map(fn () => ['width' => 1], $this->tableColumns),
            'rows' => [[
                'type' => 'TableRow',
                'style' => 'accent',
                'cells' => array_map(fn ($column) => $cell($column, true), $this->tableColumns),
            ]],
        ];

        foreach ($rows as $row) {
            $table['rows'][] = [
                'type' => 'TableRow',
                'cells' => array_map(fn ($value) => $cell((string) $value), $row),
            ];
        }

        return $table;
    }

    /**
     * @param  array<int, array<string, mixed>>  $body
     * @return array<string, mixed>
     */
    private function envelope(array $body): array
    {
        $card = [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type' => 'AdaptiveCard',
            'version' => $this->tableRows === [] ? self::SCHEMA_VERSION : self::SCHEMA_VERSION_WITH_TABLE,
            'msteams' => ['width' => 'Full'],
            'body' => $body,
        ];

        if ($this->actions !== []) {
            $card['actions'] = array_map(fn ($action) => [
                'type' => 'Action.OpenUrl',
                'title' => $action['label'],
                'url' => $action['url'],
            ], $this->actions);
        }

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => $card,
            ]],
        ];
    }

    /**
     * Normalise a value to a printable string, or null when there is nothing
     * worth printing. Snipe stores display names HTML-encoded, so they are
     * decoded here rather than at every call site.
     */
    private static function clean($value): ?string
    {
        if ($value === null || is_bool($value) || is_array($value)) {
            return is_bool($value) ? ($value ? 'Yes' : 'No') : null;
        }

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i');
        }

        $value = trim(htmlspecialchars_decode((string) $value));

        return $value === '' ? null : $value;
    }
}
