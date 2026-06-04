@php
    $count = $count ?? count($items);

    // Build the printer-grouped cards as one contiguous HTML string (no blank
    // lines) so Markdown treats it as a single pass-through HTML block. Mirrors
    // the /consumables dashboard: a narrow card per printer model, each low
    // toner as a row with a colour-coded quantity chip (red = out, amber =
    // below min). Constraining the card width keeps the numbers close to the
    // names instead of justified to the far edge of a full-width table.
    $cards = '';
    foreach ($groups as $group) {
        $pillCell = '';
        if (! is_null($group['printers_count'])) {
            $n = (int) $group['printers_count'];
            $pillCell = '<td align="right" style="white-space:nowrap;vertical-align:middle;">'
                .'<span style="display:inline-block;font-size:12px;font-weight:600;color:#374151;background:#f3f4f6;border-radius:11px;padding:3px 10px;">'
                .$n.' '.e(\Illuminate\Support\Str::plural('printer', $n)).'</span></td>';
        }

        $meta = $group['manufacturer']
            ? '<span style="font-weight:400;color:#6b7280;"> — '.e($group['manufacturer']).'</span>'
            : '';

        $rows = '';
        foreach ($group['items'] as $item) {
            $rem = (int) $item['remaining'];
            $out = $rem <= 0;
            $chipBg = $out ? '#fde8e8' : '#fef3c7';
            $chipFg = $out ? '#b42318' : '#92700a';
            $url = route(($item['type'] ?? 'consumables').'.show', $item['id']);

            $rows .= '<tr>'
                .'<td style="padding:9px 0;border-top:1px solid #f3f4f6;font-size:14px;line-height:1.3;">'
                .'<a href="'.e($url).'" style="color:#2563eb;text-decoration:none;">'.e($item['name']).'</a>'
                .'</td>'
                .'<td align="right" style="padding:9px 0;border-top:1px solid #f3f4f6;white-space:nowrap;vertical-align:middle;">'
                .'<span style="display:inline-block;min-width:24px;text-align:center;padding:3px 9px;border-radius:6px;'
                .'font-size:13px;font-weight:700;background:'.$chipBg.';color:'.$chipFg.';">'.$rem.'</span>'
                .'<span style="font-size:12px;color:#9ca3af;"> / '.e($item['min_amt']).' min</span>'
                .'</td>'
                .'</tr>';
        }

        $cards .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" '
            .'style="max-width:520px;margin:18px 0 0;border:1px solid #e5e7eb;border-radius:8px;border-collapse:separate;">'
            .'<tr><td style="padding:12px 16px 11px;border-bottom:1px solid #eef0f2;">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>'
            .'<td style="font-size:15px;font-weight:700;color:#111827;line-height:1.3;">'.e($group['model_name']).$meta.'</td>'
            .$pillCell
            .'</tr></table></td></tr>'
            .'<tr><td style="padding:0 16px 8px;">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'.$rows.'</table>'
            .'</td></tr></table>';
    }
@endphp
@component('mail::message')
# ⚠️ {{ trans('mail.Low_Inventory_Report') }}

{{ trans_choice('mail.low_inventory_alert', $count) }}

{!! $cards !!}
@endcomponent
