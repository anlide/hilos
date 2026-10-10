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
person never picked and follows the default theme. Picking the position the
default already shows is a pick of one's own. Do not offer "not chosen" as a
position a person can pick back.

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
`resolveThemeMode(pick, settings, systemDark): ThemeMode` function. Build the head
script from that same function (not in the code yet — HIL-1431). A hand-written
copy of the rule anywhere is forbidden: the first frame and the running app must
make the same decision.

Follow the system through the `change` event of
`matchMedia('(prefers-color-scheme: dark)')`, never by polling. If matchMedia is
unavailable, `system` resolves to light until a browser can supply its preference.

The core exposes `hilosThemePick: ReadonlySignal<ThemePick>`,
`hilosThemeMode: ReadonlySignal<ThemeMode>`, and
`hilosThemeChoice: ReadonlySignal<ThemeChoice | null>`;
`setHilosThemePick(pick)` changes the current tab and remembers the pick.
`resolveThemeChoice` and `THEME_POSITIONS` are what the header and the profile
row read: the position, its icon and its label, or no control when switching is
off. `ThemePick` is `light | dark | system | null`; `ThemeMode` is
`light | dark`. The header and the profile mark the **position**; a person who
never picked sees the default's position marked as the default, which the worn
theme alone cannot tell. `HilosProfileRootStore.theme` reads `hilosThemeChoice`
to supply the profile row line. The Vue header and the Vue profile row do this
(HIL-1433, HIL-1434). The React and Angular headers and profile rows are not in
the code yet — HIL-1440, HIL-1441.

## Who Sets It

Express the theme as `data-bs-theme` on `<html>`, written by the framework's head
script before the app loads (not in the code yet — HIL-1431), then by the one
`core/dom/applyTheme` effect bound by `bootHilos`. Follow "Shared DOM effects" in
[multiframework-core.md](../frontend/multiframework-core.md).

No view, component, demo or project writes `data-bs-theme`, on `<html>` or on any
other element. A subtree pinned to one mode is a pinned color by another name.

## Where A Pick Lives

- **A guest:** keep the pick under `hilos.theme.pick` in localStorage, declared
  with `browserValue`; its value is exactly `light`, `dark` or `system`, and an
  absent key means no choice. The erase on `/privacy` removes it and informs the
  current tab; other tabs follow through the `storage` event (including a clear
  event with no key). Follow "What this browser keeps" in
  [core-and-connection.md](../frontend/core-and-connection.md).
- **A signed-in person:** keep the pick in `hilos_user.theme_pick`, where null means
  no choice. Only the person's own agent writes it through
  `hilos_user_theme_pick_write`, like every other edit of their own content; the
  truth source refuses a write from any other process (HIL-1427).
  A change reaches every session of the person on every device as a frame, and
  each switches at once (not in the code yet — HIL-1429).
- **The browser copy:** the app writes the signed-in person's pick into the
  browser on every handshake so the page can wear it on its first frame
  (not in the code yet — HIL-1429).

Use this table at sign-in:

| The account holds | The guest's pick in this browser | After sign-in the page wears |
|---|---|---|
| `light`, `dark` or `system` | any of the four states | the account's pick |
| not chosen | `light`, `dark` or `system` | the guest's pick, written into the account |
| not chosen | not chosen | not chosen, which follows the default theme |

Resuming a session follows the same table
(not in the code yet — HIL-1429). Apply "What The Page Wears" to the resulting
pick: switching off still means the default, and `system` still resolves to one
of the two themes.

The browser request that creates an account carries its current `themePick`.
The people library inserts the person with that choice in the registration
transaction; no guest pick means not chosen (HIL-1427). Sign-out changes nothing
on screen
(not in the code yet — HIL-1429).

A cookie never carries the theme; the cookie is the auth credential only. See
[wire-protocol.md](../frontend/wire-protocol.md).

## Before The App Loads

Put one synchronous framework script in the `<head>` of every page the build
emits: the Vite `index.html` of the Vue and React demos, the Angular `index.html`
and `index.csr.html`, and the prerender template
([framework/frontend/prerender/src/template.ts](../../../framework/frontend/prerender/src/template.ts)).
It reads only browser values — the pick and the remembered settings — with no
network, cookie or import. Nothing remembered, on a new browser's first visit,
means `system`. The build inserts the script
(not in the code yet — HIL-1431).

No `index.html` carries a hand-written copy; a project never writes its own.

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
HIL-1441. The page has no write actions yet (HIL-1438, HIL-1439); follow
[admin-view-mode.md](admin-view-mode.md).

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
The browser-value keys are `hilos.theme.pick` and `hilos.theme.settings`
(HIL-1430). The person's stored choice is `hilos_user.theme_pick`:
`ENUM('light','dark','system') NULL DEFAULT NULL` (HIL-1427). The agent ask is
`hilos_user_theme_pick_write` with `UserThemePickWriteSignalData`; its answer is
`UserThemePickWriteDoneSignalData` under the asking coordinator's `replySignal`.
The optional `themePick` key travels on seven account-creating actions:
`hilos_complete_registration`, `hilos_complete_registration_passwordless`,
`hilos_complete_registration_passkey`, `hilos_confirm_magic_link`,
`hilos_confirm_magic_link_code`, `hilos_confirm_phone_code`, and
`hilos_oauth_create_account`. The frame to the person's sessions is HIL-1429;
pass its contract gate there. The leaf that lands a remaining surface writes
its names into this file in the same commit that clears its marker.

## Validation

- Unit-test the rule function, including the four pick states and switching off.
- Check the painting rule with `STYLE-THEME-PINNED`; see "Theming" in
  [styling-rules.md](../frontend/styling-rules.md) for the guard's scope and landing
  markers.
- Run the theme e2e (not in the code yet — HIL-1442).
