{{--
    A standalone rendering of the Adaptive Card an email would post, for the
    Settings → Emails preview pane. It reads the same payload the notifier
    sends, so what is shown here is what the channel receives — not a second
    description of it that can drift.

    Rendered into an iframe alongside the email preview, hence the full
    document and the self-contained styles.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            padding: 16px;
            background: #f5f5f5;
            font: 14px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #242424;
        }
        .card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 12px;
            max-width: 900px;
        }
        .card-title { font-size: 16px; font-weight: 600; margin: 0 0 2px; }
        .accent-good { color: #107c10; }
        .accent-warning { color: #bd6b00; }
        .accent-attention { color: #a4262c; }
        .accent-accent { color: #0f548c; }
        .accent-default, .accent-dark, .accent-light { color: #242424; }
        .subtitle { color: #616161; margin: 0 0 12px; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; margin: 0 0 12px; }
        dt { font-weight: 600; }
        dd { margin: 0; }
        .note { margin: 0 0 12px; padding-top: 10px; border-top: 1px solid #ededed; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 12px; font-size: 13px; }
        th, td { text-align: left; padding: 5px 8px; border-bottom: 1px solid #ededed; vertical-align: top; }
        th { background: #f0f0f0; font-weight: 600; }
        .actions { margin-bottom: 8px; }
        .actions span {
            display: inline-block;
            padding: 5px 12px;
            margin-right: 6px;
            border: 1px solid #0f548c;
            border-radius: 4px;
            color: #0f548c;
            font-size: 13px;
        }
        .footer { color: #616161; font-size: 12px; margin: 0; }
        .cards-note { color: #616161; font-size: 12px; margin: 0 0 12px; }
        @media (prefers-color-scheme: dark) {
            body { background: #1f1f1f; color: #e6e6e6; }
            .card { background: #292929; border-color: #3d3d3d; }
            .subtitle, .footer, .cards-note { color: #adadad; }
            th { background: #333; }
            th, td, .note { border-color: #3d3d3d; }
            .accent-default, .accent-dark, .accent-light { color: #e6e6e6; }
        }
    </style>
</head>
<body>

@if (count($cards) > 1)
    <p class="cards-note">
        {{ trans('admin/settings/general.emails_teams_split', ['count' => count($cards)]) }}
    </p>
@endif

@foreach ($cards as $card)
    <div class="card">
        @foreach ($card['body'] as $block)
            @if ($block['type'] === 'TextBlock' && ($block['weight'] ?? '') === 'Bolder' && ($block['size'] ?? '') === 'Medium')
                <p class="card-title accent-{{ $block['color'] ?? 'default' }}">{{ $block['text'] }}</p>
            @elseif ($block['type'] === 'TextBlock' && ($block['isSubtle'] ?? false) && ($block['size'] ?? '') === 'Small')
                <p class="footer">{{ $block['text'] }}</p>
            @elseif ($block['type'] === 'TextBlock' && ($block['isSubtle'] ?? false))
                <p class="subtitle">{{ $block['text'] }}</p>
            @elseif ($block['type'] === 'TextBlock')
                <p class="note">{{ $block['text'] }}</p>
            @elseif ($block['type'] === 'FactSet')
                <dl>
                    @foreach ($block['facts'] as $fact)
                        <dt>{{ $fact['title'] }}</dt>
                        <dd>{{ $fact['value'] }}</dd>
                    @endforeach
                </dl>
            @elseif ($block['type'] === 'Table')
                <table>
                    @foreach ($block['rows'] as $rowIndex => $row)
                        <tr>
                            @foreach ($row['cells'] as $cell)
                                @php($text = $cell['items'][0]['text'] ?? '')
                                @if ($rowIndex === 0)
                                    <th>{{ $text }}</th>
                                @else
                                    <td>{{ $text }}</td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                </table>
            @endif
        @endforeach

        @if (! empty($card['actions']))
            <div class="actions">
                @foreach ($card['actions'] as $action)
                    <span>{{ $action['title'] }}</span>
                @endforeach
            </div>
        @endif
    </div>
@endforeach

</body>
</html>
