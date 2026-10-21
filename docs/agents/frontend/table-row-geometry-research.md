# Table Row Geometry Research

Read when designing preservation of a departed table record's space, column
widths, or declared row height in the three SDK views. **Status: completed
Research for HIL-1321; approved behavior, current facts, and proposed technical
implementation are distinguished below.** [Q1 is closed: A](#q1), the owner's
choice to retain historical allocation after departure and layout changes.
Q2/Q3 and cards A–G remain proposals for separate approval. This is not an
implemented API or a replacement for
[table-subscription.md](table-subscription.md) or
[styling-rules.md](styling-rules.md).

The technical conclusion is that a numeric snapshot of a record's complete
rendered footprint, together with a snapshot of the wide table's column tracks,
can preserve departure geometry without retaining the departed content. The
snapshot must precede the first destructive presentation change, including the
early removal of a running row's progress bar. Keeping only the main row's
height, keeping only a permanent state column, or measuring after Apply cannot
provide the guarantee. Browser validation of the proposed mechanism remains
implementation work.

## Research basis and evidence

- Interview Code Base: `c7e4696e8`. This research inspected the current
  `HIL-1321` tree at `ec55c6bce893fc3f21bd6714210fdd0e76a66530` on 2026-10-13.
  Source line numbers below refer to that tree; the adjacent symbol or test
  title is the durable anchor.
- Design Base: `49d8855` in the operations repository. The inputs were read
  from the operations repository at `49d8855`:
  `mockups/components/table/index.html`, sections 3–5 and 9, and
  `mockups/foundations/rules/index.html`, especially rules 2, 3 and 5.
  The framework repository does not contain these mockups.
- Owner evidence: the interview map for HIL-1321 and the verbatim
  Data/Flow/Design results in its first two briefs, all kept in the operations
  repository. The first source-map pass is commit `2751c4f21`; the final pass
  extends it without product changes. D-210 was read from the design-debt
  record D-210: owner-approved, waiting, recorded by the interview in
  operations commit `73d9854`.
- The review correction uses the owner's Q1=A answer of 2026-10-13, recorded in
  the interview notes for HIL-1321 in the operations repository, Jira comment
  25820 and the supplied third brief (Telegram 5126, reply to 5123). It updates
  the report from `a7177deb3`; it does not change the implementation evidence
  base.
- A path-limited log and diff from Code Base confirm only union formatting in
  `tableFrame.ts` / `hilosUsers.ts`, the Vue Bootstrap color comment, and the
  styling canon's theme clarification in the relevant implementation inputs.
  They do not change the geometry investigated here.
- Evidence is **source inspection**, including test assertions, and browser
  platform specifications. No browser was launched for this research; no
  heights, widths, timing, zoom behavior, or 375 px screenshots were measured.
  Existing test source is evidence of coverage intent, not a fresh test result.
  The first documentation pass ran the stand-wrapped `composer run
  test:framework:unit`: exit 0, 8,765 tests, 325,581 assertions, one skipped
  test. Its 70 report/index local file links resolved and `git diff --check`
  passed. This historical check is not browser evidence. Final document
  validation is recorded at the end; no frontend unit or e2e suite is claimed.

The shared mechanism belongs to the SDK. The agnostic core owns window and
presentation identities and numeric state; view packages own DOM measurement
and their sanctioned Sass files. Demo declarations choose the height mode and
provide cell values. A project-specific observer or another way to build
tables would split this responsibility incorrectly; see
[framework-development.md](../framework-development.md).

## Owner decisions — approved behavior

These are the interview's answers and the later Q1 decision, not choices made
by this research.

| Subject | Owner's words | Consequence |
|---|---|---|
| Permanent state column | «да» | Reserve its place and enough room for its simultaneous marks, even while empty. Marks must not redistribute content columns. The empty strip is an accepted cost. |
| Apply and movement | «Разумеется да. И это не исключение -- а действие пользователя которое допускает изменение чего-то там» | Apply shows the correct new order. An own change also takes effect immediately. These are user actions that permit movement. |
| A set whose count became zero | «да» | Keep the departed slots until the window changes; report the true zero count. |
| Height choices | «Давай сделаем все варианты и хоть где-то но применим хотябы по одному разу. Это же "demo"» | Support content height, a reservation of N text lines, and a specific height. N lines reserve room even for short content. Content height may differ between records. Automatic equalization to the tallest record was not chosen. |
| Demo allocation | «Да» | Settings use content height, chat bots use N lines, users use a specific height. Each must become an actual application, not only a type or a test. |
| Overflow | «Скрол не надо, давай явное раскрытие-скрытие» | Explicitly expand and collapse text inside the record, without editing or an internal text scrollbar. Expansion may grow the record; collapse restores its declared base. Support keyboard and phone access, not just hover or title. Controls cannot be clipped to enforce the text limit. |
| Mockup discrepancy | «Да, апрув» | D-210 covers the permanent state column, full placeholder, zero set, and height/expansion in both projections. It does not approve numeric sizes, an API, or behavior after resize/font changes. |
| Q1: layout changes after departure | «Да, в таком случае варианта "А" давай». | Keep each projection's captured height after width/font/projection changes, growing only to fit the readable removal reason. A projection without a usable capture starts with a generic empty allocation and keeps its last allocation through later layout changes. |

<a id="q1"></a>

**Q1 — closed, A accepted on 2026-10-13.** The source is Telegram 5126
(reply to 5123), recorded in Jira comment 25820 and the operations note.
Policy B, replacing historical allocation with a current generic size after
layout invalidation, was rejected. This answer is separate from D-210 and
approves the retention behavior; it is not a separate approval of every
technical fallback dimension. The proposed 3rem body, shell/gap and reason
floor remain the concrete implementation design below. Cards A–G have not
been approved by this answer. [Q2](#q2) (API/default/presets/parts) and
[Q3](#q3) (demo parameters and table-only settings wrapping) remain proposals
for separate approval with their cards.

The inherited 2026-10-03 boundary is «держится МЕСТО, а не содержимое»:
preserve the whole departed record's space, including expanded text, an open
detail panel, and row work; show none of the departed data. The removal reason
is still meaningful: deleted, moved outside the window, or left the set.

On a 375 px screen the text limit concerns field values. The footprint to keep
is nevertheless the entire card, including labels, the title, badges, actions,
detail, progress and spacing. A text-line reservation is not a card height.

**Out of scope:** product implementation, ports, demo edits or tests of new
behavior; DB/RT/signal/route changes; creation of tickets or proposals;
redrawing the mockup (D-210, «Применить долги»); removal of undeclared tables
(HIL-1322); reconsidering HIL-1009/HIL-1094. PR-088 is an orphan promise about
tiles in mockup section 10, outside this research. No new promise or debt is
created here.

## Current behavior map — facts from source

### Controller and data lifetime

The anchors in this table are in
[TableViewportController.ts](../../../framework/frontend/core/src/table/TableViewportController.ts).

| Anchor and line | Current behavior | Geometry consequence |
|---|---|---|
| `TableViewportRow`, 271; `rows = computedSignal`, 899 | A placeholder exposes `row: null`, no expansion, no selection and no stale sources. The window still holds the original normalized `TableRow`. | The view no longer has values to measure after replacement. A null view-model alone does not prove the old row payload has been released. |
| `ingestDelta`, 2281; `row_removed`, 2316 | Value updates are live; foreign moves/removals wait. A foreign removal drops its row progress immediately, before Apply. | The first lost height can be the bar while the row is still pending. |
| `applyOwnDelta`, 2412 | Own updates/moves land immediately; own removal sets the placeholder reason. | Own removal needs the same pre-change measurement without an Apply click. |
| `apply`, 2441 | Removals become placeholders, then moves apply in ascending destination order; counts are already live. | A batch may contain both preserved departure slots and legitimate row movement. Apply is not a fresh window or a blanket geometry reset. |
| `placedWindow`, 2810 | Replaces the held row, removes it from its old position and inserts it at the clamped destination. | Preserve correct ordering. A move within the window is not a departure placeholder; `moved_out` is a removal reason. |
| `settleEmptyWindow`, 2998 | If no live rows remain and total is zero, clears the window, placeholders, anchors, selection, expansion, pending and announcements. | Violates the newly approved zero-set rule. Both count-before-Apply and count-after-Apply reach this path. |
| `ingestAppend`, 2137 | Appends at the physical tail, updates count and anchors, and does not trim the held array. | A future preserved empty window must keep its old slots when an authorized append arrives. |
| `ingestOwnCreate`, 2190 | Deduplicates the new key, inserts at the supplied position, evicts at the appropriate edge to the page size, clears pending/placeholder/selection/expansion for affected keys. | Key reuse cannot inherit old geometry; creation remains a user action with immediate placement. |
| `frame.footer`, 1200; `frame.body`, 1221 | Footer range uses window length; a nonempty held window makes body `rows`, independent of count. | Keeping zero-count placeholders requires separating occupied slots from live count/range, not drawing a second empty tile over them. |
| `changeWindow`, 2899; `ingestWindow`, 1779; `ingestRefusal`, 1711 | Explicit changes clear local state and request a window; delivered snapshots replace rows; refusal clears them. Old rows can remain during the delayed-skeleton wait. | Geometry needs an explicit window lifetime and must never resurrect cleared placeholder data during that wait. |

[TableRowsStore.ts](../../../framework/frontend/core/src/state/TableRowsStore.ts)
`TableRow`, line 20, allows both entity references and plain values in `slots`.
[normalizer.ts](../../../framework/frontend/core/src/state/normalizer.ts)
`normalizeTableRow`, line 264, keeps plain values and reduces entity fragments
to references; it does not write this viewport row into a table store.
[bindTableViewport.ts](../../../framework/frontend/core/src/subscription/bindTableViewport.ts),
`unsubscribeDelta`, line 168, normalizes before calling `sink.ingestDelta`.
Consequently, preserving the old `windowSignal` payload is not an acceptable
way to claim that geometry retains numbers only. The implementation must retire
the placeholder's raw slots as well as its rendered values. This does not grant
permission to delete an entity from a shared store or an independently owned
edit session that still needs it.

### The three views

| View | State column / spanning cells | Departure, work and detail | Both projections |
|---|---|---|---|
| [Vue](../../../framework/frontend/vue/src/HilosViewportTable.vue) | `markColumn` 231, `bodyColspan` 242 | Placeholder cell 665; progress row 797; detail row 835; card placeholder 918 | Wide visibility 494; cards 904; card detail 1049 and progress 1108 |
| [React](../../../framework/frontend/react/src/HilosViewportTable.tsx) | `markColumn` 225, `bodyColspan` 232 | Placeholder cell 876; progress row 960; detail 988; card placeholder 1082 | Wide visibility 711; cards 1063; card content helper at 436 |
| [Angular](../../../framework/frontend/angular/src/HilosViewportTable.ts) | `markColumn` 1155, `bodyColspan` 1166 | Placeholder cell 351; progress row 497; detail 530; card placeholder 616 | Wide visibility 181; cards 603; card detail 784 and progress 854 |

All three compute the state column from pending changes, stale sources, or
declared detail fields. Even when a detail declaration keeps the column
present, its content changes from a chevron to a snowflake plus a waiting badge
plus a chevron. Presence alone therefore does not stabilize its intrinsic
width. Header stale marks can likewise change a content column's width.

Wide records consist of up to three sibling rows: main cells, row progress,
then detail. A departure renders one ordinary full-span cell; neither its
former height nor the vanished siblings' extent is represented. There is no
column-track snapshot. Removing the widest cell can redistribute every
content column, change wrapping in surviving rows and move records below them.

Cards contain a title and badge group, a description list of labels/values,
optional detail, actions, then progress. A departure replaces that content with
a short reason. The `card mb-2` shell stays, but its intrinsic height shrinks.
All three mount both branches; `d-none d-md-block` and `d-md-none` select the
visible one. Both must discard departed content, even though only one has a
layout box. Detail panels already have different ID bases between projections.

The sanctioned style layers are
[Vue Sass](../../../framework/frontend/vue/src/hilos-styles.scss),
[React Sass](../../../framework/frontend/react/src/hilos-styles.scss), and
[Angular Sass](../../../framework/frontend/angular/src/hilos-styles.scss).
Their `.hilos-progress`, `.hilos-progress-track`,
`.hilos-table-selection-cell` and `.hilos-stack` rules demonstrate the numeric
custom-property channel, fixed framework chrome, and overlapping static room
holders. None implements departed-record height or frozen column tracks.

### Declarations and demo seams

[HilosTableFrame](../../../framework/frontend/core/src/table/tableFrame.ts),
line 137, declares the frame and columns, with no height policy.
[HilosTableColumn](../../../framework/frontend/core/src/table/hilosTableColumn.ts),
line 32, already declares `card`, `detail`, `detailWide`, `progress`, classes and
rendered field dependencies, but no text-height policy.
[tableCard.ts](../../../framework/frontend/core/src/table/tableCard.ts)
`hilosTableCard`, line 52, derives the card from these same columns. Height
declarations should extend this shared description rather than add per-port
or per-card definitions.

| Approved application | Existing declaration | Current source fact |
|---|---|---|
| Settings: content | [hilosSettings.ts](../../../framework/frontend/core/src/admin/settings/hilosSettings.ts), `SETTINGS_COLUMNS` 214 / `SETTINGS_FRAME` 248 | Key, value and actions; height is currently intrinsic. |
| Chat bots: N lines | [adminBotsPage.ts](../../../demo/chat/frontend/src/views/AdminBots/adminBotsPage.ts), `BOTS_COLUMNS` 81 / `botsFrame` 95 | Name is card title, status is badge, description and active are fields, actions are declared. A frame already exists; HIL-1322 is not a prerequisite. |
| Users: specific height | [hilosUsers.ts](../../../framework/frontend/core/src/admin/users/hilosUsers.ts), `USERS_COLUMNS` 621 / `USERS_FRAME` 653 | ID, name, presence, sessions, activity and actions. Dynamic presence does not imply a height contract. |

[AdminBots.vue](../../../demo/chat/frontend/src/views/AdminBots/AdminBots.vue),
`cell-description` at 367, currently truncates to one line, uses a `title`,
and has an inline `max-width: 18rem`. That existing inline style is recorded
in [the style baseline](../../../framework/frontend/codestyle/baseline.txt),
line 9, under HIL-449. It is not a precedent for the proposed measurement
channel. The future demo application must reconcile that existing clipping
with N-line reservation and explicit reading; this research changes neither
the demo nor the debt.

### Agreement with the mockup and D-210

At the pinned mockup, section 4's “последняя колонка — фреймворка”
(835–850) makes the column conditional and content-sized. Its placeholder
section (865–898) shows compact rows and clears them at zero count. Section 9
(1870–1882) ties absence of the wide state column to absence of detail.
Those current code behaviors match the old drawing, but D-210 supersedes these
specific decisions. Sections 4/9 also lack the approved three height modes and
explicit text expansion. The remaining layout, card field projection, work
semantics and reserved live-message room remain inputs, not redesign targets.

### Existing coverage — read, not run as geometry experiments

- [Controller tests](../../../framework/frontend/core/test/table/TableViewportController.test.ts):
  “holds a move until Apply” (1043), `moved_out` placeholder (1114), removal
  reasons (1174), empty convergence and both count orderings (1220–1268),
  placeholders over a nonempty set (1270), tail append after convergence
  (1363), own-create eviction/reused key (1577/1610), own removal (1737),
  own move (2067), and immediate progress removal (2586). These assert
  state and ordering. Several intentionally assert the old zero-set behavior
  and will need changed expectations in the implementing work.
- [Vue tests](../../../framework/frontend/vue/src/HilosViewportTable.test.ts),
  state-column growth (291), empty tile after convergence (1120), no bar under
  placeholder (1834), and separate detail IDs (1459);
  [React tests](../../../framework/frontend/react/test/HilosViewportTable.test.tsx),
  the same anchors at 260, 942, 1842 and 2002;
  [Angular tests](../../../framework/frontend/angular/test/HilosViewportTable.test.ts),
  at 545, 1277, 1911 and 2107. Mark-column assertions count header cells and
  `colspan`; convergence assertions require zero placeholders and two empty
  states. They are not assertions about actual rendered dimensions.
- [table.ts](../../../framework/frontend/e2e/table.ts) owns projection-aware
  locators and `sidewaysOverflow`. The brief names it as the shared starting
  point, but the actual `watchFirstRowTop` implementation is in
  [geometry.ts](../../../framework/frontend/e2e/geometry.ts), line 319. It
  measures the first main row relative to the table root, avoiding scroll
  offsets. It cannot detect loss of a later record's detail or a changed width.
- [bots.spec.ts](../../../demo/chat/tests/e2e/tests/bots.spec.ts) uses that
  watcher at 390, 628 and 691 for announcement room. The last filtered deletion
  test (818) currently expects no placeholder after Apply. The 375 px test
  (1183) checks visibility, zero sideways overflow and opening Edit. Neither
  establishes the proposed full-record geometry guarantee.

## Proposed technical implementation

This section is a technical design to validate in implementation, not a new
normative contract or an assertion that a browser experiment has passed.

### What is measured and what survives

Use a presentation lifetime identified by table mount, delivered window
generation, record occurrence and projection. A reused row key gets a fresh
occurrence: a new record must not inherit the departed record's panel, text
expansion, work reserve or measurements. Keep column measurements once per
wide-table layout generation, not once per row.

The geometry store keeps only keys/occurrences, removal reason, numeric
dimensions and layout validity metadata. Its numeric record includes the
main block, any row-work block, any detail block, their occupied separation,
and the complete footprint. Separate component extents allow departure-time
work to be removed semantically without losing its space. It does not hold
`R`, `TableRow.slots`, an entity reference, a progress `detail` object, text,
HTML, a VNode, a renderer closure over a row, or a detached DOM subtree.

For a wide record, read the main row's top and the last owned sibling's bottom
in the same layout pass. Account for the actual table border model and any
spacing to the next record; measuring just a `<td>` or summing content heights
is insufficient. Include the last record's bottom even when there is no next
record. For a card, measure the outer card border box and its inter-card space
separately; `mb-2` is outside the border box and must not be added twice.
Store fractional CSS-pixel values without per-record integer rounding.
Document-relative absolute positions are unnecessary retention; derive extents
from boundary differences.

`getBoundingClientRect()` supplies rendered boxes; a branch with no CSS boxes
cannot supply a useful footprint. The CSSOM View definition returns a zero
rectangle for an empty rectangle list. Read the visible projection and record
whether a measurement exists; zero from a hidden branch is not a replacement
for a valid cached height. These platform facts come from
[CSSOM View, element geometry](https://drafts.csswg.org/cssom-view/#dom-element-getboundingclientrect).

Retire the departed occurrence into a local tombstone containing only its
identity and reason; do not keep raw slots underneath `row: null`. Drop its
selection, detail/text expansion and row-work payload when no longer used by
the live/pending record. Remove the old content in **both** mounted branches
and release every geometry observer's reference to those old elements. A
placeholder may contain only framework reason text/icon and empty sizing
structure. Static framework copy is not the departed record's data.

### Capture before destruction, render once with the retained size

1. On a visible live layout, maintain the latest measured record extents and
   column tracks. Observe changes while that content still exists. Refresh
   after a user expands or collapses text/detail and after live content lays
   out; a maximum-ever height would incorrectly undo a deliberate collapse.
2. Add a synchronous, local pre-transition measurement boundary before any
   state publication that removes a record or part of its departure footprint.
   The view reads the old connected DOM; the core receives only numbers. This
   boundary is needed for foreign removal before `dropRowProgress`, own
   removal, Apply removals, and retirement during an own-create replacement.
   It is not a new server signal or DTO.
3. A foreign departure can remove the running-work display before Apply. Keep
   that bar's **numeric occupied extent** as an empty part of this departing
   record while the pending gate still holds its values. At Apply, measure the
   remaining live parts and combine them with that departure reserve, then
   replace the whole record with its placeholder. Do not keep a dead progress
   payload to render an invisible bar. If work ended earlier as a separate
   event, it is already absent and is not retrospectively restored.
4. Publish the tombstone and captured size coherently. The first render of the
   placeholder must already have its footprint; an after-render effect that
   repairs a one-line placeholder permits the very intermediate collapse being
   prevented. Measure all affected records before mutating any of them in a
   batch, and before recalculating the column layout.
5. Clear old element references after replacement, reject late observer
   callbacks for old occurrences/generations, and leave immutable numbers
   behind. Do not measure the placeholder and replace its original footprint
   with the height of the reason label.

A `ResizeObserver` is useful for maintaining the live cache, not sufficient as
the sole departure hook. Its notifications can describe removal or a switch
to `display: none`; they do not reconstruct the preceding content. Disconnect
retired targets and avoid feeding the size imposed on a placeholder back into
its source measurement. See the
[Resize Observer specification](https://drafts.csswg.org/resize-observer/#introduction).

The binding normalizes values before the controller receives a delta. Thus an
implementation must also audit normalization-triggered view updates: the old
DOM has to be sampled before any synchronous subscriber can destroy it. A
local batch/pre-publication boundary around that ingress, together with
view commit integration, can provide the ordering. Merely adding an observer
inside `apply()` cannot. Vue, React and Angular must each prove this ordering
under their own render scheduling; hook names alone are not proof. No row
payload should be cached to compensate for a missed measurement. An unmeasured
record that has never been painted has no displayed historical footprint;
losing a measurement of an already painted record is an implementation defect.

### Holding content-column widths

Reserve state chrome before taking the initial content layout. Then measure
the actual used column tracks, including the selection and actions columns,
and the table width. Derive tracks from aligned cell boundaries with the
table's border/spacing accounting; do not add border-box widths blindly.
Retain the ordered column-key vector as part of the layout identity.

The proposed wide renderer uses a complete `<colgroup>` and a Sass-controlled
fixed table layout with measured track widths and table width. Every column
has a width, so subsequent rows and a full-span placeholder cannot redistribute
tracks. The fixed algorithm's horizontal sizing depends on table/column
widths and borders, not later cell content; auto layout does depend on content.
A `min-width` on surviving cells, or a width declaration while retaining auto
layout, is not equivalent. See
[CSS 2.2, fixed table layout](https://www.w3.org/TR/CSS22/tables.html#fixed-table-layout).

Calibrate the auto-to-fixed handoff while the live rows are still present,
before they can depart: the resulting used boundaries must equal the sampled
ones, including the outer borders and fractional residual. If they differ,
correct that conversion before publishing the captured layout. This is a
browser validation obligation, not a claim that assigning `rect.width` to
each `<col>` is automatically exact.

Keep these widths for the valid layout generation, including after removal of
the widest cell, subsequent removals, all-placeholder state, and a permitted
tail append. Newly appended content uses the existing tracks; it must not
resize earlier rows through column redistribution. Text uses the declared
wrapping/expansion policy inside its track. Interactive content must remain
reachable and may not be clipped by a whole-cell overflow rule. Changes to
live row heights are a separate boundary below.

This stores one vector of numbers, not the widest cell's text or an invisible
copy of its DOM. Measuring again from surviving content after each removal
would lose the widest contributor and defeat the design. A permanent state
column addresses only one of the sources of horizontal movement.

### Permanent state room and combinations

Reserve independent places for the freshness mark, the waiting state, and the
detail control, in that order. The waiting place fits the larger of “Will
move” and “Will leave”; those two never coexist. The detail place fits both
chevron directions even if a row is not expandable. Header freshness chrome
also needs stable room so that the first snowflake cannot widen its column.

| Freshness | Pending | Detail | Required room |
|---|---|---|---|
| Current or stale | None, move, or remove | Absent, collapsed, or expanded | The same three reserved places for all 18 combinations; absent children contribute no focus target or spoken state. |
| Any of the above | Any of the above | Any of the above | Highlight/selection/tint changes do not change the footprint. Selection has its own column/edge, outside the state group. |
| Departed | None | Absent | The full record placeholder spans the fixed tracks; stale, selection and expand controls are gone. Only the departure reason is shown. |

The room can be established with inert, inaccessible static framework sizing
markup containing the known mark variants, layered with the live controls in
the Sass layer, or its measured numeric equivalent. Such markup contains no
row data, has no IDs/test IDs or interactive controls, and is not a hidden
departed-record copy. It must reserve the simultaneous combination, not just
the largest individual icon. The exact width is not approved by D-210.

For cards, keep the page's badge and the framework marks as separate groups.
Reserve the framework group's complete space from the first render. At 375,
the header may need a stable wrapping arrangement; adding a pending badge
cannot steal title width or push actions down only when it appears. The
reservation and actual layout need the same text metrics. This is a layout
proof required of all ports, not a license to drop labels or badges.

### Sass-only delivery is sufficient

The applicable rule is
[A computed value — the one legal channel](styling-rules.md#a-computed-value--the-one-legal-channel).
Measured CSS-pixel numbers fit that channel. A view may hand a finite measured
number to a **literally named** custom property on the sizing element; only
the sanctioned Sass file consumes it, with WHY/WHAT explaining the missing
Bootstrap utility and the retained record/track. Ordinary `height`/`width`
bindings, `cssText`, injected stylesheets, dynamic property-name indirection,
and hidden copies of departed values remain forbidden.

For the wide placeholder, use a dedicated cell/sizing block with controlled
padding and border accounting, rather than assuming CSS `height` on `<tr>` is
an exact cap. Keep its reason readable within the measured allocation. A card
uses an empty shell sized to its measured border box with its original gap.
The full record allocation replaces main/progress/detail together. Controls
are absent, so no deleted control can remain focusable inside reserved space.

Concretely, subtract the replacement cell's effective vertical border/padding
contribution from the captured occupied extent to obtain the inner sizing
block's allocation. Sass owns those contributions; measurement supplies their
current numeric values. Do not put the captured outer height on a padded inner
block and add that padding a second time. Reserve enough baseline room for the
static removal-reason variants while the row is live, so the reason itself
cannot force a taller replacement at the same width/font. The browser proof
must include collapsed borders and adjacent departed slots: separate rounding
errors must not accumulate after repeated removal.

Static chrome dimensions belong to Bootstrap classes or documented Sass;
do not pass a hardcoded size through a custom property and call it measured.
The N-line/exact-height declaration below distinguishes a runtime-derived
measurement from a static mode/preset. This numeric measurement proposal
requires no weakening of the current styling canon. It also does not replace
the invisible static room holders of HIL-1094's live-message line.

## Height declaration — proposed API, not an export

The proposed default is `{ mode: 'content' }`. It adds no clipping or blank
height to an existing declaration, leaves the existing cell renderers usable,
and does not equalize records. This is a compatibility recommendation by the
research, not an additional answer from the owner. Existing page-specific
truncation remains until its application is migrated; choosing the default
alone cannot undo a `text-truncate` inside a slot.

The declaration governs **ordinary value text**, not an absolute outer height
on `<tr>` or on an entire card. In a wide row, cells share the tallest required
main-cell allocation. On a card, each participating field has its own value
allocation; labels, badge groups, actions and gaps add their natural room.
Thus “specific height” is an exact collapsed **text viewport** size. The main
row/card still grows when its unclipped controls require more room. This is
the proposed precise interpretation of the owner's control-safety and 375 px
constraints; it must be reviewed with the API before implementation.

### Shared type and rendering seam

Illustrative additions to the existing core types, not declarations present
in the tree:

```ts
/** Supported static text-line reservations, compiled in every SDK Sass layer. */
export type HilosTableLineCount = 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 | 11 | 12

/** Supported text viewport sizes in root-font units, not CSS pixels. */
export type HilosTableTextRem = 2 | 3 | 4.5 | 6 | 9 | 12

/** Collapsed ordinary value text; detail and work have their own allocation. */
export type HilosTableRowHeight =
  | { readonly mode: 'content' }
  | { readonly mode: 'lines'; readonly count: HilosTableLineCount }
  | { readonly mode: 'height'; readonly rem: HilosTableTextRem }

/** Proposed member added to HilosTableFrame. */
interface HilosTableHeightDeclaration {
  readonly rowHeight?: HilosTableRowHeight
}

/** Proposed member added to HilosTableColumn, inherited by HilosTableColumnOf. */
interface HilosTableValueDeclaration {
  readonly heightContent?: 'text' | 'parts'
}
```

These two small interfaces illustrate members to merge into the existing
frame/column interfaces; they are not a second table declaration. The core
would export the policy, validate it once when the declaration is consumed,
and expose the normalized policy to all views. It performs no DOM or CSS work.
`heightContent` is opt-in because the SDK cannot infer which arbitrary cell
children are text and which are controls:

| Column declaration | Proposed rendering responsibility |
|---|---|
| Absent | Existing intrinsic slot. No automatic clipping or reservation. Appropriate for badges, controls, images and actions. |
| `text` | The SDK wraps the existing cell renderer as one ordinary text region. Only noninteractive inline text/formatting belongs in it; no buttons, links, form fields, media or independently sized blocks. |
| `parts` | The cell keeps mixed content. Its author wraps only the text in the proposed SDK `HilosTableText`; badges and controls remain outside. The SDK supplies the same row/column/policy context in the wide cell and card field. |

The proposed `HilosTableText` takes a `value: Hideable<string>` and renders
plain text or the existing hidden mark. A hidden mark stands outside any
clipping, with the declared allocation as a minimum and no disclosure button.
It takes neither a height prop nor an arbitrary child slot. It consumes its
cell's context, including column key,
occurrence and projection, and owns its disclosure button. This gives the
mixed-cell path a typed, noninteractive text boundary without inventing a
second place to configure height. It must not stringify a hidden mark or
extract a value from an edit draft. Vue passes `:value`, React `value={…}`,
Angular `[value]`; their public input is the same. Rendering it outside a
`parts` cell is a developer error. A `parts` cell in a limited mode declares
exactly one such main text region; its other content remains intrinsic.

A `text` column may use the existing named slot/render function/template, but
its declared content contract must be checked in development and tested. If
interactive content appears, keep it fully rendered without clipping and
report the declaration error; never create a keyboard target hidden by the
budget. Authors migrate mixed cells to `parts`, rather than relying on a DOM
heuristic to remove controls. `detail: true`, the actions key and `card: 'badge'`
refuse `heightContent`: their content is outside this policy. A missing cell
renderer keeps the existing absent-card-field behavior, not a fabricated
N-line field. Empty text from a present renderer still reserves its budget.

There is one logical text-expanded state per occurrence and column, separate
from the existing detail-expanded state. Both projections share that intent
but have distinct DOM IDs and overflow measurements. Hidden projections do
not measure or reset it. Movement within the window keeps it; departure,
occurrence replacement, window replacement and disposal clear it. These are
local presentation members, not RT items or wire fields.

### Static declarations stay in Sass; measured geometry stays numeric

The closed numeric sets are a deliberate technical proposal: N = 1–12 and
sizes = 2, 3, 4.5, 6, 9, 12 rem cover compact values through long descriptions
without a runtime stylesheet. All values have generated classes in **each**
sanctioned SDK Sass file, with WHY/WHAT comments. For example, `count: 3`
selects `hilos-table-text-lines-3`; `rem: 4.5` selects
`hilos-table-text-height-4-5`. A pure core mapping can return class tokens,
never a style object. A package check must prove the type/validator/catalog
and all three compiled class sets agree.

An illustrative Sass rule for the proposed plain-text viewport is:

```scss
// WHY: Bootstrap has no reserved multi-line text viewport with a reading control.
// WHAT: three line-heights of ordinary text, including when its value is short.
.hilos-table-text-lines-3 {
  block-size: 3lh;
  overflow: hidden;
}

// WHY: Bootstrap has no 4.5rem text viewport; controls stand outside this block.
// WHAT: the fixed collapsed text allocation used by the users demonstration.
.hilos-table-text-height-4-5 {
  block-size: 4.5rem;
  overflow: hidden;
}
```

The viewport has no padding/border of its own; the surrounding SDK structure
owns those and the disclosure-control room. An expanded class removes the
text cap and allows natural wrapping, with the declared allocation as a
minimum; collapse restores exactly the selected base. No new Sass file or
page-specific stylesheet is implied by this example.

`rem` follows the root font size; `lh` follows the element's computed line
height. Importantly, `lh` describes a typographic unit and does **not** prove
that arbitrary rich content occupies exactly N line boxes. Restricting this
seam to ordinary text with uniform metrics makes it useful; mixed rich
content remains intrinsic. See [CSS Values and Units, font-relative lengths](https://www.w3.org/TR/css-values-4/#font-relative-lengths).

A count of three means a three-line-height reserve at the current font for
**both** “Helper” and a paragraph. It does not mean three characters, three
source newline-separated strings, or merely `max-height`. Proposed text
rendering preserves explicit line breaks and wraps long tokens within the
available track (`white-space: pre-wrap` and `overflow-wrap: anywhere` in
Sass). A partially visible final glyph at a size boundary is not the reading
surface: Show more exposes the complete value. Implementers must prove the
clip and expansion with actual fonts and the supported browsers.

Specific sizes use rem to scale with the root font instead of an unscalable
pixel parameter. At a hypothetical 16 px root, 4.5 rem is 72 CSS px; at 20 px,
it is 90 CSS px. Those are arithmetic examples, **not browser measurements**.
A text-only zoom or user spacing override can change actual line metrics
independently of that base; the disclosure remains outside the cap and must
still expose all text. No claim of constant line count is made for height mode.
Box accounting excludes the cell's padding/borders, as distinguished by
[CSS Box Sizing](https://www.w3.org/TR/css-sizing-3/#box-sizing).

The static count/size never travels through `--hilos-*`. Converting `4.5` to
pixels in JS or measuring a dummy sized from it and calling that a computed
value would only disguise a static setting. Numeric custom properties remain
reserved for **actual live layout measurements**: record extents, tracks and
runtime control-room measurements. More static sizes mean extending the SDK
catalog and its Sass/tests. An arbitrary runtime CSS length, a free-form
`calc()` string, percentages, px, vh, or project Sass overrides are not this
proposal. If a later API requires them, it must return the style-rule conflict
to the owner; this research does not loosen the canon.

Validation rejects unknown modes, mode-incompatible members, nonfinite or
unsupported numbers, fractions for N, numeric strings and missing count/size.
There is no silent rounding, nearest preset, clamping, or fallback to content
for a malformed declaration. Omission alone means content. Dynamic column
lists are validated when their structure changes, and a changed declaration
invalidates live layout measurements. Departed allocations follow Q1=A below;
layout invalidation does not discard their captured height. A limited policy
with no participating main text region is a configuration error, not a
successfully demonstrated mode.

### Reading, controls, detail and work

Limited text gets a real **Show more / Show less** button with a name including
the field label, `aria-expanded`, and a projection-specific `aria-controls`.
It sits outside the clipped text and outside the detail chevron. Use stock
Bootstrap button/focus styling. Enter, Space and touch operate it; no hover,
`title`, edit dialog or inner scrollbar is needed. This follows the
[WAI disclosure pattern](https://www.w3.org/WAI/ARIA/apg/patterns/disclosure/).

Reserve the disclosure row for each limited text region even when its short
value needs no button: an inert static holder for the longer of the two button
labels, using the same Bootstrap markup/metrics and the existing Sass stacking
pattern. It has no IDs, controls or row data. Overflow of the **live** text
viewport determines whether a real button exists. Expanded state keeps Show
less even after a live value becomes shorter, so its focused control does not
vanish; the user can return to the base deliberately. Content mode has neither
cap nor disclosure room. Reading must remain possible under user text spacing;
see [WCAG text spacing](https://www.w3.org/WAI/WCAG21/Understanding/text-spacing.html).

The full live value can remain as the single text node clipped by its viewport;
no duplicate copy is needed to detect overflow. CSS clipping of plain text is
not an ARIA instruction to hide the complete value from assistive technology.
Both DOM copies are removed on departure. The live overflow measurement is
not retained in a tombstone as text, HTML, a closure or an element reference.

Cell buttons, links, hidden-value indicators and badges remain in normal flow.
Their wrapping may make the row/card taller than its text allocation. Text
expansion changes only its text region; detail still exposes only declared
`detail` fields and work still uses its separate row/card block. Expanding text
does not imply detail expansion, edit, row selection, or a wire action. The
full measured departure footprint includes all three independently.

On collapse keep focus on Show less as it becomes Show more. On departure of
that focused record, the view moves focus to the surviving table heading/root
with its existing accessible name (temporarily programmatically focusable),
without scrolling to an unrelated row or retaining a dead control. Do not
steal focus when the departed record did not contain it. A DOM test verifies
focus mechanics; a browser/a11y test verifies reachability and announcements.

## Demo applications — complete proposed configurations

These are edits for the later application leaf, not code changed by this
Research. The examples extend the existing declarations and preserve their
search, filters, actions, `reads`, `source`, `card` and `detail` members. All
numbers below are research choices for review, not owner-approved dimensions.

### Settings: content, including the value renderer

```ts
const SETTINGS_FRAME: HilosTableFrame = {
  search: { placeholder: 'Search settings…' },
  rowHeight: { mode: 'content' },
  columns: SETTINGS_COLUMNS,
  empty: { title: 'No settings yet.' },
}
```

Keep the actual key/value/actions columns at `hilosSettings.ts:214` intrinsic;
no `heightContent` is necessary. The card's default title is Key, its field is
Value, and its bottom row is the existing edit/reset actions.

The frame line alone is insufficient. At
[HilosSettingsPage.vue](../../../framework/frontend/vue/src/admin/settings/HilosSettingsPage.vue):250
there is an 18rem inline wrapper, and
[HilosSettingValueCell.vue](../../../framework/frontend/vue/src/admin/settings/HilosSettingValueCell.vue):80
uses `text-truncate` for the value (and at 95 for the reference key). The React and Angular
versions have the corresponding value-cell seams. The application leaf must
make the **table instance** wrap the complete value/reference text, with its
source badge and boolean checkbox visible; it must not accidentally change
the shared renderer's edit-dialog use. A proposed optional `wrap` input on all
three value-cell ports, default false and set by the table, is a concrete
local presentation seam. Remove the table's old inline cap and adjust only
its now-resolved baseline entries. This requires no wire field.

| Fixture | Wide view | 375 px card | Reading and departure |
|---|---|---|---|
| Short key and integer `5`, or boolean true | Intrinsic small cells; the checkbox/source badge and edit/reset buttons are uncut. | Key title, labeled Value and actions all remain. | No Show more for content mode. Departure keeps this whole small footprint. |
| Existing string setting with a long paragraph, long key or reference key | Value/key wrap within established tracks; this row may be taller than its neighbors. | Narrow Value width makes more lines; reference/default/custom badge takes its own room. | Full text is already readable. No spurious collapse control. Deleting/leaving captures its larger card/row, without value or key in the placeholder. |

Use a setting whose existing catalog permits a long string; do not create a
new setting or change its schema solely to carry the demo. Cover hidden values,
empty string and null with their existing marks. There is no height-mode
expansion/collapse in content mode; the acceptance assertion is their absence.
Detail, if declared by a separate table fixture, still toggles independently.

### Chat bots: three lines in Name and Description

```ts
const BOTS_COLUMNS: HilosTableColumnOf<BotRow>[] = [
  { key: 'name', label: 'Name', sortable: true, card: 'title', heightContent: 'text' },
  { key: 'description', label: 'Description', heightContent: 'text' },
  { key: 'status', label: 'Status', sortable: true, card: 'badge' },
  { key: 'active', label: 'Active', sortable: true },
  {
    key: HILOS_TABLE_ACTIONS_KEY,
    label: '',
    headerClass: 'text-end',
    cellClass: 'text-end',
    reads: ['name', 'description', 'style', 'topics', 'personality', 'active'],
  },
]

function botsFrame(view: BotsTableView): HilosTableFrame {
  return {
    search: { placeholder: 'Search bots…' },
    rowHeight: { mode: 'lines', count: 3 },
    columns: BOTS_COLUMNS,
    mainAction: { label: 'Add bot', press: view.openAdd },
    empty: { title: 'No bots yet.' },
  }
}
```

Three lines make a description readable enough to distinguish bots while
keeping a bounded initial text area. The present `cell-description` must
become ordinary wrapping text: remove `text-truncate`, the inline
`max-width: 18rem`, and the title-only reading path. The `fw-medium` name and
`text-body-secondary` description formatting can stay. The framework then
owns their three-line viewport and disclosure. Status and Active remain
badges; Edit/Delete remain buttons; they are never placed inside that viewport.
The application removes its HIL-449 baseline entry when the violating cap is
gone, without claiming the rest of that debt is paid.

| Fixture | Wide view | 375 px card | Expand, collapse and departure |
|---|---|---|---|
| Name `Helper`, description `Answers questions.` or null shown as `—` | Each participating cell reserves 3 line-heights plus disclosure room even though it has one line. | Name title reserves its own 3 lines; Description is a separate labeled 3-line field; Status badge, Active field and Edit/Delete are additional room. The card is **not** three lines tall. | No live text button without overflow. Capture the entire card including its short fields/actions. |
| Long description with explicit line breaks and a long unbroken token; longer valid name | Each text viewport has the same collapsed base as the short case; overflow gets Show more. Fixed tracks do not widen for the token. | More wrapping may cause overflow in both title and Description; either can expand without hiding the badge or actions. | Expand Description to its full text, then collapse to 3 lines. Repeat for Name independently. Depart while expanded and keep that full extent, not 3 lines. |

These remain chat's existing five columns. Style/topics/personality are read
by the editor through actions; they do not become new card fields or a detail
panel just to demonstrate height. A separate SDK fixture supplies detail/work
for the interaction matrix. There is no new bots screen in React or Angular.

### Users: a 4.5rem text viewport

```ts
const USERS_COLUMNS: HilosTableColumnOf<HilosUserRow>[] = [
  {
    key: 'id', label: 'ID', sortable: true,
    cellClass: 'text-body-secondary', heightContent: 'text',
  },
  {
    key: 'name', label: 'Name', sortable: true,
    cellClass: 'fw-medium', heightContent: 'parts',
  },
  {
    key: USER_PRESENCE_FIELD, label: 'Presence', sortable: true,
    source: USER_CONNECTIONS_SLOT,
  },
  {
    key: USER_ONLINE_SESSION_COUNT_FIELD, label: 'Sessions', sortable: true,
    headerClass: 'text-end', cellClass: 'text-end',
    source: USER_CONNECTIONS_SLOT, heightContent: 'text',
  },
  {
    key: 'lastActivity', label: 'Last activity', sortable: true,
    heightContent: 'text',
  },
  {
    key: HILOS_TABLE_ACTIONS_KEY, label: '',
    headerClass: 'text-end', cellClass: 'text-end', reads: ['id'],
  },
]

const USERS_FRAME: HilosTableFrame = {
  search: { placeholder: 'Search users…' },
  rowHeight: { mode: 'height', rem: 4.5 },
  columns: USERS_COLUMNS,
  empty: { title: 'No users yet.' },
  filters: [
    {
      kind: 'select',
      key: HILOS_USERS_LAPSED_FILTER,
      label: 'Past deadline on',
      anyLabel: 'All',
      options: () =>
        (['terms', 'privacy'] as const).map((document) => ({
          value: document,
          label: hilosLegalDocumentLabel(document),
        })),
    },
  ],
}
```

4.5 rem gives a concrete, legible demonstration distinct from counting lines:
a font or line-spacing change alters how much fits while the declared size
remains 4.5 root-font units. It deliberately reserves empty room for a short
name. The expected density on phones is a cost to inspect, not a fabricated
measurement that these numbers already look good.

The `name` slot currently combines `HilosHideable` and the merged-account
badge. Replace only its name rendering with the proposed
`<HilosTableText :value="row.name" />` (React/Angular equivalents); leave the
merged badge beside it outside the capped region. Keep hidden-value semantics.
Presence remains a badge outside the cap, Sessions and Last activity are
ordinary text, and the supplied `row-actions` stay reachable. Preserve the
presence columns' `source` and the actions' `reads: ['id']`.

| Fixture | Wide view | 375 px card | Expand, collapse and departure |
|---|---|---|---|
| ID `7`, name `Ada`, one session and a short activity value | ID, name, sessions and activity each reserve a 4.5rem text box plus disclosure room. Badges/actions determine any extra main-row room. | The existing default title is **ID**, not Name. Name, Presence, Sessions and Last activity are separate fields, plus actions; do not silently redesign that projection. Each participating value has its own 4.5rem allocation. | Short text has no active Show more. Departure holds the complete, potentially tall card. |
| Long valid name, merged badge, presence text, long activity rendering | Name can overflow its text box; the merged badge stays wholly visible beside it. | The narrower Name value may need expansion earlier; labels and Presence badge never clip. | Show more reads the full name, Show less restores 4.5rem. Hidden name shows only the hidden mark and offers no reveal of withheld data. Depart while expanded/detail/work is present in a fixture and keep their full extent. |

Use existing legal user values within backend validation limits. Font/width
fixtures can cause overflow without inventing an invalid person name. Settings
and users are shared framework admin applications, so these changes must reach
Vue, React **and** Angular; chat bots have only their existing Vue application.

## Both projections, window lifetime and boundaries

### Two mounted trees are not two measurable layouts

Keep a separate numeric cache for each mounted projection, scoped to its
actual width/font/layout generation. Do not let hidden-branch zero rectangles
overwrite visible measurements, and do not share a wide height with a card.
Only currently laid-out live content can be measured. The hidden branch must
still switch to the data-free placeholder immediately when a row departs.
Never temporarily reveal or duplicate departed data to manufacture a second
measurement.

A projection that was visited earlier may have numbers, but they are valid
only for the occurrence, expansion state and layout they measured. A live
update while it was hidden invalidates that live cache **before departure**;
such a cache cannot become a usable departure capture. A projection that was
never visible has no historical height at all. In either case it uses the
generic fallback on first display after departure. Once a usable capture is
retired with the departed occurrence, later layout invalidation does not erase
it: Q1=A keeps that projection's last allocation, growing only for the readable
reason. Switching projections never transfers a wide height to a card.

The future height policy must preserve the existing distinction between
ordinary value text, the detail panel and work. At 375, line/size limits apply
to eligible values; labels, the badge, buttons and their wrapping remain part
of the measured card. Text expansion and the existing detail toggle need
separate state and distinct accessible names/IDs. Expansion does not open an
edit modal. Both controls must work with keyboard and touch; collapse returns
focus to a surviving control when needed. A departing focused control needs
a stable focus destination, not a focusable dummy kept in the placeholder.
See [accessibility.md](accessibility.md) and
[conflict-resolution.md](conflict-resolution.md).

### Lifecycle and cleanup

| Event | Proposed geometry handling |
|---|---|
| First delivered window / first visible layout | New window and layout identities; measure live content and establish stable tracks/chrome. |
| Live value update, detail/text expansion or collapse | Refresh that live occurrence's dimensions after layout. Do not replace a different occurrence's departure snapshot. |
| Pending move | Keep the live row and its expansion; no tombstone. |
| Foreign departure notice with running work | Capture before bar removal; discard bar data while keeping its numeric departure reserve. |
| Apply / immediate own removal | Replace content with a data-free slot of the captured full extent. Apply of a move still reorders rows. |
| Layout change after departure / switch projection | Q1=A: keep each projection's last allocation, growing only for its readable reason. A projection with no usable capture starts with the proposed generic fallback; later changes retain that allocation too. Re-measure live content and tracks independently. |
| Further removal / total count 0 | Keep existing snapshots and track widths. A count event is not a window reset. |
| Own-create key reuse / explicit edge eviction | Retire the old occurrence and its observers; the new occurrence starts unselected, collapsed and without inherited dimensions. Preserve authoritative user-action placement. |
| Explicit search/filter/order/page/Show/refresh change | Retire the old logical window's geometry. If outgoing content remains during the delayed answer, its data-free presentation can hold its numbers only until replaced; clearing a placeholder flag must never expose its retired raw row again. |
| Authoritative replacement window, including reconnect | Drop all old geometry and prepare the new window even when it has identical row keys. No dimensions belong to an entity globally. |
| Refusal / unmount / controller replacement | Drop all geometry and element references, disconnect observers, cancel scheduled reads and unregister measurement callbacks. |

Reject callbacks carrying an older window, occurrence or layout identity.
There is no geometry timeout and no persisted cache. Memory is proportional
to retained display slots plus columns, not to row payload size. Long-running
append/delete churn may accumulate many empty slots before a window change;
retention until that change does not imply a bound of `pageSize`. A silent cap
or periodic compaction would change the approved behavior and is not proposed.

### Completely empty set, then another arrival

Do not merely remove the call to `settleEmptyWindow()` and leave every other
consumer unchanged. Separate the display slots that hold space from live
membership, count, range, selection and navigation metadata.

- After the last departure is applied, keep the slots and `body: rows` in
  both projections. Count is already 0; the footer must not say “1–10 of 0”.
  Empty slots are not live rows or bulk-action targets. Clear obsolete
  selection/expansion/pending state without deleting their geometric places.
  Table/bulk work, reports, filters and facets retain their own lifetimes.
- Count-before-removal, count-before-Apply and count-after-Apply must converge
  to the same display. Do not erase pending row values just because count
  reached zero; the pending gate still applies. Zero count with all slots
  departed retires obsolete row-boundary anchors without changing the
  requested filter/order/address or manufacturing a new window request.
- A legitimate `ingestAppend` adds a new live record **after** the retained
  slots. It does not recycle a vacant middle slot, erase history because count
  became positive, or relayout the wide column tracks. The new count and
  supplied anchors remain authoritative. Physical slot count must not be
  mistaken for live capacity or a footer range; a retained slot is not another
  member of the set. No new arrival-classification rule is introduced.
- `ingestOwnCreate` remains immediate and uses the server's position and
  existing user-action eviction direction. Preserve its deduplication of a
  reused key and delete that old occurrence's measurements. Do not force an
  own create to the tail or hold it behind Apply. Its deliberate insertion
  and edge eviction can move the display, as the owner's user-action rule
  permits; a foreign append does not gain that liberty.
- An announcement still changes the existing reserved message room and
  count, not row membership. Show requests a fresh window and clears the old
  slots when it changes the window. Apply neither brings announced rows in
  nor becomes Show. With only settled placeholders, there is nothing pending
  and no reason to offer an Apply that would do nothing.
- A fresh empty snapshot shows the existing empty/filtered-empty state;
  a fresh nonempty snapshot renders just its new rows. Neither inherits
  placeholders from the previous window.

The count/range distinction must be checked for an all-placeholder window
with one appended row and for a mixed window, not only for zero. It is a
client presentation correction; none of this requires inventing another
DB/RT field, signal, payload or route. The implementing work must prove these
transitions against the existing append/own-create frame semantics.

<a id="validity-boundary-and-the-remaining-product-question"></a>

### Validity boundary and approved Q1=A

The departure proof has a specific domain: the same visible projection,
available inline size, font metrics, style/declaration layout and unchanged
surviving content. With fixed tracks and equal occupied extents, replacing a
record contributes the same space; later unchanged records retain their
boundaries. Sequential removals preserve that property independently of
whether the removed record was tallest or widest. This argument does not
require equalizing live records to the tallest one.

For record `j`, its top relative to the stable body start is the sum of the
occupied extents of records before it. Replacing any one summand with an equal
number preserves that top, and its unchanged own extent preserves its bottom.
Fixed tracks keep the other rows' wrapping from invalidating those summands
when the widest contributor disappears. This explains why checking only the
first row's top cannot prove the invariant.

| Change outside that domain | What the research can conclude |
|---|---|
| Container resize / breakpoint crossing | Existing numbers no longer describe the same wrapping. Re-measure live rows and tracks. Q1=A retains each departed projection's last allocation with the reason floor; a branch without a usable departure capture starts with the generic fallback. The departed content cannot be reflowed. |
| Browser zoom | Layout-affecting zoom can change effective width and wrapping; treat the resulting layout change as invalidation. A visual-only magnification is not evidence of a new content layout. Do not multiply old heights by device pixel ratio and claim correctness. |
| Font load, fallback replacement, text-size or spacing change | Re-measure live content and static chrome. A one-time font-ready wait is not a lifetime guarantee; later fonts may load. |
| Live update to a surviving content-height row or its open detail | Values still apply immediately. They may naturally wrap to a different height. Holding them behind Apply or clipping them to old measurements would change the accepted taxonomy. The departed slots stay intact, but this research promises no global immobility caused by unrelated live content. |
| Work ends on a surviving row | Existing work semantics remove its bar without a record placeholder. Do not retain completed work indefinitely by applying the departure rule to this separate event. HIL-1094's reserved message room remains unchanged. |
| Work ends after its record has departed | Clear any obsolete progress state; the captured record footprint remains. The end event cannot shrink the data-free slot. |
| User expands/collapses, applies a move, or makes an own change | Movement is allowed by the user-action rule; update the affected live measurements. That does not authorize clearing unrelated departed slots. |

Observe container and live block size changes, visibility transitions and
font completion; do not rely on a window `resize` listener alone. Font
readiness and its later-load limitation are defined by
[CSS Font Loading, `ready`](https://drafts.csswg.org/css-font-loading/#font-face-set-ready).
Observation notices layout changes; [the closed Q1=A decision](#q1) determines
what remains afterward. Keep each departed projection's last allocated
CSS-pixel footprint. Grow it only if the currently readable framework reason,
including its shell/gap, needs more room; keep that enlarged allocation on
later changes too. Do not scale the historical height by DPR/rem or recompute
it from surviving rows. This applies to width, font, zoom, spacing and
projection changes after capture.

Distinguish that retained departed allocation from a live cache that was
already invalid **before** departure. A never-measured projection or one with
no usable capture needs the proposed generic body: 3rem plus its shell/gap and
the reason floor. Keep this fallback's last allocation on subsequent changes
under the same grow-only-for-reason rule. Neither path reconstructs a departed
card's unmeasured natural height, transfers a wide height to a card, or retains
the old values. The detailed acceptance table below requires A throughout.

Historical blank space is an accepted consequence of Q1=A; a readable reason
or unrelated live reflow can still move later records. The decision does not
promise global immobility across layouts. Slots live until the window changes;
unrelated live updates, arrivals and ordinary work completion retain their
existing semantics. There is no remaining A/B product choice for an
implementing card to reopen.

## Acceptance matrix — implementation obligations

**Evidence key:** S = inspected source/current assertions; P = proposed check,
not executed by this Research. No row below is a browser measurement. The
framework PHP unit run validates the repository/document guards, not CSS.

Use the desktop viewport (proposed fixture: 1280 CSS px) for every browser
geometry scenario, in every port assigned below. A browser check proves no
375 CSS px card and no dark palette ([testing-strategy.md](testing-strategy.md),
"Light theme, desktop width"): the narrow and dark columns below describe the
product, not what a browser test drives. Wait for the socket
transition and a settled layout, but also record intermediate rendered frames:
a final correct rectangle cannot excuse a one-frame collapse. Bookmark at
least three surviving records around the changed slot, their top **and**
bottom relative to a stable body root, each content-column's left/right
boundary, and the occupied bottom of the final record. On a last-record removal
there is no following row to reveal the loss: check the final occupied bottom
and the placeholder's extent explicitly.

Use the same root coordinate system before/after, not document scroll position.
Propose a tolerance of at most 1 CSS px per compared boundary and at the final
occupied bottom, without accumulating one pixel per removed record. Record
raw fractional results and justify any browser-specific tolerance in the
implementing test. Do not subtract legitimate row movement from a test that
claims to prove unchanged geometry; test Apply moves/own placement separately.

For combinations the actual demo does not declare (for example bots with
detail), mount a declared SDK fixture through test-only browser harness code
and drive the existing typed controller inputs. It must run the real view and
Sass, but its synthetic ingress is not evidence of real server delivery.
Keep the corresponding two-window removal/arrival cases on the real demo
table. No production page, route, wire field or new bot detail column is
required just to manufacture coverage.

| Case | Trigger and required observation | Evidence / owning cards |
|---|---|---|
| G01 | Sequential foreign deletions with Apply after each and in one batch; ordinary, tallest, widest and last record. All unchanged surviving boundaries/tracks and total occupied extent stay equal. Remove the widest while a remaining paragraph is close to wrapping. | S: controller keeps slots except at zero; views have no dimensions. P: A/B/C. |
| G02 | `left_set` and `moved_out` removal reasons; retain footprint, show only the correct reason. Do not confuse an in-window move with departure. | S: removal reason handling. P: A/B/C; use the actual protocol discriminants below. |
| G03 | Depart with detail open, work running, text expanded, and every combination of those states. Capture main + work + detail + gaps; no value, hidden branch data, progress detail, action, VNode or raw slots remain in geometry/tombstone. After layout invalidation, Q1=A keeps that full allocation with reason-only growth; test projection fallback as in B01/B02 without reconstructing the lost content. | S: siblings disappear. P: A/B/C; expanded text integration D/E/F/G. |
| G04 | Foreign departure drops progress **before Apply**. Measure before that drop, hold numeric room during pending, then hold it in the final placeholder. Work completion after departure cannot shrink it; completion before departure is not restored. | S: `dropRowProgress` ordering. P: core and each port in A/B/C. |
| G05 | Own removal, Apply batch, normalization that synchronously updates a subscriber, and immediate own-create key reuse. The old connected DOM is sampled before destructive publication; first replacement frame has its size. Late observer callbacks cannot mutate the replacement. | S: normalization precedes sink ingress. P: A/B/C scheduling tests and browsers. |
| G06 | Last member leaves: count before removal, before Apply, and after Apply; both zero and still-positive count with all visible slots departed. Retain placeholders, true count/range, no empty tile on top, no phantom selection or no-op Apply. | S: existing tests assert old convergence. P: A/B/C. |
| G07 | Append after all-placeholder and mixed windows: tail only, count/range correct, retained slots/tracks intact. Own-create inserts immediately at server position, handles both edge evictions and reused key. An announcement changes only reserved message room/count; Show replaces the window, Apply does not fetch arrivals. | S: existing arrival taxonomy. P: A/B/C. |
| G08 | Search/filter/order/page/Show/refresh, reconnect snapshot with the same keys, refusal, disposal and controller replacement. Retire numbers/listeners at the documented boundary; delayed snapshots cannot redisplay retired raw data. | S: current reset paths. P: A/B/C. |
| G09 | All 18 freshness × pending × detail combinations, header snowflakes, selection, contextual tint and badge changes. Framework group and content tracks never grow because a mark appears; labels and page badges remain readable at 375. Empty holders have no focus/IDs/spoken marks. | S: conditional current column. P: A/B/C. |
| G10 | Apply in-window move and own edit/move/create: order must become authoritative immediately as applicable; their permitted movement is asserted as correct. Unrelated departed slots and live-message room persist. | S: current controller semantics. P: A/B/C. |
| H01 | Default/explicit content with short/long values: different natural record heights; no mode disclosure, max-row equalization, or extra reserve. Long settings text/reference really wraps rather than staying locally truncated. | S: frame has no policy, settings renderer truncates. P: D/E/F/G. |
| H02 | Every allowed N, especially 1/3/12; empty, dash, one line, N lines, >N lines, newlines and long token. Each participating value reserves N line-heights even when short; card measures all fields/chrome. | P: D/E/F/G; mock rectangles alone cannot pass. |
| H03 | Every allowed rem size; 4.5rem users short/long/hidden names and merged badge. Text allocation is exact, controls remain fully visible/reachable, whole-card height is not 4.5rem. Test root font and text-only size changes separately. | S: existing mixed name slot. P: D/E/F/G. |
| H04 | Mouse, Enter, Space and touch expand/collapse each overflowing value at both widths. Full text is readable, no nested scrollbar or edit flow; collapse restores current base. Detail and text states/IDs remain distinct. Live shortening while expanded keeps the focused collapse control. | P: D/E/F/G and each port's accessibility checks. |
| H05 | Invalid/missing/extra parameters, NaN/Infinity, 0, negative, fractional N, unsupported rem, string units, actions/detail/badge misuse, absent renderer and interactive `text` slot. Refuse bad config or render unsafe content intrinsically with a diagnostic; never silently clip a control. | P: core validator + view seam in D/E/F. |
| H06 | Compile every preset in all three SDK Sass deliveries; no undeclared style property, static number laundered as measured, CSS injection, project stylesheet or duplicated departed content. Type/catalog/class parity and default omission pass. | S: current Sass-only canon. P: D/E/F. |
| B01 | Both branches mounted: remove data and controls from both DOMs; hidden zeros do not overwrite visible numbers. Switch after departure to a projection with a usable capture, a never-visited one, and one whose live cache was invalidated before departure. Q1=A retains the first projection's own allocation even after later layout changes; the other two start with the generic fallback. No wide-to-card height transfer. | S: both trees mounted. P: A/B/C, approved Q1=A. |
| B02 | Container-only resize, breakpoint crossing, layout zoom, root font change, late font load and text spacing after departure: Q1=A keeps each projection's last allocation and grows only for the readable reason. Repeat changes after generic fallback and after reason-floor growth; neither may shrink or recompute a current generic base. Live rows/tracks remeasure, slots persist; no reconstructed natural content. | P: A/B/C, approved Q1=A. |
| B03 | Live value changes in a survivor, open detail update and ordinary work completion. Values still land live, work clears as before; capture updated live dimensions without freezing unrelated natural growth or shrinking departed slots. | S: existing live semantics. P: A/B/C, integration D/E/F. |
| B04 | Window churn, many append/delete cycles, remount and reused key: no retired observer/closure/data growth after a window reset. Retained numeric slots may exceed page size while that window lives; no timeout/compaction. | P: A/B/C state/cleanup checks. |
| B05 | Focused disclosure/detail/action departs; stable focus return only when needed. Only visible projection is in accessibility tree; labels, button names and status words hold in both themes, zoom and WCAG spacing. | P: A/B/C/D/E/F/G at their seams. |

<a id="conditional-acceptance-for-the-unresolved-product-boundary"></a>

### Acceptance for approved Q1=A

The owner's selection is already recorded in [Q1](#q1). Every implementing
card must meet A; the rejected policy B is historical context only. Policy
letters in this decision are distinct from the proposed work-card letters A–G.

| Situation | Required behavior: Q1=A |
|---|---|
| Same layout as departure | Preserve the captured complete footprint and tracks; exact departure invariant. |
| Width/font changes in a measured departed projection | Keep that occurrence's last allocated CSS-pixel extent, growing only for the currently rendered static reason and its shell/gap. Do not scale it by DPR/rem or reconstruct text. |
| Enter a projection with a capture that was usable at departure | Use that projection's own retained allocation, even if its layout changed afterward. Apply the current reason floor without shrinking its last allocation. |
| Projection was never measured, or its earlier live cache was invalidated before departure | Start with the proposed generic base; there is no usable captured height to keep. No transfer of wide height to card and no hidden data reveal. |
| Later font/width/projection changes after generic fallback | Keep that projection's last allocation, growing only for the reason floor. Do not recompute the generic base or call it a historical content measurement. |
| Reason previously required more room, then becomes shorter | Keep the enlarged allocation; a smaller reason floor cannot shrink the slot. |
| Work completion / count becomes zero / time passes | No shrink from these events; retain slot until window change. |

Proposed generic base: one empty framework body of **3rem**
plus the projection's normal shell/gap, enlarged if the complete reason needs
more room. Sass owns the static 3rem; current measured reason/shell metrics
supply only the runtime floor. There are no per-field value reserves, labels,
page badge, controls, detail or work in this generic body. This is deliberately
not presented as the unmeasured card's natural size. With no live rows, new
wide tracks can use the current table container and normalized previous track
proportions (or ordinary initial layout if none existed), calibrated without
row data. Track reflow belongs to the invalidated layout, so the old-layout
no-movement guarantee no longer applies.

Q1=A accepts potentially excessive historical blank space while keeping slot
order/lifetime, reason readability and the data-free boundary. B01/B02,
post-invalidation geometry in G03, and the final acceptance of work cards A/B/C
all require this approved behavior. API samples and H01–H06 remain technical
proposals for their own review; Q1 does not approve them. No general resize
guarantee or new live-update policy is implied.

## Proposed work cards — for approval, not created tickets

Product work cannot be added to this Research: its approved result is a
written investigation, with implementation explicitly excluded. The following
seven cards split two behaviors into a core/Vue implementation and each port's
own implementation proof, followed by the real applications. They are not
architecture-only preparation or separate tooling tickets for their tests.
Each produces observable behavior and owns its own seam through a browser.
Letters A–G are local references, **not Jira keys**; none is created or assigned.
Q1=A is settled scope for these proposals, not permission to create or execute
them. Q2 and Q3 remain open proposal reviews, identified below; their numbering
stays stable rather than renumbering history after closing Q1.

Metadata basis: the coordinator checked Board 14 and Jira on 2026-10-13.
HIL-1161, **Table rework — Phase 2: what comes after the approved design
landed**, is the current phase parent (Epic, Backlog); HIL-783 is the earlier
phase. Active R3 (879, October 5–19) contains this Research. The nearest future
sprint is **R3-2 (880, no dates)** and is the proposed candidate for every card,
not an assignment already made. Recheck the board and parent when creating
cards; putting a new proposal in active R3 needs the owner's separate word.
`model-*` labels below name the execution category, not a vendor/model id;
the line chooses the actual executor. No new Jira ID is invented here.

### Dependencies and neighboring work

Read-only `jira get` on 2026-10-13 confirmed all four relevant neighbors in
Backlog, with no completed Data Result in the returned fields:

| Existing work | Its actual boundary | Relationship to this proposal |
|---|---|---|
| [HIL-1343](https://anlide.atlassian.net/browse/HIL-1343) | Remove Vue's prop-drawn branch, including columns/search/caption/pager/row props and legacy test fixtures. Explicitly preserves declared-table behavior and excludes HIL-1321. | A/D extend the existing declared path only. Shared template/test edits need reconciliation; no missing code from this neighbor is needed. |
| [HIL-1344](https://anlide.atlassian.net/browse/HIL-1344) | React version of that removal; currently waits for HIL-1343's code. | B/E keep that scope intact, use declared test frames. No reason for geometry to wait for branch deletion. |
| [HIL-1345](https://anlide.atlassian.net/browse/HIL-1345) | Angular removal, legacy row context/export and templates; waits for HIL-1343's code. | C/F touch the declared branch, not the removed context. Coordinate template/test merges; no code prerequisite. |
| [HIL-1346](https://anlide.atlassian.net/browse/HIL-1346) | Require frame/non-null card/declaration and remove signals used only by the legacy pager; waits for all three removals. Keeps page state used by the declared footer. | A/D must not add geometry or height to the legacy branch; HIL-1346 must preserve the new declared members/tests if it lands later. This is compatibility across edits, not a dependency on all of HIL-1322. |

No forward or reverse blocker on those four is justified by a missing contract
or code in the current tree. Recheck their plans before creation/execution;
an actual changed seam can justify an edge then. This Research edits no
neighbor, dependency label, Jira field or mockup. HIL-1094's live-message and
marks panel is already landed, so it is an input, not a waiting dependency.
D-210 supplies approved behavior/design reconciliation; it supplies no API or
resize decision. Q1=A supplies the later resize decision separately. PR-088 is
outside the scope. HIL-1440 theme work and the other incidental `pending near`
hits do not provide geometry primitives.

| Proposed card | Needs a contract (`by`) | Needs merged code (`after`) | Reverse consequence within this proposed set |
|---|---|---|---|
| A | Q1=A is settled; review the capture/fallback technical proposal with the card, no external ticket contract | None; existing controller/frame are present | B/C/D need A's shared occurrence/capture implementation |
| B | A's shared capture contract for interview; Q1=A is already settled here | A | G waits for React departure proof |
| C | A's shared capture contract for interview; Q1=A is already settled here | A | G waits for Angular departure proof |
| D | A's capture interface for interview; Q2 reviews this proposed height API | A for integration with numeric capture | E/F/G need implemented height/expansion |
| E | D's height/text component contract for interview | D and B | G waits for the React height/application seam |
| F | D's height/text component contract for interview | D and C | G waits for the Angular height/application seam |
| G | D's finalized API for interview; Q3 reviews the demo parameters | D, E and F, transitively A/B/C | Completes the three real applications and combined proof |

Here `by` describes what must be settled for an interview; it is not a second
label to add alongside an `after` edge to the same predecessor. At creation,
use the narrow actual code dependencies shown, with their already-recorded
contracts available to conduct interviews. A–G cannot be passed to `dep add`:
only the coordinator can map approved cards to real keys. No changes to the
existing neighbors are requested merely because paths overlap.

### A — full departure footprint and Vue behavior

Как оформлю лист:

- **Summary:** Preserve departed table records and column tracks in Vue.
- **Type:** Bug (10126): current placeholders lose the owner's accepted full
  record space and clear at zero.
- **Priority:** Highest: repairs the shared controller/presentation mechanism,
  including payload lifetime and the pre-publication measurement boundary.
- **Parent:** HIL-1161, the active table Phase 2 described above.
- **Sprint:** R3-2 (880), the verified nearest future candidate; recheck at creation.
- **Effort / model:** effort-xhigh / model-opus: ordering across normalization,
  controller publication and rendering, empty-window state and retaining
  per-projection allocations through layout changes make this mechanism work
  likely to need slices, not mechanical edits.
- **Observable behavior:** Vue retains the full departed row/card footprint,
  constant state room and content tracks at unchanged layout; zero count retains
  slots; append/own-create/Apply obey the documented rules. After layout changes,
  Q1=A keeps each projection's last allocation, including a generic fallback
  where no usable capture exists, growing only for the readable reason.
- **Paths / ownership:** core `TableViewportController.ts`,
  `subscription/bindTableViewport.ts`, and typed local measurement state under
  `core/src/table/`; Vue `HilosViewportTable.vue` and `hilos-styles.scss`.
  These are under `framework/frontend/`. This card owns the shared ingress
  boundary, raw-slot retirement, core tests and the Vue integration; B/C own
  their ports. Update the React/Angular tests that assert the shared old
  zero-count clearing when changing that controller behavior, so this card
  remains green; their DOM geometry assertions and adapters still belong to
  B/C. No DOM import enters core. Graduate only the implemented
  normative parts of `docs/agents/frontend/table-subscription.md` when landing.
- **Proof:** G01–G10, B01–B05 in controller/binding tests, Vue component tests,
  and chat's real two-window `bots.spec.ts`; geometry helpers in
  `framework/frontend/e2e/geometry.ts` and locators in `table.ts` grow only as
  needed by these assertions. Add a declared browser fixture for detail/work
  when the real bot table has none. Vue must prove its own pre-render ordering.
- **Owner frame / design:** whole record, no departed data, permanent state room,
  zero retention, allowed Apply/own movement and Q1=A are settled. Carry Q1 as
  accepted behavior, including fallback retention after later layout changes;
  the concrete fallback dimensions remain a technical proposal. Use the pinned
  table sections 4/9 with D-210 and the separate Q1 answer, no new design debt
  or mockup rewrite in this leaf.
- **Excluded:** height modes and demo mode choices (D/G), React/Angular renderers
  (B/C), legacy-path deletion (HIL-1343..1346), wire/DB/RT/routes.
- **Dependencies:** existing frame and HIL-1094 are present; no HIL-1322 blocker.
  Q1=A is already recorded; no resize-choice dependency remains.
- **Interview questions:** none open for this card; retain the closed Q1 entry
  as owner scope and review the technical proposal with the card's approval.

1. **Q1 — closed: A.** The owner accepted historical allocation retention after
   departure on 2026-10-13; see [the recorded decision](#q1). Implement that
   behavior and its unconditional acceptance table. Do not ask A/B again or
   treat this answer as approval of card A or any other card.

### B — React departure behavior

Как оформлю лист:

- **Summary:** Preserve full departed record geometry in React.
- **Type:** Bug (10126): the same accepted departure behavior fails in this port.
- **Priority:** High: port-specific correction against the shared mechanism A owns.
- **Parent:** HIL-1161, current table Phase 2; not HIL-1322's legacy cleanup.
- **Sprint:** R3-2 (880), nearest future candidate, recheck before assignment.
- **Effort / model:** effort-xhigh / model-opus: React commit/effect scheduling
  must be proven against synchronous publication; copying Vue hooks is unsafe.
- **Observable behavior:** React rows/cards hold the same full empty place and
  tracks, including early work removal, count zero and approved Q1=A retention
  after layout changes, including later changes after generic fallback.
- **Paths / ownership:** `framework/frontend/react/src/HilosViewportTable.tsx`,
  its `hilos-styles.scss`, `react/test/HilosViewportTable.test.tsx`; reuse A's
  core API and shared e2e geometry helpers. This card owns every React geometry
  adapter, observer/focus cleanup and proof; no React tail is left to G.
- **Proof:** G01–G10 and B01–B05 against React DOM ordering, component lifecycle
  and real browsers in `demo/ecommerce-shop/tests/e2e/tests/users.spec.ts`,
  extending its fixture for detail/work/removal conditions the real page lacks.
  Mocked rectangles alone cannot establish layout or a no-flash first frame.
- **Owner frame / design:** inherit the full departure and permanent-room rules
  and the owner's already accepted Q1=A; table mockup sections 4/9 plus D-210.
  The fallback dimensions are a technical proposal, not a separate numeric
  approval inferred from Q1.
- **Excluded:** React height modes (E), demo configuration (G), new shared
  protocol and HIL-1344's legacy removal or HIL-1440's general theme work.
- **Dependencies:** after A's code; inspect HIL-1344/HIL-1346 overlaps without
  making deletion of the old path a prerequisite.
- **Interview questions:** none additional; Q1=A is settled scope from this
  report. The card still needs its own approval.

### C — Angular departure behavior

Как оформлю лист:

- **Summary:** Preserve full departed record geometry in Angular.
- **Type:** Bug (10126): current Angular placeholders violate accepted retention.
- **Priority:** High: port-specific behavior, using A's shared state contract.
- **Parent:** HIL-1161, current table Phase 2.
- **Sprint:** R3-2 (880), verified future candidate; recheck at creation.
- **Effort / model:** effort-xhigh / model-opus: Angular signal/template timing,
  observer lifetimes and pre-publication ordering need their own proof.
- **Observable behavior:** Angular keeps complete row/card footprints and stable
  columns, zero-count slots and correct arrivals/user movement, with approved
  Q1=A retention after layout changes, including later changes after fallback.
- **Paths / ownership:** `framework/frontend/angular/src/HilosViewportTable.ts`,
  `angular/src/hilos-styles.scss`, `angular/test/HilosViewportTable.test.ts`,
  plus shared helpers as needed. Own the Angular adapter and consumer-loaded
  SDK Sass delivery, not an app-local stylesheet or new framework core branch.
- **Proof:** G01–G10 and B01–B05 through Angular component/AOT checks and
  `demo/online-testing/tests/e2e/tests/users.spec.ts`; add a declared fixture
  for detail/work cases absent from that page. Test destruction/recreation,
  hidden-branch readings and scheduled observer cancellation on this port.
- **Owner frame / design:** the same settled departure rules and approved Q1=A;
  table mockup sections 4/9 with D-210, including the full 375 card. The fallback
  dimensions remain the shared technical proposal, not an inferred numeric
  approval.
- **Excluded:** height modes (F), demo choices (G), legacy context/export removal
  in HIL-1345, new backend or wire contracts.
- **Dependencies:** after A; reconcile HIL-1345/HIL-1346 edits if they land first,
  without depending on unrelated removal work.
- **Interview questions:** none additional; Q1=A is settled scope from this
  report. The card still needs its own approval.

### D — declared height and reading in core/Vue

Как оформлю лист:

- **Summary:** Declare table text height and expand overflowing values in Vue.
- **Type:** Story (10127): adds the three user-visible height/read modes.
- **Priority:** Highest: defines the common SDK API, validator, text component
  contract and expansion lifetime inherited by all ports.
- **Parent:** HIL-1161, current table Phase 2.
- **Sprint:** R3-2 (880), nearest future candidate; recheck before creation.
- **Effort / model:** effort-xhigh / model-opus: the new declaration and mixed
  text/control seam cross core, rendering, Sass and accessibility.
- **Observable behavior:** a declared Vue table uses content/N-lines/rem sizes,
  reserves short text equally and explicitly reads long text without hiding
  controls; the resulting expanded footprint participates in A's capture.
- **Paths / ownership:** `core/src/table/tableFrame.ts`, `hilosTableColumn.ts`,
  typed policy/validation under `core/src/table/`, `core/src/index.ts`, Vue
  `HilosViewportTable.vue`, proposed `vue/src/HilosTableText.vue`, Vue exports
  and Sass, all under `framework/frontend/`. This card owns common API/default,
  class catalog, expansion lifecycle and Vue behavior; E/F own their view seams.
  Graduate the implemented table/style explanation when it lands, without
  silently broadening the style exception or the server-rendered field list.
- **Proof:** H01–H06, G03 and B03/B05 integration in core tests under
  `core/test/table/`, Vue SFC tests beside their modules, real Vue browser
  fixtures carried by chat's table tests and keyboard/a11y coverage in
  `demo/chat/tests/e2e/tests/a11y.spec.ts`. Validate preset class delivery,
  malformed declarations and hidden values as well as rectangle arithmetic.
- **Owner frame / design:** all three modes, N reservation for short values,
  explicit reading, no internal scroll, unclipped controls, no max-row
  equalization; table sections 4/9 and D-210. Content default and numbers are
  this report's technical proposal, subject to Q2, not an owner quotation.
- **Excluded:** real settings/bots/users applications (G), React/Angular (E/F),
  editing flow, detail/work redesign, arbitrary runtime style lengths or any
  DB/RT/signal/route change.
- **Dependencies:** A's capture interface for interview, A's code for integration.
  Q1=A is settled; it does not approve this card's API/default/presets/parts.
- **Interview questions:**

<a id="q2"></a>

2. **Q2 — open proposal review.** Approve this concrete technical API for
   implementation: content default, per-value text budgets, N 1–12 and the
   six rem presets, `text`/`parts` seam
   with a Hideable-aware text component, and independent text/detail states?
   These parameters are proposals; the three modes and explicit reading are
   already accepted and are not being asked again.

### E — React declared height and reading

Как оформлю лист:

- **Summary:** Render declared text heights and disclosure controls in React.
- **Type:** Story (10127): brings the accepted new reading behavior to React.
- **Priority:** Medium: a view feature on D's settled common API, no new shared API.
- **Parent:** HIL-1161, current table Phase 2.
- **Sprint:** R3-2 (880), nearest future candidate; recheck at creation.
- **Effort / model:** effort-high / model-opus: the policy is settled by D, but
  overflow, focus and mixed-cell rendering remain substantive view work.
- **Observable behavior:** React produces the same per-value reservations and
  independent accessible expansion, with correct capture on a later departure.
- **Paths / ownership:** `framework/frontend/react/src/HilosViewportTable.tsx`,
  proposed `react/src/HilosTableText.tsx`, exports and Sass; component tests in
  `react/test/`. Own React context/render props, overflow observation and focus
  behavior; do not substitute a shared unit test for this view's obligations.
- **Proof:** H01–H06 and G03/B03/B05 through React tests and declared browser
  fixtures in ecommerce-shop's users/settings specs; React keyboard/a11y smoke
  in `demo/tasks/tests/e2e/tests/a11y.spec.ts` where that category lives.
- **Owner frame / design:** D's recorded Q2 contract and the accepted reading
  rules, table sections 4/9 plus D-210; no new numbers or port-specific default.
- **Excluded:** applications (G), core policy redesign, legacy React deletion
  (HIL-1344), general theme work, server contracts.
- **Dependencies:** after D and B so the height control is tested against React's
  own capture seam. HIL-1344/HIL-1346 are compatible overlaps, not prerequisites.
- **Interview questions:** none additional; inherit the reviewed Q2 API.

### F — Angular declared height and reading

Как оформлю лист:

- **Summary:** Render declared text heights and disclosure controls in Angular.
- **Type:** Story (10127): supplies the new table reading behavior in Angular.
- **Priority:** Medium: view implementation of the settled common feature.
- **Parent:** HIL-1161, current table Phase 2.
- **Sprint:** R3-2 (880), verified future candidate; recheck before creation.
- **Effort / model:** effort-high / model-opus: the contract is known, while
  projected templates, focus and AOT integration still need implementation.
- **Observable behavior:** Angular fields reserve/expand/collapse identically,
  preserve labels/actions on 375 and feed their complete footprint into C.
- **Paths / ownership:** `framework/frontend/angular/src/HilosViewportTable.ts`,
  proposed `angular/src/HilosTableText.ts`, exports and SDK Sass; tests in
  `angular/test/`. Own the injected cell context, component imports, projected
  templates, consumer Sass loading and observer teardown for Angular.
- **Proof:** H01–H06 and G03/B03/B05 in Angular component tests, the AOT check,
  browser fixtures in online-testing's users/settings specs, and keyboard/a11y
  smoke in `demo/polls/tests/e2e/tests/a11y.spec.ts`.
- **Owner frame / design:** D's Q2 contract and the approved three modes/read
  rules; table sections 4/9 with D-210, same sizes/default as the other views.
- **Excluded:** real applications (G), a second height API, legacy row-context
  removal (HIL-1345), backend/RT/wire/routes.
- **Dependencies:** after D and C; coordinate HIL-1345/HIL-1346 overlaps without
  inventing a dependency on all of HIL-1322.
- **Interview questions:** none additional; inherit D's reviewed Q2 contract.

### G — real settings, bots and users applications

Как оформлю лист:

- **Summary:** Demonstrate all three table height modes in settings, bots and users.
- **Type:** Story (10127): makes the accepted allocation usable on real screens,
  rather than leaving it as an unused type or synthetic fixture.
- **Priority:** Medium: application wiring against implemented common mechanisms.
- **Parent:** HIL-1161, current table Phase 2.
- **Sprint:** R3-2 (880), nearest future candidate; recheck at creation.
- **Effort / model:** effort-high / model-opus: all seams/parameters are specified,
  but mixed hidden values, the reused settings renderer and three view ports
  require more than a mechanical numeric replacement.
- **Observable behavior:** settings display full natural text, bots reserve
  three lines for Name/Description and read more explicitly, users reserve
  4.5rem per participating text value; all actual card fields/badges/actions
  survive both widths, and leaving a set holds the full expanded footprint.
- **Paths / ownership:** `framework/frontend/core/src/admin/settings/hilosSettings.ts`,
  `core/src/admin/users/hilosUsers.ts`, the three SDKs' `src/admin/settings/`
  `HilosSettingsPage`/`HilosSettingValueCell` and `src/admin/users/HilosUsersPage`
  components, plus `demo/chat/frontend/src/views/AdminBots/adminBotsPage.ts`
  and `AdminBots.vue`. Own all three settings/users ports and Vue chat bots;
  no port-specific application tail is delegated to an unnamed later leaf.
- **Proof:** every fixture in the demo section, H01–H04 and combined G03;
  framework tests for shared declarations/components, bots tests in chat;
  settings/users browser specs in binance-btc-tracker (Vue), ecommerce-shop
  (React), online-testing (Angular). Check normal and admin view mode hidden
  values, merged badges, reference/default/custom settings and edit-dialog
  regression for the reused value renderer. Re-run each changed view's a11y
  category when its accessibility seam changes.
- **Owner frame / design:** settings/content, chat bots/N, users/specific height
  and real applications are accepted. Values 3/4.5 and the intrinsic settings
  renderer are the concrete proposal; use sections 4/9 with D-210.
- **Excluded:** new settings/entities/columns on the wire, invalid test data,
  new React/Angular bot screens, changing users' ID card title, global cleanup
  of HIL-449, new mode implementations or height overrides per page.
- **Dependencies:** after D/E/F and their geometry predecessors; no dependency
  on removing undeclared tables because these declarations already exist.
- **Interview questions:**

<a id="q3"></a>

3. **Q3 — open proposal review.** Approve the concrete application parameters
   and presentation edits above:
   bots Name/Description at three lines, users' participating values at 4.5rem,
   and a wrapping table-only settings renderer? This reviews the proposed
   density on both widths; it does not reopen the accepted mode allocation.

### Validation ownership and completion boundary

Each implementation card runs the narrow composer checks for its own change:
`test:framework:frontend` for shared SDK/view work (including Angular AOT), the
applicable demo frontend/check command for demo edits, and that demo's
`test:e2e-full -- <spec>` for its geometry/a11y scenarios. Tests run only through
the line's stand wrapper; long runs use the line's detached mechanism.
The integration/Verify phase owns the rare full pass prescribed by
[testing.md](../testing.md); this Research does not launch it. Every card includes
its behavior tests rather than creating a separate Tooling card to validate
someone else's code. CSS/browser proofs must cover each view's own lifecycle,
even when the pure numeric core tests and geometry helper are shared.

**Q1 is closed: A.** The resize/projection behavior is accepted and carried
through the matrix and cards; no A/B choice remains for implementation.
Q2 and Q3 are the two open proposal reviews, covering the research's technical
API and demo parameters. They do not reopen whether the accepted modes or
reading affordance should exist. Cards A–G still need separate approval; Q1
did not authorize their creation or execution. No ticket, operations proposal
or debt has been created. The report is a complete research handover;
implementation, browser proof and review of its technical proposals remain
outside its claim of completion.

## Final research validation

The pre-correction final pass on 2026-10-13 ran the stand-wrapped
`composer run test:framework:unit`
and passed with exit 0: **8,765 tests, 325,581 assertions,
one skipped**, in 23.844 seconds. The skip is the existing unconditional
`testFollowerOpensDegradedAfterTheSharedTimeout` in
`framework/tests/Unit/DaemonManagerWebSocketReadinessTest.php:87`, parked for
a previously observed timing flake (run 0783); this Research did not change it.
At that pre-correction pass, the report and its README route had 73 resolving
local file links; the seven card blocks, then-open interview numbering 1–3,
paired code fences and `git diff --check` were checked. The README
research-status link remains valid.

The Q1 review correction on 2026-10-13 ran the same stand-wrapped unit command:
exit 0, **8,765 tests, 325,581 assertions, the same one skipped**, in 23.798
seconds. Document checks resolved all 73 local file links and seven linked
anchors; Q1/Q2/Q3 and both historical section anchors remain reachable.
All seven complete card blocks, their unchanged acyclic code dependencies,
stable question IDs (Q1 closed, Q2/Q3 open), paired code fences and
`git diff --check` passed. A semantic pass checked Q1=A across the owner frame,
validity boundary, matrix, cards and completion boundary, including retention
after fallback and the distinction between pre-departure live-cache validity
and post-departure layout changes. These checks are not CSS geometry evidence.

No product file, normative table/style rule, mockup, ticket or dependency was
changed. No browser measurement or frontend/e2e result is claimed. The cited
CSS and WAI primary sources constrain the design; they do not prove that an
unwritten implementation satisfies the acceptance matrix.
