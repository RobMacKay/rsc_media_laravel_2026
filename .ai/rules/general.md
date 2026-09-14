---
paths:
  - '**'
---

# General

## Verify with composer test, not artisan test alone
`composer test` is the check CI actually runs: `config:clear`, then `lint:check` (Pint), `types:check` (PHPStan/Larastan), then `php artisan test`. Running only `php artisan test` passes locally while CI fails on static analysis — that happened on the invoice importer, where PHPStan caught an unchecked `fopen()` handle the whole test suite was blind to.

PHPStan needs `--memory-limit=1G` when run directly; the default 128M crashes its parallel workers.

Do not silence a PHPStan finding with `@phpstan-ignore`, a baseline entry, `assert()`, an inline `@var`, or a cast. They are usually real. `Collection::values()->all()` is inferred as `array<int, T>` rather than `list<T>` — wrap it in `array_values()` so the shape a method promises is the shape it provably returns.
