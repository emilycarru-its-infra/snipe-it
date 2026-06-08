<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light dark" />
<meta name="supported-color-schemes" content="light dark" />
</head>
<body>
<style>
/* ---- Responsive / mobile ---- */
@media only screen and (max-width: 600px) {
.inner-body { width: 100% !important; }
.footer { width: 100% !important; }
.content-cell { padding: 24px !important; }
.table { padding-left: 12px !important; padding-right: 12px !important; }
.li-card { max-width: 100% !important; }
}

@media only screen and (max-width: 500px) {
.button { width: 100% !important; }
.content-cell { padding: 20px 16px !important; }
}

/* ---- Dark mode (clients that honour prefers-color-scheme, e.g. Apple Mail) ---- */
@media (prefers-color-scheme: dark) {
body, .wrapper, .content { background-color: #15171a !important; }
.body { background-color: #1e2023 !important; border-top-color: #2a2d31 !important; border-bottom-color: #2a2d31 !important; }
.inner-body, .content-cell { background-color: #1e2023 !important; }
.header { background-color: #ffffff !important; } /* keep the dark ECU wordmark legible */
h1, h2, h3 { color: #f3f4f6 !important; }
p { color: #cbd1d8 !important; }
a { color: #6aa8e0 !important; }
.footer td, .footer p { color: #8a8f98 !important; }
.table { border-color: #3a3f44 !important; }
.table td { color: #cbd1d8 !important; border-top-color: #2f3338 !important; }
.table td strong { color: #9aa1aa !important; }
.table th { color: #9aa1aa !important; border-bottom-color: #3a3f44 !important; }
.li-card { background-color: #232629 !important; border-color: #3a3f44 !important; }
.li-card-title { color: #f3f4f6 !important; }
.li-card-meta { color: #9aa1aa !important; }
.li-pill { background-color: #2f3338 !important; color: #cbd1d8 !important; }
.li-name a { color: #6aa8e0 !important; }
.li-min { color: #7f868f !important; }
}
</style>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{{ $header ?? '' }}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell">
{{ Illuminate\Mail\Markdown::parse($slot) }}

{{ $subcopy ?? '' }}
</td>
</tr>
</table>
</td>
</tr>

{{ $footer ?? '' }}
</table>
</td>
</tr>
</table>
</body>
</html>
