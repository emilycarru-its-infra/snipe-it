# Error tracking (Sentry)

Unhandled exceptions are shipped to [Sentry](https://sentry.io) when a DSN is
configured. This is the runtime safety net in our QA system: it catches the
500s that static analysis and the route smoke-crawler can't — data-shape bugs
on real production rows, failures in POST/save paths, anything a user actually
hits — and surfaces them within seconds, with a stack trace and an occurrence
count, instead of waiting for someone to report a broken button.

It sits alongside the two pre-deploy gates:

- **PHPStan** (`composer analyse`, runs on every PR) — catches undefined
  classes/methods and type errors before merge. No data needed.
- **Route smoke-crawler** (`tests/Feature/Smoke/RouteSmokeTest.php`) — GETs
  every UI route against seeded data, asserting none 5xx.
- **Sentry** (this doc) — everything that still slips through, in production.

## Status

Wired but **inert by default**. With no DSN set, `App\Exceptions\Handler`'s
report hook is a no-op — exactly like the bundled Rollbar integration, which is
gated on `ROLLBAR_TOKEN`. Performance tracing and profiling are off; only
errors are captured, and no PII is sent (`config/sentry.php`).

Activating it takes one env var. There is no code change to make.

## Activation

### 1. Create the Sentry project

In Sentry, create a project of platform **Laravel**. Copy its DSN — it looks
like `https://<key>@o<org>.ingest.sentry.io/<project>`.

Use one project with two environments (`production`, `dev`) so issues are
tagged by origin but share a backlog. The `environment` is taken from `APP_ENV`
automatically.

### 2. Set the DSN on each App Service

The DSN is not a hard secret, but treat it like the other app settings and set
it through Terraform so a deploy doesn't wipe it.

In `infrastructure/main.tf`, add a variable:

```hcl
variable "sentry_dsn" {
  type    = string
  default = ""
}
```

Add this line to the `app_settings` map of **both** the production App Service
(around line 549) and the dev App Service (around line 664):

```hcl
"SENTRY_LARAVEL_DSN" = var.sentry_dsn
```

Supply the value in your tfvars (or the pipeline variable group):

```hcl
sentry_dsn = "https://<key>@o<org>.ingest.sentry.io/<project>"
```

Apply through the normal "Inventory - Infrastructure + Functions" pipeline.
Leaving `sentry_dsn` empty keeps Sentry disabled, so this change is safe to
land ahead of having a real DSN.

### 3. Verify

After the setting lands, restart the container and trigger a test event from
the running app:

```bash
az webapp restart --name <app-name> --resource-group <rg>
```

```bash
php artisan sentry:test
```

The event should appear in the Sentry project within a few seconds.

## Local use

Add the DSN to your local `.env` to see your own errors while developing:

```bash
SENTRY_LARAVEL_DSN=https://<key>@o<org>.ingest.sentry.io/<project>
```

Leave it blank and Sentry stays off — no network calls, no overhead.
