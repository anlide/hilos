# A Tab's Language And Region: Two Ladders And One Choice

Read this before deciding which language and region a tab shows, storing or
carrying a person's choice of them, formatting a date or a number for a reader,
switching a language, locale or country off, reading the country of a
connection, or choosing the language of a letter. For the reference itself —
its tables, their one writer, the switch and the reflow — read
[languages-and-countries.md](languages-and-countries.md).

This page is written ahead of its code for epic HIL-2279. Each marker names the
leaf that lands that sentence; that leaf clears it in the same commit, under
[A Rule Written Ahead Of Its Code](../rule-authoring.md#a-rule-written-ahead-of-its-code).

## Words

- **The active language** — the language a tab shows now.
- **The active region** — the tab's locale: the formats its dates and numbers
  are written in.
- **A variant** — a locale with a country, such as `ru-UA`.
- **The countryless locale** — a language's locale without a country, such as
  `ru`.
- **A choice** — what a person picked themselves. It is kept.
- **A pick** — what a ladder gave. It is never stored.
- **Available** — offered to people; see *What Is Available*.
- **The default language** — the language `HILOS_DEFAULT_LANGUAGE` names.
- **A language address** — a host the project declares for one language.

## Core Rule

Decide a tab's language and region with two ladders. The language ladder runs
first; the region ladder runs for the language already decided. On each ladder
the first rung that fits wins.

Run both ladders for every tab each time it connects — in the worker, during
the handshake, never in the master (see
[heavy work in the master](../antipatterns/heavy-work-in-master.md)) — and
apply the result silently, with no banner and no reload.
They run where the framework stamps the handshake response,
`AbstractAgent::sendHandshakeResponse()`, on every session-state frame: a tab
connecting, signing in, signing out, and a state sent again.

Store only a choice; a pick is stored nowhere but on the tab's connection row,
which lives as long as the socket does (see *What Every Tab Knows*), so a
person who changes the browser's language or travels gets a new pick on the
next connection.

## What Is Available

- A language is available when it is switched on.
- A variant is available when its language, the locale itself and its country
  are all switched on.
- A countryless locale is available when its language and the locale itself are
  switched on.

For both ladders a switched-off country is an unknown country; this is the
owner's decision of 2026-11-26. Switching a country off says the product does
not work there, so nothing offers that country's variants.

The list every tab holds includes only available languages and locales.
The header's language menu will show only available choices
(not in the code yet — HIL-2301). The language window will follow the same rule
(not in the code yet — HIL-114).
No rung of either ladder takes a language or a locale that is not available.

The reflow creates known countries switched off and with no default locale,
and it creates neither languages nor locales: a person adds those. The one
exception is the default language, which the i18n library creates and switches
on at first startup, together with its countryless locale when the catalog has
one. On a fresh installation a variant therefore reaches people only after an
administrator has added the locale, and its language unless that is the
default language, and has switched on all three — the language, the locale
and its country.

## The Language Ladder

Take the first rung that fits, top down:

1. **The language address.** A host the project declares for a language sets
   that language for everyone, a signed-in person included
   (not in the code yet — HIL-2313).
2. **The person's own choice.** For a guest, the choice made in this browser
   (not in the code yet — HIL-2299); for a signed-in person, the language of
   the account (not in the code yet — HIL-2298).
3. **The browser.** The first available language of `Accept-Language`, taken by
   weight.
4. **The country of the connection.** The language of the locale that country
   holds as its default, `hilos_country.default_locale_id`, when that language
   is available.
5. **The default language**, which
   [`DefaultLanguage`](../../../framework/backend/I18n/DefaultLanguage.php)
   reads from `HILOS_DEFAULT_LANGUAGE`. Nobody can switch it off, so the ladder
   always ends.

A chosen language that is not available sends the tab straight to rung 5, past
the browser and the country; the choice is not erased and waits.

The sessions library reads the header at the handshake and sends it raw in the
state frame, `SessionStateSignalData::$acceptLanguage`, for the one socket that
connected; the project's side parses it with
[`BrowserLanguages`](../../../framework/backend/I18n/BrowserLanguages.php) —
weights by RFC 9110, the language is the first subtag taken literally with no
synonyms, the region the subtag after it or after an optional script, at most
16 tags. The tab's connection row remembers the list as `browserLanguages`, and
every later frame takes it from there, the frames that reach every tab of a
session included; a browser whose language changed reaches an open tab when
that tab reconnects.

## The Region Ladder

Run it for the language the first ladder decided. Take the first rung that
fits, top down:

1. The person's chosen variant of this language, when it is available. The
   choice reaches the ladder later, as on rung 2 of the language ladder:
   a guest's (not in the code yet — HIL-2299), an account's
   (not in the code yet — HIL-2298).
2. The variant of this language for the country of the connection.
3. The first available variant of this language that the browser names.
4. The countryless locale of this language, when it is available.
5. The countryless locale of the default language, when it is switched on.

When no rung fits, the tab has no stored locale, and its formats are those of
the built-in catalog's locale for the default language: its countryless
locale, otherwise its first locale in the catalog — `de-DE` for `de`
(owner's decision, 2026-11-26). Of the 50 built-in languages eight have a
countryless locale in the catalog (`en`, `zh`, `es`, `hi`, `ar`, `ru`, `pt`,
`fr`); with `de` as the default language a tab with no chosen variant ends
here until an administrator adds and switches on a countryless `de` locale.

The country stands ahead of the browser. A browser names the region of its own
build — a Russian Chrome asks for `ru-RU` anywhere — while the country says
where the person is now. The accepted price: a person who deliberately set
another region in the browser gets the local one until they choose a variant
themselves.

Example: Russian from Ukraine → `ru-UA`; a Russian Chrome asking `ru-RU` in
Ukraine → still `ru-UA`.

## A Person's Choice

What counts as a choice:

- Picking a language in the simple view of the language window chooses the
  language only; the region keeps following its ladder
  (not in the code yet — HIL-2304).
- Picking a variant in the full view, or taking a suggestion, chooses the
  language and the region (not in the code yet — HIL-2304).
- Opening and closing the window chooses nothing, and there is no "back to
  automatic" command (not in the code yet — HIL-2304).

Where a choice lives:

- **A guest:** the choice lives in this browser — a reload and a new tab keep
  it, the other tabs of this browser follow it, and another browser starts
  from the ladders (not in the code yet — HIL-2299).
  The server knows a guest's choice for every tab, because it needs it to move
  tabs, to record the choice at sign-up and to write a letter
  (not in the code yet — HIL-2299).
- **A signed-in person:** the choice lives in the account — the language and,
  when one was chosen, the variant (not in the code yet — HIL-2298).
  All of that person's tabs, on every device and every node, switch at once
  and without a reload, by the same path the theme takes; see
  [theme.md](theme.md) (not in the code yet — HIL-2298).
  The check of that path on a cluster — HIL-2320.

Sign-in, sign-up and sign-out follow this table
(not in the code yet — HIL-2305).

| Event | The language and the region |
|---|---|
| sign-in | the screen takes the account's language; the guest's choice is not carried into the account and does not argue with it |
| sign-up | the account records the language on screen, and the region only if it was chosen |
| sign-out | the screen keeps its language |

Accounts made before this epic are owed no backfill and no conversion
(owner's decision, 2026-11-26); see
[Whose Data Is Owed Compatibility](../orm/migrations.md#whose-data-is-owed-compatibility).

## On A Language Address

On a host the project names in its environment for a language, the language
is that one for everyone, a signed-in person included, and the region ladder
runs as usual (not in the code yet — HIL-2313).

Choosing another language there moves the browser to the address of the chosen
language, or to the main address when that language has none; nothing is
remembered (not in the code yet — HIL-2314).

On the main address both ladders and a person's choice work as
[Core Rule](#core-rule) and [A Person's Choice](#a-persons-choice) say.

Editions on language addresses and `hreflang` — HIL-130.

## What Every Tab Knows

- On connecting, a tab learns its active language and region, the seven
  formats of that region
  ([`LocaleFormats`](../../../framework/backend/I18n/DTO/LocaleFormats.php))
  and the language's `rtl` flag. Every handshake response carries them as
  `handshake_response.data.tabLanguage` — `languageId`, `languageCode`, `rtl`,
  `localeId`, `localeCode` and `formats`, the region's two keys null when no
  stored locale fits. The node itself is null in a project without the I18N
  feature, and while the default language has no row yet; the next frame
  brings it. In the browser the core reads the node off every response of the
  current socket and publishes it as `hilosTabLanguage` from `@hilos/core`; a
  response without the node or with a malformed one keeps the last answer.
- The server remembers the language and the region of every tab: the tab's
  connection row carries `languageId` and `localeId`
  ([`HilosSessionConnection`](../../../framework/backend/Runtime/State/Item/HilosSessionConnection.php)),
  written before the response leaves and only when they change.
- The document carries `lang`: the code of the active language, never of its
  region. A null node leaves it as the page was served, and a response naming
  another language rewrites it at once, with no reload.
- A tab also learns the first day of the week of its region
  (not in the code yet — HIL-2318).
- A language with `rtl` sets `dir` and the RTL build of Bootstrap on the shell
  and the framework's windows in all three view frameworks; a demo's own pages
  come later, separately (not in the code yet — HIL-2317).
- With i18n enabled, guest and signed-in tabs receive a live list of available
  languages with their variants, native names and country names. The
  `hilos_languages` group supplies the list, and `@hilos/core/base` exposes it
  in the browser as `hilosAvailableLanguages`. On a cluster, the list stays
  live while the library's node is up, subject to the
  [group membership lifetime](../signals/subscriptions.md#group-subscription).
- The list uses each country's base English name and falls back to its code
  when that name is missing. By the owner's decision of 2026-11-26, country
  names will follow the tab's interface language, including regional overrides
  when available (not in the code yet — HIL-2280).
- The menu's suggestions come from the browser only, never from the country:
  the whole variant when it is available, otherwise the language without a
  region (not in the code yet — HIL-2301).
- The window's suggestions follow the same rule
  (not in the code yet — HIL-114).

## The Formats A Reader Sees

Write a date or a number on a screen in the formats of the tab's region — the
seven formats of the reference, which an administrator chose — and not by
handing the locale tag to `Intl`; this is the owner's decision of 2026-11-26
(not in the code yet — HIL-2303).
When the region changes, an open screen reformats without a reload
(not in the code yet — HIL-2303).

The date picker starts its week on the first day of the week of the locale; an
administrator edits that day in the locale window
(not in the code yet — HIL-2318).

The reader's time zone is another question and lives in
[time-zones.md](time-zones.md): the zone decides which hour it is, the region
decides how to write it down. The zone half is in the code —
[One Core Formatter](time-zones.md#one-core-formatter) supplies the reader's
zone on every call.

## Switching Off

- **A language.** Every open tab where it is active moves to the default
  language at once, with no click from the person
  (not in the code yet — HIL-2315).
  The choice is not erased: when the language is switched on again, the tabs
  of those who chose it return (not in the code yet — HIL-2315).
  The reply to that switch-off says how many tabs moved
  (not in the code yet — HIL-2316).
- **A chosen variant.** The language stays, and the region ladder picks the
  region again (not in the code yet — HIL-2315).
- **A country.** Its variants stop being available. A chosen variant of that
  country is treated as a switched-off variant. When that reaches the open
  tabs — HIL-2315.
- **The default language** cannot be switched off, so a tab always has a
  language to move to.

## Deleting A Chosen Language

The server refuses to delete a language while it is chosen in at least one
account, and the refusal names the number of those accounts
(not in the code yet — HIL-2306).
By the owner's decision of 2026-11-26, guests' choices are not counted
(not in the code yet — HIL-2306).

## The Country Of A Connection

The address of a connection is what `resolveClientIp()` in
[`WebSocketClient`](../../../framework/backend/Socket/Client/WebSocketClient.php)
answers under `HILOS_TRUSTED_PROXIES`. That part is in the code.

- The country of an address comes from an IP-to-country database file in the
  MMDB format — DB-IP Lite Country or GeoLite2 Country. The lookup is
  `countryOf()` of
  [`IpCountryFile`](../../../framework/backend/I18n/Country/IpCountryFile.php):
  an ISO 3166-1 alpha-2 code, or `null` when the country is unknown.
- The operator puts that file, named `country.mmdb`, into the installation's
  country directory: the logical `$fs` directory `ip_country`
  (`FsContext::IP_COUNTRY`) registered `CLUSTER` (see
  [Node Or Cluster](filesystem.md#node-or-cluster)), whose path the environment
  value `HILOS_IP_COUNTRY_DIR` can override.
- With no file, or with no such address in the file, the country is unknown
  and nothing fails.
- A file that cannot be read leaves one line in the log, and the country is
  unknown.
- By the owner's decision of 2026-11-26, a new file is seen after the daemon
  restarts and is not picked up on the fly.
- A rung takes the country as given and does not know where it came from.
- An unknown country silently skips the rung, behind a VPN the country is the
  exit's, and the country never feeds a suggestion.

The sessions library looks the country up at the handshake — `countryOf()` of
the address the transport handed it, `WebSocketHandshakeSignalDTO::$clientIp` —
and sends it as a code in the state frame,
`SessionStateSignalData::$connectionCountry`, for the one socket that
connected; the address never leaves the library. The tab's connection row
remembers the country as `connectionCountry`, and every later frame takes it
from there, so a move or a VPN reaches an open tab when that tab reconnects.
The default locale of the country gives its language even when that locale is
itself switched off.

How the file is kept fresh is not decided.

## The Language Of A Letter

A letter, an SMS and any other outgoing message is written in the language of
its reader. The caller that orders the send decides it, top down:

1. **The address belongs to an account** — the account's language, even when a
   guest's tab ordered the letter.
2. **Otherwise** — the language of the tab that ordered the letter, which is
   what its ladder gave, the browser and the country included. A letter to a
   guest and the screen that ordered it never differ.
3. **The order did not come from a tab** — the default language.

Example: a sign-in link ordered from a German screen for the address of an
account whose language is Russian is written in Russian. The letter speaks the
reader's language, and after the sign-in the screen takes the account's
language anyway.

The caller decides the language and passes it in. A delivery agent never
resolves it again: it has neither the ordering tab nor the account in reach.
Nothing remembers the language of a flow: the second letter of a flow is
decided again by the same rule.

Today no caller passes a locale and every template is written in English, so
every letter is in English; passing it and translating letters — HIL-2281.
The region of a letter to an account's owner — HIL-2281.

The interface comment of
[`MailTemplate`](../../../framework/backend/Mail/Template/MailTemplate.php)
retells this rule for the code that orders a send; this section is the canon.

## What This Page Does Not Decide

These keys are pointers to later work, not implementation markers:

- What carries a guest's choice in the browser — HIL-2299.
- Where the menu takes the browser's suggestions from — HIL-2301.
- Names of months and weekdays on a screen; the reference's date formats are
  numeric only — HIL-2303.
- When switching a country off reaches the open tabs — HIL-2315.
- The region and the translation of letters — HIL-2281.
- Translation of the interface and a preview of a switched-off language —
  HIL-2280.
- Editions on language addresses, `hreflang`, and prerendering chat by
  `Accept-Language` — HIL-130.
- The week start of analytics — HIL-1402.
- Suggestions of a country's time zones — undecided; see
  [time-zones.md](time-zones.md).

## Anti-Patterns

- **Wrong:** store a pick as if it were a choice. The product this framework
  grew from did that, and the language of the first visit stayed forever.
  Store only a choice.
- **Wrong:** show a banner or reload the page when a ladder picks. Apply the
  pick silently.
- **Wrong:** put the browser's region ahead of the country. The country of the
  connection comes first.
- **Wrong:** feed the country into suggestions. Suggestions come from the
  browser only.
- **Wrong:** run a ladder in the master. Run it in the worker, during the
  handshake.
- **Wrong:** write a date for a reader with the browser's default locale, or
  with a locale tag handed to `Intl` past the region's formats. Use the seven
  formats of the tab's region (not in the code yet — HIL-2303).
- **Wrong:** let a delivery agent resolve the language of a letter. The caller
  that orders the send decides it and passes it in.
- **Wrong:** offer a language or a locale that is not available — in the
  menu, in the window or on a rung. Offer only what is available.

## Related

- [languages-and-countries.md](languages-and-countries.md) — the reference:
  its tables, the switch and the reflow.
- [theme.md](theme.md) — the same questions for the theme, and the path an
  account's choice takes to every tab.
- [time-zones.md](time-zones.md) — the reader's zone and the moment formatter.
- [filesystem.md](filesystem.md) — node and cluster directories.
- [../antipatterns/heavy-work-in-master.md](../antipatterns/heavy-work-in-master.md)
  — why a ladder never runs in the master.
- [../rule-authoring.md](../rule-authoring.md) — clearing a marker when its
  code lands.
