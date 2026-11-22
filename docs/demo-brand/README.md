# Hilos marks

Research artifacts for **HIL-2151**, the new system of Hilos marks: the Hilos
logo in three variants, the ladder that picks a variant by its size on screen,
the corner mark that puts the logo into a demo's sign, the signs of the twelve
demos with a frontend, and the palette. **HIL-2261** redrew the demo signs with
the mark in the bottom-right corner. Open [preview.html](preview.html)
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
- Every logo file, and every corner mark that carries a bolt, carries this
  one bolt with two bends. The thinner bolts the ladder of HIL-2151 gave the
  smaller sizes — one bend, then a thin zigzag line — read as a broken bolt
  (the owner, 2026-11-21 and 2026-11-22, HIL-2261) and left the system. Below
  10 px the logo is a plain dot without a bolt.
- The circle is `#0288D1` and the bolt `#FFD500`. The former node blue
  `#32C5FF` leaves the system.
- A demo's sign carries the logo in miniature as a mark in its bottom-right
  corner, separated from the demo's symbol by a transparent gap. The top-right
  corner of an icon is where apps put a badge for something new, and a bare
  blue dot there in a browser tab read as unread messages (the owner,
  2026-11-21, HIL-2261). The bottom-right corner is where an icon shows a trait
  of the thing itself, such as a work profile or the status on an avatar.
- A demo's symbol is free in shape and color, several colors included. The one
  requirement: the sign reads from a single file on a light and on a dark
  background, with no switch by theme. The right lower quarter belongs to the
  mark. The limits of HIL-1539 — one color, one fill, a shared palette — no
  longer apply.

The six HIL-1539 masters, `sources/<demo>.svg`, are removed: the demo signs
below replace them. [export.cjs](export.cjs) rasterizes the logo and the signs
into the files a page links to; see "Files for integration" and "Source choice
and export".

## The ladder and its boundaries

The ladder names the logo file a surface links to. A variant is chosen by the
diameter of the blue circle as displayed, in CSS pixels: the logo's own size,
since its circle fills the whole viewBox. Since HIL-2261 the three files carry
the same two-bend bolt, so the ladder no longer changes the bolt; it keeps the
file names every installation links to, and below 10 px the circle stands
alone. The corner mark of a demo sign carries the same bolt or a dot (next
section).

| Circle diameter, CSS px | Variant | Bolt | Logo source |
|---|---|---|---|
| 48 and up | large | Filled, two bends | [hilos-large.svg](sources/hilos-large.svg) |
| 20 to below 48 | medium | Filled, two bends | [hilos-medium.svg](sources/hilos-medium.svg) |
| 10 to below 20 | small | Filled, two bends | [hilos-small.svg](sources/hilos-small.svg) |
| Below 10 | dot | None | The `hilos-circle` element of any variant, alone |

| Surface | Displayed size | Logo source |
|---|---|---|
| Browser tab | 16 px | `hilos-small.svg` |
| Header | 32 px | `hilos-medium.svg` |
| Larger brand surfaces | 64 px and up | `hilos-large.svg` |
| Phone home-screen tile | 180 px | `hilos-large.svg` |
| Link preview (Open Graph) | 512 px | `hilos-large.svg` |
| Web app manifest | 192 and 512 px | `hilos-large.svg` |

The same choice applies on a high-density screen: a 16 CSS px tab icon is
`hilos-small.svg` whatever the device pixel ratio. Density changes the raster
pixel count, not which variant it is.

The boundaries of HIL-2151 came from rasterizing the three files at 8 to 180
px on `#FFFFFF` and `#212529` and enlarging the small ones with
nearest-neighbor pixels. That run found the horizontal step of the two-bend
bolt closing at 20 px and below, and gave the smaller sizes the one-bend bolt
and the zigzag line instead. On review both read as a broken bolt, while the
logo itself was right (the owner, HIL-2151 and HIL-2261), so on 2026-11-22
every file took the two-bend bolt. Rasterized again at 16, 20, 24 and 32 px
with nearest-neighbor pixels:

- The two-bend bolt reads as a bolt from 20 px up and is sharp from 32.
- At 16 its middle step merges: it reads as a small bolt with a cross-like
  middle. The owner accepted that on the preview on 2026-11-22.
- Below 10 px no bolt fits, and the circle stands alone.

### Logo geometry

Every logo file has `viewBox="0 0 32 32"`, intrinsic dimensions of 32 × 32, a
transparent background, a title, a description and a provenance comment. It
contains exactly two elements, in paint order:

| Element | Value |
|---|---|
| `hilos-circle` | Circle at `(16, 16)`, radius `16`, fill `#0288D1`; it fills the viewBox edge to edge |
| `hilos-bolt` in `hilos-large.svg` | `M19.5 4L9.5 17.5H15.2L12.5 28L22.5 14.5H16.8Z`, fill `#FFD500` |
| `hilos-bolt` in `hilos-medium.svg` | The same path and fill as in `hilos-large.svg` (since HIL-2261) |
| `hilos-bolt` in `hilos-small.svg` | The same path and fill as in `hilos-large.svg` (since HIL-2261) |

The bolt runs from the tip `(19.5, 4)` to the tip `(12.5, 28)` and is
point-symmetric about `(16, 16)`: its upper and lower halves are offset by a
horizontal step of `5.7` on each side. Until HIL-2261 the medium file carried a
one-bend bolt and the small file a zigzag line of the same slant; both are
gone. Nothing else is painted: no gradient, no opacity, no outline.

These three files are original constructions for HIL-2151 under the
repository's [MIT license](../../LICENSE). No third-party icon paths, fonts,
embedded bitmaps or remote references were used.

## Mark construction and gap

The mark is the logo in miniature — the blue circle and the yellow bolt, one
construction — placed in the bottom-right corner of a demo's sign. A sign is a
file of its own, `sources/<demo>-{large,medium,small}.svg`, with
`viewBox="0 0 32 32"` like the logo, and the right lower quarter of that
viewBox, `x` 16 to 32 and `y` 16 to 32, belongs to the mark. Around the mark
runs a transparent gap: the demo's symbol is cut away by a mask, not covered
by a white ring, so the gap shows whatever background the sign is on, light or
dark. Without the gap, at 16 px the mark merges with the symbol.

All numbers are in units of the 32 × 32 viewBox and are the same in all three
sign files:

| Part | Value |
|---|---|
| Mark circle | Center `(25, 25)`, radius `7`, diameter `14`, fill `#0288D1` |
| Gap | Width `2` around the mark circle |
| Cut-out circle of the mask | Center `(25, 25)`, radius `9`; it touches `x = 16` and `y = 16`, the inner edges of the quarter |
| Logo-to-mark transform | `matrix(0.4375 0 0 0.4375 18 18)`: scale `7/16` and shift by `(18, 18)` map the logo circle `(16, 16)`, radius `16`, onto the mark circle |
| Mark circle on screen | `14/32` of the sign's displayed size: 7 px at 16, 14 px at 32, 28 px at 64, 78.75 px at 180 |
| Gap on screen | `2/32` of the sign's size: 1 px at 16, 2 px at 32, 4 px at 64 |

The demo's symbol keeps out of the quarter as far as it can; the mask
guarantees the gap wherever it does reach in (see "Placement" for where it
does). The mask is the only place where
white and black appear in a sign file, and they are mask luminance, not paint.
Paint order: the masked symbol group first, the mark group last. In markup:

```svg
<mask id="hilos-mark-gap" maskUnits="userSpaceOnUse" x="0" y="0" width="32" height="32">
  <rect width="32" height="32" fill="#fff"/>
  <circle cx="25" cy="25" r="9" fill="#000"/>
</mask>
<g id="demo-symbol" fill="…" mask="url(#hilos-mark-gap)">
  <!-- the demo's symbol -->
</g>
<g id="hilos-mark">
  <g transform="matrix(0.4375 0 0 0.4375 18 18)">
    <circle cx="16" cy="16" r="16" fill="#0288D1"/>
    <!-- the hilos-bolt path of the chosen logo variant, copied unchanged -->
  </g>
</g>
```

Every `id` is unique within its file. In a sign whose mark is a dot, the
`hilos-mark` group holds only `<circle cx="25" cy="25" r="7" fill="#0288D1"/>`,
without the transform. If a sign is embedded inline, namespace its ids per
instance as for any inline SVG.

Each sign file shows its mark at a range of sizes, and one file carries one
bolt. The `-medium` and `-large` files carry the bolt of the main logo,
`hilos-large.svg`, with two bends. The thinner bolts of the ladder — the
zigzag line and the one-bend bolt — read as a broken bolt inside the mark
(the owner, 2026-11-22, on the acceptance of HIL-2261), while the two-bend
bolt reads as a bolt from a 10.5 px mark up. The `-small` file carries a dot:

| Sign file | Surfaces | Sign size, CSS px | Mark diameter, CSS px | Bolt in the mark |
|---|---|---|---|---|
| `<demo>-small.svg` | Browser tab, 16 | Below 24 | 7 at 16; under 10.5 in the whole range | None: a dot |
| `<demo>-medium.svg` | Header, 32 | 24 to below 48 | 10.5 at 24, 14 at 32, up to 21 | Two bends, the bolt of `hilos-large.svg` |
| `<demo>-large.svg` | 64 and up: phone tile 180, manifest 192 and 512 | 48 and up | 21 at 48, 28 at 64, 78.75 at 180, 224 at 512 | Two bends, the bolt of `hilos-large.svg` |

So every `-medium` and `-large` sign carries the same two-bend bolt as the main
logo, from a 10.5 px mark up to 224 px. The construction was checked on the 36
sign files of the 2026-11-21 export run, before the bolt changed on 2026-11-22;
the mask is the same. Each file was rasterized at 1280 px: the ring between
radius 7 and radius 9 around `(25, 25)` is unpainted in every file, and the mark
circle is painted whole. The exports were viewed on both backgrounds: at 16 the
gap keeps the dot apart from every symbol. On 2026-11-22 the two-bend bolt was
checked in the `-medium` files at 32, 48 and 64 and in the `-large` files at 64
and 180, on both backgrounds: it reads as a bolt.

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
| `chat` | Speech bubble: the header icon `chat-dots` | Large `chat-dots`; medium `chat-dots-fill`; small an original wide speech bubble with three dots | `#845EF7` | [large](sources/chat-large.svg) · [medium](sources/chat-medium.svg) · [small](sources/chat-small.svg) |
| `concert-tickets` | Ticket: the header icon `ticket-perforated-fill`, also the favicon | Large and medium `ticket-perforated-fill`; small `ticket-fill` | `#E03131` | [large](sources/concert-tickets-large.svg) · [medium](sources/concert-tickets-medium.svg) · [small](sources/concert-tickets-small.svg) |
| `ecommerce-shop` | Flower: the header icon `flower1` | Large `flower1`; medium and small an original five-petal flower | `#E64980` | [large](sources/ecommerce-shop-large.svg) · [medium](sources/ecommerce-shop-medium.svg) · [small](sources/ecommerce-shop-small.svg) |
| `file-gallery-manager` | Pictures: the header icon `images` | Large and medium `images`; small an original single picture: a frame, a sun and mountains | `#0CA678` | [large](sources/file-gallery-manager-large.svg) · [medium](sources/file-gallery-manager-medium.svg) · [small](sources/file-gallery-manager-small.svg) |
| `online-testing` | Graduation cap: the header icon `mortarboard` | Large `mortarboard`; medium and small `mortarboard-fill`; mirrored in all three, the tassel on the left | `#B58B00` | [large](sources/online-testing-large.svg) · [medium](sources/online-testing-medium.svg) · [small](sources/online-testing-small.svg) |
| `pmd` | Letter P: the header shows the name as text, so the symbol is the P of the demo's favicon | The favicon's rounded square and letter in all three | Square `#A9682F`, letter `#FFFFFF` | [large](sources/pmd-large.svg) · [medium](sources/pmd-medium.svg) · [small](sources/pmd-small.svg) |
| `polls` | Bar chart: the header icon `bar-chart` | Three original bars after `bar-chart-fill` in all three, rising from left to right as in the header; small on the 16-pixel grid | `#AE3EC9` | [large](sources/polls-large.svg) · [medium](sources/polls-medium.svg) · [small](sources/polls-small.svg) |
| `preferans` | Spade: the header icon `suit-spade-fill`, also the favicon | `suit-spade-fill` in all three | `#868E96` | [large](sources/preferans-large.svg) · [medium](sources/preferans-medium.svg) · [small](sources/preferans-small.svg) |
| `roblox-game-server` | Game controller: the header icon `controller`, also the favicon | Large `controller`; medium and small its outer contour filled, with the cross and the buttons cut out; tilted by 30° in all three | `#5C940D` | [large](sources/roblox-game-server-large.svg) · [medium](sources/roblox-game-server-medium.svg) · [small](sources/roblox-game-server-small.svg) |
| `solar-system-model` | Sun: the header icon `sun-fill` | Large `sun-fill`; medium and small an original sun, a disk and eight thick rays | `#E37400` | [large](sources/solar-system-model-large.svg) · [medium](sources/solar-system-model-medium.svg) · [small](sources/solar-system-model-small.svg) |
| `tasks` | Check box: the header icon `check2-square` | `check-square-fill` in all three | `#2F9E44` | [large](sources/tasks-large.svg) · [medium](sources/tasks-medium.svg) · [small](sources/tasks-small.svg) |

### Where the three files differ

One subject, three levels of detail, in the spirit of the ladder:

- `<demo>-large.svg` keeps the header icon as it is drawn where its outline
  survives the cut-out: `chat`, `online-testing` and `roblox-game-server` are
  outlines, and `flower1` and `sun-fill` are drawn at the scale `1.36` of
  "Placement". `polls` and `tasks` are filled in all three files instead of
  the outline of their header icons: the cut-out opened the foot of the third
  bar of `bar-chart` into two prongs, and of the right side of `check2-square`
  it left a stub above the cut-out.
- `<demo>-medium.svg` takes the filled form of the same icon where the outline
  turns pale at 32 px: `chat`, `online-testing` and `roblox-game-server`. It
  takes the simplified geometry of the small file where the icon at the scale
  `1.36` has lines thinner than one and a half pixels at 32: the flower, whose
  `flower1` joints are about 0.7 px, and the sun, whose `sun-fill` rays are
  about 1.4 px. So the medium file of `ecommerce-shop` and of
  `solar-system-model` equals the small one. The ticket's perforation, the
  pictures, the spade, the bitcoin sign, the P, the bars and the check box read
  at 32 as they are, so medium equals large there.
- `<demo>-small.svg` drops what is below a pixel at 16 px: the perforation of
  the ticket, the second picture of the gallery, the twelve thin petals of the
  flower and the thin rays of the sun. The flower and the sun are redrawn with
  fewer and bolder parts, and the gallery shows one picture of its own. The
  chat bubble is an original wide bubble whose three dots are 2 × 2 pixels on
  the 16-pixel grid, so at 16 the dots stay. The bars of `polls` are aligned to
  the 16-pixel grid.

### Viewing criteria and observed limits

The exports of the 2026-11-21 run were viewed on
`#FFFFFF` and on `#212529`, at their own pixels and enlarged with
nearest-neighbor pixels: each file at the size it serves — `-small` at 16,
`-medium` at 32, `-large` at 64 and 180 — and the logo at the same sizes. The
criteria: the symbol is recognizable as its subject; the gap keeps the mark
apart from the symbol; the mark carries the bolt of its row in the sign-file
table; and the symbol neither disappears on the dark background nor needs a
second file for it.

- **Logo.** Since 2026-11-22 every file carries the two-bend bolt: at 16 it
  reads as a small bolt with a cross-like middle, from 20 it reads, and at 32,
  64 and 180 it is sharp.
- **16, `-small`.** The bitcoin sign, the chat bubble with its three dots,
  the ticket with the notches on its sides, the flower with five petals and
  its open middle, the mortarboard with the tassel on the left, the letter P,
  the three rising bars, the spade, the sun with its disk and eight rays and
  the check box read clearly. At their limit, readable by outline rather than
  by detail: the controller, a tilted green body with two grips whose cross and
  buttons are light spots, and the gallery picture, a one-pixel frame with a
  square sun and a step of mountains. The dot mark stands apart from every
  symbol on both backgrounds: the one-pixel gap holds.
- **32, `-medium`.** All twelve symbols read, and so does the two-bend bolt in
  the mark (2026-11-22). The ticket's perforation and the controller's cross
  and buttons are the finest detail at this size and still read. In the stack
  of pictures the mark covers the bottom-right corner of the front picture; its
  mountain and sun stay in view.
- **64 and 180, `-large`.** All twelve read, the outline icons included: the
  lines of `chat`, `online-testing` and `roblox-game-server` are 2.9 to 3.5
  pixels wide at 64. At the scale `1.36` the rays of `sun-fill` are about 2.7
  pixels at 64 and the joints of `flower1` about 1.4; they read, and they are
  sharp at 180. The two-bend bolt in the mark reads at 64 and is sharp at 180.
- **Phone tiles.** On the white 180 × 180 tiles every symbol and mark stays
  clear of the corners, checked with a 40-pixel rounded mask laid over the PNG:
  the sign covers pixels 20 to 160, and its point nearest a corner, `(20, 20)`,
  lies 11.7 pixels inside the rounding. The light symbols — the spade
  `#868E96`, the mortarboard `#B58B00` — keep their contrast of 3:1 and more
  against the white tile.
- **ICO.** Both images decode: the 16 × 16 image is `-small` at 16, and the
  32 × 32 one is the same small file at 32, with a dot for the mark, as the
  ladder gives for a 16 CSS px tab on a 2× screen. Both are byte for byte the
  `-small` exports at 16 and 32. At 32 every small file reads.

What only a device shows, the owner checks when the ticket is ready: the tab in
Chromium and Firefox, light and dark, at 100% zoom and on a 2× screen, and the
home-screen icon on iOS and Android. At 16 the controller and the gallery are
the signs to look at first: they are at the limit above.

### Placement

A Bootstrap icon is drawn on a 16 × 16 grid. A sign places it with
`matrix(1.75 0 0 1.75 0 0)`: 28 units, flush with the left and top edges of
the viewBox, so a full-size icon covers `x` 0 to 28 and `y` 0 to 28. Where the
cut-out would take a characteristic part of the symbol — a ray, a petal, a
grip, a lobe, a corner of the ticket — the symbol is drawn smaller or turned
so that it stays whole; the cut-out is left to bite only the corner of a solid
rectangle (`file-gallery-manager`, `pmd`, `tasks`) and the foot of the tallest
bar (`polls`). The placement of each demo, all in viewBox units:

- `binance-btc-tracker`, all three: `matrix(1.875 0 0 1.875 -4.5 -2.8)`. The
  bitcoin sign is narrow, so it is drawn larger; it covers `x` 1.6 to 18.93
  and `y` 0 to 24.4 and clears the cut-out.
- `chat` large and medium: `matrix(1.45 0 0 1.45 0 -1.45)`, `x` 0 to 23.2 and
  `y` 0 to 21.65 with the tail; clear of the cut-out.
- `chat` small: a rounded rectangle `x` 0 to 28, `y` 0 to 16, radius `4`; a
  tail from its lower edge between `x` 4 and 12 down to `(4, 24)`; three dots
  of radius `2` at `(6, 8)`, `(14, 8)` and `(22, 8)`, cut out even-odd. Clear
  of the cut-out.
- `concert-tickets`, all three: `matrix(1.6 0 0 1.6 0 -4.8)`. The ticket is
  wide and low and lies in the upper half, `x` 0 to 25.6 and `y` 0 to 16; its
  lower edge at `y = 16` touches the cut-out.
- `ecommerce-shop` large: `matrix(1.36 0 0 1.36 0 0)`, `x` and `y` 0 to 21.76.
- `ecommerce-shop` medium and small: five circles of radius `5` whose centers
  lie at distance `7` from `(11.5, 11.5)`, the first at 9° clockwise from the
  `x` axis: the opening between two petals faces the mark, and the flower is
  symmetric about the diagonal.
- `file-gallery-manager` large and medium: `matrix(1.75 0 0 1.75 0 -1.75)`,
  since the `images` icon starts at `y = 1`; `x` 0 to 28 and `y` 0 to 24.5.
  The cut-out takes the bottom-right corner of the front picture.
- `file-gallery-manager` small: a frame `28 × 24` at `(0, 0)`, outer radius
  `3`, width `2`; the sky is cut out even-odd above the ridge `(2, 18) (8, 12)
  (12, 16) (18, 10) (26, 18)`, and the sun is a circle of radius `2` at
  `(6, 6)`. The cut-out takes the bottom-right corner of the frame.
- `online-testing`, all three: `matrix(-1.7 0 0 1.7 27.2 -3.4)`, mirrored so
  that the tassel hangs on the left and not under the mark, and raised by the
  empty band above the cap; `x` 0 to 27.2 and `y` 0 to 23.8.
- `pmd`, all three: the rounded square is `24 × 24` at `(0, 0)`, radius `4.5`,
  and the favicon's letter path is placed with `matrix(0.75 0 0 0.75 0 0)`.
  The cut-out takes the bottom-right corner of the square, not the letter.
- `polls` large and medium: three bars of width `7` at `x` 0, 9 and 18, their
  tops at `y` 18, 9 and 0, all standing on `y = 28`, corner radius `1.75`. The
  bars rise from left to right as in the header; the cut-out takes the foot of
  the third, tallest bar, and the right edge of the middle bar, `x = 16`, only
  touches it.
- `polls` small: the same on the 16-pixel grid, two units to a pixel: width
  `6`, `x` 0, 8 and 16, tops at `y` 18, 10 and 0, corner radius `1`.
- `preferans`, all three: `matrix(1.5 0 0 1.5 -1.5 0)`, `x` 0 to 21 and `y` 0
  to 22.5.
- `roblox-game-server`, all three: `translate(12.25 12.25) rotate(-30)
  scale(1.75) translate(-8 -8)`: the default size, turned 30°
  counterclockwise about the icon's center, so that both grips stay whole and
  the mark stands between them.
- `solar-system-model` large: `matrix(1.36 0 0 1.36 0 0)`, `x` and `y` 0 to
  21.76.
- `solar-system-model` medium and small: a disk of radius `5.5` at `(11, 11)`
  and eight rays from radius `8` to `9.5`, stroke width `2.5`, round caps that
  reach `10.75`.
- `tasks`, all three: the default `matrix(1.75 0 0 1.75 0 0)`. The cut-out
  takes the bottom-right corner of the square, not the check mark.

The scale `1.36` of the radial icons, the flower and the sun, comes from a
circle of radius 8 grid units set flush with the left and top edges: it stays
out of the cut-out while `8·s·(1 + √½) ≤ 25 − 9·√½`, that is `s ≤ 1.364`.

The symbols use one color each, except `pmd`, whose letter is white on its
colored square. The colors are chosen so that the symbol's fill reads on both
backgrounds (next section) and none is a blue near the mark's `#0288D1`; the
two former blues, `#0d6efd` of the `pmd` and `roblox-game-server` favicons, are
replaced.

## Adding another demo

Seven demos have no frontend yet: `dkp-system`, `mail-monitor`,
`personal-homepage`, `private-clinic`, `research-lab`, `small-airport`
and `traivan-map-analyzer`. A demo gets its sign when its
frontend comes to life, and any further demo the same way:

1. Pick the subject: by default the icon the demo shows in its header. Shape
   and colors are free, several colors included; nothing ties the symbol to the
   other demos' symbols or to a shared palette.
2. Meet the one requirement: a single file reads on `#FFFFFF` and on `#212529`,
   with no switch by theme. Aim the symbol's main fill at 3:1 on both (a
   relative luminance of about 0.15 to 0.30); where it does not get there, say
   so in "Palette and contrast" with what carries it instead, such as a second
   color or an outline. Keep away from blues near the mark's `#0288D1`.
3. Leave the right lower quarter to the mark: build the file exactly as in
   "Mark construction and gap" — the mask, the `demo-symbol` group first, the
   `hilos-mark` group last. The symbol may reach into the quarter; the mask cuts
   the gap. Where the cut-out would take a characteristic part of the symbol,
   draw it smaller or turn it, as in "Placement".
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
7. Copy the installed set into `demo/<demo>/frontend/public/`, add the three
   icon links to `index.html` and show `-medium.svg` in the header's brand slot,
   following the twelve demos in "Installed copies".

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
| og:image | `<name>-preview-512.png`, written by export.cjs | `sources/<name>-large.svg` on an opaque `#FFFFFF` square, 512 × 512, padding 57 |
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

The link preview tile (`og:image`) is 512 × 512 on an opaque `#FFFFFF` square
with 57 pixels of padding (the phone tile's 20 of 180, scaled to 512). The
raster is 512 because platforms such as Facebook reject preview images smaller
than 200 × 200, and 512 produces a crisp square card on high-density screens.
It is opaque because preview aggregators draw transparent areas over their own
background colors, exactly as iOS does for the home-screen tile.

The real browser tab and the real home-screen icon are checked by the owner
when the ticket is ready and by HIL-2152 when the files go into the demos, in
Chromium, Firefox, iOS and Android: a headless browser draws neither, so the
observations in "Viewing criteria and observed limits" are of the files
themselves.

## Installed copies

| Destination | Copy from |
|---|---|
| `demo/binance-btc-tracker/frontend/public/{favicon.svg, favicon.ico, apple-touch-icon.png, binance-btc-tracker-medium.svg}` | `sources/binance-btc-tracker-small.svg`, `binance-btc-tracker.ico`, `binance-btc-tracker-touch-180.png`, `sources/binance-btc-tracker-medium.svg` |
| `demo/chat/frontend/public/{favicon.svg, favicon.ico, apple-touch-icon.png, chat-medium.svg}` | `sources/chat-small.svg`, `chat.ico`, `chat-touch-180.png`, `sources/chat-medium.svg` |
| `demo/pmd/frontend/public/{favicon.svg, favicon.ico, apple-touch-icon.png, pmd-medium.svg}` | `sources/pmd-small.svg`, `pmd.ico`, `pmd-touch-180.png`, `sources/pmd-medium.svg` |
| `demo/roblox-game-server/frontend/public/{favicon.svg, favicon.ico, apple-touch-icon.png, roblox-game-server-medium.svg}` | `sources/roblox-game-server-small.svg`, `roblox-game-server.ico`, `roblox-game-server-touch-180.png`, `sources/roblox-game-server-medium.svg` |
| `demo/ecommerce-shop/frontend/public/{favicon.svg, favicon.ico, apple-touch-icon.png, ecommerce-shop-medium.svg}` | `sources/ecommerce-shop-small.svg`, `ecommerce-shop.ico`, `ecommerce-shop-touch-180.png`, `sources/ecommerce-shop-medium.svg` |
| `demo/file-gallery-manager/frontend/public/{favicon.svg, favicon.ico, apple-touch-icon.png, file-gallery-manager-medium.svg}` | `sources/file-gallery-manager-small.svg`, `file-gallery-manager.ico`, `file-gallery-manager-touch-180.png`, `sources/file-gallery-manager-medium.svg` |
| `demo/solar-system-model/frontend/public/{favicon.svg, favicon.ico, apple-touch-icon.png, solar-system-model-medium.svg}` | `sources/solar-system-model-small.svg`, `solar-system-model.ico`, `solar-system-model-touch-180.png`, `sources/solar-system-model-medium.svg` |
| `demo/tasks/frontend/public/{favicon.svg, favicon.ico, apple-touch-icon.png, tasks-medium.svg}` | `sources/tasks-small.svg`, `tasks.ico`, `tasks-touch-180.png`, `sources/tasks-medium.svg` |
| `demo/concert-tickets/frontend/public/{favicon.svg, favicon.ico, apple-touch-icon.png, concert-tickets-medium.svg}` | `sources/concert-tickets-small.svg`, `concert-tickets.ico`, `concert-tickets-touch-180.png`, `sources/concert-tickets-medium.svg` |
| `demo/online-testing/frontend/public/{favicon.svg, favicon.ico, apple-touch-icon.png, online-testing-medium.svg}` | `sources/online-testing-small.svg`, `online-testing.ico`, `online-testing-touch-180.png`, `sources/online-testing-medium.svg` |
| `demo/polls/frontend/public/{favicon.svg, favicon.ico, apple-touch-icon.png, polls-medium.svg}` | `sources/polls-small.svg`, `polls.ico`, `polls-touch-180.png`, `sources/polls-medium.svg` |
| `demo/preferans/frontend/public/{favicon.svg, favicon.ico, apple-touch-icon.png, preferans-medium.svg}` | `sources/preferans-small.svg`, `preferans.ico`, `preferans-touch-180.png`, `sources/preferans-medium.svg` |
| `demo/hilos-website/frontend/public/{favicon.svg, favicon.ico, apple-touch-icon.png, hilos-medium.svg, hilos-preview-512.png}` | `sources/hilos-small.svg`, `hilos.ico`, `hilos-touch-180.png`, `sources/hilos-medium.svg`, `hilos-preview-512.png` |
| `demo/hilos-website/frontend/public/demos/<demo>-large.svg` for the twelve demos in "Demo signs" | `sources/<demo>-large.svg` |
| `framework/frontend/brand/{favicon.svg, favicon.ico, apple-touch-icon.png, hilos-medium.svg}` | `sources/hilos-small.svg`, `hilos.ico`, `hilos-touch-180.png`, `sources/hilos-medium.svg` |

Installed copies are not edited by hand. When a master changes, export the files
again with `export.cjs` and copy the set again. These installations do not use a
web app manifest: as decided for HIL-2152 on 2026-11-19, installing the demos as
apps is not in scope. The 192 and 512 pixel exports can be generated when they
are needed.

## Source choice and export

The SVG files in `sources/` are the masters; PNG and ICO files are exports, are
not kept in this folder and are never edited; the copies a page serves are
listed in "Installed copies". For an SVG consumer, copy the
source unchanged and set its rendered width and height to the required equal
size. For example, in a header with the demo name next to it, an `<img>` can use
`width="32" height="32" alt=""`; the adjacent name provides the accessible
label. A standalone meaningful image needs its own descriptive `alt`. If
embedding inline, namespace its title/description and group IDs per instance to
avoid duplicate IDs on the page.

For PNG and ICO output, the recipe below was executed on 2026-11-22 with:

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
brand_output=/tmp/HIL-2261
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
writes to `/tmp/HIL-2261/png`:

- `<name>-<variant>-<size>.png` for every variant — `small`, `medium`,
  `large` — and every requested size: transparent PNGs, drawn on a transparent
  canvas at the requested pixel size with no background, padding or extra
  resampling pass;
- `<name>-touch-180.png`, the phone tile described in "Files for integration";
- `<name>-preview-512.png`, the link preview tile described there;
- `<name>.ico`, the ICO described there. The script writes the ICO header
  itself; it needs no package beyond Playwright.

The tile, the preview tile and the ICO are written whatever the size list. The
script reports the renderer versions and checks every file before it writes it:
each PNG has the requested dimensions; a transparent PNG has both fully
transparent and painted pixels; the tile and preview tile have no pixel that is
not fully opaque; and the ICO is read back from its own header — two entries, 16
and 32, each pointing at a PNG of that size, the offsets and lengths adding up
to the file's length. The 2026-11-22 run wrote 195 files: 156 transparent PNGs
(13 names × 3 variants × the default sizes 16, 32, 64 and 180), 13 phone tiles
180 of 5.1 to 11.2 KB, 13 preview tiles 512 of 17.9 to 37.9 KB and 13 ICO
files of 1.0 to 2.0 KB. An independent reader, `file`, recognized each ICO as an
icon resource with two PNG images, 16 × 16 and 32 × 32.

Give the sizes after the output directory to replace the default `16 32 64
180`, as any integers from 16 to 4096: `192 512` for a manifest, or `32 64 128`
for twice the pixels of a display at 16, 32 and 64 CSS px. Sizes below 16 are
not reviewed. Do not redraw a sign for a raster export: when a file does not
read at a size, the size belongs to another variant.

## Source provenance and license

The logo files are original constructions for HIL-2151; see the end of "Logo
geometry". The mark in every sign file is the same logo geometry, scaled.

The demo symbols come from three kinds of source:

- **Bootstrap Icons 1.13.2**, MIT. The geometry is the file
  `bootstrap-icons/icons/<name>.svg` of the npm package `bootstrap-icons`,
  version 1.13.2 — the version the demos' frontends lock, as in
  `demo/concert-tickets/frontend/package-lock.json`. Its paths are copied
  unchanged into the sign and placed with a transform; only the color is the
  sign's. License: MIT, "Copyright (c) 2019-2024 The Bootstrap Authors"; carry
  that notice with the files below when redistributing them.
- **A demo's favicon**, a file of this repository under its
  [MIT license](../../LICENSE).
- **Original geometry for HIL-2261**, under the repository's MIT license.

| Demo | Large | Medium | Small |
|---|---|---|---|
| `binance-btc-tracker` | `bootstrap-icons/icons/currency-bitcoin.svg`; the same icon is the demo's favicon, `demo/binance-btc-tracker/frontend/public/favicon.svg`, recolored | as large | as large |
| `chat` | `bootstrap-icons/icons/chat-dots.svg` | `bootstrap-icons/icons/chat-dots-fill.svg` | Original geometry for HIL-2261: a wide speech bubble with three dots (see "Placement") |
| `concert-tickets` | `bootstrap-icons/icons/ticket-perforated-fill.svg`, also `demo/concert-tickets/frontend/public/favicon.svg`, which paints it in `currentColor` | as large | `bootstrap-icons/icons/ticket-fill.svg` |
| `ecommerce-shop` | `bootstrap-icons/icons/flower1.svg` | Original geometry for HIL-2261: five circles (see "Placement") | as medium |
| `file-gallery-manager` | `bootstrap-icons/icons/images.svg` | as large | Original geometry for HIL-2261: one picture after `images.svg` (see "Placement") |
| `online-testing` | `bootstrap-icons/icons/mortarboard.svg`, mirrored | `bootstrap-icons/icons/mortarboard-fill.svg`, mirrored | as medium |
| `pmd` | The rounded square and the letter path of `demo/pmd/frontend/public/favicon.svg`, recolored and scaled | as large | as large |
| `polls` | Original geometry for HIL-2261: three bars after `bootstrap-icons/icons/bar-chart-fill.svg` (see "Placement") | as large | The same on the 16-pixel grid |
| `preferans` | `bootstrap-icons/icons/suit-spade-fill.svg`, also `demo/preferans/frontend/public/favicon.svg`, which paints it in `currentColor` | as large | as large |
| `roblox-game-server` | `bootstrap-icons/icons/controller.svg`, also `demo/roblox-game-server/frontend/public/favicon.svg`, recolored; tilted | The same icon's subpaths: its outer contour and its cross and buttons, filled even-odd; its inner contour is left out; tilted | as medium |
| `solar-system-model` | `bootstrap-icons/icons/sun-fill.svg` | Original geometry for HIL-2261: a disk and eight rays after the same icon (see "Placement") | as medium |
| `tasks` | `bootstrap-icons/icons/check-square-fill.svg` | as large | as large |

No fonts, embedded bitmaps or remote references are used in any sign. The
owner's decisions are recorded with the interviews of HIL-1316, HIL-1539,
HIL-2151 and HIL-2261.
