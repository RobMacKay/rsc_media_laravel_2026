---
paths:
  - '.github/workflows/**'
---

# Workflows

## CI has two jobs and both have silent ways to be useless
`php-version` in `.github/workflows/tests.yml` must track what `composer.lock` needs, not what `composer.json` says. The lock outgrew the `^8.3` constraint when Symfony went to 8.1 (needs PHP 8.4.1+) and CI sat broken for days at `composer install` — invisible locally, because development is on 8.5. When the lock is updated, check the workflow still installs.

The `mysql` job needs its own Node step and `npm run build`. It does not use `composer setup`, so it does not get an asset build for free the way the `ci` job does, and without the Vite manifest every test that renders a page fails there — about 50 of them. A green `ci` with a red `mysql` full of "Vite manifest not found" means this regressed.

The job exists to catch what MySQL does differently from the SQLite the suite normally runs on (a DEFAULT on a JSON column, `ORDER BY` with `DISTINCT` — both have broken the live site). Failing for any other reason means it is not doing its job.
