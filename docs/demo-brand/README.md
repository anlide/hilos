# Hilos demo marks

Research artifacts for **HIL-1316**, ready for the owner's visual review. Open
[preview.html](preview.html) directly from disk: it shows the six editable
masters at 16, 32 and 64 CSS pixels on white and dark backgrounds. No server,
JavaScript, custom fonts or network is needed for the preview or the SVGs.

The owner approved the family on 2026-10-11: keep the three blue Hilos nodes and
spokes, replace the central green node with a demo's symbol, and use that same
mark in the favicon and header. These samples propose the exact geometry for
that family; their presence here does not mean the owner has approved the
individual drawings. Production integration follows that review separately.

## The six masters

| Demo directory | Central symbol | Editable source | 16 px source |
|---|---|---|---|
| `chat` | Speech bubble with a tail | [chat.svg](sources/chat.svg) | Same master |
| `tasks` | Check mark | [tasks.svg](sources/tasks.svg) | Same master |
| `polls` | Two answer options, dots and bars | [polls.svg](sources/polls.svg) | Same master |
| `binance-btc-tracker` | Bitcoin, drawn as paths | [binance-btc-tracker.svg](sources/binance-btc-tracker.svg) | Same master |
| `ecommerce-shop` | Four-petal flower with an open center | [ecommerce-shop.svg](sources/ecommerce-shop.svg) | Same master |
| `online-testing` | Graduation cap with a tassel | [online-testing.svg](sources/online-testing.svg) | Same master |

The SVGs themselves are the authoritative sources. Edit their paths and shapes
directly or open them in a vector editor; no upstream drawing, font glyph or
image generator is needed. The optional [export.cjs](export.cjs) only rasterizes
these files. It neither constructs nor owns their geometry.

## Geometry and palette

Every master has `viewBox="0 0 32 32"`, intrinsic dimensions of 32 × 32, a
transparent background and two named groups, in paint order:

1. `hilos-network`: the shared blue nodes and spokes.
2. `demo-symbol`: the green thematic center.

Keep this coordinate system and scale the whole SVG uniformly. Do not crop the
viewBox to the painted bounds: its whitespace is part of the common alignment.
Each file also carries a title, description and provenance comment.

| Part | Value |
|---|---|
| Hilos center reference | `(16, 16)` |
| Top node | `(16, 6.5)`, radius `3` |
| Right node | `(24.2, 20.8)`, radius `3` |
| Left node | `(7.8, 20.8)`, radius `3` |
| Spoke width | `2.6` viewBox units |
| Network color | `#32C5FF` |
| Symbol color | `#00D4AA` |
| Preview backgrounds | `#FFFFFF` and `#212529` |

The reference is the unchanged
[framework favicon](../../framework/frontend/brand/favicon.svg), whose central
disk has radius `4.6` and whose round-ended spokes originally start at `(16, 16)`.
Here that disk is replaced, and the inner ends of the spokes stop at `(16, 11)`,
`(20.32, 18.53)` and `(11.68, 18.53)`, approximately five units from the center.
Their outer ends still meet the original node centers. The inner ends are flat
(`stroke-linecap="butt"`) so blue does not run through holes or gaps in a
thematic symbol. These three shortened spokes are identical across the masters;
the upstream Hilos logo is not modified.

The symbols are optically placed around `(16, 16)`, with tails, stems and the
cap extending below it. The shared outer nodes, their positions and radii must
remain visible. The marks use solid shapes and broad strokes: no chat dots,
checkbox frame, text labels inside the mark, petal veins or fine cap folds.
Bitcoin's two open counters and the flower's center are transparent cutouts
using `fill-rule="evenodd"`, not white paint. All six therefore work on either
background without changing their colors or exporting a second theme asset.

## Source provenance and license

- Hilos network geometry and palette derive from
  `framework/frontend/brand/favicon.svg` at Code Base `c7e4696e8`. The same file
  was present at the slice's starting HEAD
  `17e0b8a5045001763e31f9abfa3d532d8edf4639`.
- All six central shapes are **original path constructions for HIL-1316**.
  No third-party icon paths, fonts, embedded bitmaps or remotely referenced
  elements were copied. They are covered by the repository's
  [MIT license](../../LICENSE), including its copyright notice; carry that
  notice when redistributing the sources or substantial copies.
- The thematic references were the `navbar-brand` elements in the separate
  `hilos-ops` repository at Design Base `74ada4b`, under
  `mockups/demos/{chat,tasks,polls,binance-btc-tracker,ecommerce-shop,online-testing}/index.html`.
  Their Bootstrap Icon names are respectively `chat-dots`, `check2-square`,
  `ui-checks`, `currency-bitcoin`, `flower1` and `mortarboard`. Those are subject
  references, **not the source of these SVG paths**. In particular the shop
  mark here uses four broad petals, and the poll mark uses two answer rows.
- The owner's decisions are recorded in `hilos-ops/notes/HIL-1316.md`,
  “Согласованное решение”. `hilos-ops/design-debts/D-209-demo-hilos-brand-marks.md`
  records the six mockup headers that still use separate thematic icons.
  These ops paths identify the research record; the preview does not load them.

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

For PNG output, the recipe below was executed with:

| Tool | Version |
|---|---|
| Container | `mcr.microsoft.com/playwright:v1.60.0-noble` |
| Node.js in that container | `v24.15.0` |
| Playwright package | `1.60.0` |
| Chromium bundled with that image | `148.0.7778.96` |

Run from the repository root. Docker and the named image must be available.
The first command installs only export tooling into `/tmp`; it needs network
access on first use. Nothing is added to a demo's dependencies or build. On the
shared Hilos stand, keep the `with-stand` wrapper on both Docker commands.
Outside that stand, omit only the wrapper path and its `-t HIL-1316` arguments.

```bash
brand_output=/tmp/HIL-1316
mkdir -p "$brand_output/tools"
/home/cloud/hilos-ops/with-stand -t HIL-1316 docker run --rm \
  --user "$(id -u):$(id -g)" \
  -e npm_config_cache=/out/npm-cache \
  -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
  -v "$brand_output:/out" \
  mcr.microsoft.com/playwright:v1.60.0-noble \
  npm install --prefix /out/tools --no-save --package-lock=false playwright@1.60.0

/home/cloud/hilos-ops/with-stand -t HIL-1316 docker run --rm --network none \
  --user "$(id -u):$(id -g)" \
  -e NODE_PATH=/out/tools/node_modules \
  -v "$PWD:/hilos:ro" -v "$brand_output:/out" \
  mcr.microsoft.com/playwright:v1.60.0-noble \
  node /hilos/docs/demo-brand/export.cjs /out/png 16 32 64
```

This writes 18 transparent PNGs to `/tmp/HIL-1316/png`, named
`<demo>-<size>.png`. The script reports the actual renderer versions, validates
each PNG's dimensions, and checks that it contains painted and fully transparent
pixels. Rasterization uses a transparent canvas at the requested physical pixel
size; it adds no background, padding or resampling pass.

Replace `16 32 64` with any required integer sizes from 16 to 4096. For example,
`32 64 128` provides twice the pixels for displays at 16, 32 and 64 CSS px.
Omitting the size list defaults to `16 32 64`. Sizes below 16 are not reviewed.
PNG files are derived exports, never editable masters. ICO packaging, if a
consumer needs it, belongs to that integration; do not redraw an icon for it.

## Viewing criteria and observed limits

Open `preview.html` at **100% browser zoom**. Each card explicitly labels the
demo, symbol, background and pixel size. Images are direct relative references
to `sources/*.svg`; the preview contains no copy of their geometry. Its local
CSS formats this standalone research sheet, outside the application SDK.

The slice review on 2026-10-13 used the Chromium version above, at device scale
factor 1, with JavaScript disabled and the network unavailable. All 36 images
loaded from disk. Desktop width 1440 and narrow width 375 were inspected; the
latter stacks the two backgrounds without horizontal scrolling. Keyboard Tab
reaches the specification and source links with the browser's visible focus
outline. One `h1`, named sections, image alternatives and visible size labels
keep the sheet usable without interpreting the colors.

The 16 px PNG exports were also viewed at their actual size and enlarged with
nearest-neighbor pixels to inspect what survived rasterization. Enlargement is
diagnostic only; it is not proof of legibility at actual size.

| Mark | Observation at 16 px on both backgrounds |
|---|---|
| Chat | Solid bubble and descending tail form the broadest uninterrupted center. |
| Tasks | The diagonal check is the most open silhouette and is distinct from the filled centers. |
| Polls | Two separated rows remain; the option dots soften into the row ends. |
| Bitcoin | Narrow upright center and two counters distinguish it; the double stems are weak at this size. |
| Flower | Open center distinguishes it from the solid bubble; individual petals are much clearer at 32 px. |
| Graduation cap | Wide diamond over a lower band distinguishes it from the vertical bitcoin; the tassel is not a reliable cue at 16 px. |

In this inspection, the six centers are distinguishable side by side and the
three blue outer nodes survive in every sample. At 16 px the spokes are short
antialiased joins beside those nodes; at 32 and 64 px their direction and the
thematic shapes are clearer. Recognition of every subject without a demo name
is not established by this inspection, particularly for bitcoin and the cap.
The owner's Ready review is the decision point for these drawings.

The fixed palette is deliberately bright and therefore softer on white. Its
contrast against white is about **1.99:1** for blue and **1.91:1** for green;
against `#212529` it is **7.76:1** and **8.08:1**. These are brand marks, not
status indicators or a substitute for a control's accessible name. Retain the
visible demo name in headers and the page title in browser tabs. No dark outline
or alternate palette has been silently added to compensate.

This review does not establish rendering in Firefox, Safari, OS favicon caches,
high-contrast modes or real browser tabs at different display scales. Check
those on integration. Below 16 px, or on an arbitrary photographic background,
readability is not promised. No product runtime or frontend suite was changed;
the slice's required `composer run test:framework:unit` passed through
`with-stand` (8,765 tests, 325,581 assertions, one skip).

## Adding another demo

1. Choose one subject when that demo becomes active; do not pre-draw the rest
   of the catalog. Distinguish it by geometry, using the same two colors.
2. Copy the shared coordinate system and `hilos-network` group exactly. Replace
   only `demo-symbol`, then update its title, description and provenance.
3. Keep the nodes unobscured and the spokes visible. Prefer a broad silhouette
   to detail that disappears at 16 px. Keep holes transparent.
4. Add the master to the table, preview and export list. Review it beside all
   existing marks at 16/32/64 px in both backgrounds at 100% zoom.
5. If simplification is necessary, store `<demo>-small.svg` as another editable
   source, keep its network unchanged, and document the exact removed features
   and size threshold. Show the source actually selected at each preview size.
   Do not introduce a second, unrelated logo for the favicon.
6. Record precise upstream path/version and license for any future borrowed
   geometry; a subject or an icon name alone is not source attribution.

## Input to the later implementation

After explicit owner approval, an implementation leaf takes these six SVGs,
the mapping above and the source/export rules as its input. Its integration
points are `demo/<demo>/frontend/public/favicon.svg` for each of the six demos
and their corresponding `navbar-brand` surfaces. The shorter historical path
`frontend/public/favicon.svg` needs that demo prefix.

Use the same composed sign in the tab and header, with the same source choice
for light and dark themes. Preserve the surrounding names, links and navigation.
Resolve the six header differences tracked by D-209 using the reviewed drawings.
This research changes none of those product files, the framework Hilos favicon,
the ops mockups, routes, contracts or builds, and creates no implementation
ticket. Approval of that later work is separate from review of these samples.
