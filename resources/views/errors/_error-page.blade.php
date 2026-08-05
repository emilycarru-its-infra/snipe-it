{{--
    Shared error page body: a quiet, serious statement — status code,
    what happened, and the one useful way forward. Theme-aware via the
    layout's tokens; no mascots, no jokes.

    Expects: $code (string), $headline (string), $message (string, may
    contain markup), $action_url (string), $action_label (string).
--}}
<div class="error-shell">
    <div class="error-card">
        <div class="error-code">{{ $code }}</div>
        <h1 class="error-headline">{{ $headline }}</h1>
        <p class="error-message">{!! $message !!}</p>
        <a href="{{ $action_url }}" class="btn btn-primary error-action">{{ $action_label }}</a>
    </div>
</div>

<style>
    /* The basic layout doesn't stamp data-theme; follow the OS directly so
       light-dark() resolves and dark-mode users get a dark page. */
    :root { color-scheme: light dark; }
    body { background: light-dark(#f4f4f4, #181818) !important; }
    .error-shell {
        min-height: 60vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px 16px;
    }
    .error-card {
        max-width: 460px;
        width: 100%;
        text-align: center;
        border: 1px solid light-dark(#e4e4e4, #3a3a3a);
        border-radius: 14px;
        background: light-dark(#ffffff, #1f1f1f);
        padding: 40px 36px 36px;
    }
    .error-code {
        font-size: 13px;
        font-weight: 700;
        letter-spacing: .14em;
        color: light-dark(#8a8a92, #9a9aa4);
        margin-bottom: 6px;
    }
    .error-headline {
        font-size: 26px;
        font-weight: 700;
        margin: 0 0 10px;
        color: light-dark(#262626, #e4e4e8);
    }
    .error-message {
        font-size: 14px;
        line-height: 1.55;
        color: light-dark(#5a5a62, #b0b0b8);
        margin: 0 0 24px;
    }
    .error-action { min-width: 180px; }
</style>
