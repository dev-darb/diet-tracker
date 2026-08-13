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
| `APP_NAME` | `Pantry & Nutrition` | |
| `APP_ENV` | `production` | |
| `APP_KEY` | *(generate)* | Click "generate" in Cloud, or run `php artisan key:generate --show` in the console and paste it. Required. |
| `APP_DEBUG` | `false` | Never `true` in production. |
| `APP_URL` | `https://<your-app>.laravel.cloud` | Your assigned URL. |
| `SESSION_DRIVER` | `redis` | |
| `CACHE_STORE` | `redis` | |
| `QUEUE_CONNECTION` | `redis` | No worker needed yet (M0–2 have no queued jobs); a worker gets added when M3/M7 land. |
| `DB_CONNECTION` | `pgsql` | `DB_*` host/user/pass come from the provisioned Postgres automatically. |
| `FILESYSTEM_DISK` | `public` | **Caveat:** uploaded Scan photos go to local disk and may not survive a redeploy. Fine for early alpha; we switch to S3-compatible storage in hardening (brief §21 Q43). The barcode path stores no image. |
| `OFF_BASE_URL` | `https://world.openfoodfacts.org` | Default; keyless. |
| `OFF_USER_AGENT` | `DietTracker/0.1 (alpha; contact dev-darb)` | OFF requires a descriptive UA. |
| `MAIL_MAILER` | `log` | Password-reset emails are only logged until a real mailer is set (see note). |
| `OPENROUTER_API_KEY` | *(optional, later)* | Enables live photo identification (Part D). |
| `AI_PRODUCT_IDENTIFIER_MODEL` | `openai/gpt-4o-mini` | Optional; swap to benchmark models (brief §14). |

**Mail note:** with `MAIL_MAILER=log`, "forgot password" links are written to logs, not delivered. For real reset emails, add a mail provider (Resend/Postmark/Mailgun) and set `MAIL_*`. Not required to test the core loop.

---

## Part C — build & release commands

Laravel Cloud auto-detects Laravel and normally runs these. If you set them manually:

- **Build:** `composer install --no-dev --optimize-autoloader && npm ci && npm run build`
- **Release/deploy:** `php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache`

---

## Part D — OpenRouter key (optional, for live photo identification)

Barcode scanning + manual add work with **no key**. To enable photo→AI identification:

1. Get a key at **https://openrouter.ai** → Keys → Create Key (`sk-or-...`), add a little credit.
2. Set `OPENROUTER_API_KEY` in Laravel Cloud env vars → redeploy (or just save; Cloud restarts).
3. (Optional) set `AI_PRODUCT_IDENTIFIER_MODEL` to try different models.

Without it, the photo path degrades gracefully to "scan the barcode or add manually" — never an error.

---

## What I (Claude) keep responsible for

- Repo stays deploy-ready on the branch above; every green milestone is pushed and auto-deploys.
- After you share the URL: smoke-test the live app, help resolve any deploy/runtime issues, and wire S3 image storage + a queue worker when the milestones that need them land.
