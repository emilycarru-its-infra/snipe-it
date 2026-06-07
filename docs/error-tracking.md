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

### 2. Supply the DSN

The Terraform plumbing is already in place (parent Inventory repo): a
`sentry_dsn` variable feeds `SENTRY_LARAVEL_DSN` on both App Services, and the
post-checkout hook reads a `SentryLaravelDsn` Key Vault secret into local
tfvars. Both default to empty, so Sentry stays off until you supply the value.

For dev and prod, add a `SentryDsn` variable to the `inventory-assets-keys`
pipeline variable group with the DSN. Then pass it to Terraform by adding one
line to each `terraform` step's `commandOptions` in
`pipelines/inventory-infra-deployment.yml`:

```
-var "sentry_dsn=$(SentryDsn)"
```

That line is intentionally not committed yet. An undefined `$(SentryDsn)` macro
would reach Terraform as a literal string and become an invalid DSN that breaks
Sentry initialisation — add the line only once the variable-group value exists.

For local development, add a `SentryLaravelDsn` secret to the
`assets-inventory-creds` Key Vault; the post-checkout hook writes it into
`terraform.tfvars` on your next checkout.

Apply through the normal "Inventory - Infrastructure + Functions" pipeline.

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
