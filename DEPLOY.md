# Hosted-demo deploy checklist (Forge)

First-time provisioning of the demo instance on a Laravel Forge server. Standalone box, isolated from TeamLinkt production (same Forge account, different server) so this public-LLM-spending endpoint's blast radius is contained.

## Load-bearing items (do NOT skip)

Three items that, if forgotten, produce silent-loss or cost-blowout classes of failure. Every one of these was validated against locally and MUST be validated hosted.

1. **Redis `maxmemory-policy=noeviction`.** Forge's managed Redis often ships with `allkeys-lru` — under memory pressure it silently evicts queued jobs. That's the async silent-loss door the chaos suite closed locally. On the Forge box:
   ```
   ssh forge@<server>
   sudo sed -i 's/^# maxmemory-policy .*/maxmemory-policy noeviction/' /etc/redis/redis.conf
   sudo systemctl restart redis-server
   redis-cli config get maxmemory-policy   # → "noeviction"
   ```
2. **Nginx basic-auth on `/horizon`.** `HorizonServiceProvider::gate()` returns `false` for all non-local requests, but Horizon's UI still SHOULD NOT be publicly reachable — job payloads, queue internals, PII in job serialization leaks. Add basic-auth via the Forge site's Nginx config:
   ```
   location /horizon {
       auth_basic "Horizon";
       auth_basic_user_file /etc/nginx/horizon.htpasswd;
       # ... existing Laravel forwarding ...
   }
   ```
   Create the htpasswd file (`htpasswd -c /etc/nginx/horizon.htpasswd <user>`), reload Nginx.
3. **Deploy script includes `npm run build`.** Without it, `@vite()` blade directives resolve to nonexistent bundles and every page 500s. Add to the Forge deploy script AFTER `composer install --no-dev`:
   ```
   npm ci
   npm run build
   ```

If any of the three is skipped, the demo is broken in a way that isn't obvious from the landing page rendering correctly.

## Provisioning

1. **Server** — new small droplet (2GB RAM sufficient for the ~$3 conversions we've measured). Ubuntu 22.04+, Nginx, PHP 8.4. Forge provisions this in a few clicks.
2. **Redis** — Forge's install-Redis toggle. Then the `noeviction` fix in item 1 above.
3. **PHP extensions** — `phpredis` (Forge ships it), `intl`, `mbstring`, `curl`, `zip`. Standard Laravel prerequisites.
4. **Site** — create a new site pointing at the target subdomain (e.g., `demo.migration-engine.example`). Point DNS at Forge's IP.
5. **Repository** — connect the Forge site to this repo. Set the default branch (main).
6. **SSL** — Forge's one-click Let's Encrypt.

## Environment variables (Forge site → Environment)

```
APP_NAME="Migration Engine"
APP_ENV=production
APP_DEBUG=false
APP_KEY=<generate via `php artisan key:generate --show`>
APP_URL=https://demo.migration-engine.example

LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=sqlite      # if using in-repo db, or configure MySQL if Forge added one

SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

# LOAD-BEARING API keys — copy from local .env (never in git).
ANTHROPIC_API_KEY=sk-ant-api03-…
FIRECRAWL_API_KEY=fc-…

SCRAPES_DISK=local        # single-server; storage/app is persistent per Forge box.

# LOAD-BEARING cost-safety layer. See CLAUDE.md "Hosted demo" section.
DEMO_TOKEN=<pick-something-32-chars-random>
DEMO_URL_ALLOWLIST=https://www.tbirdhoops.org/,https://www.cjfl.org/,https://www.tenacityvolleyball.com/,https://www.langdondiamonds.ca/
DEMO_DAILY_BUDGET_USD=30
DEMO_CONCURRENT_CONVERSIONS=1

# Blockfill fixture replay — MUST BE UNSET in production. Anything
# other than "1" is treated as false so leaving it blank is safe, but
# be explicit.
BLOCKFILL_FIXTURE_REPLAY=
```

The **DEMO_TOKEN** is client-visible (embedded in landing HTML). Not a real secret. Cost is bounded by DEMO_URL_ALLOWLIST + DEMO_DAILY_BUDGET_USD, not by token secrecy. If the token leaks (assume it will), the worst an attacker can do is trigger conversions of ALLOWLISTED URLs up to the daily budget, then get 429s until midnight. Bounded to $30/day.

## Deploy script

Forge's default plus the node steps:

```
cd $FORGE_SITE_PATH
git pull origin $FORGE_SITE_BRANCH

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci
npm run build

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Horizon reloads workers on new code:
php artisan horizon:terminate
```

## Horizon daemon

Site → Daemons → New daemon:
- Command: `php artisan horizon`
- User: `forge`
- Directory: `/home/forge/<site>`

Forge auto-restarts on crash. Verify running: `ps aux | grep horizon` — should see two supervisors (block-fill + default).

## Scheduler cron (for the sweeper)

Site → Scheduler → Enable. Adds `* * * * * cd /home/forge/<site> && php artisan schedule:run` to the crontab. The sweeper (`engine:reconcile-stuck-conversions`) runs every minute per `routes/console.php`. Verify by tailing `storage/logs/laravel.log` for a minute — should see "no batches to sweep" or similar every 60s.

## Post-deploy smoke test (BEFORE sharing the URL)

**Do not send the URL to anyone until this passes.**

1. Visit `https://<domain>/` — should show the landing page with tbirdhoops as the lead URL and allowlist chips.
2. Confirm token is embedded: `curl -s https://<domain>/ | grep demoToken` — should show the token value.
3. Confirm allowlist reject:
   ```
   curl -sX POST https://<domain>/api/conversions \
     -H 'Content-Type: application/json' \
     -H "X-Demo-Token: $DEMO_TOKEN" \
     -d '{"url": "https://not-in-allowlist.example/"}'
   # → 400 "This demo only converts a curated set..."
   ```
4. Confirm daily counter increments on a real conversion:
   ```
   curl -sX POST https://<domain>/api/conversions \
     -H 'Content-Type: application/json' \
     -H "X-Demo-Token: $DEMO_TOKEN" \
     -d '{"url": "https://www.tbirdhoops.org/"}'
   # → 202 with conversion_id.
   # SSH into the box:
   redis-cli -n 1 GET "laravel-database-laravel-cache-conversion:daily-spend-cents:$(date -u +%Y-%m-%d)"
   # → "400" (400 cents = $4 estimated)
   ```
5. Watch the conversion complete via the landing page. Expect ~5-8 min for tbirdhoops.
6. Visit the `/preview/conv-<id>` URL from the redirect — should render the rebuilt site.
7. Refresh mid-conversion — should NOT trigger a second conversion (dedupe hit, same conversion_id returned).
8. Try a non-allowlisted URL — should get 400.
9. Try TWO different allowlisted URLs in quick succession — second should get 409 while the first is running.

If all 9 pass, the demo is ready to share.

If any fail, DO NOT share. Diagnose:
- Landing 500 → check `npm run build` ran, check `storage/logs/laravel.log`.
- POST 503 → `DEMO_TOKEN` unset in Forge env.
- POST 500 → check Horizon is running (`sudo supervisorctl status`), check `storage/logs/horizon.log`.
- Conversion hangs at `stage: block_fill` past 15 min → check Redis noeviction (chaos test's target failure mode), check the sweeper cron is enabled.
- `/preview/conv-<id>` shows nothing → check ContentLoader can read the scrape files (permissions on `storage/app`).

## Rollback

If something's wrong after a deploy, in the Forge dashboard: Deployments → previous → Redeploy. Forge stores the last few deploys, one-click revert.
