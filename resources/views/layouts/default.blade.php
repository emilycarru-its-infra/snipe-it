<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ Helper::determineLanguageDirection() }}" data-theme="light">
<head>
    <meta charset="utf-8">
    {{-- Stamp the real theme before any CSS loads. The attribute above is
         only a no-JS default; leaving it as "light" until the toggle script
         at the end of <body> ran meant dark-mode users got a light first
         paint and anything keyed on [data-theme="dark"] (the wordmark swap,
         the chrome tokens) was wrong until then. --}}
    <script nonce="{{ csrf_token() }}">
        (function () {
            try {
                var stored = localStorage.getItem("theme");
                var theme = stored !== null
                    ? stored
                    : (window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");
                document.documentElement.setAttribute("data-theme", theme);
            } catch (e) { /* leave the no-JS default */ }
        })();
    </script>
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>
        @section('title')
        @show
        :: {{ $snipeSettings->site_name }}
    </title>
    <!-- Tell the browser to be responsive to screen width -->
    <meta content="width=device-width, initial-scale=1" name="viewport">

    <meta name="apple-mobile-web-app-capable" content="yes">


    <link rel="apple-touch-icon"
          href="{{ ($snipeSettings) && ($snipeSettings->favicon!='') ?  Storage::disk('public')->url(e($snipeSettings->logo)) :  config('app.url').'/img/snipe-logo-bug.png' }}">
    <link rel="apple-touch-startup-image"
          href="{{ ($snipeSettings) && ($snipeSettings->favicon!='') ?  Storage::disk('public')->url(e($snipeSettings->logo)) :  config('app.url').'/img/snipe-logo-bug.png' }}">
    <link rel="shortcut icon" type="image/ico"
          href="{{ ($snipeSettings) && ($snipeSettings->favicon!='') ?  Storage::disk('public')->url(e($snipeSettings->favicon)) : config('app.url').'/favicon.ico' }}">


    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="language" content="{{ Helper::mapBackToLegacyLocale(app()->getLocale()) }}">
    <meta name="language-direction" content="{{ Helper::determineLanguageDirection() }}">
    <meta name="baseUrl" content="{{ config('app.url') }}/">
    <meta name="theme-color" content="{{ $snipeSettings->header_color ?? '#5fa4cc' }}">

    <script nonce="{{ csrf_token() }}">
        window.Laravel = {csrfToken: '{{ csrf_token() }}'};
    </script>

    {{-- stylesheets --}}
    <link rel="stylesheet" href="{{ url(mix('css/dist/all.css')) }}">
    {{-- The ECU design layer: tokens + shared components (cards, kickers,
         chevron rails, control kit). Static and unbundled on purpose — it
         carries no build step, and the mtime query busts caches on deploy. --}}
    <link rel="stylesheet" href="{{ url(asset('css/ecu-ui.css')) }}?v={{ @filemtime(public_path('css/ecu-ui.css')) }}">

    {{-- page level css --}}
    @stack('css')


    <style>


        :root {
            color-scheme: light dark;
            --btn-theme-hover-text-color: {{ $nav_link_color ?? 'light-dark(hsl(from var(--main-theme-color) h s calc(l - 10)),hsl(from var(--main-theme-color) h s calc(l - 10)))' }};
            --btn-theme-hover: {{ $nav_link_color ?? 'light-dark(hsl(from var(--main-theme-color) h s calc(l - 10)),hsl(from var(--main-theme-color) h s calc(l - 10)))' }};
            --btn-theme-text-color: {{ $nav_link_color ?? 'light-dark(hsl(from var(--main-theme-color) h s calc(l + 10)),hsl(from var(--main-theme-color) h s calc(l - 10)))' }};
            --color-fg: light-dark(#373636, #ffffff);
            --main-footer-bg-color: light-dark(#f4f4f4,#1a1a1a);
            --main-footer-text-color: light-dark(#6a6a6a, #8c8c8c);
            --main-footer-top-border-color: light-dark(#e4e4e4,#3a3a3a);
            --main-theme-color: {{ $snipeSettings->header_color ?? '#3c8dbc' }};
            --nav-hover-text-color: {{ $nav_link_color ?? 'hsl(from var(--main-theme-color) h s calc(l - 10))' }};
            --nav-primary-text-color: {{ $nav_link_color ?? '#ffffff' }};
            --search-highlight: #e9d15b;
            {{-- Sidebar and topbar text ride on the neutral chrome surface, so
                 they take the same foreground as body copy rather than the
                 near-white that the old coloured slab required. --}}
            --sidenav-hover-color-bg: var(--chrome-hover-bg);
            --sidenav-text-hover-color: var(--chrome-fg);
            --sidenav-text-nohover-color: var(--chrome-fg-muted);
            --table-border-row-color: light-dark(#f0f0f0, #2c2c2c);
            --table-border-row-top: 1px solid var(--table-border-row-color);
            --table-border-row: 1px solid var(--table-border-row-color);
            --table-stripe-bg-alt: light-dark(#fafafa, #212121);
            --table-stripe-bg: light-dark(#ffffff, #1f1f1f);
            --text-danger: light-dark(#a94442, #fa5b48);
            --text-help: light-dark(#777676,#a6a4a4);
            --text-info: light-dark(#31708f,#2baae6);
            --text-success: light-dark(#039516,#4ced61);
            --text-warning: light-dark(#da9113,#f3a51f);
            --input-border-color: light-dark(#d2d6de,#656464);
        }

        {{--
            Chrome tokens.

            The sidebar and topbar are *not* painted with the brand colour.
            They sit on the same surface as the body so the whole window reads
            as one field with the content boxes floating on it, rather than a
            coloured slab wrapped around a grey page. --main-theme-color stays
            in play as an accent only: active rails, buttons, badges, links.

            --chrome-bg is deliberately identical to --color-bg. Keep it that
            way — it is the whole point of the treatment. Separation between
            chrome and content comes from --chrome-border-color hairlines and
            from the white/raised --box-bg of the content boxes, not from a
            background contrast step.
        --}}

        [data-theme="light"] {
            color-scheme: light;
            --box-bg: #ffffff;
            --box-header-bottom-border-color: #f0f0f0;
            --box-header-bottom-border: 1px solid var(--box-header-bottom-border-color);
            --box-header-top-border-color: #d2d6de;
            --box-header-top-border: 3px solid var(--box-header-top-border-color);
            {{-- Clamped, not lightened. The accent is user-configurable and
                 --nav-primary-text-color defaults to white, so a light accent
                 used to put white text on a pale fill. Holding the fill in a
                 dark band keeps white legible whatever hue is configured. --}}
            --btn-theme-base: hsl(from var(--main-theme-color) h s clamp(28, l, 38));
            --btn-theme-border: hsl(from var(--btn-theme-base) h s calc(l - 12));
            --btn-theme-hover-text-color:  var(--nav-primary-text-color);
            --btn-theme-hover: hsl(from var(--btn-theme-base) h s calc(l - 8));
            --btn-neutral-bg: #ffffff;
            {{-- Darker than Bootstrap's #ccc, which only reaches 1.6:1 against
                 a white box — not enough to read the segmented filter group as
                 a control at all. --}}
            --btn-neutral-border: #909090;
            --btn-neutral-fg: #3d4144;
            --btn-neutral-active-bg: #e8e8e8;
            --callout-bg-color: var(--box-header-bottom-border-color);
            --callout-left-border: var(--box-header-top-border-color);
            --chrome-active-bg: #ffffff;
            --chrome-bg: #f4f4f4;
            --chrome-border-color: #e4e4e4;
            --chrome-fg: #2f2f2f;
            --chrome-fg-muted: #5f5f5f;
            --chrome-hover-bg: #eaeaea;
            --chrome-shadow: 0 6px 16px rgba(0, 0, 0, .16);
            --color-bg: #f4f4f4;
            --header-color: #000000;
            --input-group-bg: hsl(from var(--box-bg) h s calc(l - 5));
            --input-group-fg: hsl(from var(--input-group-bg) h s calc(l - 50));
            --link-color: {{ $link_light_color ?? '#296282' }};
            --link-hover:  hsl(from var(--link-color) h s calc(l - 10));
            --main-theme-hover: hsl(from var(--main-theme-color) h s calc(l - 10));
            --tab-bottom-border: 1px solid var(--box-header-top-border-color);
            --text-legend-help: var(--text-help);

        }

        [data-theme="dark"] {
            color-scheme: dark;
            --box-bg: #1f1f1f;
            --box-header-bottom-border-color: #2c2c2c;
            --box-header-bottom-border: 1px solid var(--box-header-bottom-border-color);
            --box-header-top-border-color: #3a3a3a;
            --box-header-top-border: 3px solid var(--box-header-top-border-color);
            {{-- Same clamp as light mode so white stays legible on the fill.
                 The fill is close in lightness to --box-bg here, so the button
                 edge comes from a *lighter* border rather than a darker one. --}}
            --btn-theme-base: hsl(from var(--main-theme-color) h s clamp(32, l, 44));
            --btn-theme-border: hsl(from var(--btn-theme-base) h s calc(l + 18));
            --btn-theme-hover-text-color:  var(--nav-primary-text-color);
            --btn-theme-hover: hsl(from var(--btn-theme-base) h s calc(l - 8));
            --btn-neutral-bg: #2c2c2c;
            --btn-neutral-border: #4a4a4a;
            --btn-neutral-fg: #f2f2f2;
            --btn-neutral-active-bg: #343434;
            --callout-bg-color: var(--box-header-top-border-color);
            --callout-left-border: #323131;
            --chrome-active-bg: #262626;
            --chrome-bg: #1a1a1a;
            --chrome-border-color: #3a3a3a;
            --chrome-fg: #f2f2f2;
            --chrome-fg-muted: #b6b6b6;
            --chrome-hover-bg: #262626;
            --chrome-shadow: 0 6px 18px rgba(0, 0, 0, .55);
            --color-bg: #1a1a1a;
            --header-color: #ffffff;
            --input-group-bg: hsl(from var(--box-bg) h s calc(l + 10));
            --input-group-fg: hsl(from var(--input-group-bg) h s calc(l + 50));
            --link-color: {{ $link_dark_color ?? '#5fa4cc' }};
            --link-hover:  hsl(from var(--link-color) h s calc(l + 15));
            --main-theme-hover: hsl(from var(--main-theme-color) h s calc(l - 10));
            --tab-bottom-border: 1px solid var(--box-header-top-border-color);
            --text-legend-help: #d6d6d6;

        }

        .label2_fields,
        .l2fd-main,
        .l2fd-listitem,
        .fixed-table-loading,
        .list-group-item
        {
            background-color: var(--box-bg) !important;
            color: var(--color-fg) !important;
        }

        .list-group-item {
            border: var(--tab-bottom-border);
        }

        footer.main-footer {
            color: var(--main-footer-text-color) !important;
            background-color: var(--main-footer-bg-color) !important;
            border-top: 1px solid var(--main-footer-top-border-color) !important;
        }

        a,
        a:link,
        a:visited
        {
            color: var(--link-color);
        }

        a:hover,
        a:focus
        {
            color: var(--link-hover) !important;
        }

        label.form-control {
            color: var(--color-fg) !important;
        }

        .footer-links a {
            color: var(--link-color) !important;
        }

        h2 small {
            color: var(--color-fg) !important;
        }

        .btn-theme {
            background-color: var(--btn-theme-base);
            color: var(--nav-primary-text-color) !important;
            border: 1px solid var(--btn-theme-border) !important;
        }

        .btn-theme:hover {
            background-color: var(--btn-theme-hover);
            color: var(--nav-primary-text-color) !important;
            border: 1px solid var(--btn-theme-border) !important;
        }

        .btn-theme.active
        {
            background-color: var(--btn-theme-hover) !important;
        }

        .btn-theme:focus {
            color: var(--nav-primary-text-color) !important;
        }


        .dropdown-wrapper,
        .js-data-ajax,
        .option,
        .select2 .select2-container .select2-container--default,
        .select2,
        .select2-choice,
        .select2-container,
        .select2-results__option,
        .select2-search input,
        .select2-search--dropdown,
        .select2-search__field,
        .select2-selection .select2-selection--single,
        .select2-selection,
        .select2-selection--single,
        .select2-selection__rendered,
        input[type="date"],
        input[type="number"],
        input[type="text"],
        input[type="url"],
        input[type="email"],
        input[type="password"],
        input[type="tel"],
        {{-- bootstrap-table renders its toolbar filter as type="search", which
             was missing from this list and so kept Bootstrap's white fill in
             dark mode. --}}
        input[type="search"],
        option:active,
        option[active],
        option[selected],
        select option,
        select,
        textarea
        {
            background-color: var(--table-stripe-bg) !important;
            color: var(--color-fg) !important;
            border-color: var(--input-border-color) !important;

        }

        .select2-container--default.select2-container--focus .select2-selection--multiple,
        .select2-container--default .select2-search--dropdown .select2-search__field {
            border-color: hsl(from var(--main-theme-color) h s calc(l - 5)) !important;
        }

        /**
        Multiselect maybe?
         */
        .select2-results__option[aria-selected=true]
        {
            background-color: var(--main-theme-color) !important;
            color: var(--nav-primary-text-color) !important;
        }

        .select2-results__option[aria-selected=false]
        {
            background-color: var(--table-stripe-bg) !important;
            /*background-color: hsl(from var(--main-theme-color) h s calc(l - 15)) !important;*/
            /*color: var(--nav-primary-text-color) !important;*/
            color: var(--color-fg) !important;
        }

        /**
        Highlight the select2 on hover when NOT the selected option
         */
        .select2-results__option--highlighted[aria-selected=false]
        {
            background-color: hsl(from var(--main-theme-color) h s calc(l - 10)) !important;
            color: var(--nav-primary-text-color) !important;
        }

        /**
        Highlight the select2 on hover when the selected option
         */
        .select2-results__option--highlighted[aria-selected=true],
        .select2-results__option--highlighted[aria-selected=true]:hover,
        .select2-results__option--highlighted[aria-selected=true]:link,
        .select2-results__option--highlighted[aria-selected=true]:focus,
        .select2-results__option--highlighted[aria-selected=true]:visited
        {
            background-color: hsl(from var(--main-theme-color) h s calc(l - 15)) !important;
            /*color: var(--color-fg) !important;*/
            color: var(--nav-primary-text-color) !important;
        }

        .select2-selection__choice,
        .select2-container--default .select2-selection--multiple .select2-selection__choice
        {
            background-color: var(--main-theme-color) !important;
            border-color: hsl(from var(--main-theme-color) h s calc(l - 15)) !important;
            color: var(--nav-primary-text-color) !important;
        }

        .select2-selection__choice__remove {
            color: var(--nav-primary-text-color) !important;
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice
        {
            background-color: hsl(from var(--main-theme-color) h s calc(l - 5)) !important;
            color: var(--nav-primary-text-color) !important;
            overflow-y: auto;
        }


        .input-group-addon {
            background-color: var(--input-group-bg) !important;
            color: var(--input-group-fg) !important;
            border-color: var(--input-border-color) !important;
        }

        input:disabled,
        input[type="checkbox"]:disabled,
        input[type="radio"]:disabled,
        input[readonly],
        textarea[readonly],
        .select2-container--default.select2-container--disabled .select2-selection--single,
        .select2-container--default.select2-container--disabled .select2-selection--multiple,
        .select2-container--default.select2-container--disabled .select2-selection__rendered,
        .select2-container--default.select2-container--disabled .select2-selection--multiple .select2-search--inline {
            background-color: light-dark(rgb(234, 232, 232), rgb(117, 116, 117)) !important;
            cursor: not-allowed !important;
        }

        .select2-container--default.select2-container--disabled .select2-search__field::placeholder {
            color: var(--text-help) !important;
            opacity: 1 !important;
        }

        input[type="search"].search-highlight {
            background-color: var(--search-highlight);
            border: 1px solid hsl(from var(--search-highlight) h s calc(l - 20)) !important;
        }

        .content-wrapper {
            background-color: var(--color-bg);
        }

        .btn-anchor {
            outline: none !important;
            padding: 0;
            border: 0;
            padding-left: 20px;
            vertical-align: baseline;
            cursor: pointer;
        }

        h1,
        h2,
        h3,
        h4,
        p,
        .modal-title,
        .modal-header h2
        {
            color: var(--color-fg) !important;
        }

        .btn-danger,
        .btn-danger:hover,
        .btn-danger:focus,
        .btn-warning,
        .btn-warning:hover,
        .btn-warning:focus,
        .btn-primary,
        .btn-primary:hover,
        .btn-primary:focus,
        .modal-danger,
        .modal-danger h2,
        .modal-warning h2,
        .modal-danger h4,
        .modal-warning h4,
        .bg-maroon,
        .bg-maroon:hover,
        .bg-maroon:focus,
        .bg-purple,
        .bg-purple:hover,
        .bg-purple:focus
        {
            color: white !important;
        }


        .btn-selected,
        .btn-selected a,
        .btn-selected:hover,
        .btn-selected:focus {
            color: light-dark(hsl(from var(--main-theme-color) h s calc(l + 30)), hsl(from var(--main-theme-color) h s calc(l + 30))) !important;
            background-color: light-dark(hsl(from var(--main-theme-color) h s calc(l - 20)), hsl(from var(--main-theme-color) h s calc(l - 20))) !important;
            border-color: light-dark(hsl(from var(--main-theme-color) h s calc(l - 25)), hsl(from var(--main-theme-color) h s calc(l - 25))) !important;

        }

        {{-- Bootstrap paints .btn-default white with dark text, which is a
             light-mode-only assumption — in dark mode the filter pills above
             a table came out as white slabs. Route it through neutral tokens
             so it follows the theme. --}}
        .btn-default,
        .btn-default:link,
        .btn-default:visited
        {
            background-color: var(--btn-neutral-bg) !important;
            border-color: var(--btn-neutral-border) !important;
            color: var(--btn-neutral-fg) !important;
        }

        .btn-default:hover,
        .btn-default:focus,
        .btn-default.active,
        .btn-default:active,
        .open > .dropdown-toggle.btn-default
        {
            background-color: var(--btn-neutral-active-bg) !important;
            border-color: var(--btn-neutral-border) !important;
            color: var(--btn-neutral-fg) !important;
        }

        {{-- The pressed filter needs to be distinguishable from hover, and
             from its unpressed neighbours in the same group: a filled pill
             with a hairline ring — no underline bars anywhere. --}}
        .btn-default.active,
        .btn-default:active
        {
            box-shadow: inset 0 0 0 1px var(--btn-neutral-border);
            font-weight: 600;
        }

        body
        {
            background-color: var(--color-bg);
            color: var(--color-fg);
        }



        label,
        .icon-med,
        .nav-tabs-custom > .nav-tabs > li > a,
        .nav-tabs-custom > .nav-tabs > li.active > a:link
        {
            color: var(--color-fg);
        }

        .popover.right .arrow:after
        {
            border-right-color: var(--box-bg) !important;
        }

        .popover.right .arrow {
            border-right-color: var(--box-bg) !important;
        }

        .table-bordered > tbody > tr > td,
        .table-bordered > tbody > tr > th,
        .table-bordered > tfoot > tr > td,
        .table-bordered > tfoot > tr > th,
        .table-bordered > thead > tr > td,
        .table-bordered > thead > tr > td,
        .table-bordered > thead > tr > th,
        .table-bordered > thead > tr > th,
        .table-bordered,
        .well
        {
            border: 1px solid var(--box-header-top-border-color) !important;
            border-left-color: var(--box-header-top-border-color) !important;
            border-right-color: var(--box-header-top-border-color) !important;
        }

        {{-- The box is the ecu card now: one hairline and a radius, in
             place of AdminLTE's coloured 3px top accent and drop shadow.
             No overflow clip — table action dropdowns escape the box, and
             the corners share the box background so nothing visibly pokes. --}}
        .box,
        .box.box-default,
        .nav-tabs-custom {
            border: 1px solid var(--box-header-top-border-color);
            border-radius: 14px;
            box-shadow: none;
        }



        .box-header.with-border {
            border-bottom: var(--box-header-bottom-border);
        }

        {{-- A full-bleed table inside a rounded box pokes its square
             corners past the radius; clip the body edge. --}}
        .box > .box-body.no-padding {
            overflow: hidden;
            border-radius: 0 0 14px 14px;
        }

        {{-- AdminLTE small-boxes (contracts dashboard, reports hub, fleet
             health) painted the whole tile in a status colour. They are ecu
             cards now: quiet surface, hairline edge, the colour carried by
             the headline number and the watermark icon. Accents are scoped
             to .small-box so bg-* keeps its meaning on labels and calendar
             chips elsewhere. --}}
        .small-box,
        .small-box[class*="bg-"] {
            background: var(--box-bg) !important;
            color: var(--color-fg) !important;
            border: 1px solid var(--box-header-top-border-color);
            border-radius: 14px;
            box-shadow: none;
        }
        .small-box .inner h3 {
            color: var(--sb-accent, var(--color-fg)) !important;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
        }
        .small-box .inner p { color: var(--color-fg) !important; opacity: 0.8; }
        .small-box .icon {
            color: var(--sb-accent, var(--chrome-fg-muted));
            opacity: 0.16;
            font-size: 56px;
        }
        .small-box:hover .icon { font-size: 56px; }
        .small-box > .small-box-footer,
        .small-box > .small-box-footer:hover {
            background: var(--table-stripe-bg-alt) !important;
            color: var(--chrome-fg-muted) !important;
            border-top: 1px solid var(--box-header-bottom-border-color);
            border-radius: 0 0 14px 14px;
        }
        a.small-box-link { text-decoration: none; }
        a.small-box-link .small-box { transition: transform 0.1s ease, background 0.1s ease; }
        a.small-box-link:hover .small-box { transform: translateY(-2px); background: var(--table-stripe-bg-alt) !important; }
        .small-box.bg-aqua,
        .small-box.bg-teal       { --sb-accent: light-dark(#0097bc, #45cdec); }
        .small-box.bg-blue,
        .small-box.bg-light-blue { --sb-accent: light-dark(#0073b7, #4aa3dd); }
        .small-box.bg-green,
        .small-box.bg-olive      { --sb-accent: light-dark(#00a65a, #35cc86); }
        .small-box.bg-yellow,
        .small-box.bg-orange     { --sb-accent: light-dark(#d68910, #f0b429); }
        .small-box.bg-red,
        .small-box.bg-maroon     { --sb-accent: light-dark(#c9302c, #ee7060); }
        .small-box.bg-purple     { --sb-accent: light-dark(#605ca8, #8f8ccc); }
        .small-box.bg-navy       { --sb-accent: light-dark(#33415c, #8ba3c7); }

        .box-footer
        {
            border-top: var(--box-header-bottom-border);
        }


        {{-- Detail-page tabs (#3956): the tab row is a quiet strip of
             pills inside the card — no boxed tabs, no 3px brand underline.
             The active tab is the raised pill the topbar established, so
             every tabbed view (user profile, asset view, settings) reads
             as one system. --}}
        .nav-tabs-custom > .nav-tabs {
            border-bottom: var(--tab-bottom-border);
            border-radius: 14px 14px 0 0;
            padding: 8px 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 2px;
        }

        .nav-tabs > li > a {
            margin-right: 0;
            border: 0;
        }

        .nav-tabs-custom > .nav-tabs > li {
            float: none;
            margin: 0;
        }
        .nav-tabs-custom > .nav-tabs > li > a {
            border-radius: 999px !important;
            padding: 7px 14px;
            margin: 0;
        }
        .nav-tabs-custom > .nav-tabs > li > a:hover {
            background-color: var(--chrome-hover-bg) !important;
        }
        .nav-tabs-custom > .nav-tabs > li.active {
            border-top: none;
        }
        .nav-tabs-custom > .nav-tabs > li.active > a,
        .nav-tabs-custom > .nav-tabs > li.active > a:link,
        .nav-tabs-custom > .nav-tabs > li.active > a:visited,
        .nav-tabs-custom > .nav-tabs > li.active > a:hover {
            background-color: var(--chrome-active-bg) !important;
            box-shadow: inset 0 0 0 1px var(--chrome-border-color);
            font-weight: 600;
        }
        {{-- The badge count riding a tab keeps its pill from ballooning. --}}
        .nav-tabs-custom > .nav-tabs > li > a .badge { margin-left: 6px; }

        .box,
        .box-footer,
        .tab-content,
        .nav-tabs-custom,
        .nav-tabs-custom > .nav-tabs > li,
        .nav-tabs-custom > .nav-tabs > li:first-of-type,
        .nav-tabs-custom > .nav-tabs > li.active > a:link,
        .nav-tabs-custom > .nav-tabs > li.active > a:visited,
        .nav-tabs-custom > .nav-tabs > li.active > a:hover,
        .bootstrap-table.fullscreen,
        .well
        {

            color: var(--color-fg);
            background-color: var(--box-bg) !important;
            border-left: 1px solid transparent;
            border-right: 1px solid  transparent;

        }

        .panel {
            border-color: var(--box-header-top-border-color);
        }
        .panel-body {
            background-color: var(--box-bg) !important;
        }

        .panel-heading,
        .panel-default > .panel-heading
        {
            color: var(--color-fg) !important;
            background-color: var(--table-stripe-bg-alt) !important;
            border-color: var(--box-header-top-border-color);
        }

        .panel-footer {
            background-color: var(--box-bg) !important;
            border-color: var(--box-header-top-border-color);
        }

        /**
        This fixes the weird spacing in the nav tabs if there is a badge count on the tab
         */
        .badge {
            font-size: 11px;
        }

        /**
        table rows
         */

        .table > thead > tr > th,
        .table > tbody > tr > th,
        .table > tfoot > tr > th,
        .table > thead > tr > td,
        .table > tbody > tr > td,
        .table > tfoot > tr > td
        {
            border-top: var(--table-border-row) !important;
        }


        .table-striped > tbody > tr:nth-of-type(even),
        .row-new-striped > .row:nth-of-type(even),
        .row-new-striped > .div:nth-of-type(odd),
        .cansort
        {
            background-color: var(--table-stripe-bg) !important;
            border-top: var(--table-border-row-top) !important;
            color: var(--color-fg) !important;
        }

        .table-striped > tbody > tr:nth-of-type(odd),
        .row-new-striped > .row:nth-of-type(even),
        .row-new-striped > .div:nth-of-type(odd),
        .cansort
        {
            background-color: var(--table-stripe-bg-alt) !important;
            border-top: var(--table-border-row-top) !important;
            color: var(--color-fg) !important;
        }




        /**
        main header nav
         */


        {{-- Dropdown panels float above the chrome, so they take the raised
             box surface plus a shadow rather than the chrome surface — the
             two are the same colour and a flat panel would have no edge. --}}
        .dropdown-menu {
            background-color: var(--box-bg);
            border-color: var(--chrome-border-color);
            box-shadow: var(--chrome-shadow);
        }

        .main-header .navbar {
            border-bottom: 1px solid var(--chrome-border-color);
        }

        .dropdown-menu > li {
            background-color: var(--box-bg);
            color: var(--color-fg) !important;
        }

        .navbar,
        .navbar-nav
        {
            background-color: var(--chrome-bg);
            color: var(--chrome-fg) !important;
        }

        {{-- Badges keep the brand colour: they sit on white content boxes,
             where the chrome surface would leave them invisible. --}}
        .label-default,
        .label-default:hover
        {
            background-color: var(--main-theme-color);
            color: var(--nav-primary-text-color) !important;
        }


        .dropdown-menu > li > a,
        .dropdown-menu > li > a:link,
        .dropdown-menu > li > a:visited,
        .dropdown-menu > .active > a:link,
        .dropdown-menu > .active > a:visited,
        .main-header .navbar .dropdown-menu li a
        {
            background-color: var(--box-bg) !important;
            color: var(--color-fg) !important;
        }

        .navbar-nav .open > a:link,
        .navbar-nav .open > a:visited,
        .navbar-nav > li > a:link,
        .navbar-nav > li > a:visited
        {
            background-color: var(--chrome-bg) !important;
            color: var(--chrome-fg) !important;
        }

        /* Topbar quick-nav: icon + spelled-out label instead of a bare icon. */
        .topbar-nav-label {
            margin-left: 6px;
        }
        @media (max-width: 991px) {
            /* Reclaim the room on smaller screens — fall back to icon-only. */
            .topbar-nav-label { display: none; }
        }

        {{-- Chrome hover/open states: a neutral tint of the chrome surface,
             not the brand colour. --}}
        .btn-tableButton.active.focus,
        .btn-tableButton.active:focus,
        .btn-tableButton.active:hover,
        .dropdown-menu > .active > a:focus,
        .dropdown-menu > .active > a:hover,
        .dropdown-menu > .active > a:link,
        .dropdown-menu > .active > a:visited,
        .dropdown-menu > li > a:focus,
        .dropdown-menu > li > a:hover,
        .dropdown-menu > li:focus,
        .dropdown-menu > li:hover,
        .navbar-nav .open  li.active > a:focus,
        .navbar-nav .open  li.active > a:hover,
        .navbar-nav .open > a:focus,
        .navbar-nav .open > a:hover,
        .navbar-nav > li > a:focus,
        .navbar-nav > li > a:hover,
        .open > .dropdown-toggle.btn-tableButton:focus,
        .open > .dropdown-toggle.btn-tableButton:hover
        {
            background-color: var(--chrome-hover-bg) !important;
            border-color: var(--chrome-border-color) !important;
            color: var(--color-fg) !important;
        }

        {{-- Pagination stays branded — it is a control on a content box, not
             part of the window chrome. --}}
        {{-- Pagination stays branded, but on the clamped button fill rather
             than the raw accent — same reason: --nav-primary-text-color is
             white and a pale accent leaves the page numbers unreadable. --}}
        .page-next a,
        .pagination > .active > a:hover,
        .page-item.active,
        .pagination > .active > a,
        .pagination > li > .active > a,
        .pagination > li > .active > a:hover,
        .pagination > li > a:hover
        {
            background-color: var(--btn-theme-hover) !important;
            border-color: var(--btn-theme-border) !important;
            color: var(--nav-primary-text-color) !important;
        }

        .pagination > li > a
        {
            background-color: var(--btn-theme-base) !important;
            border-color: var(--btn-theme-border) !important;
            color: var(--nav-primary-text-color) !important;
        }


        {{-- Controls are rounded app-wide: every button and input shares
             one radius instead of Bootstrap 3's squares. Input groups keep
             their seam — outer corners round, the joint stays square. --}}
        .btn {
            border-radius: 10px;
        }
        .form-control,
        .input-group-addon,
        .select2-container--default .select2-selection--single,
        .select2-container--default .select2-selection--multiple {
            border-radius: 10px;
        }
        .input-group .form-control:first-child,
        .input-group-addon:first-child {
            border-radius: 10px 0 0 10px;
        }
        .input-group .form-control:last-child,
        .input-group-addon:last-child {
            border-radius: 0 10px 10px 0;
        }
        .input-group-btn:last-child > .btn { border-radius: 0 10px 10px 0; }
        .input-group-btn:first-child > .btn { border-radius: 10px 0 0 10px; }
        .input-group .form-control:not(:first-child):not(:last-child) { border-radius: 0; }
        .dropdown-menu {
            border-radius: 12px;
            overflow: clip;
            padding: 4px;
        }
        .dropdown-menu > li > a { border-radius: 8px; }

        {{-- List-page chrome (#3955). One rule-set for every
             bootstrap-table page: rounded controls, a quiet uppercase
             header row, and pagination as pills — the parts of a list
             page the box-to-card change could not reach. --}}
        .bootstrap-table .fixed-table-toolbar .btn,
        .bootstrap-table .fixed-table-toolbar .form-control,
        .box-body > .btn,
        .box-header .btn,
        .box-footer .btn,
        .content .btn-group > .btn,
        #assetsToolBar .btn,
        .search input.form-control {
            border-radius: 10px;
        }
        {{-- No segmented capsules: every toolbar/filter button is its own
             rounded pill with a small gap, instead of a fused strip whose
             middle buttons lose their corners. Beats Bootstrap's
             .btn-group > .btn + .btn { margin-left: -1px } fuse. --}}
        .content .btn-group > .btn,
        .bootstrap-table .fixed-table-toolbar .btn-group > .btn {
            border-radius: 10px !important;
        }
        .content .btn-group > .btn + .btn,
        .content .btn-group + .btn-group,
        .bootstrap-table .fixed-table-toolbar .btn-group > .btn + .btn,
        .bootstrap-table .fixed-table-toolbar .btn-group + .btn-group {
            margin-left: 5px;
        }

        {{-- Table toolbars are quiet: the action buttons ride as neutral
             ghosts (card surface, hairline edge, muted glyph) instead of
             brand-coloured slabs. Semantic colours (danger on a pressed
             delete, warning on bulk-edit) still read because those classes
             repaint background + text below. --}}
        .bootstrap-table .fixed-table-toolbar .btn-theme,
        .bootstrap-table .fixed-table-toolbar .btn-tableButton,
        #assetsToolBar .btn-theme,
        #assetsToolBar .btn-tableButton,
        .box-header .btn-theme.actions,
        .toolbar .btn-theme {
            background-color: var(--box-bg) !important;
            background-image: none !important;
            border: 1px solid var(--box-header-top-border-color) !important;
            color: var(--chrome-fg-muted) !important;
        }
        .bootstrap-table .fixed-table-toolbar .btn-theme:hover,
        .bootstrap-table .fixed-table-toolbar .btn-theme:focus,
        .bootstrap-table .fixed-table-toolbar .btn-tableButton:hover,
        .bootstrap-table .fixed-table-toolbar .btn-tableButton:focus,
        #assetsToolBar .btn-theme:hover,
        #assetsToolBar .btn-tableButton:hover,
        .toolbar .btn-theme:hover {
            background-color: var(--chrome-hover-bg) !important;
            color: var(--chrome-fg) !important;
        }

        .bootstrap-table .table > thead > tr > th {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: var(--chrome-fg-muted);
        }
        .bootstrap-table .table > thead > tr > th .sortable { color: inherit; }

        .pagination > li:first-child > a { border-radius: 999px 0 0 999px; }
        .pagination > li:last-child > a { border-radius: 0 999px 999px 0; }

        .bootstrap-table .fixed-table-container { border: none; }

        .bootstrap-table .fixed-table-toolbar li.dropdown-item-marker label
        {
            color: var(--color-fg) !important;
        }

        .bootstrap-table .fixed-table-toolbar li.dropdown-item-marker label:hover
        {
            background-color: var(--chrome-hover-bg) !important;
            color: var(--color-fg) !important;
        }


        .dropdown-menu,
        .dropdown-menu > li
        {
            background-color: var(--box-bg);
            border-color: var(--chrome-border-color);
            color: var(--color-fg) !important;
        }

        {{-- The chrome band is taller than AdminLTE's 50px so the wordmark
             can carry real size. Everything that assumed 50px keys off the
             one variable: brand row, pill margins, sticky offsets. --}}
        :root { --header-h: 68px; }

        {{-- The header is one flex row and never wraps. The wordmark scales
             with the viewport, and below that the quick-nav items drop out
             one by one (username text first, then Contracts, Consumables,
             Procurement, Users, Assets) instead of stacking a second row. --}}
        .main-header .navbar {
            display: flex;
            align-items: center;
            flex-wrap: nowrap;
            min-width: 0;
        }
        .main-header .navbar > * { flex-shrink: 0; }
        .main-header .navbar > .navbar-left { flex-shrink: 1; min-width: 0; }
        .main-header .navbar .navbar-custom-menu { margin-left: auto; float: none; }
        .main-header .navbar .navbar-custom-menu > .navbar-nav {
            display: flex;
            align-items: center;
            flex-wrap: nowrap;
            float: none;
        }
        .main-header .navbar-form { margin: 0; padding: 0; border: 0; box-shadow: none; }
        img.navbar-brand-img { max-width: clamp(200px, 30vw, 470px); height: auto; object-fit: contain; }
        @media (max-width: 1500px) {
            .main-header .user-menu > a > .hidden-xs { display: none !important; }
        }
        @media (max-width: 1380px) { .main-header li.topnav-contracts { display: none; } }
        @media (max-width: 1270px) { .main-header li.topnav-consumables { display: none; } }
        @media (max-width: 1160px) { .main-header li.topnav-procurement { display: none; } }
        @media (max-width: 1050px) { .main-header li.topnav-users { display: none; } }
        @media (max-width: 950px)  { .main-header li.topnav-assets { display: none; } }
        .main-header .navbar,
        .main-header .navbar-static-top {
            min-height: var(--header-h);
        }
        .main-header { max-height: none; }
        .main-header .sidebar-toggle {
            height: var(--header-h);
            display: flex;
            align-items: center;
        }
        .main-header .navbar-custom-menu .navbar-nav > li > .dropdown-menu { margin-top: 0; }

        {{-- Sticky chrome: the header rides along on scroll and the sidebar
             is pinned below it, so a long table never takes the navigation
             away. Desktop only — small screens keep the stacked flow.

             body and .wrapper ship from AdminLTE as overflow:auto scroll
             containers, which silently retargets every position:sticky
             descendant at them instead of the viewport — the header looked
             sticky and then rode away with the wrapper. clip-x/visible-y
             keeps stray horizontal overflow contained WITHOUT creating a
             scrollport, so sticky means the viewport again. --}}
        body, .wrapper {
            overflow: clip visible !important;
        }
        @media (min-width: 768px) {
            .main-header {
                position: sticky;
                top: 0;
                z-index: 1030;
            }
            .main-sidebar {
                position: fixed;
                top: var(--header-h);
                bottom: 0;
                min-height: 0;
                height: auto;
                padding-top: 10px;
                overflow-y: auto;
                z-index: 900;
            }
            {{-- Collapsed to the icon rail, the flyout labels render as
                 absolutely-positioned panels hanging outside the 50px rail;
                 any overflow clipping cuts them off (and their clipped left
                 rail was the stray floating line beside the sidebar). --}}
            .sidebar-collapse .main-sidebar { overflow: visible; }
            {{-- The table toolbar (search + actions) sticks just under the
                 header on list pages, so the controls stay reachable at
                 row 400. --}}
            .bootstrap-table .fixed-table-toolbar {
                position: sticky;
                top: var(--header-h);
                z-index: 90;
                background: var(--box-bg);
            }
            .bootstrap-table .fixed-table-toolbar {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 6px;
                padding: 4px 0 8px;
            }
            .bootstrap-table .fixed-table-toolbar::before,
            .bootstrap-table .fixed-table-toolbar::after { display: none; }
            .bootstrap-table .fixed-table-toolbar .bs-bars {
                flex: 1 1 auto;
                min-width: 0;
                float: none !important;
            }
            .bootstrap-table .fixed-table-toolbar .search,
            .bootstrap-table .fixed-table-toolbar .columns {
                float: none !important;
                margin: 0;
            }
        }

        {{-- Sidebar at 150px instead of AdminLTE's 230px — the labels fit
             at 13px and the content gets the room back. --}}
        @media (min-width: 768px) {
            .main-sidebar { width: 150px; }
            .content-wrapper, .main-footer { margin-left: 150px; }
            .sidebar-collapse .content-wrapper, .sidebar-collapse .main-footer { margin-left: 0; }
            .main-header .navbar { margin-left: 150px !important; }
            .sidebar-mini.sidebar-collapse .main-header .navbar { margin-left: 50px !important; }
        }
        {{-- The brand must never inherit AdminLTE's 230x50 clipped logo
             box — the wordmark is wider than that at full size. --}}
        a.logo.navbar-brand,
        .main-header .logo {
            width: auto !important;
            max-width: none;
            height: var(--header-h);
            line-height: var(--header-h);
            overflow: visible;
        }
        .main-header .left-navblock { max-width: none !important; width: auto !important; min-width: 0 !important; }
        @media (max-width: 767px) {
            .main-sidebar { padding-top: var(--header-h); }
        }
        .sidebar-menu > li > a { font-size: 13px; padding: 9px 5px 9px 12px; }
        .sidebar-menu .treeview-menu > li > a { font-size: 13px; }
        .sidebar-menu .treeview-menu { padding-left: 6px; }

        {{-- Sidebar polish: no brand rails, no coloured status icons — the
             nav is quiet chrome, colour belongs to content. --}}
        .sidebar-menu > li > a,
        .sidebar-menu > li.active > a,
        .sidebar-menu .treeview-menu > li > a,
        .sidebar-menu .treeview-menu > li.active > a {
            border-left: 0 !important;
        }
        #users-sidenav-option .text-danger,
        #users-sidenav-option .text-warning,
        #users-sidenav-option .text-success,
        #users-sidenav-option .text-info,
        #users-sidenav-option .text-grey {
            color: var(--sidenav-text-nohover-color) !important;
        }

        {{-- Six equal tiles on one row (reports hub, contracts dashboard,
             transactions): flex instead of Bootstrap 3's 12-col grid, with
             every card stretched to the tallest. Below 992px they fall back
             to the col-sm-6 two-up layout. --}}
        @media (min-width: 992px) {
            .tile-row-6 { display: flex; flex-wrap: nowrap; }
            .tile-row-6 > div[class*="col-"] {
                {{-- flex-basis 0 splits the row equally among however many
                     tiles the viewer's permissions produce. --}}
                flex: 1 1 0;
                max-width: none;
                display: flex;
                flex-direction: column;
            }
            .tile-row-6 > div[class*="col-"] > .small-box,
            .tile-row-6 a.small-box-link {
                flex: 1;
                display: flex;
                flex-direction: column;
            }
            .tile-row-6 a.small-box-link > .small-box {
                flex: 1;
                margin-bottom: 15px;
            }
        }

        {{-- Topbar items are pills: a rounded hover surface floating in the
             band, instead of full-height blocks with a brand rail underlining
             the active one. The margin+padding sum keeps the header height. --}}
        .main-header .navbar-nav > li > a {
            border-radius: 999px;
            margin: calc((var(--header-h) - 34px) / 2) 3px;
            padding: 7px 13px !important;
            line-height: 20px;
        }
        .main-header .navbar .nav>.active>a {
            background-color: var(--chrome-active-bg) !important;
            box-shadow: inset 0 0 0 1px var(--chrome-border-color);
            color: var(--chrome-fg) !important;
            font-weight: 600;
        }

        {{-- Topbar lookup: a single rounded field with the magnifier as a
             quiet icon button inside it. --}}
        .topbar-search {
            position: relative;
            display: flex;
            align-items: center;
        }
        .topbar-search input.form-control {
            border-radius: 999px;
            border: 1px solid var(--chrome-border-color);
            background: var(--chrome-active-bg);
            padding-right: 34px;
            width: 220px;
            box-shadow: none;
            transition: border-color 0.1s ease;
        }
        .topbar-search input.form-control:focus {
            border-color: var(--btn-neutral-border);
            box-shadow: none;
        }
        .topbar-search-btn {
            position: absolute;
            right: 4px;
            border: 0;
            background: transparent;
            color: var(--chrome-fg-muted);
            padding: 6px 8px;
            border-radius: 999px;
            line-height: 1;
        }
        .topbar-search-btn:hover { color: var(--chrome-fg); background: var(--chrome-hover-bg); }

        .navbar-nav > .notifications-menu > .dropdown-menu > li.header,
        .navbar-nav > .messages-menu > .dropdown-menu > li.header,
        .navbar-nav > .tasks-menu > .dropdown-menu > li.header,
        .navbar-nav > .notifications-menu > .dropdown-menu > li .menu,
        .navbar-nav > .messages-menu > .dropdown-menu > li .menu, .navbar-nav > .tasks-menu > .dropdown-menu > li .menu,
        .navbar-nav > .messages-menu > .dropdown-menu > li .menu, .navbar-nav > .tasks-menu > .dropdown-menu > li .menu a:hover,
        .navbar-nav > .messages-menu > .dropdown-menu > li .menu, .navbar-nav > .tasks-menu > .dropdown-menu > li:hover,
        .navbar-nav > .tasks-menu > .dropdown-menu > li .menu > li:hover > a,
        .task_menu
        {
            background-color: var(--box-bg) !important;
            color: var(--color-fg) !important;
            margin-bottom: 0;
        }

        .navbar-nav > .notifications-menu > .dropdown-menu > li .menu > li > a, .navbar-nav > .messages-menu > .dropdown-menu > li .menu > li > a, .navbar-nav > .tasks-menu > .dropdown-menu > li .menu > li > a {
            border-bottom: 1px solid var(--chrome-border-color);
        }


        /**
        Active and hover for top tier sidenav items
         */

        /**
        The sidebar and the topbar share the *body* surface — neither is
        painted with --main-theme-color. The window reads as one continuous
        field with the content boxes raised on it, rather than a coloured
        slab wrapped around a grey page. The brand colour survives as the
        active rail, so the current section is still obvious.
         */

        .main-sidebar {
            background-color: var(--chrome-bg);
            border-right: 1px solid var(--chrome-border-color);
        }


        .sidebar-menu>li.active > a,
        .sidebar-menu>li:hover>a,
        .treeview-menu>li> a
        {
            color: var(--sidenav-text-hover-color) !important;
            border-left-color: var(--main-theme-color);
        }

        .sidebar-menu > li:hover > a,
        .sidebar-menu > li.active > a
        {
            border-left-color: var(--main-theme-color);
            padding-left: 12px;
        }


        .sidebar-menu > li:hover {
            background-color: var(--chrome-hover-bg);
        }

        .sidebar-menu>li>.treeview-menu
        {
            background-color: var(--chrome-bg);
        }

        {{-- Collapsed rail: the hover flyout leaves the sidebar and floats over
             the content, so it needs the raised surface and an edge. --}}
        .sidebar-mini.sidebar-collapse .sidebar-menu > li:hover > a > span,
        .sidebar-mini.sidebar-collapse .sidebar-menu > li:hover > .treeview-menu
        {
            background-color: var(--chrome-active-bg) !important;
            border: 1px solid var(--chrome-border-color);
            box-shadow: var(--chrome-shadow);
            color: var(--chrome-fg) !important;
        }


        .list-group-item:first-child {
            border-top: 0 !important;
        }

        .sidebar-menu > li > a:link,
        .sidebar-menu > li > a:visited,
        .treeview-menu>li> a
        {
            color: var(--sidenav-text-nohover-color) !important;
        }

        .sidebar-menu > li.active > a,
        .sidebar-menu > li:hover > a
        {
            background-color: var(--chrome-hover-bg);
            border-left-color: var(--main-theme-color);
            border-left-style: solid;
            border-left-width: 3px;
            color: var(--sidenav-text-hover-color) !important;
        }

        {{-- The current section reads as a raised card in the rail: the box
             surface the content uses, with the brand rail down its edge. --}}
        .sidebar-menu > li.active > a {
            background-color: var(--chrome-active-bg);
            font-weight: 600;
        }

        thead,
        tbody,
        .table > thead > tr > th,
        .table > tbody > tr > th,
        .table > tfoot > tr > th,
        .table > thead > tr > td,
        .table > tbody > tr > td,
        .table > tfoot > tr > td

        {
            border-top-color: var(--box-bg) !important;
            border-bottom-color: var(--box-header-bottom-border-color) !important;
            color: var(--color-fg);
        }


        .help-block {
            color: var(--text-help) !important;
        }

        .alert-msg,
        .has-error
        {
            color: var(--text-danger) !important;
        }

        .has-error .form-control {
            border-color: var(--text-danger);
        }

        .alert a {
            color: white !important;
        }


        .text-dark-gray a:link,
        .text-dark-gray a:hover,
        .text-dark-gray a:visited,
        .text-dark-gray a:focus
        {
            color: hsl(from var(--main-theme-color) h s calc(l - 5));
        }

        .text-warning {
            color: var(--text-warning) !important;
        }

        .text-info {
            color: var(--text-info) !important;
        }

        .text-primary {
            color: var(--main-theme-color) !important;
        }

        .text-danger {
            color: var(--text-danger) !important;
        }

        .text-success {
            color: var(--text-success) !important;
        }

        .dropdown-menu > .divider {
            background-color: var(--chrome-border-color);
            margin-top: 0;
            margin-bottom: 0;
            padding-top: 1px;

        }

        input[type="radio"]::before {
            box-shadow: inset 1em 1em hsl(from var(--main-theme-color) h s calc(l - 20)) !important;
        }


        input[type="checkbox"]::before {
            box-shadow: inset 1em 1em hsl(from var(--main-theme-color) h s calc(l - 20)) !important;
        }




        .callout.callout-legend {
            background-color: var(--callout-bg-color);
            border-left: 5px solid var(--callout-left-border);

        }

        .callout-legend h4 a,
        .callout-legend h4 a:hover
        {
            color: var(--color-fg) !important;
        }



        p.callout-subtext, p.callout-subtext a:hover, p.callout-subtext a:visited, p.callout-subtext a:link {
            color: var(--text-legend-help) !important;
            text-decoration: none;
        }


        legend {
            border-bottom: 1px solid var(--callout-left-border);
        }

        th,
        .fix-sticky table thead {
            background-color: var(--box-bg);
            color: var(--color-fg) !important;
        }

        .datepicker.dropdown-menu th, .datepicker.datepicker-inline th,
        .datepicker.dropdown-menu td,
        .datepicker.datepicker-inline td

        {
            color: var(--color-fg);
            border-color: var(--color-fg);
            background-color: var(--box-bg) !important;
        }

        .datepicker.dropdown-menu th:hover,
        .datepicker.datepicker-inline th:hover,
        .datepicker.dropdown-menu td:hover,
        .datepicker.datepicker-inline td:hover,
        .datepicker table tr td span:hover,
        .datepicker table tr td span.focused
        {
            background-color: var(--main-theme-color) !important;
            color: var(--nav-primary-text-color) !important;
        }

        {{--
            Brand block. overrides.less pins the wordmark and the surrounding
            .left-navblock to white, which only worked against the old coloured
            header. On the neutral chrome it has to follow --chrome-fg.

            The wordmark ships as two real assets — ecu-wordmark-light.png
            (near-black artwork for the light chrome) and ecu-wordmark-dark.png
            (white artwork for the dark chrome) — swapped by theme below. No
            CSS filter tricks: filters on the uploaded logo depended on
            light-dark(), which is a colour function and is invalid inside
            filter:, so the fallback silently dropped and the logo vanished
            into whichever chrome it wasn't made for.
        --}}
        .left-navblock,
        .main-header .logo,
        .main-header .logo a:link,
        .main-header .logo a:hover,
        .main-header .logo a:visited,
        a.logo.navbar-brand,
        a.logo.navbar-brand:link,
        a.logo.navbar-brand:visited
        {
            color: var(--chrome-fg) !important;
        }

        .logo:hover,
        a.logo.navbar-brand:hover,
        a.logo.navbar-brand:focus
        {
            background-color: transparent !important;
            color: var(--chrome-fg) !important;
        }

        {{-- The brand is a vertically centred row, not an image with its own
             ad-hoc top padding (app.less gives it 20px up top and 10px below,
             which reads as the logo stuck to the ceiling). --}}
        a.logo.navbar-brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            height: var(--header-h);
            padding-top: 0;
            padding-bottom: 0;
        }
        img.navbar-brand-img {
            padding: 0;
            max-height: 56px;
            width: auto;
        }

        {{-- Theme swap for the two wordmark files. The default (no
             data-theme stamped yet) follows the OS via the media query so
             the right file shows from the first paint; the stamped attribute
             then wins in both directions. --}}
        img.brand-wordmark-dark { display: none; }
        [data-theme="dark"] img.brand-wordmark-light { display: none; }
        [data-theme="dark"] img.brand-wordmark-dark { display: inline-block; }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) img.brand-wordmark-light { display: none; }
            :root:not([data-theme="light"]) img.brand-wordmark-dark { display: inline-block; }
        }

        .datepicker.dropdown-menu,
        .modal-content,
        .popover.help-popover,
        .popover.help-popover .popover-content,
        .popover.help-popover .popover-body,
        .popover.help-popover .popover-title,
        .popover.help-popover .popover-header
        {
            background-color: var(--box-bg) !important;
            color: var(--color-fg) !important;
        }

        {{-- Modals are cards too (#3957): same radius and hairline as
             every other raised surface, headers and footers separated by
             the same quiet border, no Bootstrap 3 hard shadow box. --}}
        .modal-content {
            border: 1px solid var(--box-header-top-border-color);
            border-radius: 14px;
            box-shadow: var(--chrome-shadow);
        }
        .modal-header {
            border-bottom: var(--box-header-bottom-border);
        }
        .modal-footer {
            border-top: var(--box-header-bottom-border);
        }
        .datepicker.dropdown-menu,
        .popover.help-popover {
            border: 1px solid var(--box-header-top-border-color);
            border-radius: 12px;
        }
        .well {
            border-radius: 12px;
            box-shadow: none;
        }
        .callout {
            border-radius: 12px;
        }
        .alert {
            border-radius: 12px;
        }
        {{-- Informational flashes are a note, not a siren: quiet card
             surface with the message in body text, instead of the solid
             cyan slab. Success/warning/danger keep their colour — those
             report outcomes. --}}
        .alert.alert-info {
            background: var(--table-stripe-bg-alt) !important;
            border: 1px solid var(--box-header-top-border-color) !important;
            color: var(--color-fg) !important;
        }
        .alert.alert-info a,
        .alert.alert-info .alert-link { color: var(--link-color); }
        .alert.alert-info .close { color: var(--chrome-fg-muted); opacity: .7; text-shadow: none; }
        .nav-pills > li > a {
            border-radius: 999px;
        }
        .label {
            border-radius: 6px;
        }
        .progress {
            border-radius: 999px;
        }

        /** this handles the arrows for the datepicker widget **/

        /** arrow on the bottom - bg color **/
        .datepicker-dropdown.datepicker-orient-top:after {
            border-top: 6px solid var(--box-bg);
        }

        /** arrow on the bottom - border color **/
        .datepicker-dropdown.datepicker-orient-top:before {
            border-top: 6px solid var(--color-bg);
        }

        /** arrow on the top - bg color **/
        .datepicker-dropdown:after {
            border-bottom: 6px solid var(--box-bg);
        }

        /** arrow on the top - border color **/
        .datepicker-dropdown:before {
            border-bottom: 7px solid var(--color-bg);
        }

        /** end handling arrows for the datepicker widget **/


        .treeview-menu > li {
            background-color: var(--chrome-bg);
            color: var(--sidenav-text-nohover-color) !important;
        }

        .treeview-menu > li >a:hover,
        .treeview-menu > li:hover,
        .treeview-menu > li.active > a
        {
            color: var(--chrome-fg) !important;
            background-color: var(--sidenav-hover-color-bg) !important;
        }

        {{-- Without a coloured slab behind it, a sub-item's active state is
             otherwise indistinguishable from its hover state. --}}
        .treeview-menu > li.active > a {
            box-shadow: inset 3px 0 0 var(--main-theme-color);
            font-weight: 600;
        }

        .sidebar-toggle.btn,
        .sidebar-toggle.btn:hover,
        .sidebar-toggle-mobile
        {
            color: var(--chrome-fg) !important;
        }

        .chart-responsive {
            color: var(--color-fg) !important;
        }

        .table > tbody + tbody {
            border-top: 0px !important;
        }

        h4#progress-text {
            color: white !important;
        }

        .small-box h3, .small-box p {
            color: white !important;
        }

        .box.box-theme {
            border-top:  var(--main-theme-color) !important;
        }

        input[type="date"]:focus,
        input[type="number"]:focus,
        input[type="text"]:focus,
        input[type="url"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus,
        input[type="tel"]:focus,
        input[type="search"]:focus,
        textarea:focus
        {
            border-color: hsl(from var(--main-theme-color) h s calc(l - 5)) !important;
        }

        input[type="date"]:required,
        input[type="number"]:required,
        input[type="text"]:required,
        input[type="url"]:required,
        input[type="email"]:required,
        input[type="password"]:required,
        input[type="tel"]:required,
        select:required,
        input:required,
        textarea:required
        {
            border-right: 5px solid orange !important;
        }

        .bootstrap-table .fixed-table-container .table tbody tr.selected td {
            background-color: light-dark(hsl(from var(--main-theme-color) h s calc(l + 40)),hsl(from var(--main-theme-color) h s calc(l - 40))) !important;
        }

        tr.success > td {
            background-color: #00a65a !important;
            color: white !important;
        }

        tr.danger > td {
            background-color: var(--text-danger) !important;
            color: white !important;
        }

        @media print {

            body,
            div.content-wrapper,
            section.content,
            .webui,
            .main-panel,
            .nav-tabs-custom,
            .box,
            .box-body,
            .list-group,
            .list-group-unbordered,
            .list-group-item,
            .row,
            .tab-content
            {
                background: white !important;
                color: black !important;
            }
            .fixed-table-toolbar,
            .fixed-table-pagination,
            #assetsToolBar,
            .fixed-table-pagination
            {
                display: none !important;
            }
            .tab-pane.hidden-print {
                display: none !important;
                visibility: hidden !important;
            }

            h2, h3, h4 {
                color: black !important;
            }

            .col-sm-9,
            .main-panel
            {
                float: left;
                width: 100% !important;
            }

        }

        .list-group-item.subitem {
            padding-left: 20px !important;
            border-left: 0 !important;
            border-right: 0 !important;
        }

        .list-group-item.subitem:first-child {
            border: var(--tab-bottom-border);
        }

        .list-group-item.subitem:last-child {
            border: 0 !important;
        }

        .main-panel-content {
            line-height: 20px;
            border-bottom: var(--tab-bottom-border);
            padding: 10px 15px;
        }


        /* table */

        dl.table-display {
            float: left;
            width: 100%;
            margin: 1em 0;
            padding: 0;
        }

        .table-display dt {
            line-height: 25px;
            clear: left;
            float: left;
            /*text-align: right;*/
            width: 20%;
            margin: 0;
            padding: 8px;
            border-top: var(--tab-bottom-border);
            font-weight: bold;
        }

        .table-display dd {
            line-height: 20px;
            float: left;
            width: 80%;
            margin: 0;
            padding: 10px;
            border-top: var(--tab-bottom-border);
        }

        .well-display dt {
            clear: left;
            float: left;
            width: 70%;
            margin: 0;
            padding: 6px;
            border-top: 0;
            font-weight: bold;
        }

        .well-display dd {
            float: left;
            width: 30%;
            margin: 0;
            padding: 6px;
            border-top: 0;
        }

        .well-sm {
            line-height: 30px;
        }

        .table-display dd:first-of-type, .table-display dt:first-of-type {
            border-top: 0 !important;
        }


        @media (max-width: 750px) {
            .table-display dd {
                width: 100% !important;
            }

            .table-display dt {
                width: 100% !important;
            }
        }

        @media print {
            /* All your print styles go here */
            .box-profile {
                display: block !important;
                width: 100% !important;
            }
        }


    </style>

    {{-- Custom CSS --}}
    @if (($snipeSettings) && ($snipeSettings->custom_css))
        <style>
            {!! $snipeSettings->show_custom_css() !!}
        </style>
    @endif


    <script nonce="{{ csrf_token() }}">
        window.snipeit = {
            settings: {
                "per_page": {{ $snipeSettings->per_page }}
            }
        };
    </script>

    <!-- HTML5 Shim and Respond.js IE8 support of HTML5 elements and media queries -->
    <script src="{{ url(asset('js/html5shiv.js')) }}" nonce="{{ csrf_token() }}"></script>
    <script src="{{ url(asset('js/respond.js')) }}" nonce="{{ csrf_token() }}"></script>


</head>

    <body class="sidebar-mini{{ (session('menu_state')!='open') ? ' sidebar-mini sidebar-collapse' : ''  }}">

        <a class="skip-main" href="#main">{{ trans('general.skip_to_main_content') }}</a>
        <div class="wrapper">

            <header class="main-header">

                <!-- Logo -->

                <!-- Header Navbar: style can be found in header.less -->
                @php
                    // An end user gets the top bar as their whole navigation:
                    // the sidebar is an empty frame for someone who can open
                    // none of it, so it is not rendered for them at all.
                    $isEndUser = auth()->check() && auth()->user()->isEndUser();
                @endphp
                <nav class="navbar navbar-static-top" role="navigation">
                    @unless ($isEndUser)
                    <!-- Sidebar toggle button above the compact sidenav -->
                    <a href="#" class="sidebar-toggle btn btn-white" data-toggle="push-menu"
                       role="button">
                        <span class="sr-only">{{ trans('general.toggle_navigation') }}</span>
                    </a>
                    @endunless
                    <div class="nav navbar-nav navbar-left">
                        <div class="left-navblock">
                            {{-- The brand ships in-repo as a light/dark asset
                                 pair rather than the settings-uploaded file:
                                 one white and one near-black wordmark, with
                                 CSS picking the right file per theme. --}}
                            <a class="logo navbar-brand no-hover" href="{{ config('app.url') }}">
                                <img class="navbar-brand-img brand-wordmark-light"
                                     src="{{ url('img/branding/ecu-wordmark-light.png') }}"
                                     alt="{{ $snipeSettings->site_name }} logo">
                                <img class="navbar-brand-img brand-wordmark-dark"
                                     src="{{ url('img/branding/ecu-wordmark-dark.png') }}"
                                     alt="">
                                <span class="sr-only">{{ $snipeSettings->site_name }}</span>
                            </a>
                        </div>
                    </div>

                    @if ($isEndUser)
                        {{-- The whole product, for an end user: Assets,
                             Forms, then Store and Orders. The last two are
                             the second step of the faculty program, so a
                             faculty member sees them only once their form
                             for the renewal year is in; staff with no
                             forms see no Forms tab at all. --}}
                        @php
                            $euCanUseStore = auth()->user()->canUseStore();
                            $euHasOrders = $euCanUseStore
                                && \App\Models\StoreOrder::where('user_id', auth()->id())->exists();
                        @endphp
                        <ul class="nav navbar-nav eu-nav">
                            <li {!! request()->is('my') ? 'class="active"' : '' !!}>
                                <a href="{{ route('my') }}"><i class="fa-solid fa-laptop fa-fw" aria-hidden="true"></i> {{ trans('general.assets') }}</a>
                            </li>
                            @if (! empty($formsAccessible))
                                <li {!! request()->is('procurement/forms*') ? 'class="active"' : '' !!}>
                                    <a href="{{ route('forms.index') }}"><i class="fas fa-file-signature fa-fw" aria-hidden="true"></i> {{ trans('admin/forms/general.menu_link') }}</a>
                                </li>
                            @endif
                            @if ($euCanUseStore)
                                <li {!! request()->is('store') ? 'class="active"' : '' !!}>
                                    <a href="{{ route('store.index') }}"><i class="fa-solid fa-store fa-fw" aria-hidden="true"></i> {{ trans('admin/store/general.store') }}</a>
                                </li>
                            @endif
                            {{-- Orders only earns a tab once there is an order
                                 to look at. --}}
                            @if ($euHasOrders)
                                <li {!! request()->is('store/orders*') ? 'class="active"' : '' !!}>
                                    <a href="{{ route('store.orders') }}"><i class="fa-solid fa-truck-fast fa-fw" aria-hidden="true"></i> {{ trans('admin/store/general.nav_orders') }}</a>
                                </li>
                            @endif
                        </ul>
                        <style>
                            /* No sidebar exists for an end user; the content
                               takes the full width on every breakpoint, and
                               the whole header sits quiet on the body
                               background — brand colour is an accent here,
                               not a paint bucket. */
                            .content-wrapper, .main-footer { margin-left: 0 !important; }

                            /* One gutter for the whole product. The header row
                               and the page below it are laid out from the same
                               token, so the wordmark and the first card sit on
                               one line instead of two arbitrary ones — the
                               header still carried the sidebar's margin, which
                               pushed the brand a sidebar-width inboard of
                               content that had already been pulled flush. */
                            :root { --eu-gutter: 28px; }
                            .main-header .navbar { margin-left: 0 !important; padding-left: var(--eu-gutter); padding-right: 12px; }
                            /* On wide screens the header contents track the
                               centred 1200px content column instead of
                               hugging the far left edge. The header band
                               itself still paints edge to edge. The body
                               carries sidebar-mini/sidebar-collapse even for
                               end users, and the admin chrome pins the navbar
                               margin under those classes at higher
                               specificity — so this override matches them. */
                            .main-header .navbar,
                            .sidebar-mini .main-header .navbar,
                            .sidebar-mini.sidebar-collapse .main-header .navbar {
                                max-width: calc(1200px + 2 * var(--eu-gutter));
                                margin-left: auto !important;
                                margin-right: auto !important;
                            }
                            .main-header .navbar-brand { padding-left: 0 !important; }
                            /* !important only to beat the base template's
                               inline padding-top:0 on .content, which exists
                               to sit flush under an admin page header this
                               layout does not render. */
                            .content-wrapper > .content { padding: 18px var(--eu-gutter) 32px !important; }
                            {{-- End users never see breadcrumbs: the topbar
                                 already says where they are, and Home > Forms
                                 > ... is admin furniture. Every eu page also
                                 tracks the same centred 1200px column the
                                 header uses, so forms and the store line up
                                 with /my instead of hugging the left edge. --}}
                            .content-wrapper > .content-header { display: none; }
                            .content-wrapper > .content {
                                max-width: calc(1200px + 2 * var(--eu-gutter));
                                margin-left: auto;
                                margin-right: auto;
                            }
                            @media (max-width: 767px) { :root { --eu-gutter: 16px; } }

                            .eu-nav > li > a { font-weight: 600; }
                            {{-- One surface for the whole header band. The
                                 navbar is a centred 1200px strip inside the
                                 full-bleed .main-header; painting only the
                                 strip left two-tone banding in dark mode. --}}
                            .main-header, .main-header .navbar, .main-header .logo, .main-header .left-navblock {
                                background: light-dark(#f4f4f4, #1a1a1a) !important;
                                color: light-dark(#262626, #e4e4e8) !important;
                            }
                            .main-header { border-bottom: 1px solid light-dark(#e4e4e4, #3a3a3a); }
                            /* The navbar carried its own hairline right above
                               the header's — a double line. One edge, on the
                               header. */
                            .main-header .navbar { border-bottom: 0 !important; }
                            .main-footer { padding: 12px var(--eu-gutter); }
                            /* Something in the shell overflows by a few px and
                               draws a horizontal scrollbar across the footer;
                               nothing in this layout scrolls sideways on
                               purpose, so clip instead of letting it. */
                            body { overflow-x: clip; }
                            .main-header .navbar-nav > li > a,
                            .main-header .navbar-custom-menu .dropdown-toggle {
                                color: light-dark(#262626, #e4e4e8) !important;
                            }
                            .main-header .left-navblock { width: auto !important; min-width: 0 !important; }
                            /* The avatar block reads as a leftover without the
                               admin chrome around it; the name is the identity. */
                            .main-header .user-menu > a > img.user-image,
                            .main-header .user-menu > a > svg,
                            .main-header .user-menu > a > i { display: none !important; }
                            .main-header .user-menu > a > .hidden-xs { display: inline !important; }
                        </style>
                    @endif

                    <!-- Navbar Right Menu -->
                    <div class="navbar-custom-menu">
                        <ul class="nav navbar-nav">
                            <li aria-hidden="true">

                                    @unless ($isEndUser)
                                    <a href="#" class="sidebar-toggle-mobile visible-xs hidden-lg hidden-md" data-toggle="push-menu"
                                   role="button">
                                    <span class="sr-only">{{ trans('general.toggle_navigation') }}</span>
                                    <x-icon type="nav-toggle" />
                                </a>
                                    @endunless

                            </li>

                            @can('index', \App\Models\Asset::class)
                                <li aria-hidden="true" class="topnav-assets{!! (request()->is('hardware*') ? ' active' : '') !!}">
                                    <a href="{{ url('hardware') }}" {{$snipeSettings->shortcuts_enabled == 1 ? "accesskey=1" : ''}} tabindex="-1" data-tooltip="true" data-placement="bottom" data-title="{{ trans('general.assets') }}">
                                        <x-icon type="assets" class="fa-fw" />
                                        <span class="topbar-nav-label">{{ trans('general.assets') }}</span>
                                    </a>
                                </li>
                            @endcan
                            {{-- Procurement earns a top-level slot: the hub is
                                 a daily destination, not something to go
                                 hunting for in a sidebar treeview. --}}
                            @can('view', \App\Models\Order::class)
                                <li aria-hidden="true" class="topnav-procurement{!! (request()->is(App\Helpers\Helper::ProcurementUrls()) ? ' active' : '') !!}">
                                    <a href="{{ route('procurement.index') }}" tabindex="-1" data-tooltip="true" data-placement="bottom" data-title="{{ trans('general.procurement') }}">
                                        <x-icon type="procurement" class="fa-fw" />
                                        <span class="topbar-nav-label">{{ trans('general.procurement') }}</span>
                                    </a>
                                </li>
                            @endcan
                            @can('view', \App\Models\Contract::class)
                                <li aria-hidden="true" class="topnav-contracts{!! ((request()->is('contracts*') || request()->is('licenses*') || request()->is('admin/license-models*')) ? ' active' : '') !!}">
                                    <a href="{{ route('contracts.index') }}" {{$snipeSettings->shortcuts_enabled == 1 ? "accesskey=2" : ''}} tabindex="-1" data-tooltip="true" data-placement="bottom" data-title="{{ trans('admin/contracts/general.contracts') }}">
                                        <x-icon type="contracts" class="fa-fw" />
                                        <span class="topbar-nav-label">{{ trans('admin/contracts/general.contracts') }}</span>
                                    </a>
                                </li>
                            @endcan
                            @can('index', \App\Models\Consumable::class)
                                <li aria-hidden="true" class="topnav-consumables{!! (request()->is('consumables*') ? ' active' : '') !!}">
                                    <a href="{{ url('consumables') }}" {{$snipeSettings->shortcuts_enabled == 1 ? "accesskey=3" : ''}} tabindex="-1" data-tooltip="true" data-placement="bottom" data-title="{{ trans('general.consumables') }}">
                                        <x-icon type="consumables" class="fa-fw" />
                                        <span class="topbar-nav-label">{{ trans('general.consumables') }}</span>
                                    </a>
                                </li>
                            @endcan

                            @can('index', \App\Models\User::class)
                                <li aria-hidden="true" class="topnav-users{!! (request()->is('users*') ? ' active' : '') !!}">
                                    <a href="{{ route('users.index') }}" {{$snipeSettings->shortcuts_enabled == 1 ? "accesskey=4" : ''}} tabindex="-1" data-tooltip="true" data-placement="bottom" data-title="{{ trans('general.users') }}">
                                        <x-icon type="users" class="fa-fw" />
                                        <span class="topbar-nav-label">{{ trans('general.users') }}</span>
                                    </a>
                                </li>
                            @endcan

                            @can('index', \App\Models\Asset::class)
                                <li>
                                    <form class="navbar-form navbar-left form-inline" role="search" action="{{ route('findbytag/hardware') }}" method="get">

                                                {{-- One rounded field with the magnifier riding inside it,
                                                     instead of an input with a coloured button block welded on. --}}
                                                <div class="topbar-search">
                                                    <label class="sr-only" for="tagSearch">
                                                        {{ trans('general.lookup_anything') }}
                                                    </label>
                                                    <input type="text" class="form-control" id="tagSearch" name="assetTag" placeholder="{{ trans('general.lookup_anything') }}">
                                                    <button type="submit" id="topSearchButton" class="topbar-search-btn"><x-icon type="search" class="fa-fw" /><div class="sr-only">{{ trans('general.search') }}</div></button>
                                                </div>

                                        <input type="hidden" name="topsearch" value="true" id="search">

                                    </form>
                                </li>
                            @endcan

                            @can('admin')
                                <li class="dropdown user-menu" aria-hidden="true">
                                    <a href="#" class="dropdown-toggle" data-toggle="dropdown" tabindex="-1">
                                        {{ trans('general.create') }}
                                        <strong class="caret"></strong>
                                    </a>
                                    <ul class="dropdown-menu">
                                        @can('create', \App\Models\Asset::class)
                                            <li{!! (request()->is('hardware/create') ? ' class="active"' : '') !!}>
                                                <a href="{{ route('hardware.create') }}" tabindex="-1">
                                                    <x-icon type="assets" class="fa-fw" />
                                                    {{ trans('general.asset') }}
                                                </a>
                                            </li>
                                        @endcan
                                        @can('create', \App\Models\License::class)
                                            <li{!! (request()->is('licenses/create') ? ' class="active"' : '') !!}>
                                                <a href="{{ route('licenses.create') }}" tabindex="-1">
                                                    <x-icon type="licenses" class="fa-fw" />
                                                    {{ trans('general.license') }}
                                                </a>
                                            </li>
                                        @endcan
                                        @can('create', \App\Models\Accessory::class)
                                            <li {!! (request()->is('accessories/create') ? 'class="active"' : '') !!}>
                                                <a href="{{ route('accessories.create') }}" tabindex="-1">
                                                    <x-icon type="accessories" class="fa-fw" />
                                                    {{ trans('general.accessory') }}
                                                </a>
                                            </li>
                                        @endcan
                                        @can('create', \App\Models\Consumable::class)
                                            <li {!! (request()->is('consunmables/create') ? 'class="active"' : '') !!}>
                                                <a href="{{ route('consumables.create') }}" tabindex="-1">
                                                    <x-icon type="consumables" class="fa-fw" />
                                                    {{ trans('general.consumable') }}
                                                </a>
                                            </li>
                                        @endcan
                                        @can('create', \App\Models\Component::class)
                                            <li {!! (request()->is('components/create') ? 'class="active"' : '') !!}>
                                                <a href="{{ route('components.create') }}" tabindex="-1">
                                                    <x-icon type="components" class="fa-fw" />
                                                    {{ trans('general.component') }}
                                                </a>
                                            </li>
                                        @endcan
                                        @can('create', \App\Models\User::class)
                                            <li {!! (request()->is('users/create') ? 'class="active"' : '') !!}>
                                                <a href="{{ route('users.create') }}" tabindex="-1">
                                                    <x-icon type="users" class="fa-fw" />
                                                    {{ trans('general.user') }}
                                                </a>
                                            </li>
                                        @endcan


                                    </ul>
                                </li>
                            @endcan

                            @can('admin')
                                <x-alert-menu />
                            @endcan



                            <!-- User Account: style can be found in dropdown.less -->
                            @auth
                                <li class="dropdown user user-menu">

                                    <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                                        @if (auth()->user()->present()->gravatar())
                                            <img src="{{ Auth::user()->present()->gravatar() }}" class="user-image"
                                                 alt="">
                                        @else
                                            <x-icon type="user" />
                                        @endif

                                        <span class="hidden-xs">
                                            {{ Auth::user()->display_name }}
                                            <strong class="caret"></strong>
                                        </span>
                                    </a>


                                    <ul class="dropdown-menu">

                                        {{-- User assets. Deliberately not behind
                                             `self.profile`: that gate is the
                                             Admin → General "users may edit
                                             their profile" setting, which says
                                             nothing about whether someone may
                                             see the equipment issued to them.
                                             With it off, the only route to your
                                             own laptop was knowing the URL. --}}
                                        <li {!! (request()->is('my') ? ' class="active"' : '') !!}>
                                            <a href="{{ route('my') }}">
                                                <x-icon type="checkmark" class="fa-fw" />
                                                {{ trans('general.viewassets') }}
                                            </a>
                                        </li>


                                        @can('viewRequestable', \App\Models\Asset::class)
                                            <li {!! (request()->is('account/requested') ? ' class="active"' : '') !!}>
                                                <a href="{{ route('account.requested') }}">
                                                    <x-icon type="requested" class="fa-fw" />
                                                    {{ trans('general.requested_assets_menu') }}
                                                </a></li>
                                        @endcan

                                        @if (! empty($formsAccessible))
                                            <li {!! (request()->is('procurement/forms*') ? ' class="active"' : '') !!}>
                                                <a href="{{ route('forms.index') }}">
                                                    <i class="fas fa-file-alt fa-fw" aria-hidden="true"></i>
                                                    {{ trans('admin/forms/general.menu_link') }}
                                                </a>
                                            </li>
                                        @endif

                                        {{-- Accept Assets left this menu: anything
                                             waiting on a signature is the first
                                             card on /my now. --}}
                                        <li {!! (request()->is('account/profile') ? ' class="active"' : '') !!}>
                                            <a href="{{ route('profile') }}">
                                                <x-icon type="user" class="fa-fw" />
                                                {{ trans('general.editprofile') }}
                                            </a>
                                        </li>

                                        @can('self.profile')
                                            @if (Auth::user()->ldap_import!='1')
                                                <li {!! (request()->is('account/password') ? ' class="active"' : '') !!}>
                                                    <a href="{{ route('account.password.index') }}">
                                                        <x-icon type="password" class="fa-fw"/>
                                                        {{ trans('general.changepassword') }}
                                                    </a>
                                                </li>
                                            @endif
                                        @endcan

                                        <li>
                                            <a type="button" data-theme-toggle aria-label="{{ trans('general.dark_mode') }}" class="btn-link btn-anchor" onclick="event.preventDefault();">
                                                {{ trans('general.dark_mode') }}
                                            </a>
                                        </li>

                                        @can('self.api')
                                            <li {!! (request()->is('account/api') ? ' class="active"' : '') !!}>
                                                <a href="{{ route('user.api') }}">
                                                    <x-icon type="api-key" class="fa-fw" />
                                                     {{ trans('general.manage_api_keys') }}
                                                </a>
                                            </li>
                                        @endcan
                                        
                                        <li class="divider"></li>
                                        <li>
                                            <a href="{{ route('logout.get') }}"
                                               onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                                                <x-icon type="logout" class="fa-fw" />
                                                 {{ trans('general.logout') }}
                                            </a>

                                            <form id="logout-form" action="{{ route('logout.post') }}" method="POST" style="display: none;">
                                                <button type="submit" style="display: none;" title="logout"></button>
                                                {{ csrf_field() }}
                                            </form>

                                        </li>
                                    </ul>
                                </li>
                            @endauth


                            @can('superadmin')
                                <li>
                                    <a href="{{ route('settings.index') }}">
                                        <x-icon type="admin-settings" />
                                        <span class="sr-only">{{ trans('general.admin') }}</span>
                                    </a>
                                </li>
                            @endcan
                        </ul>
                    </div>
                </nav>

                <!-- Sidebar toggle button-->
            </header>

            <!-- Left side column. contains the logo and sidebar -->
            @unless ($isEndUser ?? false)
            <aside class="main-sidebar">
                <!-- sidebar: style can be found in sidebar.less -->
                <section class="sidebar">
                    <!-- sidebar menu: : style can be found in sidebar.less -->
                    <ul class="sidebar-menu" data-widget="tree" data-follow-link="true" {{ \App\Helpers\Helper::determineLanguageDirection() == 'rtl' ? 'style="margin-right:12px' : '' }}>
                        @can('admin')
                            <li {!! (\request()->route()->getName()=='home' ? ' class="active"' : '') !!} class="firstnav">
                                <a href="{{ route('home') }}">
                                    <x-icon type="dashboard" class="fa-fw" />
                                    <span>{{ trans('general.dashboard') }}</span>
                                </a>
                            </li>
                        @endcan

                        @canany([
                            'reports.view',
                            'reports.procurement.view',
                            'reports.contracts.view',
                            'reports.transactions.view',
                        ])
                            <li class="treeview{{ (request()->is('reports*') ? ' active' : '') }}">

                                <a href="{{ route('reports.index') }}">
                                    <x-icon type="reports" class="fa-fw" />
                                    <span>{{ trans('general.reports') }}</span>
                                    <x-icon type="angle-left" class="pull-right"/>
                                </a>

                                <ul class="treeview-menu">
                                    @can('reports.procurement.view')
                                        <li {{!! (request()->is('reports/procurement*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('reports.procurement') }}">
                                                {{ trans('admin/purchase-orders/general.reports') }}
                                            </a>
                                        </li>
                                    @endcan
                                    @can('reports.contracts.view')
                                        <li {{!! (request()->is('reports/contracts*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('reports.contracts') }}">
                                                {{ trans('admin/contracts/general.reports') }}
                                            </a>
                                        </li>
                                    @endcan
                                    @can('reports.transactions.view')
                                        <li {{!! (request()->is('reports/transactions*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('reports.transactions.index') }}">
                                                {{ trans('admin/reports/transactions.dashboard_title') }}
                                            </a>
                                        </li>
                                    @endcan
                                    @can('view', App\Models\Asset::class)
                                        <li {{!! (request()->is('reports/printing*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('reports.printing') }}">
                                                {{ trans('admin/reports/printing.dashboard_title') }}
                                            </a>
                                        </li>
                                    @endcan
                                    @can('view', App\Models\Order::class)
                                        <li {{!! (request()->is('reports/exhibit*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('reports.exhibit') }}">
                                                {{ trans('admin/exhibit-projects/general.dashboard_title') }}
                                            </a>
                                        </li>
                                    @endcan
                                </ul>
                            </li>
                        @endcanany

                        @can('index', \App\Models\Asset::class)
                            <li class="treeview{{ ((request()->is('statuslabels/*') || request()->is(['hardware*', 'maintenances*'])) ? ' active' : '') }}">
                                <a href="{{ route('hardware.index') }}">
                                    <x-icon type="assets" class="fa-fw" />
                                    <span>{{ trans('general.assets') }}</span>
                                    <x-icon type="angle-left" class="pull-right fa-fw"/>
                                </a>
                                <ul class="treeview-menu">
                                    <li {!! (!request()->query('status_type') && (request()->is('hardware')) ? ' class="active"' : '') !!}>
                                        <a href="{{ url('hardware') }}">
                                            <x-icon type="circle" class="text-grey fa-fw"/>
                                            {{ trans('general.list_all') }}
                                            <span class="badge">
                                                {{ (isset($total_assets)) ? $total_assets : '' }}
                                            </span>
                                        </a>
                                    </li>

                                    {{-- Fully qualified deliberately: an inline PHP block inside
                                         Blade control flow cannot carry a `use` statement, because
                                         PHP only permits imports at top-level file scope. A
                                         formatter that hoists one here produces a parse error. --}}
                                    <?php $status_navs = \App\Models\Statuslabel::where('show_in_nav', '=', 1)->withCount('assets as asset_count')->get(); ?>
                                    @if (count($status_navs) > 0)
                                        @foreach ($status_navs as $status_nav)
                                            <li{!! (request()->is('statuslabels/'.$status_nav->id) ? ' class="active"' : '') !!}>
                                                <a href="{{ route('statuslabels.show', ['statuslabel' => $status_nav->id]) }}">
                                                    <i class="fas fa-circle text-grey fa-fw"
                                                       aria-hidden="true"{!!  ($status_nav->color!='' ? ' style="color: '.e($status_nav->color).'"' : '') !!}></i>
                                                    {{ $status_nav->name }}
                                                    <span class="badge badge-secondary">{{ $status_nav->asset_count }}</span></a></li>
                                        @endforeach
                                    @endif


                                    <li id="deployed-sidenav-option" {!! (request()->query('status_type') == 'Deployed' ? ' class="active"' : '') !!}>
                                        <a href="{{ url('hardware?status_type=Deployed') }}">
                                            <x-icon type="circle" class="text-blue fa-fw" />
                                            {{ trans('general.deployed') }}
                                            <span class="badge">{{ (isset($total_deployed_sidebar)) ? $total_deployed_sidebar : '' }}</span>
                                        </a>
                                    </li>
                                    <li id="rtd-sidenav-option"{!! (request()->query('status_type') == 'RTD' ? ' class="active"' : '') !!}>
                                        <a href="{{ url('hardware?status_type=RTD') }}">
                                            <x-icon type="circle" class="text-green fa-fw" />
                                            {{ trans('general.ready_to_deploy') }}
                                            <span class="badge">{{ (isset($total_rtd_sidebar)) ? $total_rtd_sidebar : '' }}</span>
                                        </a>
                                    </li>
                                    <li id="pending-sidenav-option"{!! (request()->query('status_type') == 'Pending' ? ' class="active"' : '') !!}>
                                        <a href="{{ url('hardware?status_type=Pending') }}">
                                            <x-icon type="circle" class="text-orange fa-fw" />
                                            {{ trans('general.pending') }}
                                            <span class="badge">{{ (isset($total_pending_sidebar)) ? $total_pending_sidebar : '' }}</span>
                                        </a>
                                    </li>
                                    <li id="undeployable-sidenav-option"{!! (request()->query('status') == 'Undeployable' ? ' class="active"' : '') !!} ><a
                                            href="{{ url('hardware?status_type=Undeployable') }}">
                                            <x-icon type="x" class="text-red fa-fw" />
                                            {{ trans('general.undeployable') }}
                                            <span class="badge">{{ (isset($total_undeployable_sidebar)) ? $total_undeployable_sidebar : '' }}</span>
                                        </a>
                                    </li>
                                    <li id="byod-sidenav-option"{!! (request()->query('status_type') == 'byod' ? ' class="active"' : '') !!}>
                                        <a
                                            href="{{ url('hardware?status_type=byod') }}">
                                            <x-icon type="x" class="text-red fa-fw" />
                                            {{ trans('general.byod') }}
                                            <span class="badge">{{ (isset($total_byod_sidebar)) ? $total_byod_sidebar : '' }}</span>
                                        </a>
                                    </li>
                                    <li id="archived-sidenav-option"{!! (request()->query('status_type') == 'Archived' ? ' class="active"' : '') !!}>
                                        <a
                                            href="{{ url('hardware?status_type=Archived') }}">
                                            <x-icon type="x" class="text-red fa-fw" />
                                            {{ trans('admin/hardware/general.archived') }}
                                            <span class="badge">{{ (isset($total_archived_sidebar)) ? $total_archived_sidebar : '' }}</span>
                                        </a>
                                    </li>
                                    <li id="requestable-sidenav-option"{!! (request()->query('status_type') == 'Requestable' ? ' class="active"' : '') !!}>
                                        <a
                                            href="{{ url('hardware?status_type=Requestable') }}">
                                            <x-icon type="checkmark" class="text-blue fa-fw" />
                                            {{ trans('admin/hardware/general.requestable') }}
                                        </a>
                                    </li>

                                    @can('audit', \App\Models\Asset::class)
                                        <li id="audit-due-sidenav-option"{!! (request()->is('hardware/audit/due') ? ' class="active"' : '') !!}>
                                            <a href="{{ route('assets.audit.due') }}">
                                                <x-icon type="audit" class="text-yellow fa-fw"/>
                                                {{ trans('general.audit_due') }}
                                                <span class="badge">{{ (isset($total_due_and_overdue_for_audit)) ? $total_due_and_overdue_for_audit : '' }}</span>
                                            </a>
                                        </li>
                                    @endcan

                                    @can('checkin', \App\Models\Asset::class)
                                    <li id="checkin-due-sidenav-option"{!! (request()->is('hardware/checkins/due') ? ' class="active"' : '') !!}>
                                        <a href="{{ route('assets.checkins.due') }}">
                                            <x-icon type="due" class="text-orange fa-fw"/>
                                            {{ trans('general.checkin_due') }}
                                            <span class="badge">{{ (isset($total_due_and_overdue_for_checkin)) ? $total_due_and_overdue_for_checkin : '' }}</span>
                                        </a>
                                    </li>
                                    @endcan

                                    <li class="divider">&nbsp;</li>
                                    @can('checkin', \App\Models\Asset::class)
                                        <li{!! (request()->is('hardware/quickscancheckin') ? ' class="active"' : '') !!}>
                                            <a href="{{ route('hardware/quickscancheckin') }}">
                                                {{ trans('general.quickscan_checkin') }}
                                            </a>
                                        </li>
                                    @endcan

                                    @can('checkout', \App\Models\Asset::class)
                                        <li{!! (request()->is('hardware/bulkcheckout') ? ' class="active"' : '') !!}>
                                            <a href="{{ route('hardware.bulkcheckout.show') }}">
                                                {{ trans('general.bulk_checkout') }}
                                            </a>
                                        </li>
                                        <li{!! (request()->is('hardware/requested') ? ' class="active"' : '') !!}>
                                            <a href="{{ route('assets.requested') }}">
                                                {{ trans('general.requested') }}</a>
                                        </li>
                                    @endcan

                                    @can('create', \App\Models\Asset::class)
                                        <li{!! (request()->query('status_type') == 'Deleted' ? ' class="active"' : '') !!}>
                                            <a href="{{ url('hardware?status_type=Deleted') }}">
                                                {{ trans('general.deleted') }}
                                            </a>
                                        </li>
                                        <li {!! (request()->is('maintenances') ? ' class="active"' : '') !!}>
                                            <a href="{{ route('maintenances.index') }}">
                                                {{ trans('general.maintenances') }}
                                            </a>
                                        </li>
                                    @endcan
                                    @can('audit', \App\Models\Asset::class)
                                        <li id="bulk-audit-sidenav-option" {!! (request()->is('hardware/bulkaudit') ? ' class="active"' : '') !!}>
                                            <a href="{{ route('assets.bulkaudit') }}">
                                                {{ trans('general.bulkaudit') }}
                                            </a>
                                        </li>
                                    @endcan

                                    @can('admin')
                                        <li id="import-history-sidenav-option" {!! (request()->is('hardware/history') ? ' class="active"' : '') !!}>
                                            <a href="{{ url('hardware/history') }}">
                                                {{ trans('general.import-history') }}
                                            </a>
                                        </li>
                                    @endcan

                                </ul>
                            </li>
                        @endcan
                        @if (Gate::allows('view', \App\Models\Contract::class) || Gate::allows('view', \App\Models\License::class))
                            <li id="contracts-sidenav-option" class="treeview {{ (request()->is('contracts*') || request()->is('licenses*') || request()->is('admin/license-models*')) ? 'active' : '' }}">
                                <a href="{{ route('contracts.index') }}">
                                    <x-icon type="contracts" class="fa-fw"/>
                                    <span>{{ trans('admin/contracts/general.contracts') }}</span>
                                    <x-icon type="angle-left" class="pull-right fa-fw"/>
                                </a>
                                <ul class="treeview-menu">
                                    @can('view', \App\Models\Contract::class)
                                        <li {!! (request()->is('contracts*') ? ' class="active"' : '') !!}>
                                            <a href="{{ route('contracts.index') }}">
                                                {{ trans('admin/contracts/general.contracts') }}
                                            </a>
                                        </li>
                                    @endcan
                                    @can('view', \App\Models\License::class)
                                        <li {!! (request()->is('licenses*') ? ' class="active"' : '') !!}>
                                            <a href="{{ route('licenses.index') }}">
                                                {{ trans('general.licenses') }}
                                            </a>
                                        </li>
                                    @endcan
                                    @can('view', \App\Models\LicenseModel::class)
                                        <li {!! (request()->is('admin/license-models*') ? ' class="active"' : '') !!}>
                                            <a href="{{ route('license-models.index') }}">
                                                {{ trans('admin/licensemodels/general.sidebar_label') }}
                                            </a>
                                        </li>
                                    @endcan
                                </ul>
                            </li>
                        @endif
                        @can('index', \App\Models\Accessory::class)
                            <li id="accessories-sidenav-option"{!! (request()->is('accessories*') ? ' class="active"' : '') !!}>
                                <a href="{{ route('accessories.index') }}">
                                    <x-icon type="accessories" class="fa-fw" />
                                    <span>{{ trans('general.accessories') }}</span>
                                </a>
                            </li>
                        @endcan
                        @can('view', \App\Models\Consumable::class)
                            <li id="consumables-sidenav-option"{!! (request()->is('consumables*') ? ' class="active"' : '') !!}>
                                <a href="{{ url('consumables') }}">
                                    <x-icon type="consumables" class="fa-fw" />
                                    <span>{{ trans('general.consumables') }}</span>
                                </a>
                            </li>
                        @endcan
                        @can('view', \App\Models\Component::class)
                            <li id="components-sidenav-option"{!! (request()->is('components*') ? ' class="active"' : '') !!}>
                                <a href="{{ route('components.index') }}">
                                    <x-icon type="components" class="fa-fw" />
                                    <span>{{ trans('general.components') }}</span>
                                </a>
                            </li>
                        @endcan
                        @can('view', \App\Models\PredefinedKit::class)
                            @if ($snipeSettings->show_predefined_kits)
                                <li id="kits-sidenav-option"{!! (request()->is('kits') ? ' class="active"' : '') !!}>
                                    <a href="{{ route('kits.index') }}">
                                        <x-icon type="kits" class="fa-fw" />
                                        <span>{{ trans('general.kits') }}</span>
                                    </a>
                                </li>
                            @endif
                        @endcan

                        {{-- Catalog: asset taxonomies (models, categories, manufacturers). --}}
                        @if (Gate::allows('view', \App\Models\AssetModel::class) || Gate::allows('view', \App\Models\Category::class) || Gate::allows('view', \App\Models\Manufacturer::class))
                            <li id="catalog-sidenav-option" class="treeview {!! (request()->is(App\Helpers\Helper::CatalogUrls()) ? ' active' : '') !!}">
                                <a href="#">
                                    <x-icon type="catalog" class="fa-fw" />
                                    <span>{{ trans('general.catalog') }}</span>
                                    <x-icon type="angle-left" class="pull-right fa-fw"/>
                                </a>
                                <ul class="treeview-menu">
                                    @can('view', \App\Models\AssetModel::class)
                                        <li {{!! (request()->is('models*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('models.index') }}">
                                                {{ trans('general.asset_models') }}
                                            </a>
                                        </li>
                                    @endcan
                                    @can('view', \App\Models\Category::class)
                                        <li {{!! (request()->is('categories*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('categories.index') }}">
                                                {{ trans('general.categories') }}
                                            </a>
                                        </li>
                                    @endcan
                                    @can('view', \App\Models\Manufacturer::class)
                                        <li {{!! (request()->is('manufacturers*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('manufacturers.index') }}">
                                                {{ trans('general.manufacturers') }}
                                            </a>
                                        </li>
                                    @endcan
                                </ul>
                            </li>
                        @endif

                        {{-- Organization: people (nested) and org structure (departments, locations, companies). --}}
                        @if (Gate::allows('view', \App\Models\User::class) || Gate::allows('view', \App\Models\Department::class) || Gate::allows('view', \App\Models\Location::class) || Gate::allows('view', \App\Models\Company::class))
                            <li id="organization-sidenav-option" class="treeview {!! (request()->is(App\Helpers\Helper::OrganizationUrls()) ? ' active' : '') !!}">
                                <a href="#">
                                    <x-icon type="organization" class="fa-fw" />
                                    <span>{{ trans('general.organization') }}</span>
                                    <x-icon type="angle-left" class="pull-right fa-fw"/>
                                </a>
                                <ul class="treeview-menu">
                                    @can('view', \App\Models\User::class)
                                        <li class="treeview{{ (request()->is('users*') ? ' active' : '') }}" id="users-sidenav-option">
                                            <a href="#" {{$snipeSettings->shortcuts_enabled == 1 ? "accesskey=6" : ''}}>
                                                <x-icon type="users" class="fa-fw" />
                                                {{ trans('general.people') }}
                                                <x-icon type="angle-left" class="pull-right fa-fw"/>
                                            </a>
                                            <ul class="treeview-menu">
                                                <li {!! ((request()->is('users')  && (request()->input() == null)) ? ' class="active"' : '') !!} id="users-sidenav-list-all">
                                                    <a href="{{ route('users.index') }}">
                                                        <x-icon type="circle" class="text-grey fa-fw fa-fw"/>
                                                        {{ trans('general.list_all') }}
                                                    </a>
                                                </li>
                                                <li class="{{ (request()->is('users') && request()->input('superadmins') == "true") ? 'active' : '' }}" id="users-sidenav-superadmins">
                                                    <a href="{{ route('users.index', ['superadmins' => 'true']) }}">
                                                        <x-icon type="superadmin" class="text-danger fa-fw"/>
                                                        {{ trans('general.show_superadmins') }}
                                                    </a>
                                                </li>
                                                <li class="{{ (request()->is('users') && request()->input('admins') == "true") ? 'active' : '' }}" id="users-sidenav-list-admins">
                                                    <a href="{{ route('users.index', ['admins' => 'true']) }}">
                                                        <x-icon type="admin" class="text-warning fa-fw"/>
                                                        {{ trans('general.show_admins') }}
                                                    </a>
                                                </li>
                                                <li class="{{ (request()->is('users') && request()->input('status') == "deleted") ? 'active' : '' }}" id="users-sidenav-deleted">
                                                    <a href="{{ route('users.index', ['status' => 'deleted']) }}">
                                                        <x-icon type="x" class="text-danger fa-fw"/>
                                                        {{ trans('general.deleted_users') }}
                                                    </a>
                                                </li>
                                                <li class="{{ (request()->is('users') && request()->input('activated') == "1") ? 'active' : '' }}" id="users-sidenav-activated">
                                                    <a href="{{ route('users.index', ['activated' => true]) }}">
                                                        <i class="fa-solid fa-person-circle-check text-success fa-fw"></i>
                                                        {{ trans('general.login_enabled') }}
                                                    </a>
                                                </li>
                                                <li class="{{ (request()->is('users') && request()->input('activated') == "0") ? 'active' : '' }}" id="users-sidenav-not-activated">
                                                    <a href="{{ route('users.index', ['activated' => false]) }}">
                                                        <i class="fa-solid fa-person-circle-xmark text-danger fa-fw"></i>
                                                        {{ trans('general.login_disabled') }}
                                                    </a>
                                                </li>
                                                @if (auth()->user()->isSuperUser())
                                                    <li class="{{ (request()->is('admin/groups*') ? 'active' : '') }}" id="users-sidenav-groups">
                                                        <a href="{{ route('groups.index') }}">
                                                            <x-icon type="groups" class="text-grey fa-fw"/>
                                                            {{ trans('general.groups') }}
                                                        </a>
                                                    </li>
                                                @endif
                                            </ul>
                                        </li>
                                    @endcan
                                    @can('view', \App\Models\Department::class)
                                        <li {{!! (request()->is('departments*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('departments.index') }}">
                                                {{ trans('general.departments') }}
                                            </a>
                                        </li>
                                    @endcan
                                    @can('view', \App\Models\Location::class)
                                        <li {{!! (request()->is('locations*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('locations.index') }}">
                                                {{ trans('general.locations') }}
                                            </a>
                                        </li>
                                    @endcan
                                    @can('view', \App\Models\Company::class)
                                        <li {{!! (request()->is('companies*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('companies.index') }}">
                                                {{ trans('general.companies') }}
                                            </a>
                                        </li>
                                    @endcan
                                </ul>
                            </li>
                        @endif

                        {{-- Your own equipment. Ungated, because the page only
                             ever shows the assets checked out to whoever is
                             asking — there is nothing here to be permitted to
                             see. It is also in the account dropdown, but for
                             someone whose entire sidebar is Store and this,
                             burying "where is my laptop" behind an avatar menu
                             is not a findable answer. --}}
                        <li {{!! (request()->is('my') ? ' class="active"' : '') !!}}>
                            <a href="{{ route('my') }}">
                                <x-icon type="checkmark" class="fa-fw" />
                                <span>{{ trans('general.viewassets') }}</span>
                            </a>
                        </li>

                        {{-- Store and My Orders live under Procurement in the
                             admin sidebar; end users reach them from the eu
                             topbar, so top-level entries here were noise. --}}

                        {{-- Procurement: operational purchasing data. --}}
                        @if (Gate::allows('view', \App\Models\Order::class) || Gate::allows('view', \App\Models\Supplier::class) || Gate::allows('view', \App\Models\Depreciation::class))
                            <li id="procurement-sidenav-option" class="treeview {!! (request()->is(App\Helpers\Helper::ProcurementUrls()) ? ' active' : '') !!}">
                                <a href="#">
                                    <x-icon type="procurement" class="fa-fw" />
                                    <span>{{ trans('general.procurement') }}</span>
                                    <x-icon type="angle-left" class="pull-right fa-fw"/>
                                </a>
                                <ul class="treeview-menu">
                                    @can('view', \App\Models\Order::class)
                                        <li {{!! (request()->is('procurement') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('procurement.index') }}">
                                                {{ trans('admin/store/general.procurement') }}
                                            </a>
                                        </li>
                                        <li {{!! (request()->is('procurement/queue*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('procurement.queue') }}">
                                                {{ trans('admin/store/general.queue') }}
                                            </a>
                                        </li>
                                    @endcan
                                    {{-- The forms platform — the faculty program
                                         intake starts here, the first step of the
                                         procurement flow it now lives under. Shown
                                         only to someone a form is open to. --}}
                                    @if (! empty($formsAccessible))
                                        <li {!! (request()->is('procurement/forms*') ? ' class="active"' : '') !!}>
                                            <a href="{{ route('forms.index') }}">
                                                {{ trans('admin/forms/general.menu_link') }}
                                            </a>
                                        </li>
                                    @endif
                                    {{-- Store and My Orders moved here from the
                                         sidebar top level. --}}
                                    <li {!! (request()->is('store') ? ' class="active"' : '') !!}>
                                        <a href="{{ route('store.index') }}">
                                            {{ trans('admin/store/general.store') }}
                                        </a>
                                    </li>
                                    <li {!! (request()->is('store/orders*') ? ' class="active"' : '') !!}>
                                        <a href="{{ route('store.orders') }}">
                                            {{ trans('admin/store/general.my_orders') }}
                                        </a>
                                    </li>
                                    @can('view', \App\Models\Order::class)
                                        <li {{!! (request()->is('requisitions*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('requisitions.index') }}">
                                                {{ trans('admin/purchase-orders/general.requisitions') }}
                                            </a>
                                        </li>
                                        <li {{!! (request()->is('orders*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('orders.index') }}">
                                                {{ trans('admin/orders/general.orders') }}
                                            </a>
                                        </li>
                                        <li {{!! (request()->is('purchase-orders*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('purchase-orders.index') }}">
                                                {{ trans('admin/purchase-orders/general.purchase_orders') }}
                                            </a>
                                        </li>
                                    @endcan
                                    @can('view', \App\Models\Supplier::class)
                                        <li {{!! (request()->is('suppliers*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('suppliers.index') }}">
                                                {{ trans('general.suppliers') }}
                                            </a>
                                        </li>
                                    @endcan
                                    @can('view', \App\Models\Order::class)
                                        <li {{!! (request()->is('lease-decisions*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('lease-decisions.index') }}">
                                                {{ trans('admin/lease-decisions/general.lease_decisions') }}
                                            </a>
                                        </li>
                                        <li {{!! (request()->is('user-agreements*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('user-agreements.index') }}">
                                                {{ trans('admin/user-agreements/general.user_agreements') }}
                                            </a>
                                        </li>
                                    @endcan
                                    @can('view', \App\Models\Depreciation::class)
                                        <li {{!! (request()->is('depreciations*') ? ' class="active"' : '') !!}}>
                                            <a href="{{ route('depreciations.index') }}">
                                                {{ trans('general.depreciation') }}
                                            </a>
                                        </li>
                                    @endcan
                                </ul>
                            </li>
                        @endif

                        @can('import')
                            <li id="import-sidenav-option"{!! (request()->is('import*') ? ' class="active"' : '') !!}>
                                <a href="{{ route('imports.index') }}">
                                    <x-icon type="import" class="fa-fw" />
                                    <span>{{ trans('general.import') }}</span>
                                </a>
                            </li>
                        @endcan

                        @can('backend.interact')
                            <li id="settings-sidenav-option" class="treeview {!! (request()->is(App\Helpers\Helper::SettingUrls()) ? ' active' : '') !!}">
                                <a href="#" id="settings">
                                    <x-icon type="settings" class="fa-fw" />
                                    <span>{{ trans('general.settings') }}</span>
                                    <x-icon type="angle-left" class="pull-right fa-fw"/>
                                </a>

                                <ul class="treeview-menu">
                                    @if(Gate::allows('view', App\Models\CustomField::class) || Gate::allows('view', App\Models\CustomFieldset::class))
                                        <li {!! (request()->is('fields*') ? ' class="active"' : '') !!}>
                                            <a href="{{ route('fields.index') }}">
                                                {{ trans('admin/custom_fields/general.custom_fields') }}
                                            </a>
                                        </li>
                                    @endif

                                    @can('view', \App\Models\Statuslabel::class)
                                        <li {!! (request()->is('statuslabels*') ? ' class="active"' : '') !!}>
                                            <a href="{{ route('statuslabels.index') }}">
                                                {{ trans('general.status_labels') }}
                                            </a>
                                        </li>
                                    @endcan
                                </ul>
                            </li>
                        @endcan

                        @can('viewRequestable', \App\Models\Asset::class)
                            <li{!! (request()->is('account/requestable-assets') ? ' class="active"' : '') !!}>
                                <a href="{{ route('requestable-assets') }}">
                                    <x-icon type="requestable" class="fa-fw" />
                                    <span>{{ trans('general.requestable_items') }}</span>
                                </a>
                            </li>
                        @endcan


                    </ul>
                </section>
                <!-- /.sidebar -->
            </aside>
            @endunless

            <!-- Content Wrapper. Contains page content -->

            <div class="content-wrapper" role="main" id="setting-list">

                {{-- Borrowed identity. Loud and on every page, because the
                     failure mode is forgetting: an administrator who thinks
                     they are themselves will read a deliberately restricted
                     view as a bug, and anything they change is written to
                     someone else's name. The exit is in the banner so it is
                     never more than one click from wherever they got to. --}}
                @if (session()->has(\App\Http\Controllers\ImpersonateController::SESSION_KEY))
                    {{-- Viewing as someone is one floating badge, not a banner:
                         the point is seeing their screen as they see it, and a
                         full-width bar over every page is the one thing they do
                         not have. Yellow, bottom corner, over nothing anyone
                         interacts with, exit one click away. --}}
                    <div class="imp-chip">
                        <span class="imp-chip-dot" aria-hidden="true"></span>
                        <span>{{ trans('admin/users/general.impersonate_chip', ['name' => auth()->user()?->present()->fullName]) }}</span>
                        <form method="POST" action="{{ route('impersonate.stop') }}" style="display:inline;">
                            {{ csrf_field() }}
                            <button type="submit" class="imp-chip-stop">{{ trans('admin/users/general.impersonate_stop') }}</button>
                        </form>
                    </div>

                    <style>
                        .imp-chip {
                            position: fixed;
                            right: 20px;
                            bottom: 20px;
                            z-index: 2050;
                            display: flex;
                            align-items: center;
                            gap: 10px;
                            padding: 9px 12px;
                            border-radius: 999px;
                            background: #f0b429;
                            color: #241a02;
                            font-size: 12.5px;
                            font-weight: 600;
                            box-shadow: 0 4px 14px rgba(0, 0, 0, .35);
                        }
                        .imp-chip-dot {
                            width: 8px;
                            height: 8px;
                            border-radius: 50%;
                            background: #8a6400;
                        }
                        .imp-chip-stop {
                            border: 1px solid rgba(36, 26, 2, .45);
                            background: transparent;
                            color: #241a02;
                            border-radius: 999px;
                            font-size: 11.5px;
                            font-weight: 600;
                            padding: 3px 10px;
                        }
                        .imp-chip-stop:hover { background: rgba(36, 26, 2, .12); }
                    </style>
                @endif

                @if ($debug_in_production)
                    <div class="row" style="margin-bottom: 0px; background-color: red; color: white; font-size: 15px;">
                        <div class="col-md-12"
                             style="margin-bottom: 0px; background-color: #b50408 ; color: white; padding: 10px 20px 10px 30px; font-size: 16px;">
                            <x-icon type="warning" class="fa-3x pull-left"/>
                            <strong>{{ strtoupper(trans('general.debug_warning')) }}:</strong>
                            {!! trans('general.debug_warning_text') !!}
                        </div>
                    </div>
                @endif

                <!-- Content Header (Page header) -->
                <section class="content-header">


                    <div class="row">
                        <div class="col-md-12" style="margin-bottom: 0px;">

                        <style>
                            .breadcrumb-item {
                                display: inline;
                                list-style: none;
                            }
                        </style>

                            <h1 class="pull-left pagetitle" style="font-size: 22px; margin-top: 5px;">

                                @if (Breadcrumbs::has() && (Breadcrumbs::current()->count() > 1))
                                    <ul style="padding-left: 0;">

                                    @foreach (Breadcrumbs::current() as $crumbs)
                                        @if ($crumbs->url() && !$loop->last)
                                            <li class="breadcrumb-item">
                                                <a href="{{ $crumbs->url() }}">
                                                    @if ($loop->first)
                                                        <x-icon type="dashboard" />
                                                    @else
                                                        {{ $crumbs->title() }}
                                                    @endif
                                                </a>
                                                <x-icon type="angle-right" />
                                            </li>
                                        @elseif (is_null($crumbs->url()) && !$loop->last)
                                            <li class="breadcrumb-item active">
                                                {{ $crumbs->title() }}
                                                <x-icon type="angle-right" />
                                            </li>
                                       @else
                                            <li class="breadcrumb-item active">
                                                {{ $crumbs->title() }}
                                            </li>
                                        @endif
                                    @endforeach

                                    </ul>
                                @else
                                    @yield('title')
                                @endif

                            </h1>

                                @if (isset($helpText))
                                    @include ('partials.more-info',
                                                           [
                                                               'helpText' => $helpText,
                                                               'helpPosition' => (isset($helpPosition)) ? $helpPosition : 'left'
                                                           ])
                                @endif
                                <div class="pull-right">
                                    @yield('header_right')
                                </div>

                        </div>
                    </div>
                </section>


                <section class="content" id="main" tabindex="-1" style="padding-top: 0px;">

                    <!-- Notifications -->
                    <div class="row">
                        @if (config('app.lock_passwords'))
                            <div class="col-md-12">
                                <div class="callout callout-info">
                                    {{ trans('general.some_features_disabled') }}
                                </div>
                            </div>
                        @endif

                        @include('notifications')
                    </div>


                    <!-- Content -->
                    <div id="{!! (request()->is('*api*') ? 'app' : 'webui') !!}">
                        @yield('content')
                    </div>

                </section>

            </div><!-- /.content-wrapper -->
            <footer class="main-footer hidden-print" style="display:grid;flex-direction:column;">

                <div class="hidden-xs pull-left">
                    <div class="pull-left footer-links">
                         {!! trans('general.footer_credit') !!}

                        <a target="_blank" href="https://bsky.app/profile/snipeitapp.com" rel="noopener" data-tooltip="true" data-title="Join us on Bluesky">
                            <i class="fa-brands fa-square-bluesky fa-fw"></i>
                        </a>
                        <a target="_blank" href="https://github.com/grokability/snipe-it/" rel="noopener" data-tooltip="true" data-title="Join us on Github">
                            <i class="fa-brands fa-square-github fa-fw"></i>
                        </a>
                        <a target="_blank" href="https://hachyderm.io/@grokability" rel="noopener" data-tooltip="true" data-title="Join us on Mastodon">
                            <i class="fa-brands fa-mastodon fa-fw"></i>
                        </a>
                        <a target="_blank" href="https://discord.gg/yZFtShAcKk" rel="noopener" data-tooltip="true" data-title="Join us on Discord">
                            <i class="fa-brands fa-discord fa-fw"></i>
                        </a>

                    </div>
                    <div class="pull-right">
                    @if ($snipeSettings->version_footer!='off')
                        @if (($snipeSettings->version_footer=='on') || (($snipeSettings->version_footer=='admin') && (Auth::user()->isSuperUser()=='1')))
                            &nbsp; {{ trans('general.version') }} {{ config('version.app_version') }}{{ config('ecu.version_suffix') }}@if (config('ecu.build_sha')).{{ \Illuminate\Support\Str::limit(config('ecu.build_sha'), 7, '') }}@endif -
                            {{ trans('general.build') }} {{ config('version.build_version') }} ({{ config('version.branch') }})
                            &middot;
                            <a target="_blank" rel="noopener" href="{{ config('ecu.fork_source_url') }}" data-tooltip="true" data-title="View source on GitHub (AGPL-3.0)">Source</a>
                        @endif
                    @endif

                    @if (isset($user) && ($user->isSuperUser()) && (app()->environment('local')))
                       <a href="{{ url('telescope') }}" class="label label-default" rel="noopener">Open Telescope</a>
                    @endif




                    @if ($snipeSettings->support_footer!='off')
                        @if (($snipeSettings->support_footer=='on') || (($snipeSettings->support_footer=='admin') && (Auth::user()->isSuperUser()=='1')))
                            <a target="_blank" class="label label-default"
                               href="https://snipe-it.readme.io/docs/overview"
                               rel="noopener">{{ trans('general.user_manual') }}</a>
                            <a target="_blank" class="label label-default" href="https://snipeitapp.com/support/"
                               rel="noopener">{{ trans('general.bug_report') }}</a>
                        @endif
                    @endif

                    @if ($snipeSettings->privacy_policy_link!='')
                        <a target="_blank" class="label label-default" rel="noopener"
                           href="{{  $snipeSettings->privacy_policy_link }}"
                           target="_new">{{ trans('admin/settings/general.privacy_policy') }}</a>
                    @endif
                    </div>
                    <br>
                    @if ($snipeSettings->footer_text!='')
                        <div class="pull-left">
                            {!!  Helper::parseEscapedMarkedown($snipeSettings->footer_text)  !!}
                        </div>
                    @endif
                </div>
            </footer>
        </div><!-- ./wrapper -->


        <!-- end main container -->

        <div class="modal modal-danger fade" id="dataConfirmModal" tabindex="-1" role="dialog" aria-labelledby="dataConfirmModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                        <h4 class="modal-title" id="dataConfirmModalLabel">
                            <span class="modal-header-icon"></span>&nbsp;
                        </h4>
                    </div>
                    <div class="modal-body"></div>
                    <div class="modal-footer">
                        <form method="post" id="deleteForm" role="form" action="">
                            {{ csrf_field() }}
                            {{ method_field('DELETE') }}

                            <button type="button" class="btn btn-default pull-left"
                                    data-dismiss="modal">{{ trans('general.cancel') }}</button>
                            <button type="submit" class="btn btn-outline"
                                    id="dataConfirmOK">{{ trans('general.yes') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>


        <div class="modal modal-warning fade" id="restoreConfirmModal" tabindex="-1" role="dialog"
             aria-labelledby="confirmModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                        <h4 class="modal-title" id="confirmModalLabel">&nbsp;</h4>
                    </div>
                    <div class="modal-body"></div>
                    <div class="modal-footer">
                        <form method="post" id="restoreForm" role="form">
                            {{ csrf_field() }}
                            {{ method_field('POST') }}

                            <button type="button" class="btn btn-default pull-left"
                                    data-dismiss="modal">{{ trans('general.cancel') }}</button>
                            <button type="submit" class="btn btn-outline"
                                    id="dataConfirmOK">{{ trans('general.yes') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>



        {{-- Javascript files --}}
        <script src="{{ url(mix('js/dist/all.js')) }}" nonce="{{ csrf_token() }}"></script>
        <script src="{{ url('js/select2/i18n/'.Helper::mapBackToLegacyLocale(app()->getLocale()).'.js') }}"></script>

        {{-- Page level javascript --}}
        @stack('js')

        @section('moar_scripts')
        @show


        <script nonce="{{ csrf_token() }}">

            // Handle the first selected tabs regardless of permissions
            if ($('li.snipetab').is(':first-of-type')) {
                var hash = $('li.snipetab:first-of-type').children().attr('href');
                $('li.snipetab:first-of-type').addClass('active');
                $('div'+hash+'.snipetab-pane').addClass('in active');
            }


            //color picker with addon
            $(".color").colorpicker();


            /**
             * Utility function to calculate the current theme setting.
             * Look for a local storage value.
             * Fall back to system setting.
             * Fall back to light mode.
             */
            function calculateSettingAsThemeString({ localStorageTheme, systemSettingDark }) {
                if (localStorageTheme !== null) {
                    return localStorageTheme;
                }

                if (systemSettingDark.matches) {
                    return "dark";
                }

                return "light";
            }

            /**
             * Utility function to update the button text and aria-label.
             */
            function updateButton({ buttonEl, isDark }) {
                const newCta = isDark ? '{{ trans('general.light_mode') }}' : '{{ trans('general.dark_mode') }}';
                const newCtaButton = isDark ? '<i class="fa-regular fa-sun fa-fw"></i> ' : '<i class="fa-solid fa-moon fa-fw"></i> ';
                // use an aria-label if omitting text on the button
                // and using a sun/moon icon, for example
                buttonEl.setAttribute("aria-label", newCta);
                buttonEl.innerHTML = newCtaButton + newCta;
            }

            /**
             * Utility function to update the theme setting on the html tag
             */
            function updateThemeOnHtmlEl({ theme }) {
                document.querySelector("html").setAttribute("data-theme", theme);
            }


            /**
             * On page load:
             */

            /**
             * 1. Grab what we need from the DOM and system settings on page load
             */

            const button = document.querySelector("[data-theme-toggle]");
            const localStorageTheme = localStorage.getItem("theme");
            const systemSettingDark = window.matchMedia("(prefers-color-scheme: dark)");
            const clearButton = document.querySelector("[data-theme-toggle-clear]");

            /**
             * 2. Work out the current site settings
             */
            let currentThemeSetting = calculateSettingAsThemeString({ localStorageTheme, systemSettingDark });

            /**
             * 3. Update the theme setting and button text according to current settings
             */
            // The theme itself is applied first and unconditionally. The
            // toggle lives in a menu that not every layout renders, and
            // when it was absent the throw took the whole page's theme
            // down with it.
            updateThemeOnHtmlEl({ theme: currentThemeSetting });

            /**
             * 4. Add an event listener to toggle the theme
             */
            if (button) {
                updateButton({ buttonEl: button, isDark: currentThemeSetting === "dark" });

                button.addEventListener("click", (event) => {
                    const newTheme = currentThemeSetting === "dark" ? "light" : "dark";

                    localStorage.setItem("theme", newTheme);
                    updateButton({ buttonEl: button, isDark: newTheme === "dark" });
                    updateThemeOnHtmlEl({ theme: newTheme });

                    currentThemeSetting = newTheme;
                });
            }




            $.fn.datepicker.dates['{{ app()->getLocale() }}'] = {
                days: [
                    "{{ trans('datepicker.days.sunday') }}",
                    "{{ trans('datepicker.days.monday') }}",
                    "{{ trans('datepicker.days.tuesday') }}",
                    "{{ trans('datepicker.days.wednesday') }}",
                    "{{ trans('datepicker.days.thursday') }}",
                    "{{ trans('datepicker.days.friday') }}",
                    "{{ trans('datepicker.days.saturday') }}"
                ],
                daysShort: [
                    "{{ trans('datepicker.short_days.sunday') }}",
                    "{{ trans('datepicker.short_days.monday') }}",
                    "{{ trans('datepicker.short_days.tuesday') }}",
                    "{{ trans('datepicker.short_days.wednesday') }}",
                    "{{ trans('datepicker.short_days.thursday') }}",
                    "{{ trans('datepicker.short_days.friday') }}",
                    "{{ trans('datepicker.short_days.saturday') }}"
                ],
                daysMin: [
                    "{{ trans('datepicker.min_days.sunday') }}",
                    "{{ trans('datepicker.min_days.monday') }}",
                    "{{ trans('datepicker.min_days.tuesday') }}",
                    "{{ trans('datepicker.min_days.wednesday') }}",
                    "{{ trans('datepicker.min_days.thursday') }}",
                    "{{ trans('datepicker.min_days.friday') }}",
                    "{{ trans('datepicker.min_days.saturday') }}"
                ],
                months: [
                    "{{ trans('datepicker.months.january') }}",
                    "{{ trans('datepicker.months.february') }}",
                    "{{ trans('datepicker.months.march') }}",
                    "{{ trans('datepicker.months.april') }}",
                    "{{ trans('datepicker.months.may') }}",
                    "{{ trans('datepicker.months.june') }}",
                    "{{ trans('datepicker.months.july') }}",
                    "{{ trans('datepicker.months.august') }}",
                    "{{ trans('datepicker.months.september') }}",
                    "{{ trans('datepicker.months.october') }}",
                    "{{ trans('datepicker.months.november') }}",
                    "{{ trans('datepicker.months.december') }}",
                ],
                monthsShort:  [
                    "{{ trans('datepicker.months_short.january') }}",
                    "{{ trans('datepicker.months_short.february') }}",
                    "{{ trans('datepicker.months_short.march') }}",
                    "{{ trans('datepicker.months_short.april') }}",
                    "{{ trans('datepicker.months_short.may') }}",
                    "{{ trans('datepicker.months_short.june') }}",
                    "{{ trans('datepicker.months_short.july') }}",
                    "{{ trans('datepicker.months_short.august') }}",
                    "{{ trans('datepicker.months_short.september') }}",
                    "{{ trans('datepicker.months_short.october') }}",
                    "{{ trans('datepicker.months_short.november') }}",
                    "{{ trans('datepicker.months_short.december') }}",
                ],
                today: "{{ trans('datepicker.today') }}",
                clear: "{{ trans('datepicker.clear') }}",
                format: "yyyy-mm-dd",
                weekStart: {{ $snipeSettings->week_start ?? 0 }},
            };


            var clipboard = new ClipboardJS('.js-copy-link');

            clipboard.on('success', function(e) {
                e.text = e.text.replace(/^\s/, '').trim();
                var clickedElement = $(e.trigger);
                clickedElement.tooltip('hide').attr('data-original-title', '{{ trans('general.copied') }}').tooltip('show');
            });


            // Reference: https://jqueryvalidation.org/validate/
            var validator = $('#create-form').validate({
                ignore: 'input[type=hidden]',
                errorClass: 'alert-msg',
                errorElement: 'div',
                errorPlacement: function(error, element) {

                    if ($(element).hasClass('select2') || $(element).hasClass('js-data-ajax')) {
                        // If the element is a select2 then append the error to the parent div
                        element.parent('div').append(error);

                     } else if ($(element).parent().hasClass('input-group')) {
                        var end_input_group = $(element).next('.input-group-addon').parent();
                        error.insertAfter(end_input_group);
                    } else {
                        error.insertAfter(element);
                    }

                },
                highlight: function(inputElement) {

                    // We have to go two levels up if it's an input group
                    if ($(inputElement).parent().hasClass('input-group')) {
                        $(inputElement).parent().parent().parent().addClass('has-error');
                    } else {
                        $(inputElement).parent().addClass('has-error');
                        $(inputElement).closest('.help-block').remove();
                    }

                },
                onfocusout: function(element) {
                    // We have to go two levels up if it's an input group
                    if ($(element).parent().hasClass('input-group')) {
                        $(element).parent().parent().parent().removeClass('has-error');
                        return $(element).valid();
                    } else {
                        $(element).parent().removeClass('has-error');
                        return $(element).valid();
                    }

                },

            });

            $.extend($.validator.messages, {
                required: "{{ trans('validation.generic.required') }}",
                email: "{{ trans('validation.generic.email') }}"
            });

            $.validator.addMethod('pattern', function(value, element, param) {
                if (this.optional(element)) {
                    return true;
                }
                if (typeof param === 'string') {
                    param = new RegExp('^(?:' + param + ')$');
                }
                return param.test(value);
            }, '{{ trans('validation.generic.invalid_value_in_field') }}');


            function showHideEncValue(e) {
                // Use element id to find the text element to hide / show
                var targetElement = e.id+"-to-show";
                var hiddenElement = e.id+"-to-hide";
                var audio = new Audio('{{ config('app.url') }}/sounds/lock.mp3');
                if($(e).hasClass('fa-lock')) {
                    @if ((isset($user)) && ($user->enable_sounds))
                        audio.play()
                    @endif
                    $(e).removeClass('fa-lock').addClass('fa-unlock');
                    // Show the encrypted custom value and hide the element with asterisks
                    document.getElementById(targetElement).style.fontSize = "100%";
                    document.getElementById(hiddenElement).style.display = "none";

                } else {
                    @if ((isset($user)) && ($user->enable_sounds))
                        audio.play()
                    @endif
                    $(e).removeClass('fa-unlock').addClass('fa-lock');
                    // ClipboardJS can't copy display:none elements so use a trick to hide the value
                    document.getElementById(targetElement).style.fontSize = "0px";
                    document.getElementById(hiddenElement).style.display = "";

                 }
             }




            function checkInfoSidePanel() {
                var side_panel_state = localStorage.getItem("side_panel_state");

                // Open side info panel
                if (side_panel_state == 'collapsed') {
                    collapseInfoSidePanel();

                // Collapse side info panel
                } else {
                    expandInfoSidePanel();
                }

            }

            function toggleInfoSidePanel() {
                var side_panel_state = localStorage.getItem("side_panel_state");

                if (side_panel_state == 'expanded') {
                    localStorage.setItem("side_panel_state", 'collapsed');
                } else {
                    localStorage.setItem("side_panel_state", 'expanded');
                }

                checkInfoSidePanel();
            }

            function collapseInfoSidePanel() {
                $('.side-box').removeClass('expanded').hide();
                // Pages that render the sidebar after the main panel use
                // push/pull to swap the visual order; a collapsed panel must
                // shed the push or it sits 25% off-centre with its right
                // edge past the viewport.
                $('.main-panel').each(function () {
                    var $panel = $(this);
                    if ($panel.data('was-pushed') === undefined) {
                        $panel.data('was-pushed', $panel.hasClass('col-md-push-3'));
                    }
                    $panel.removeClass('col-md-9 col-md-push-3').addClass('col-md-12');
                });
                $('.main-panel').siblings('.col-md-pull-9').addClass('hidden');
                $("#expand-info-panel-button").addClass('fa-square-caret-left').removeClass('fa-square-caret-right');
            }

            function expandInfoSidePanel() {
                $('.side-box').fadeIn("fast").addClass('expanded');
                $('.main-panel').each(function () {
                    var $panel = $(this);
                    $panel.removeClass('col-md-12').addClass('col-md-9');
                    if ($panel.data('was-pushed')) {
                        $panel.addClass('col-md-push-3');
                    }
                });
                $('.main-panel').siblings('.col-md-pull-9').removeClass('hidden');
                $("#expand-info-panel-button").addClass('fa-square-caret-right').removeClass('fa-square-caret-left');
            }


            $(document).ready(function () {
                checkInfoSidePanel();

                // Handle the info-panel
                $("#expand-info-panel-button").click(function () {
                    toggleInfoSidePanel();
                });



                // This handles the show/hide for cloned items
                $('#use_cloned_image').click(function() {
                    if ($('#use_cloned_image').is(':checked')) {
                        $('#image_delete').prop('checked', false);
                        $('#image-upload').hide();
                        $('#existing-image').show();
                    } else {
                        $('#image-upload').show();
                        $('#existing-image').hide();
                    }
                    //$('#image-upload').hide();
                });

                // Invoke Bootstrap 3's tooltip
                $('[data-tooltip="true"]').tooltip({
                    container: 'body',
                    animation: true,
                });

                $('[data-toggle="popover"]').popover();
                $('.select2 span').addClass('needsclick');
                $('.select2 span').removeAttr('title');

                // This javascript handles saving the state of the menu (expanded or not)
                $('body').bind('expanded.pushMenu', function () {
                    $.ajax({
                        type: 'GET',
                        url: "{{ route('account.menuprefs', ['state'=>'open']) }}",
                        _token: "{{ csrf_token() }}"
                    });

                });

                $('body').bind('collapsed.pushMenu', function () {
                    $.ajax({
                        type: 'GET',
                        url: "{{ route('account.menuprefs', ['state'=>'close']) }}",
                        _token: "{{ csrf_token() }}"
                    });
                });

            });

            // Initiate the ekko lightbox
            $(document).on('click', '[data-toggle="lightbox"]', function (event) {
                event.preventDefault();
                $(this).ekkoLightbox();
            });
            //This prevents multi-click checkouts for accessories, components, consumables
            $(document).ready(function () {
                $('#checkout_form').submit(function (event) {
                    event.preventDefault();
                    $('#submit_button').prop('disabled', true);
                    this.submit();
                });
            });

            // Select encrypted custom fields to hide them in the asset list
            $(document).ready(function() {
                // Selector for elements with css-padlock class
                var selector = 'td.css-padlock';

                // Function to add original value to elements
                function addValue($element) {
                    // Get original value of the element
                    var originalValue = $element.text().trim();

                    // Show asterisks only for not empty values
                    if (originalValue !== '') {
                        // This is necessary to avoid loop because value is generated dynamically
                        if (originalValue !== '' && originalValue !== asterisks) $element.attr('value', originalValue);

                        // Hide the original value and show asterisks of the same length
                        var asterisks = '*'.repeat(originalValue.length);
                        $element.text(asterisks);

                        // Add click event to show original text
                        $element.click(function() {
                            var $this = $(this);
                            if ($this.text().trim() === asterisks) {
                                $this.text($this.attr('value'));
                            } else {
                                $this.text(asterisks);
                            }
                        });
                    }
                }
                // Add value to existing elements
                $(selector).each(function() {
                    addValue($(this));
                });

                // Function to handle mutations in the DOM because content is generated dynamically
                var observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        // Check if new nodes have been inserted
                        if (mutation.type === 'childList') {
                            mutation.addedNodes.forEach(function(node) {
                                if ($(node).is(selector)) {
                                    addValue($(node));
                                } else {
                                    $(node).find(selector).each(function() {
                                        addValue($(this));
                                    });
                                }
                            });
                        }
                    });
                });

                // Configure the observer to observe changes in the DOM
                var config = { childList: true, subtree: true };
                observer.observe(document.body, config);
            });


        </script>

        @if ((session()->get('topsearch')=='true') || (request()->is('/')))
            <script nonce="{{ csrf_token() }}">
                $("#tagSearch").focus();
            </script>
        @endif

        </body>
</html>
