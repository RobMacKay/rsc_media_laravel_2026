---
paths:
  - 'public/**'
---

# Public

## Icons come from the logo's roundel, and there are three kinds
Every icon is cut from the **second path** in `components/rsc/logo.blade.php` — the roundel on its own, no wordmark. It is already a perfect 1684×1684 square at the origin, so it needs no cropping. Ink `#04121a` mark on a `#2fe6a8` tile, that way round because the reverse collapses into a blob at 16px: the ring and the bird run together.

Three shapes, and they are not interchangeable:
- `favicon.svg` / `favicon.ico` / `icon-any-*` — rounded tile (22% radius), shown as drawn.
- `apple-touch-icon.png` — **square to the edges.** iOS applies its own squircle; a pre-rounded source gets rounded twice and shows pale corners.
- `icon-maskable-*` — **square to the edges, mark at 78% of the width.** Android crops these to its own shape, and anything outside the safe circle (80% of the width) is cut off. Checked against a circle crop, not assumed.

There is no SVG rasteriser on this machine — no ImageMagick, no rsvg. `qlmanage -t -s 512 -o <dir> <file.svg>` renders one, and `sips -z <n> <n>` resizes it. `favicon.ico` has no tool at all: it is a hand-built container (6-byte header, 16-byte entries, PNG payloads) holding 16, 32 and 48.

The manifest is a **route**, not a file in `public/`, because it must arrive as `application/manifest+json` and nginx does not reliably know that extension. `WebManifestTest` checks every icon it advertises exists at the size it claims, so regenerating them badly fails a test rather than a phone.
