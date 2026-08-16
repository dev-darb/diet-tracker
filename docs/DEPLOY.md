# Deploying to Laravel Cloud (private alpha)

Goal: get the current app (Milestones 0–2: auth, onboarding, Scan, Pantry) live on a URL you can open in Safari on your iPhone. As more milestones are pushed to the deploy branch, Laravel Cloud re-deploys automatically.

**Deploy branch:** `claude/app-planning-breakdown-unjb8x` (all work lives here; there is no populated `main` yet).

---

## Part A — your steps (account + connect + deploy)

You do these once; I keep the repo deploy-ready.

1. **Create an account** at **https://cloud.laravel.com** and connect your **GitHub** (authorize access to `dev-darb/diet-tracker`).
2. **Create an application** → pick the repo `dev-darb/diet-tracker` → set the **branch** to `claude/app-planning-breakdown-unjb8x`. Leave auto-deploy ON so future pushes redeploy.
3. **Provision a database**: add a **PostgreSQL** database in the app's environment. Laravel Cloud injects `DB_*` automatically — you don't set those by hand.
4. **Provision Redis** (KeyDB): add it in the environment. It injects `REDIS_*` automatically. (We use it for session/cache/queue.)
5. **Set environment variables** (see Part B). Most importantly `APP_KEY`, `APP_ENV`, `APP_URL`.
6. **Deploy.** Laravel Cloud runs the build + release commands (Part C) itself.
7. **Make yourself admin** (to reach the internal `/admin` console): open the app's **Console/Commands** tab in Laravel Cloud and run:
   ```
   php artisan app:make-admin ddarbar50@googlemail.com
   ```
8. Open the app URL on your phone → **Register** → complete onboarding → try **Scan** (barcode works now; photo→AI needs the OpenRouter key, Part D).

Tell me your app URL once it's up and I'll smoke-test it and help fix anything.

---

## Part B — environment variables to set

Laravel Cloud auto-injects `DB_*` and `REDIS_*` from the resources you provision, so you only set these:

| Key | Value | Notes |
|---|---|---|
| `APP_NAME` | `foody` | |
| `APP_ENV` | `production` | |
| `APP_KEY` | *(generate)* | Click "generate" in Cloud, or run `php artisan key:generate --show` in the console and paste it. Required. |
| `APP_DEBUG` | `false` | Never `true` in production. |
| `APP_URL` | `https://foody.gg` | The custom domain (Environment -> Domains); the *.laravel.cloud URL keeps working. |
| `SESSION_DRIVER` | `redis` | |
| `CACHE_STORE` | `redis` | |
| `QUEUE_CONNECTION` | `redis` | The pipelined scanner and background AI run as queued jobs; add the worker in Part B2 for instant results. Without a worker the scan page self-heals: its poll processes any capture stranded in-flight for ~20s inline (slower, never stuck). `sync` also works — jobs then run inside the capture request itself. |
| `DB_CONNECTION` | `pgsql` | `DB_*` host/user/pass come from the provisioned Postgres automatically. |
| `FILESYSTEM_DISK` | `public` | **Caveat:** uploaded Scan photos go to local disk and may not survive a redeploy. Fine for early alpha; we switch to S3-compatible storage in hardening (brief §21 Q43). The barcode path stores no image. |
| `OFF_BASE_URL` | `https://world.openfoodfacts.org` | Default; keyless. |
| `OFF_USER_AGENT` | `DietTracker/0.1 (alpha; contact dev-darb)` | OFF requires a descriptive UA. |
| `MAIL_MAILER` | `log` | Password-reset emails are only logged until a real mailer is set (see note). |
| `AI_GATEWAY` | `openrouter` | Selects the AI gateway all capabilities use: `openrouter` (default) or `vercel` (Part D). |
| `OPENROUTER_API_KEY` | *(optional, later)* | Live photo identification when `AI_GATEWAY=openrouter` (Part D). |
| `AI_GATEWAY_API_KEY` | *(optional, later)* | Live photo identification when `AI_GATEWAY=vercel` (Vercel AI Gateway, Part D). |
| `AI_PRODUCT_IDENTIFIER_MODEL` | `openai/gpt-4o-mini` | Optional; swap to benchmark models (brief §14). Same `creator/model` format on both gateways. |

---

## Part B2 — the queue worker (one-time, ~3 minutes)

The scanner analyses photos **in the background** ("the interface should never
make the user wait for the AI"): every shutter press queues a
`ProcessScanCapture` job and the app polls for the result. The resident chef
and diet insights queue work the same way. That needs one worker process.

**1. Confirm the queue connection.** Under **Environment → Variables**, check:

```
QUEUE_CONNECTION=redis
```

Laravel Cloud injects the `REDIS_*` credentials from the provisioned
Valkey/Redis instance automatically — you do not set a host or password.

**2. Add the worker.** Open **Environment → Workers** (older UIs: **Compute →
add a worker**) and create one with:

| Field | Value |
| --- | --- |
| Command | `php artisan queue:work --queue=default --tries=2 --timeout=200 --max-jobs=250 --max-time=3600` |
| Connection / queue | `redis` / `default` |
| Size | The smallest available — one worker drains the alpha comfortably |
| Processes | 1 |

Why those flags: `--tries=2` matches the job's own retry budget (a failed
identification settles the capture honestly rather than spinning);
`--timeout=200` sits above the job's 180s ceiling for a vision call plus an
Open Food Facts import; `--max-jobs`/`--max-time` recycle the process
periodically so a long-lived worker never accumulates memory.

**3. Deploy.** The worker starts with the deployment. Nothing else changes.

**4. Verify.** Either scan something (results should land in a second or two
while the shutter stays live), or open **Console** and run:

```
php artisan queue:monitor redis:default --max=25
```

A healthy queue reports a near-zero backlog. To confirm the worker is the one
doing the work rather than the fallback, check the application log: the
rescue path writes `Scan captures rescued inline — is the queue worker
running?` every time it has to step in. With a healthy worker that line never
appears.

### Retry window (important)

`retry_after` on the redis connection **must stay above the longest job
timeout** — it is set to 210s in `config/queue.php` against
`ProcessScanCapture`'s 180s ceiling. At Laravel's stock 90s the queue would
hand a still-running scan to a second worker, paying for the same AI
identification twice. If you ever raise a job's timeout, raise
`REDIS_QUEUE_RETRY_AFTER` past it in the same change.

### If the worker is down

Nothing stays stuck. The scan page's poll detects any capture stranded
in-flight beyond ~20 seconds and runs the same pipeline inline
(`ScanCaptureService::rescueStale`), so results still land — just later, and
with that warning in the log. A session with `QUEUE_CONNECTION=sync` (e.g.
local dev without a worker) runs jobs inline at capture time instead.

---


**Mail note:** with `MAIL_MAILER=log`, "forgot password" links are written to logs, not delivered. For real reset emails, add a mail provider (Resend/Postmark/Mailgun) and set `MAIL_*`. Not required to test the core loop.

---

## Part C — build & release commands

Laravel Cloud auto-detects Laravel and normally runs these. If you set them manually:

- **Build:** `composer install --no-dev --optimize-autoloader && npm ci && npm run build`
- **Release/deploy:** `php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache`

---

## Part D — AI gateway key (optional, for live photo identification)

Barcode scanning + manual add work with **no key**. The AI gateway is env-selectable
via a single switch, `AI_GATEWAY` (default `openrouter`); both gateways are
OpenAI-style and share the same `creator/model` id format, so only the key differs.

**Option 1 — OpenRouter (default, `AI_GATEWAY=openrouter`):**

1. Get a key at **https://openrouter.ai** → Keys → Create Key (`sk-or-...`), add a little credit.
2. Set `OPENROUTER_API_KEY` in Laravel Cloud env vars → redeploy (or just save; Cloud restarts).

**Option 2 — Vercel AI Gateway (`AI_GATEWAY=vercel`):**

1. Create an API key in the **Vercel AI Gateway** dashboard.
2. Set `AI_GATEWAY=vercel` and `AI_GATEWAY_API_KEY` in Laravel Cloud env vars → redeploy.
   (Requests then go to `https://ai-gateway.vercel.sh/v1`.)

Either way, (optional) set `AI_PRODUCT_IDENTIFIER_MODEL` to try different models.

With **no** key set for the selected gateway, the photo path degrades gracefully to
"scan the barcode or add manually" and weekly insights fall back to the deterministic
rule-based generator — never an error.

---

## What I (Claude) keep responsible for

- Repo stays deploy-ready on the branch above; every green milestone is pushed and auto-deploys.
- After you share the URL: smoke-test the live app, help resolve any deploy/runtime issues, and wire S3 image storage + a queue worker when the milestones that need them land.
