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
