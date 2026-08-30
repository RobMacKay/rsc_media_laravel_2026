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
