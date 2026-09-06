---
paths:
  - 'app/Console/**'
---

# Console

## app:preflight is what proves a server is actually set up
`php artisan app:preflight` checks the things that fail **silently** in production: mail transport and from address, queue connection, failed jobs, whether a worker is alive, whether the scheduler is running, whether APP_URL will let signed links validate, private storage, and Turnstile keys. FAIL exits non-zero, so it can gate a deploy.

The worker check dispatches `App\Jobs\PreflightPing` and waits for it to write a cache key. That is deliberate: counting pending jobs cannot tell a busy queue from a dead one, and every email this app sends is queued, so "no worker" and "working fine" look identical from the outside. `--skip-probe` skips it, `--wait=` changes the patience.

The scheduler check reads `scheduler.last_run`, a cache key written every minute by the first entry in `routes/console.php`. It exists only for this check — every other scheduled task just queues work, so nothing else proves the scheduler is alive. Do not remove it, and note it needs a persistent cache store (`CACHE_STORE=array` would make it always look stopped).

Severity follows the environment: a `log` mailer or a `sync` queue is a warning on a laptop and a failure on the live site.
