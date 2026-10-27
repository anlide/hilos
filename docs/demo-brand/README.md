# Hilos demo marks

Research artifacts for **HIL-1539**, the second draw of the six demo marks that
HIL-1316 first constructed, ready for the owner's visual review. Open
[preview.html](preview.html) directly from disk: it shows the six editable
masters at 16, 32 and 64 CSS pixels on white and dark backgrounds. No server,
JavaScript, custom fonts or network is needed for the preview or the SVGs.

The owner's decisions so far:

- 2026-10-11: one family. Keep the Hilos nodes, replace the central node with a
  demo's symbol, and use that same mark in the favicon and header.
- 2026-10-13: the three outer nodes are plain light-blue circles, `#32C5FF`.
  The spokes are removed entirely, not shortened.
- 2026-10-24: each demo's center has its own approved color, listed below. One
  master and one shade serve both the white `#FFFFFF` and the dark `#212529`
  background; there is no second theme asset and no alternate palette.

These samples apply those decisions to the exact geometry; their presence here
does not mean the owner has approved the individual drawings. Production
integration follows that review separately.

## The six masters

| Demo directory | Central symbol | Center color | Editable source | 16 px source |
|---|---|---|---|---|
| `chat` | Speech bubble with a tail | `#8B5CF6` violet | [chat.svg](sources/chat.svg) | Same master |
| `tasks` | Check mark | `#16A34A` green | [tasks.svg](sources/tasks.svg) | Same master |
| `polls` | Two answer options, dots and bars | `#3B82F6` blue | [polls.svg](sources/polls.svg) | Same master |
| `binance-btc-tracker` | Bitcoin, drawn as paths | `#EA580C` orange | [binance-btc-tracker.svg](sources/binance-btc-tracker.svg) | Same master |
| `ecommerce-shop` | Four-petal flower with an open center | `#DB2777` pink | [ecommerce-shop.svg](sources/ecommerce-shop.svg) | Same master |
| `online-testing` | Graduation cap with a tassel | `#B58B00` gold | [online-testing.svg](sources/online-testing.svg) | Same master |

The SVGs themselves are the authoritative sources. Edit their paths and shapes
directly or open them in a vector editor; no upstream drawing, font glyph or
image generator is needed. The optional [export.cjs](export.cjs) only rasterizes
these files. It neither constructs nor owns their geometry.

## Geometry and palette

Every master has `viewBox="0 0 32 32"`, intrinsic dimensions of 32 × 32, a
transparent background and two named groups, in paint order:

1. `hilos-network`: three light-blue circles, identical in every master and
   nothing else: no spokes, no central disk, no connecting strokes.
2. `demo-symbol`: the thematic center in that demo's own color.

Keep this coordinate system and scale the whole SVG uniformly. Do not crop the
viewBox to the painted bounds: its whitespace is part of the common alignment.
Each file also carries a title, description and provenance comment.

| Part | Value |
|---|---|
| Hilos center reference | `(16, 16)` |
| Top node | `(16, 6.5)`, radius `3` |
| Right node | `(24.2, 20.8)`, radius `3` |
| Left node | `(7.8, 20.8)`, radius `3` |
| Node color, every master | `#32C5FF` |
| Preview backgrounds | `#FFFFFF` and `#212529` |

| Demo | Center color | Where it is painted |
|---|---|---|
| `chat` | `#8B5CF6` | bubble fill |
| `tasks` | `#16A34A` | check stroke, width `3`, round caps and joins |
| `polls` | `#3B82F6` | two dots and two bars |
| `binance-btc-tracker` | `#EA580C` | bitcoin body and four stems |
| `ecommerce-shop` | `#DB2777` | four petals |
| `online-testing` | `#B58B00` | cap fill and tassel stroke, width `1.6` |

Each center color is the `fill` of the `demo-symbol` group and, where the symbol
is stroked, the same value on the stroke. A master contains no other paint, no
gradient and no opacity. The node positions and radii come from the unchanged
[framework favicon](../../framework/frontend/brand/favicon.svg); its central
disk and its spokes are not reproduced here, and the upstream Hilos logo is not
modified.

The symbols are optically placed around `(16, 16)`, with tails, stems and the
cap extending below it. The three nodes, their positions and radii must remain
visible. The marks use solid shapes and broad strokes: no chat dots, checkbox
frame, text labels inside the mark, petal veins or fine cap folds. Bitcoin's two
open counters and the flower's center are transparent cutouts using
`fill-rule="evenodd"`, not white paint. Nothing joins a symbol to the nodes.
Where a symbol reaches a node, it paints over the blue: the flower's lower
petals overlap the left and right nodes by about `0.6` units and the cap's
tassel touches the right node by about `0.1`. All six therefore work on either
background without changing their colors or exporting a second theme asset.

## Source provenance and license

- Hilos node geometry and the node color derive from
  `framework/frontend/brand/favicon.svg` at Code Base `c7e4696e8` (HIL-1316).
  The same file was present at that slice's starting HEAD
  `17e0b8a5045001763e31f9abfa3d532d8edf4639`. HIL-1539, at Code Base
  `22e977c605f9a53023f607b5b47826de04730935`, removed the spokes and recolored
  the centers; it added no geometry.
- All six central shapes are **original path constructions for HIL-1316**.
  No third-party icon paths, fonts, embedded bitmaps or remotely referenced
  elements were copied. They are covered by the repository's
  [MIT license](../../LICENSE), including its copyright notice; carry that
  notice when redistributing the sources or substantial copies.
- The thematic references were the `navbar-brand` elements in the separate
  operations repository at Design Base `74ada4b`, under
  `mockups/demos/{chat,tasks,polls,binance-btc-tracker,ecommerce-shop,online-testing}/index.html`.
  Their Bootstrap Icon names are respectively `chat-dots`, `check2-square`,
  `ui-checks`, `currency-bitcoin`, `flower1` and `mortarboard`. Those are subject
  references, **not the source of these SVG paths**. In particular the shop
  mark here uses four broad petals, and the poll mark uses two answer rows.
- The owner's decisions are recorded with the interviews of HIL-1316 and
  HIL-1539 (“Согласованное решение”). The design-debt records D-240 to D-245,
  one per demo in the order of the table above, track the six mockup headers
  (`navbar-brand` and favicon) that still show a separate thematic icon and
  wait for this review. All of these live in the operations repository and
  identify the research record; the preview does not load them.

## Source choice and export

Use `<demo>.svg` for all three reviewed sizes, including 16 px. There are no
`-small.svg` variants: the masters already omit small incidental details. The
same choice applies to a 16 CSS px display on a high-density screen; density
changes the raster pixel count, not which symbol it is.

For an SVG consumer, copy the source unchanged and set its rendered width and
height to the required equal size. For example, in a header with the demo name
next to it, an `<img>` can use `width="32" height="32" alt=""`; the adjacent name
provides the accessible label. A standalone meaningful image needs its own
descriptive `alt`. If embedding inline, namespace its title/description and
group IDs per instance to avoid duplicate IDs on the page.

For PNG output, the recipe below was executed on 2026-10-13 and again on
2026-10-27 against the second-draw masters with:

| Tool | Version |
|---|---|
| Container | `mcr.microsoft.com/playwright:v1.60.0-noble` |
| Node.js in that container | `v24.15.0` |
| Playwright package | `1.60.0` |
| Chromium bundled with that image | `148.0.7778.96` |

Run from the repository root. Docker and the named image must be available.
The first command installs only export tooling into `/tmp`; it needs network
access on first use. Nothing is added to a demo's dependencies or build.

```bash
brand_output=/tmp/HIL-1539
mkdir -p "$brand_output/tools"
docker run --rm \
  --user "$(id -u):$(id -g)" \
  -e npm_config_cache=/out/npm-cache \
  -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
  -v "$brand_output:/out" \
  mcr.microsoft.com/playwright:v1.60.0-noble \
  npm install --prefix /out/tools --no-save --package-lock=false playwright@1.60.0

docker run --rm --network none \
  --user "$(id -u):$(id -g)" \
  -e NODE_PATH=/out/tools/node_modules \
  -v "$PWD:/hilos:ro" -v "$brand_output:/out" \
  mcr.microsoft.com/playwright:v1.60.0-noble \
  node /hilos/docs/demo-brand/export.cjs /out/png 16 32 64
```

This writes 18 transparent PNGs to `/tmp/HIL-1539/png`, named
`<demo>-<size>.png`. The script reports the actual renderer versions, validates
each PNG's dimensions, and checks that it contains painted and fully transparent
pixels. Rasterization uses a transparent canvas at the requested physical pixel
size; it adds no background, padding or resampling pass. The script did not
change for the second draw: it reads the same six masters, and the 2026-10-27
run produced the 18 files at the requested sizes, each with transparent and
painted pixels. In those files the fully opaque pixels are `#32C5FF` and the
demo's center color only, apart from a few blended edge pixels where the flower
and the tassel overlap a node.

Replace `16 32 64` with any required integer sizes from 16 to 4096. For example,
`32 64 128` provides twice the pixels for displays at 16, 32 and 64 CSS px.
Omitting the size list defaults to `16 32 64`. Sizes below 16 are not reviewed.
PNG files are derived exports, never editable masters. ICO packaging, if a
consumer needs it, belongs to that integration; do not redraw an icon for it.

## Viewing criteria and observed limits

Open `preview.html` at **100% browser zoom**. Each card explicitly labels the
demo, symbol, center color, background and pixel size. Images are direct
relative references to `sources/*.svg`; the preview contains no copy of their
geometry. Its local CSS formats this standalone research sheet, outside the
application SDK.

The review of the second draw on 2026-10-27 used the Chromium version above,
at device scale factor 1, with JavaScript disabled and the network unavailable.
All 36 images loaded from disk. Desktop width 1440 and narrow width 375 were
inspected; the latter stacks the two backgrounds without horizontal scrolling.
Keyboard Tab reaches the specification link and then each master link with the
browser's visible focus outline. One `h1`, named sections, image alternatives
and visible size and color labels keep the sheet usable without interpreting
the colors.

Clips of the marks as the preview actually drew them at 16, 32 and 64 px were
then enlarged with nearest-neighbor pixels (8×, 4× and 2×) to inspect what
survived rasterization. Enlargement is diagnostic only; it is not proof of
legibility at actual size.

| Mark | Observation at 16 px on both backgrounds |
|---|---|
| Chat | Solid violet block with a one-pixel tail; the broadest uninterrupted center. |
| Tasks | A diagonal green stroke, the most open silhouette and the only center that is a line. |
| Polls | The two rows blur into a soft blue rectangle; the dots merge with the bars. Its blue is darker than the nodes', but it is the center closest to the node color. |
| Bitcoin | An upright orange block; the two counters are faint lighter pixels and the four stems are gone. |
| Flower | A pink block whose open center survives as one lighter pixel; the lower petals touch the side nodes, so the mark reads as one wide shape. |
| Graduation cap | A flat gold lozenge; the tassel merges into the right node and is not a cue. |

In this inspection the six centers are distinguishable side by side, by color
first and by silhouette second. The three nodes survive in every sample as soft
light-blue dots; without spokes nothing ties them to the center, so the mark
reads as a cluster, and on white the light blue is the softest element of it.
At 32 px every symbol reads; at 64 px the check, the counters, the petals and
the tassel are clear. Recognition of every subject without a demo name is not
established by this inspection, particularly for bitcoin and the cap, which at
16 px are told apart mainly as an upright orange shape against a flat gold one.
The owner's Ready review is the decision point for these drawings.

The contrast ratios below are sRGB arithmetic, not an observation from the
screenshots. They say nothing about whether a small shape is recognizable, and
the demos also differ by shape; they are listed so that the figures are on
record. The node blue is the owner's choice and was not altered to raise its
figure.

| Color | Against `#FFFFFF` | Against `#212529` |
|---|---|---|
| Nodes `#32C5FF` | 1.99:1 | 7.76:1 |
| Chat `#8B5CF6` | 4.23:1 | 3.64:1 |
| Tasks `#16A34A` | 3.30:1 | 4.68:1 |
| Polls `#3B82F6` | 3.68:1 | 4.19:1 |
| Bitcoin `#EA580C` | 3.56:1 | 4.33:1 |
| Flower `#DB2777` | 4.60:1 | 3.36:1 |
| Graduation cap `#B58B00` | 3.15:1 | 4.89:1 |

These are brand marks, not status indicators or a substitute for a control's
accessible name. Retain the visible demo name in headers and the page title in
browser tabs. No dark outline or alternate palette has been silently added to
compensate for the soft blue on white.

This review does not establish rendering in Firefox, Safari, OS favicon caches,
high-contrast modes or real browser tabs at different display scales. Check
those on integration. Below 16 px, or on an arbitrary photographic background,
readability is not promised. No product runtime or frontend suite was changed;
this leaf touches only `docs/demo-brand/`.

## Adding another demo

1. Choose one subject when that demo becomes active; do not pre-draw the rest
   of the catalog. Give it its own center color, distinct from the six above and
   from the node blue, and a silhouette distinct from the six existing ones.
2. Copy the shared coordinate system and `hilos-network` group exactly: three
   circles, no spokes. Replace only `demo-symbol`, then update its title,
   description and provenance.
3. Keep the nodes unobscured. Prefer a broad silhouette to detail that
   disappears at 16 px. Keep holes transparent. Use one shade for both
   backgrounds; do not introduce a second thematic tone.
4. Add the master to the table, preview and export list. Review it beside all
   existing marks at 16/32/64 px in both backgrounds at 100% zoom.
5. If simplification is necessary, store `<demo>-small.svg` as another editable
   source, keep its network unchanged, and document the exact removed features
   and size threshold. Show the source actually selected at each preview size.
   Do not introduce a second, unrelated logo for the favicon.
6. Record precise upstream path/version and license for any future borrowed
   geometry; a subject or an icon name alone is not source attribution.

## Input to the later implementation

After explicit owner approval, an implementation leaf takes as its input these
six SVGs, the table above mapping each demo to its symbol, center color and
master, and the source and export rules of this document. Its integration
points are `demo/<demo>/frontend/public/favicon.svg` for each of the six demos
and their corresponding `navbar-brand` surfaces. The shorter historical path
`frontend/public/favicon.svg` needs that demo prefix.

Use the same composed sign in the tab and header, with the same master for
light and dark themes. Preserve the surrounding names, links and navigation.
Resolve the six header differences tracked by D-240 to D-245 using the reviewed
drawings. This research changes none of those product files, the framework
Hilos favicon, the ops mockups, routes, contracts or builds, and creates no
implementation ticket. Approval of that later work is separate from review of
these samples.
