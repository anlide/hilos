# Theme: Two Modes, Three Positions, One Rule

Read this before deciding, storing, carrying or applying the page's theme — the
header's theme menu, the profile's Theme row, the Appearance section, a person's
pick in the account or the browser, the script in the head of a page. For painting
a surface, read [styling-rules.md](../frontend/styling-rules.md), "Theming".

The theme is being built by epic HIL-1295. A sentence about behavior that is not
in the code yet ends with the key of the leaf that lands it.

## Core Rule

Use exactly two themes: **light and dark**, Bootstrap 5.3's stock color modes.
A person picks one of three positions: **light, dark, as the system**. "As the
system" chooses one of the two themes; it is not a third theme.

Wherever a pick is kept — in the account or the browser — distinguish four
states: `light`, `dark`, `system`, and **not chosen**. "Not chosen" means the
person has no personal position and follows the default theme, whether they
never picked or explicitly returned to the site default. Picking the position
the default already shows is a pick of one's own. **Use site default** is a
separate reset command below the three positions, always visible when switching
is available, including while already following the default. It has no check
mark and is not a fourth position; the actual default position keeps its mark
and default badge (HIL-1534).

Keep one vocabulary through DB, PHP, the wire, TypeScript and the browser:
`light`, `dark`, `system`. "Not chosen" is the absence of a pick — `null` on the
wire, nothing stored in the browser — never a fourth word. See
[cross-layer-field-names.md](../code-style/cross-layer-field-names.md).

## What The Page Wears

Apply this rule in this order:

1. Switching is off → use the default theme.
2. Otherwise, there is a pick → use the pick.
3. Otherwise, there is no pick → use the default theme.
4. If that gave `system` → use the system's color scheme.

`@hilos/core` implements this as the pure
`resolveThemeMode(pick, settings, systemDark): ThemeMode` function.
[`themeHeadScript()`](../../../framework/frontend/core/src/theme/themeHeadScript.ts)
builds the head script from that function and the browser-value parsers in
[`themeStored.ts`](../../../framework/frontend/core/src/theme/themeStored.ts).
A hand-written copy of the rule anywhere is forbidden: the first frame and the
running app must make the same decision.

Follow the system through the `change` event of
`matchMedia('(prefers-color-scheme: dark)')`, never by polling. If matchMedia is
unavailable, `system` resolves to light until a browser can supply its preference.

The core exposes `hilosThemePick: ReadonlySignal<ThemePick>`,
`hilosThemeMode: ReadonlySignal<ThemeMode>`, and
`hilosThemeChoice: ReadonlySignal<ThemeChoice | null>`;
`setHilosThemePick(pick)` changes the guest's current tab and remembers the pick.
`sendHilosThemePick(pick): ActionHandle` sends an explicit account choice through
the action lifecycle already bound by `bootHilos`. It does not change the local
choice or browser value; the authoritative handshake does that. A successful
action reply completes the tracked action without reapplying its submitted choice,
so a newer handshake cannot be overwritten by an older acknowledgement.
`resolveThemeChoice` and `THEME_POSITIONS` are what the header and the profile
row read: the position, its icon and its label, or no control when switching is
off. `ThemePick` is `light | dark | system | null`; `ThemeMode` is
`light | dark`. The header and the profile mark the **position**; a person who
has no personal pick sees the default's position marked as the default, which
the worn theme alone cannot tell. `HilosProfileRootStore.theme` reads `hilosThemeChoice`
to supply the profile row line. The Vue header and the Vue profile row do this
(HIL-1433, HIL-1434). The React and Angular headers and profile rows are not in
the code yet — HIL-1440, HIL-1441.

## Who Sets It

Express the theme as `data-bs-theme` on `<html>`, written by the framework's head
script before the app loads, then by the one
`core/dom/applyTheme` effect bound by `bootHilos`. Follow "Shared DOM effects" in
[multiframework-core.md](../frontend/multiframework-core.md).

No view, component, demo or project writes `data-bs-theme`, on `<html>` or on any
other element. A subtree pinned to one mode is a pinned color by another name.

## Where A Pick Lives

- **A guest:** keep the pick under `hilos.theme.pick` in localStorage, declared
  with `browserValue`; its value is exactly `light`, `dark` or `system`, and an
  absent key means no choice. The erase on `/privacy` removes it and informs the
  current tab; other tabs follow through the `storage` event (including a clear
  event with no key). Use site default calls `setHilosThemePick(null)`, removes
  only this pick and applies the current default; other guest tabs follow the
  same storage event. No Save, confirmation or success toast is involved.
  Follow "What this browser keeps" in
  [core-and-connection.md](../frontend/core-and-connection.md).
- **A signed-in person:** keep the pick in `hilos_user.theme_pick`, where null means
  no personal position. `hilos_user.theme_pick_initialized` distinguishes a
  never-chosen null (`false`, adoption allowed) from an explicit return to the
  default (`true`, adoption refused). Every explicit write, including null,
  sets this bit to true; it never returns to false. Only the person's own agent
  writes it through
  `hilos_user_theme_pick_write`, like every other edit of their own content; the
  truth source refuses a write from any other process (HIL-1427).
  After a successful write or a conditional skip, the owner asks for the existing
  `hilos_user_sessions_restate`. The sessions library publishes session state for
  every open session of that person on the current node; the project answers each
  local socket with `handshake_response`, whose `entities.currentUser.themePick`
  and `themePickInitialized` read the stored pair. A stranger's session receives
  nothing. Delivery to
  sessions on other nodes remains pending (not in the code yet — HIL-1443).
- **The browser copy:** on every valid handshake, a non-null
  `currentUser.themePick` paired with a boolean `themePickInitialized` becomes
  the tab's choice and is written under
  `hilos.theme.pick`, so the page can wear it on its first frame. A null account
  pick with initialized=false keeps the browser's existing choice while adoption
  is pending. A null pick with initialized=true authoritatively applies null,
  removes the browser key and never attempts adoption, including after reconnect.
  An absent or invalid field in the pair preserves the last valid choice and
  sends no adoption. A refused storage write
  still lets the current tab apply the choice in memory.

Use this table at sign-in:

| The account holds | The guest's pick in this browser | After sign-in the page wears |
|---|---|---|
| `light`, `dark` or `system` | any of the four states | the account's pick |
| null, initialized=false | `light`, `dark` or `system` | the guest's pick, written into the account |
| null, initialized=false | not chosen | not chosen, which follows the default theme |
| null, initialized=true | any of the four states | no personal pick; erase the browser pick and follow the default |

Resuming a session and receiving a restate follow the same table. Apply "What
The Page Wears" to the resulting pick: switching off still means the default,
and `system` still resolves to one of the two themes.

When the account pick is null, initialized=false and the browser has a choice,
`bindThemeState` uses the action lifecycle supplied by `bootHilos` to send `hilos_theme_adopt`
with `{themePick}` once per connection, person and choice. The server resolves
the person from the live ordinary session belonging to the action's `acceptKey`,
never from a client-supplied `userId`. Anonymous, expired, missing, impersonated
and mismatched session/connection identities are refused. The people library
asks the person's owner with `adoptOnly=true`: the owner writes only while
`theme_pick` is null and `theme_pick_initialized` is false. A successful adoption
sets initialized=true too. The first choice to reach the owner's sequential queue
wins; a later proposal keeps that stored choice and restates it too. Closing
the initiating socket after admission does not retarget or cancel the write.

Adoption completes quietly, with no dialog or success toast. A failed write
leaves the account unchanged and does not cause a retry loop on repeated
handshakes; a new connection may try again. A guest tab follows changes of
`hilos.theme.pick` through `storage`; a signed-in tab follows the authoritative
account frame, so an older browser value cannot overwrite its account choice.

The browser request that creates an account carries its current `themePick`.
The people library inserts the person with that choice in the registration
transaction; a non-null choice initializes the bit, while no guest pick means
null with initialized=false (HIL-1534). Sign-out's
`currentUser=null` keeps the tab's choice and browser copy, so the page keeps
wearing the same theme.

A cookie never carries the theme; the cookie is the auth credential only. See
[wire-protocol.md](../frontend/wire-protocol.md).

## Explicit Account Choices

The signed-in person's header and profile send `hilos_theme_pick` with only
`{themePick}`. The people library resolves the person from the live ordinary
session belonging to `acceptKey`, with the same admission checks as adoption,
then asks their owner through `hilos_user_theme_pick_write` with `adoptOnly=false`.
Every explicit choice is a write, including the current position or the marked
default: choosing one of Light/Dark/System makes it personal and removes the
default badge. Use site default sends `{themePick:null}` and writes null with
initialized=true, restoring the badge and following later administrator changes
of `theme.default`. Repeated reset remains available and has the same result.
The owner's sequential queue orders choices from different sessions; the last
processed explicit write wins. Automatic `hilos_theme_adopt` remains conditional
with `adoptOnly=true` and cannot replace a personal choice or undo an explicit
reset. A stale browser's Dark, even after reconnect, cannot be adopted over
null with initialized=true. An explicit choice after reset is still allowed.

After the owner answers through `hilos_user_theme_pick_done`, the library resumes
the tracked browser action. The existing session restate and handshake delivery
update the worn mode, header marks, profile line, open profile modal and browser
copy in every local session of that person. There is no second theme lifecycle,
polling or separate browser fan-out signal. Cross-node delivery remains HIL-1443.

Vue's `HilosThemeMenu` closes on a choice and returns focus to its toggle.
The reset is `role=menuitem` after a separator; the three positions stay
`menuitemradio`. ArrowUp/Down and Home/End include all four controls; Escape
closes and returns focus.
`useTrackedAction` disables repeated submission while busy and shows delayed
loading on the toggle. For an account action, a post-flush watcher returns focus
after Vue removes the native disabled state, while the signed-in, switching and
impersonation guards still allow the control. Refusal, timeout and disconnect
use the usual action error toast; the last confirmed theme stays in place.
Success needs no toast.
Guests continue to use `setHilosThemePick`.

The profile's Change control opens `HilosProfileTheme`, a focus-trapped
`HilosModal` titled Theme. It marks the live position, including the default badge
while no personal choice exists. Each click sends that position or the separate
reset command directly; there is no
draft or Save. Pending work disables repeat clicks and shows delayed loading on
the submitted row. Success closes the modal and returns focus to Change;
refusal leaves it open with `HilosActionError` and the usual toast. Close, Escape
and the backdrop close without writing; closing after admission does not cancel
that write. A late success belongs to the opening that sent it and does not close
a later opening or apply a local choice.

Both account controls check current availability before sending: switching is
on, the person is signed in and the session is not impersonating someone.
Switching off, sign-out or impersonation closes an open surface. The server
checks admission again. React and Angular controls remain to be built in
HIL-1440 and HIL-1441.

## Before The App Loads

The last project build step is
`node node_modules/@hilos/core/bin/theme-head.mjs <dir>`, after Vite prerendering
or `ng build`. It inserts `<script data-hilos-theme-head>` just before `</head>`
in every emitted HTML page: Vite's `index.html` and prerendered pages, Angular's
`index.csr.html` and `<route>/index.html` pages. The synchronous script is built
from `resolveThemeMode` and the browser-value parsers in `themeStored.ts`; it
reads only the pick and remembered settings, with no network, cookie or import.
Nothing remembered on a new browser's first visit means `system`.

An account's pick reaches this cache after a handshake. The head script on a
clean browser's first visit cannot know a server choice before that handshake;
the account first-frame check covers a browser that already synchronized it.

The pass refuses a missing or unreadable output directory, a directory without
HTML pages, a page without `</head>`, and a deferred stylesheet. Angular
production sets `optimization.styles.inlineCritical: false`: critical CSS
inlining otherwise defers the external Bootstrap stylesheet with `media=print`
and `onload`, so the first frame could have the right theme attribute but the
wrong background. Dev servers do not carry the build-inserted script. No source
`index.html` carries a hand-written copy; a project never writes its own.

## The Two Settings

Declare two keys in the framework's settings catalog:

- `theme.switching_enabled`: whether people may switch the theme, a boolean
  **true** by default;
- `theme.default`: the default theme, a string accepting exactly `light`,
  `dark` or `system`, **system** by default.

These keys and defaults live in `ThemeSettingsCatalog`. A project whose catalog
carries no theme fragment lives on these defaults. As settings, they take effect
at once on every node, with nothing restarted; see "Settings and catalogs" in
[framework-development.md](../framework-development.md).

Every connected tab, a guest's too, receives both in the handshake and again as a
frame right after a write that moved either. Follow the path of
`hilos_auth_methods`: `SettingsLibraryAgent::settle()` compares the frame before
and after a write and calls `sendToAllConnected()` when it changed
([SettingsLibraryAgent.php](../../../framework/backend/Database/Settings/Library/SettingsLibraryAgent.php)).
The browser remembers both settings under `hilos.theme.settings` as one complete
JSON pair for the head script. `bootHilos` reads both browser values after
binding the session scope and before connecting; each valid handshake or live
frame replaces the settings pair in memory and in the cache. Missing or invalid
data leaves the last valid pair in place. Unreadable browser values fall back to
no pick and the catalog defaults `true/system`; refused writes do not stop the
current tab. The privacy sweep removes both values through the browser registry.
The replacement session's handshake can immediately fill the settings cache
again with the server's current pair; the guest's erased choice stays absent.

Switching off has three consequences everywhere at once:

- the header's theme icon disappears
  (Vue does; not in the code yet — HIL-1440, HIL-1441);
- the profile's Theme row disappears
  (Vue does; not in the code yet — HIL-1440, HIL-1441);
- everyone wears the default, while people's picks in accounts and browsers are
  kept, not erased, and come back when switching is on again
  as soon as the settings frame arrives.

Neither value is secret — every guest receives both. The admin view mode shows
the Appearance section and its values and refuses changes
in Vue (HIL-1435); React and Angular remain to be built in HIL-1440 and
HIL-1441. The Vue page edits switching through
`appearance_theme_switching_set` (HIL-1438) and the default theme through
`appearance_default_theme_set` (HIL-1439). Both open a row-edit modal; the
default theme offers Light, Dark and System, marking the catalog default.
A viewer may open either window and change its draft, while Save stays disabled;
follow [admin-view-mode.md](admin-view-mode.md).

## Not In Hilos

- A third theme.
- A palette of the framework's own or a project recoloring the two modes. That
  is later work, not a Sass-layer exception.
- Hiding the switch for one person ("remove from menu" or "no theme").
- A cookie for the theme.
- A file the daemon writes beside the page for the head script.

## Contract Gate

The catalog keys are `theme.switching_enabled` and `theme.default` (HIL-1426).
The installation settings use `data.themeSettings` in the handshake and
`hilos_theme_settings` as the live frame. Both carry the complete pair
`{switchingEnabled: bool, defaultTheme: 'light'|'dark'|'system'}` (HIL-1428).
The Appearance page action `appearance_theme_switching_set` carries
`{enabled: bool}` through `HilosAppearanceThemeSwitchingSetActionDTO` (HIL-1438).
`appearance_default_theme_set` carries `{defaultTheme: string}` through
`HilosAppearanceDefaultThemeSetActionDTO`; the DTO checks presence and type,
and the catalog's `ThemeDefaultRule` accepts only `light`, `dark` or `system`
(HIL-1439). The ADMIN page forwards the existing `hilos_setting_write` with
`SettingWriteSignalData` to the settings library and answers the action after
`hilos_appearance_setting_write_done` returns with `HandoverAnswerSignalData`.
The library writes `theme.switching_enabled` or `theme.default`, then broadcasts
the complete settings pair once when it changed. A successful default-theme write
answers with `Default theme saved.`; a refusal leaves the modal and its draft open.
DB and RT shapes and the `hilos_appearance` route stay unchanged.
The browser-value keys are `hilos.theme.pick` and `hilos.theme.settings`
(HIL-1430). The person's stored choice is `hilos_user.theme_pick`:
`ENUM('light','dark','system') NULL DEFAULT NULL` (HIL-1427), with
`theme_pick_initialized TINYINT(1) NOT NULL DEFAULT 0` (HIL-1534). The agent ask is
`hilos_user_theme_pick_write` with `UserThemePickWriteSignalData`; `adoptOnly`
is a boolean defaulting to false, and true means write only while the stored
choice is null and initialized=false. Its answer is
`UserThemePickWriteDoneSignalData` under the
asking coordinator's `replySignal`, carrying the unchanged ask, including
`adoptOnly`, and `error`, `errorType`, `errorDetail`.

`handshake_response.entities.currentUser.themePick` is always present for a
signed-in person as `'light'|'dark'|'system'|null`; when `currentUser` is null
there is no separate theme field. PHP carries it as
`HandshakeResponseSignalData::themePick` and `?string $selfThemePick`.
`currentUser.themePickInitialized` is a required boolean in the same account
frame, carried by `HandshakeResponseSignalData::themePickInitialized` and
`bool $selfThemePickInitialized` (HIL-1534).
The browser action is `HilosSignalConstants::HILOS_THEME_ADOPT`, whose wire name
is `hilos_theme_adopt`, with `ThemeAdoptActionDTO` carrying only
`{themePick: 'light'|'dark'|'system'}`. The people library declares it in `AGENT_ACTIONS`
and `AUTH_ACTIONS`; no client identity is accepted. Its owner answer is
`HilosSignalConstants::HILOS_USER_THEME_PICK_ADOPT_DONE`, whose wire name is
`hilos_user_theme_pick_adopt_done`, using `UserThemePickWriteDoneSignalData`,
declared in the library's `AGENT_SIGNALS`. These declarations provide the
existing action and answer routes. Local delivery reuses
`hilos_user_sessions_restate` with `UserSessionsRestateSignalData {userId}`,
session state and `handshake_response`; no new browser live signal or page
subscription is involved (HIL-1429).

Explicit choice uses `HilosSignalConstants::HILOS_THEME_PICK`, wire name
`hilos_theme_pick`, with `ThemePickActionDTO` carrying only
`{themePick: 'light'|'dark'|'system'|null}`. The DTO requires the field and accepts
null or a string in `ThemeSettingsCatalog::THEME_VALUES`; `auto`, a missing
field or another type is refused. Adoption still requires a non-null position.
No client `userId` or `accountId` is accepted. The people
library declares the action in `AGENT_ACTIONS` and `AUTH_ACTIONS`, and its answer
`HilosSignalConstants::HILOS_USER_THEME_PICK_DONE`, wire name
`hilos_user_theme_pick_done`, in `AGENT_SIGNALS` with the existing
`UserThemePickWriteDoneSignalData`. The declarations provide the browser-to-library
action and owner-to-library answer routes. The existing owner ask and nested
answer carry nullable `themePick`; `adoptOnly=true` with null is refused.
RT rows, signal names, page routes and session fan-out remain unchanged (HIL-1534).

The optional `themePick` key travels on seven account-creating actions:
`hilos_complete_registration`, `hilos_complete_registration_passwordless`,
`hilos_complete_registration_passkey`, `hilos_confirm_magic_link`,
`hilos_confirm_magic_link_code`, `hilos_confirm_phone_code`, and
`hilos_oauth_create_account`. The leaf that lands a remaining surface writes
its names into this file in the same commit that clears its marker.

## Validation

- Unit-test the rule function, including the four pick states and switching off.
- Check the painting rule with `STYLE-THEME-PINNED`; see "Theming" in
  [styling-rules.md](../frontend/styling-rules.md) for the guard's scope and landing
  markers.
- Run chat's `theme-guest.spec.ts` for browser choices and `theme-account.spec.ts`
  for independent account sessions, both directions of delivery, a stranger's
  isolation, System media, reload/new tabs, the synchronized browser's first frame
  with application JS blocked, reset from both account controls, following later
  site defaults, stale Dark reconnect without adoption, first guest adoption,
  and profile/modal/header keyboard use at 375px. Guest reset covers storage
  synchronization, repeated reset and reload in both palettes.
- Run binance-btc-tracker's `theme-smoke.spec.ts` for its signed-in header and
  reload. The later redistribution of theme e2e remains HIL-1442; cross-node
  account delivery remains pending (not in the code yet — HIL-1443).
