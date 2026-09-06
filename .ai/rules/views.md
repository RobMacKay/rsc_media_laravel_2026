---
paths:
  - 'resources/views/**'
---

# Views

## RSC design tokens, not Flux, for portal and marketing screens
The RSC Media screens are built from the Claude Design handoff in `design/`, not from Flux components. The palette lives in `resources/css/app.css` as `--rsc-*` custom properties (light on `:root`, dark on `.dark`), surfaced to Tailwind through `@theme inline` as `bg-ink`, `bg-panel`, `border-line`, `text-body`, `text-muted`, `text-brand`, `text-warm`, `text-accent-ink`, `font-display`, `font-mono`.

Build screens from `resources/views/components/rsc/*` (panel, pill, chip, button, field, input, meter, heading, kicker) and the layouts in `resources/views/layouts/rsc/`. Flux is still used by the inherited `settings/*` and `teams/*` pages — leave those alone.

The theme toggle writes `$flux.appearance`, which owns the `.dark` class, so both systems stay in step.

Percentage heights need a parent with a definite height — bars inside an auto-height flex column collapse to nothing.

## Name Livewire components without the ⚡ prefix
Livewire's single-file components support a `⚡` filename prefix as a visual marker, and the starter kit shipped every component that way. Do not add it back.

Files with the emoji in the name did not update on the Forge deploy: a change to `⚡home.blade.php` kept serving the old markup after a deploy that successfully applied every ASCII-named file in the same commit, and clearing the compiled views did not help. Renaming it fixed it, so the rest were renamed to match.

`Livewire\Finder` checks the plain `<name>.blade.php` path too and strips zap characters when resolving, so component names are unchanged — `pages::client.dashboard` still points at `resources/views/pages/client/dashboard.blade.php`.

Watch for one thing when naming components under `resources/views/components/`: Blade anonymous components live there too, so a Livewire component called `foo` now also answers to `<x-foo>`. Nothing uses those tags today, but a future anonymous component must not reuse a Livewire component's name.

## Timestamps are stored in UTC and shown with shown()
`config('app.timezone')` stays UTC — unambiguous, never shifts under us. `config('app.display_timezone')` (Europe/London) is what people read, and the conversion happens at the point of display through the global `shown()` helper:

    {{ shown($ticket->created_at)->format('j M, H:i') }}
    {{ shown($invoice->paid_at)?->format('j F Y') }}

`shown()` takes and returns null, so a nullable column reads the same as any other, and it copies rather than mutating — the original timestamp is untouched for anything downstream.

Use it on **timestamps** only. Date-only columns — `due_on`, `target_on`, `issued_on`, `starts_on` — are `date` casts with no time component and are already correct in any timezone; putting `shown()` round one is noise at best. `diffForHumans()` needs nothing either: it is relative, so both ends are in the same zone.

Miss it and every clock time is an hour out for half the year, and anything that happened between midnight and 1am BST is shown as the day before. There is one clock time in the whole app (the admin queue's "raised") and about fourteen dates rendered from timestamps, so the failure is quiet.

`DisplayTime::timezone()` treats a blank config value as UTC on purpose: `config()` only falls back when a key is missing, so an empty `APP_DISPLAY_TIMEZONE=` would otherwise reach `setTimezone('')` and throw.
