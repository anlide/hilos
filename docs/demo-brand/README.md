# Hilos marks

Research artifacts for **HIL-2151**, the new system of Hilos marks: the Hilos
logo in three variants, the ladder that picks a variant by its size on screen,
the corner mark that puts the logo into a demo's sign, the signs of the twelve
demos with a frontend, and the palette. Open [preview.html](preview.html)
directly from disk: it shows the three logo files and the logo at 16, 32, 64 and
180 CSS pixels, then every demo sign at the same four sizes, on white and dark
backgrounds, each size labeled with the file it uses, and the phone
home-screen tile of each. No server, JavaScript, custom fonts or network is
needed for the preview or the SVGs.

The owner's decisions for this system:

- The logo is a blue circle with one yellow lightning bolt. The bolt is the
  classic one, as on a usual lightning icon: sharp ends at the top and the
  bottom, a medium slant, a filled silhouette, two bends. The stepped bolt with
  two ledges is rejected, and so is any pair of equal parallel bolts.
- The ladder simplifies the bolt as the logo gets smaller: two bends, then one
  bend, then a thin zigzag line, then a plain dot without a bolt.
- The circle is `#0288D1` and the bolt `#FFD500`. The former node blue
  `#32C5FF` leaves the system.
- A demo's sign carries the logo in miniature as a mark in its top-right
  corner, separated from the demo's symbol by a transparent gap.
- A demo's symbol is free in shape and color, several colors included. The one
  requirement: the sign reads from a single file on a light and on a dark
  background, with no switch by theme. The right upper quarter belongs to the
  mark. The limits of HIL-1539 — one color, one fill, a shared palette — no
  longer apply.

The six HIL-1539 masters, `sources/<demo>.svg`, are removed: the demo signs
below replace them. [export.cjs](export.cjs) rasterizes the logo and the signs
into the files a page links to; see "Files for integration" and "Source choice
and export".

## The ladder and its boundaries

One ladder serves the logo and the corner mark alike. A variant is chosen by
the diameter of the blue circle as displayed, in CSS pixels. For the logo that
diameter is the logo's own size, since its circle fills the whole viewBox; for
the mark it is the mark's diameter inside a demo sign (next section). The rule
is "if it fits": a variant is used down to the smallest diameter at which its
bolt still reads as a bolt and not as a smudge, and the next simpler variant
takes over below that.

| Circle diameter, CSS px | Variant | Bolt | Logo source |
|---|---|---|---|
| 48 and up | large | Filled, two bends | [hilos-large.svg](sources/hilos-large.svg) |
| 20 to below 48 | medium | Filled, one bend | [hilos-medium.svg](sources/hilos-medium.svg) |
| 10 to below 20 | small | Thin zigzag line | [hilos-small.svg](sources/hilos-small.svg) |
| Below 10 | dot | None | The `hilos-circle` element of any variant, alone |

| Surface | Displayed size | Logo source |
|---|---|---|
| Browser tab | 16 px | `hilos-small.svg` |
| Header | 32 px | `hilos-medium.svg` |
| Larger brand surfaces | 64 px and up | `hilos-large.svg` |
| Phone home-screen tile | 180 px | `hilos-large.svg` |
| Web app manifest | 192 and 512 px | `hilos-large.svg` |

The same choice applies on a high-density screen: a 16 CSS px tab icon is
`hilos-small.svg` whatever the device pixel ratio. Density changes the raster
pixel count, not which variant it is.

The boundaries come from rasterizing the three files at 8, 10, 12, 14, 16, 18,
20, 24, 32, 48, 64 and 180 px on `#FFFFFF` and `#212529` and enlarging the
small ones with nearest-neighbor pixels:

- The two-bend bolt reads at 32 px and up. At 24 its horizontal step between
  the two halves is a pixel or two, and at 20 and below the step closes and the
  bolt turns into a cross-shaped smudge. From 20 to 32 the one-bend bolt is the
  bolder and clearer of the two, so the large variant starts at 48, where the
  step is several pixels wide.
- The one-bend bolt is a broad upper body with one notch and a narrow lower
  point. It reads from 20 to 32 px; at 16 the notch closes and the body is a
  yellow blot.
- The zigzag line reads at 16 and 12 px. At 10 it is at its limit, a line of
  about one pixel with its corners merged; at 8 it is a smudge.
- Below 10 px no bolt fits, and the circle stands alone.

### Logo geometry

Every logo file has `viewBox="0 0 32 32"`, intrinsic dimensions of 32 × 32, a
transparent background, a title, a description and a provenance comment. It
contains exactly two elements, in paint order:

| Element | Value |
|---|---|
| `hilos-circle` | Circle at `(16, 16)`, radius `16`, fill `#0288D1`; it fills the viewBox edge to edge |
| `hilos-bolt` in `hilos-large.svg` | `M19.5 4L9.5 17.5H15.2L12.5 28L22.5 14.5H16.8Z`, fill `#FFD500` |
| `hilos-bolt` in `hilos-medium.svg` | `M19.5 4L9 18.5H15.5L12.5 28L22.5 13.5Z`, fill `#FFD500` |
| `hilos-bolt` in `hilos-small.svg` | `M19.5 5.5L11.5 16H20.5L12.5 26.5`, no fill, stroke `#FFD500`, width `3`, miter joins, butt ends |

The two filled bolts share their tips, `(19.5, 4)` and `(12.5, 28)`, and their
slant. The two-bend bolt is point-symmetric about `(16, 16)`: its upper and
lower halves are offset by a horizontal step of `5.7` on each side. The
one-bend bolt keeps that step on the left side only, at `y = 18.5`, and joins
its right side in one corner at `(22.5, 13.5)`. The zigzag follows the same
path as the two-bend bolt — down to the left, across to the right, down to the
left — and is point-symmetric about `(16, 16)` too. Nothing else is painted:
no gradient, no opacity, no outline.

These three files are original constructions for HIL-2151 under the
repository's [MIT license](../../LICENSE). No third-party icon paths, fonts,
embedded bitmaps or remote references were used.

## Mark construction and gap

The mark is the logo in miniature — the blue circle and the yellow bolt, one
construction — placed in the top-right corner of a demo's sign. A sign is a
file of its own, `sources/<demo>-{large,medium,small}.svg`, with
`viewBox="0 0 32 32"` like the logo, and the right upper quarter of that
viewBox, `x` 16 to 32 and `y` 0 to 16, belongs to the mark. Around the mark
runs a transparent gap: the demo's symbol is cut away by a mask, not covered
by a white ring, so the gap shows whatever background the sign is on, light or
dark. Without the gap, at 16 px the mark merges with the symbol.

All numbers are in units of the 32 × 32 viewBox and are the same in all three
sign files:

| Part | Value |
|---|---|
| Mark circle | Center `(25, 7)`, radius `7`, diameter `14`, fill `#0288D1` |
| Gap | Width `2` around the mark circle |
| Cut-out circle of the mask | Center `(25, 7)`, radius `9`; it touches `x = 16` and `y = 16`, the inner edges of the quarter |
| Logo-to-mark transform | `matrix(0.4375 0 0 0.4375 18 0)`: scale `7/16` and shift by `(18, 0)` map the logo circle `(16, 16)`, radius `16`, onto the mark circle |
| Mark circle on screen | `14/32` of the sign's displayed size: 7 px at 16, 14 px at 32, 28 px at 64, 78.75 px at 180 |
| Gap on screen | `2/32` of the sign's size: 1 px at 16, 2 px at 32, 4 px at 64 |

The demo's symbol keeps out of the quarter as far as it can; the mask
guarantees the gap wherever it does reach in. The mask is the only place where
white and black appear in a sign file, and they are mask luminance, not paint.
Paint order: the masked symbol group first, the mark group last. In markup:

```svg
<mask id="hilos-mark-gap" maskUnits="userSpaceOnUse" x="0" y="0" width="32" height="32">
  <rect width="32" height="32" fill="#fff"/>
  <circle cx="25" cy="7" r="9" fill="#000"/>
</mask>
<g id="demo-symbol" fill="…" mask="url(#hilos-mark-gap)">
  <!-- the demo's symbol -->
</g>
<g id="hilos-mark">
  <g transform="matrix(0.4375 0 0 0.4375 18 0)">
    <circle cx="16" cy="16" r="16" fill="#0288D1"/>
    <!-- the hilos-bolt path of the chosen logo variant, copied unchanged -->
  </g>
</g>
```

Every `id` is unique within its file. In a sign whose mark is a dot, the
`hilos-mark` group holds only `<circle cx="25" cy="7" r="7" fill="#0288D1"/>`,
without the transform. If a sign is embedded inline, namespace its ids per
instance as for any inline SVG.

Each sign file shows its mark at a range of sizes, but one file carries one
bolt. The bolt is the one the ladder gives at the **smallest** size the file is
used at: a simpler bolt stays legible when it is displayed larger than its
range, while a richer bolt turns into a smudge below its range. The sign
files' own size ranges are chosen so that this smallest mark fits its bolt:

| Sign file | Surfaces | Sign size, CSS px | Mark diameter, CSS px | Bolt in the mark |
|---|---|---|---|---|
| `<demo>-small.svg` | Browser tab, 16 | Below 24 | 7 at 16; under 10.5 in the whole range | None: a dot |
| `<demo>-medium.svg` | Header, 32 | 24 to below 48 | 10.5 at 24, 14 at 32, up to 21 | Thin zigzag line, the bolt of `hilos-small.svg` |
| `<demo>-large.svg` | 64 and up: phone tile 180, manifest 192 and 512 | 48 and up | 21 at 48, 28 at 64, 78.75 at 180, 224 at 512 | One bend, the bolt of `hilos-medium.svg` |

So `<demo>-large.svg` carries the one-bend bolt even at 180 and 512 px, where
the mark alone would qualify for two bends; no sign carries the two-bend bolt.
The construction was checked with a sample sign — a plain square symbol in all
three files — rasterized at 16, 24, 32, 48, 64 and 180 px on both backgrounds:
at 16 the gap keeps the dot apart from the symbol, which it does not without
the mask; at 32 the zigzag reads; at 64 and 180 the one-bend bolt reads.

## Demo signs

Each of the twelve demos that has a frontend has three sign files,
`sources/<demo>-{large,medium,small}.svg`, built as in "Mark construction and
gap": the demo's symbol in the group `demo-symbol` under the mask, and the mark
of that file's row in the sign-file table. The subject of each symbol is the
icon the demo shows in its header today. Icon names are those of Bootstrap
Icons 1.13.2; "Source provenance and license" gives the file of each.

| Demo | Subject | Symbol in the three files | Color | Files |
|---|---|---|---|---|
| `binance-btc-tracker` | Bitcoin sign: the header icon `currency-bitcoin`, also the demo's favicon | `currency-bitcoin` in all three | `#F76707` | [large](sources/binance-btc-tracker-large.svg) · [medium](sources/binance-btc-tracker-medium.svg) · [small](sources/binance-btc-tracker-small.svg) |
| `chat` | Speech bubble: the header icon `chat-dots` | Large `chat-dots`; medium `chat-dots-fill`; small the HIL-1316 bubble without dots | `#845EF7` | [large](sources/chat-large.svg) · [medium](sources/chat-medium.svg) · [small](sources/chat-small.svg) |
| `concert-tickets` | Ticket: the header icon `ticket-perforated-fill`, also the favicon | Large and medium `ticket-perforated-fill`; small `ticket-fill` | `#E03131` | [large](sources/concert-tickets-large.svg) · [medium](sources/concert-tickets-medium.svg) · [small](sources/concert-tickets-small.svg) |
| `ecommerce-shop` | Flower: the header icon `flower1` | Large and medium `flower1`; small an original five-petal flower | `#E64980` | [large](sources/ecommerce-shop-large.svg) · [medium](sources/ecommerce-shop-medium.svg) · [small](sources/ecommerce-shop-small.svg) |
| `file-gallery-manager` | Pictures: the header icon `images` | Large and medium `images`; small `image-fill` | `#0CA678` | [large](sources/file-gallery-manager-large.svg) · [medium](sources/file-gallery-manager-medium.svg) · [small](sources/file-gallery-manager-small.svg) |
| `online-testing` | Graduation cap: the header icon `mortarboard` | Large `mortarboard`; medium and small `mortarboard-fill` | `#B58B00` | [large](sources/online-testing-large.svg) · [medium](sources/online-testing-medium.svg) · [small](sources/online-testing-small.svg) |
| `pmd` | Letter P: the header shows the name as text, so the symbol is the P of the demo's favicon | The favicon's rounded square and letter in all three | Square `#A9682F`, letter `#FFFFFF` | [large](sources/pmd-large.svg) · [medium](sources/pmd-medium.svg) · [small](sources/pmd-small.svg) |
| `polls` | Bar chart: the header icon `bar-chart`, mirrored | Large `bar-chart`; medium and small `bar-chart-fill` | `#AE3EC9` | [large](sources/polls-large.svg) · [medium](sources/polls-medium.svg) · [small](sources/polls-small.svg) |
| `preferans` | Spade: the header icon `suit-spade-fill`, also the favicon | `suit-spade-fill` in all three | `#868E96` | [large](sources/preferans-large.svg) · [medium](sources/preferans-medium.svg) · [small](sources/preferans-small.svg) |
| `roblox-game-server` | Game controller: the header icon `controller`, also the favicon | Large `controller`; medium and small its outer contour filled, with the cross and the buttons cut out | `#5C940D` | [large](sources/roblox-game-server-large.svg) · [medium](sources/roblox-game-server-medium.svg) · [small](sources/roblox-game-server-small.svg) |
| `solar-system-model` | Sun: the header icon `sun-fill` | Large and medium `sun-fill`; small an original sun, a disk and eight thick rays | `#E37400` | [large](sources/solar-system-model-large.svg) · [medium](sources/solar-system-model-medium.svg) · [small](sources/solar-system-model-small.svg) |
| `tasks` | Check box: the header icon `check2-square` | Large `check2-square`; medium and small `check-square-fill` | `#2F9E44` | [large](sources/tasks-large.svg) · [medium](sources/tasks-medium.svg) · [small](sources/tasks-small.svg) |

### Where the three files differ

One subject, three levels of detail, in the spirit of the ladder:

- `<demo>-large.svg` keeps the header icon as it is drawn, outline where the
  header has an outline. At 64 px and up its lines are four pixels wide.
- `<demo>-medium.svg` takes the filled form of the same icon where the outline
  is thinner than two pixels at 32 px and turns pale: chat, online-testing,
  polls, roblox-game-server and tasks. The ticket's perforation, the flower's
  petals, the pictures, the sun's rays, the spade, the bitcoin sign and the P
  read at 32 as they are, so medium equals large there.
- `<demo>-small.svg` drops what is below a pixel at 16 px: the dots of the chat
  bubble, the perforation of the ticket, the second picture of the gallery, the
  twelve thin petals of the flower and the thin rays of the sun. The flower and
  the sun are redrawn with fewer and bolder parts; the chat bubble is the
  rounded rectangle of HIL-1316, which reads at 16 better than the round
  bubble with the mask's bite out of it.

### Viewing criteria and observed limits

The exports of the 2026-11-19 run ("Source choice and export") were viewed on
`#FFFFFF` and on `#212529`, at their own pixels and enlarged with
nearest-neighbor pixels: each file at the size it serves — `-small` at 16,
`-medium` at 32, `-large` at 64 and 180 — and the logo at the same sizes. The
criteria: the symbol is recognizable as its subject; the gap keeps the mark
apart from the symbol; the mark carries the bolt of its row in the sign-file
table; and the symbol neither disappears on the dark background nor needs a
second file for it.

- **Logo.** At 16 the zigzag of `hilos-small.svg` reads as a bolt, a soft line
  about two pixels wide. At 32 the one-bend bolt reads clearly; at 64 and 180
  the two-bend bolt is sharp.
- **16, `-small`.** The bitcoin sign, the ticket, the letter P, the polls bars,
  the spade, the sun with its disk and rays and the check box read clearly. At
  their limit, readable by outline rather than by detail: the chat bubble is a
  plain purple shape with its tail, the flower a pink blot with five lobes, the
  mortarboard a cap and a tassel, the gallery picture a green frame with the
  mountain half closed, and the controller a green body whose cross and buttons
  are barely visible. The dot mark stands apart from every symbol on both
  backgrounds: the one-pixel gap holds.
- **32, `-medium`.** All twelve symbols read, and so does the zigzag in the
  mark. The flower's twelve petals and the ticket's perforation are the finest
  detail at this size and still read.
- **64 and 180, `-large`.** All twelve read, the outline icons included: their
  lines are four pixels wide at 64. The one-bend bolt in the mark reads at 64
  and is sharp at 180.
- **Phone tiles.** On the white 180 × 180 tiles every symbol and mark stays
  clear of the corners, checked with a 40-pixel rounded mask laid over the PNG.
  The light symbols — the spade `#868E96`, the mortarboard `#B58B00` — keep
  their contrast of 3:1 and more against the white tile.
- **ICO.** Both images decode: the 16 × 16 image is `-small` at 16, and the
  32 × 32 one is the same small file at 32, with a dot for the mark, as the
  ladder gives for a 16 CSS px tab on a 2× screen.

What only a device shows, the owner checks when the ticket is ready: the tab in
Chromium and Firefox, light and dark, at 100% zoom and on a 2× screen, and the
home-screen icon on iOS and Android. At 16 the controller, the gallery and the
flower are the signs to look at first: they are at the limit above.

### Placement

A Bootstrap icon is drawn on a 16 × 16 grid. A sign places it with
`matrix(1.75 0 0 1.75 0 4)`: 28 units, flush with the left and bottom edges of
the viewBox, so a full-size icon covers `x` 0 to 28 and `y` 4 to 32. Whatever
reaches into the mark's quarter is cut away by the mask. The departures, all in
viewBox units:

- `binance-btc-tracker`: `matrix(1.875 0 0 1.875 -2 3)`. The bitcoin sign is
  narrow, so it is drawn larger.
- `concert-tickets`: `matrix(1.75 0 0 1.75 0 8)`. The ticket is wide and low;
  set lower, it keeps more of its right corner out of the cut-out.
- `polls`: `matrix(-1.75 0 0 1.75 28 4)`, mirrored. In the header's order the
  tallest bar stands under the mark, and the cut-out took its top, so the bar
  read as a "U"; mirrored, the shortest bar stands there and keeps its whole
  shape.
- `roblox-game-server` medium and small: `matrix(1.75 0 0 1.75 0 6)`, lower for
  the same reason as the ticket.
- `pmd`: the rounded square is `24 × 24` at `(0, 8)`, radius `4.5`, and the
  favicon's letter path is placed with `matrix(0.75 0 0 0.75 0 8)`.
- `chat` small: the HIL-1316 bubble with `matrix(2.4 0 0 2.4 -26.4 -21.8)`,
  `x` 0 to 24, `y` 5.8 to 31.
- `ecommerce-shop` small: five circles of radius `5` whose centers lie at
  distance `7` from `(12, 20)`, the first straight up.
- `solar-system-model` small: a disk of radius `6` at `(12, 19)` and eight rays
  from radius `8.5` to `11`, stroke width `2.5`, round caps.

The symbols use one color each, except `pmd`, whose letter is white on its
colored square. The colors are chosen so that the symbol's fill reads on both
backgrounds (next section) and none is a blue near the mark's `#0288D1`; the
two former blues, `#0d6efd` of the `pmd` and `roblox-game-server` favicons, are
replaced.

## Adding another demo

Eight demos have no frontend yet: `dkp-system`, `hilos-website`,
`mail-monitor`, `personal-homepage`, `private-clinic`, `research-lab`,
`small-airport` and `traivan-map-analyzer`. A demo gets its sign when its
frontend comes to life, and any further demo the same way:

1. Pick the subject: by default the icon the demo shows in its header. Shape
   and colors are free, several colors included; nothing ties the symbol to the
   other demos' symbols or to a shared palette.
2. Meet the one requirement: a single file reads on `#FFFFFF` and on `#212529`,
   with no switch by theme. Aim the symbol's main fill at 3:1 on both (a
   relative luminance of about 0.15 to 0.30); where it does not get there, say
   so in "Palette and contrast" with what carries it instead, such as a second
   color or an outline. Keep away from blues near the mark's `#0288D1`.
3. Leave the right upper quarter to the mark: build the file exactly as in
   "Mark construction and gap" — the mask, the `demo-symbol` group first, the
   `hilos-mark` group last. The symbol may reach into the quarter; the mask cuts
   the gap.
4. Draw three files, `sources/<demo>-large.svg`, `-medium.svg` and
   `-small.svg`, each with the mark of its row in the sign-file table. Each has
   `viewBox="0 0 32 32"`, intrinsic dimensions of 32 × 32, a transparent
   background, a title, a description and a provenance comment.
5. Check `-small` at 16 px, `-medium` at 32 and `-large` at 64 and 180 on both
   backgrounds, and simplify a file where its symbol smudges, as in "Where the
   three files differ".
6. Add the demo's row to "Demo signs", its color to "Palette and contrast" and
   its sources to "Source provenance and license", its signs and its phone tile
   to [preview.html](preview.html), and its name to the list in
   [export.cjs](export.cjs).

## Palette and contrast

| Element | Color | Against the circle `#0288D1` | Against `#FFFFFF` | Against `#212529` |
|---|---|---|---|---|
| Logo and mark circle | `#0288D1` | — | 3.86:1 | 4.00:1 |
| Logo and mark bolt | `#FFD500` | 2.71:1 | — | — |
| `binance-btc-tracker` symbol | `#F76707` | 1.27:1 | 3.04:1 | 5.07:1 |
| `chat` symbol | `#845EF7` | 1.11:1 | 4.26:1 | 3.62:1 |
| `concert-tickets` symbol | `#E03131` | 1.17:1 | 4.51:1 | 3.42:1 |
| `ecommerce-shop` symbol | `#E64980` | 1.03:1 | 3.73:1 | 4.14:1 |
| `file-gallery-manager` symbol | `#0CA678` | 1.24:1 | 3.12:1 | 4.95:1 |
| `online-testing` symbol | `#B58B00` | 1.22:1 | 3.15:1 | 4.89:1 |
| `pmd` square | `#A9682F` | 1.16:1 | 4.46:1 | 3.46:1 |
| `pmd` letter | `#FFFFFF` | — | — | — |
| `polls` symbol | `#AE3EC9` | 1.26:1 | 4.85:1 | 3.18:1 |
| `preferans` symbol | `#868E96` | 1.16:1 | 3.32:1 | 4.64:1 |
| `roblox-game-server` symbol | `#5C940D` | 1.05:1 | 3.69:1 | 4.18:1 |
| `solar-system-model` symbol | `#E37400` | 1.24:1 | 3.10:1 | 4.97:1 |
| `tasks` symbol | `#2F9E44` | 1.12:1 | 3.45:1 | 4.48:1 |

The ratios are WCAG 2.x contrast ratios from sRGB relative luminance. The bolt
is painted only on the circle, so its ratio to the circle is the one that
counts; the circle is the part that meets the background. `#FFFFFF` and
`#212529` are the two backgrounds every mark is reviewed on. One file and one
shade serve both; there is no second theme asset and no alternate palette.
These are brand marks, not status indicators or a substitute for a control's
accessible name: keep the visible name in headers and the page title in
browser tabs.

Every symbol color meets the non-text contrast of 3:1 on both `#FFFFFF` and
`#212529`, which is why the symbols are mid-tones: a color that holds 3:1 on
both has a relative luminance between about 0.15 and 0.30. The lowest are
`solar-system-model` `#E37400` at 3.10:1 and `binance-btc-tracker` `#F76707` at
3.04:1 on white, and `polls` `#AE3EC9` at 3.18:1 on dark. The `pmd` letter is
painted only on its square and reads against it at 4.46:1. A symbol's ratio
against the mark circle is low, 1.03 to 1.29:1, because the circle is a
mid-tone too: symbol and mark are told apart by hue and by the transparent gap,
not by lightness.

## Files for integration

A page that shows the Hilos logo or a demo sign links four files, all of one
name — `hilos` or the demo's directory name. This set is the input of HIL-2152,
which puts the signs into the demos, and of HIL-2187, the site:

```html
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
```

| Served as | File | Made from |
|---|---|---|
| `/favicon.ico` | `<name>.ico`, written by [export.cjs](export.cjs) | `sources/<name>-small.svg` at 16 and 32 pixels, two PNG images in one ICO |
| `/favicon.svg` | `sources/<name>-small.svg`, copied unchanged | — |
| `/apple-touch-icon.png` | `<name>-touch-180.png`, written by export.cjs | `sources/<name>-large.svg` on an opaque `#FFFFFF` square, 180 × 180 |
| Header image | `sources/<name>-medium.svg`, copied unchanged | — |
| Web app manifest, 192 and 512 | `<name>-large-192.png` and `<name>-large-512.png`, written by export.cjs on request | `sources/<name>-large.svg` |

In a header, show the medium file next to the demo's name:
`<img src="…/<name>-medium.svg" width="32" height="32" alt="">`. The name next
to it gives the accessible label, so the image itself is decorative. A
manifest is not part of this set: if HIL-2152 decides a demo needs one, run
export.cjs with the sizes `192 512` and take the two `-large-` files; a
maskable icon would need its own tile with a wider safe zone and is not drawn
here.

The set follows "How to Favicon in 2026: Three files that fit most needs" by
Andrey Sitnik, Evil Martians
(<https://evilmartians.com/chronicles/how-to-favicon-in-2021-six-files-that-fit-most-needs>).
The tab takes the SVG in a browser that supports SVG icons; the ICO serves the
others and every tool that asks for `/favicon.ico` at the site root. The
`sizes="32x32"` on the ICO link is not a size hint for its own sake: the
article gives it as the fix for Chrome choosing the ICO file over the SVG. Its
earlier fix, `sizes="any"`, was replaced in 2023 because Chrome then downloaded
both files. The article suggests a single 32 × 32 image in the ICO unless it
does not scale down to 16 well; `<name>-small.svg` is drawn for 16 pixels, so
the ICO carries a 16 × 16 image of its own beside the 32 × 32 one. Both are the
small file: the ICO serves the same 16 CSS px tab, and a 32-pixel image there
is the tab on a 2× screen, which the ladder leaves on the small variant.

The phone tile is opaque because iOS fills the transparent pixels of an
apple-touch-icon with black. The `-large` file is drawn at 140 × 140 with 20
pixels of white on every side, the padding the same article recommends. iOS
rounds the corners of the square tile with a radius of about 22% of its side,
about 40 pixels at 180; with 20 pixels of padding, the corner of the 140-pixel
square lies 28 pixels from the center of that rounding, inside its 40-pixel
radius, so nothing a symbol puts in a corner is cut. The mark on the tile is
61.25 pixels across.

The real browser tab and the real home-screen icon are checked by the owner
when the ticket is ready and by HIL-2152 when the files go into the demos, in
Chromium, Firefox, iOS and Android: a headless browser draws neither, so the
observations in "Viewing criteria and observed limits" are of the files
themselves.

## Source choice and export

The SVG files in `sources/` are the masters; PNG and ICO files are exports, are
not kept in the repository and are never edited. For an SVG consumer, copy the
source unchanged and set its rendered width and height to the required equal
size. For example, in a header with the demo name next to it, an `<img>` can use
`width="32" height="32" alt=""`; the adjacent name provides the accessible
label. A standalone meaningful image needs its own descriptive `alt`. If
embedding inline, namespace its title/description and group IDs per instance to
avoid duplicate IDs on the page.

For PNG and ICO output, the recipe below was executed on 2026-11-19 with:

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
brand_output=/tmp/HIL-2151
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
  node /hilos/docs/demo-brand/export.cjs /out/png
```

The script reads the three files of `hilos` and of each of the twelve demos and
writes to `/tmp/HIL-2151/png`:

- `<name>-<variant>-<size>.png` for every variant — `small`, `medium`,
  `large` — and every requested size: transparent PNGs, drawn on a transparent
  canvas at the requested pixel size with no background, padding or extra
  resampling pass;
- `<name>-touch-180.png`, the phone tile described in "Files for integration";
- `<name>.ico`, the ICO described there. The script writes the ICO header
  itself; it needs no package beyond Playwright.

The tile and the ICO are written whatever the size list. The script reports the
renderer versions and checks every file before it writes it: each PNG has the
requested dimensions; a transparent PNG has both fully transparent and painted
pixels; the tile has no pixel that is not fully opaque; and the ICO is read
back from its own header — two entries, 16 and 32, each pointing at a PNG of
that size, the offsets and lengths adding up to the file's length. The
2026-11-19 run wrote 182 files: 156 transparent PNGs (13 names × 3 variants ×
the default sizes 16, 32, 64 and 180), 13 tiles of 5 to 13 KB and 13 ICO files
of 1.2 to 2.1 KB. An independent reader, `file`, recognized each ICO as an icon
resource with two PNG images, 16 × 16 and 32 × 32.

Give the sizes after the output directory to replace the default `16 32 64
180`, as any integers from 16 to 4096: `192 512` for a manifest, or `32 64 128`
for twice the pixels of a display at 16, 32 and 64 CSS px. Sizes below 16 are
not reviewed. Do not redraw a sign for a raster export: when a file does not
read at a size, the size belongs to another variant.

## Source provenance and license

The logo files are original constructions for HIL-2151; see the end of "Logo
geometry". The mark in every sign file is the same logo geometry, scaled.

The demo symbols come from four kinds of source:

- **Bootstrap Icons 1.13.2**, MIT. The geometry is the file
  `bootstrap-icons/icons/<name>.svg` of the npm package `bootstrap-icons`,
  version 1.13.2 — the version the demos' frontends lock, as in
  `demo/concert-tickets/frontend/package-lock.json`. Its paths are copied
  unchanged into the sign and placed with a transform; only the color is the
  sign's. License: MIT, "Copyright (c) 2019-2024 The Bootstrap Authors"; carry
  that notice with the files below when redistributing them.
- **A demo's favicon**, a file of this repository under its
  [MIT license](../../LICENSE).
- **The HIL-1316 geometry**, original paths drawn for the previous masters and
  covered by the repository's MIT license.
- **Original geometry for HIL-2151**, under the repository's MIT license.

| Demo | Large | Medium | Small |
|---|---|---|---|
| `binance-btc-tracker` | `bootstrap-icons/icons/currency-bitcoin.svg`; the same icon is the demo's favicon, `demo/binance-btc-tracker/frontend/public/favicon.svg`, recolored | as large | as large |
| `chat` | `bootstrap-icons/icons/chat-dots.svg` | `bootstrap-icons/icons/chat-dots-fill.svg` | The HIL-1316 speech bubble, the `demo-symbol` path of the former `sources/chat.svg` |
| `concert-tickets` | `bootstrap-icons/icons/ticket-perforated-fill.svg`, also `demo/concert-tickets/frontend/public/favicon.svg`, which paints it in `currentColor` | as large | `bootstrap-icons/icons/ticket-fill.svg` |
| `ecommerce-shop` | `bootstrap-icons/icons/flower1.svg` | as large | Original: five circles (see "Placement") |
| `file-gallery-manager` | `bootstrap-icons/icons/images.svg` | as large | `bootstrap-icons/icons/image-fill.svg` |
| `online-testing` | `bootstrap-icons/icons/mortarboard.svg` | `bootstrap-icons/icons/mortarboard-fill.svg` | as medium |
| `pmd` | The rounded square and the letter path of `demo/pmd/frontend/public/favicon.svg`, recolored and scaled | as large | as large |
| `polls` | `bootstrap-icons/icons/bar-chart.svg`, mirrored | `bootstrap-icons/icons/bar-chart-fill.svg`, mirrored | as medium |
| `preferans` | `bootstrap-icons/icons/suit-spade-fill.svg`, also `demo/preferans/frontend/public/favicon.svg`, which paints it in `currentColor` | as large | as large |
| `roblox-game-server` | `bootstrap-icons/icons/controller.svg`, also `demo/roblox-game-server/frontend/public/favicon.svg`, recolored | The same icon's subpaths: its outer contour and its cross and buttons, filled even-odd; its inner contour is left out | as medium |
| `solar-system-model` | `bootstrap-icons/icons/sun-fill.svg` | as large | Original: a disk and eight rays after the same icon (see "Placement") |
| `tasks` | `bootstrap-icons/icons/check2-square.svg` | `bootstrap-icons/icons/check-square-fill.svg` | as medium |

No fonts, embedded bitmaps or remote references are used in any sign. The
owner's decisions are recorded with the interviews of HIL-1316, HIL-1539 and
HIL-2151.
