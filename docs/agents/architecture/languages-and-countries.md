# Architecture: Languages And Countries

Read this before changing languages, countries, locales or their names: the
five tables, the built-in catalog and its reflow, the default language from env,
the switch that freezes a row, the lock on a name, deletion or section addresses.
Read it too before switching the section on in a project.

This is the target architecture of HIL-70, written ahead of its code. Each
marker names the leaf that makes that sentence true; that leaf clears it in
the same commit, under [A Rule Written Ahead Of Its Code](../rule-authoring.md).

## Words

- **The reference** — the five tables below.
- **The built-in catalog** — a constant in the framework sources.
- **The reflow** — copying the built-in catalog into the tables at startup.
- **A known row** — its code is in the built-in catalog.
- **An own row** — its code is absent from the built-in catalog.
- **Switched on / switched off** — the row's `enabled` value.
- **A locked name** — a name protected from reflow by `locked`.
- **The i18n library** — the agent holding all five tables and the reflow record.

The epic calls these the language and country libraries; in Hilos terms they
are one reference held by one library. [Library](entity-libraries.md) names an
agent, not a dictionary of values.

## Core Rule

The reference belongs to the framework: one library owns the five `hilos_*`
tables, and a project activates the section without supplying its contents.

A language, country or locale is addressed by its code, which never changes.
A switched-on row is frozen for everyone, both a person and the reflow. The
editing sequence is **switch off → edit → switch on**. A name has a lock,
not an on-switch. The rules and their implementing leaves are below.

The owner's reason (2026-09-12): a switched-on row is live — people have the
product open in that language, and it must not change underneath them.

## The Five Tables

Names follow [entity first, then purpose](../code-style/table-names.md), with
the framework prefix `hilos_`.

| Table | Columns and meaning |
|---|---|
| `hilos_language` | `id`; `code` — unique ISO 639-1 code; `native_name` — the language's own name; `rtl` — writing direction, a property of the language rather than the locale; `enabled`. |
| `hilos_country` | `id`; `code` — unique ISO 3166-1 alpha-2 code, in the catalog's case (`us`); `currency_symbol` — for display; `currency_code` — three-letter ISO 4217 code, for calculations; `default_locale_id` — a locale of this country, or empty to take formats from the language; `enabled`. A composite database key on (`id`, `default_locale_id`) holds the default to a locale of this country. |
| `hilos_locale` | `id`; `code` — composed by creation from the pair (`en-GB`), or the language code alone (`en`) without a country; `language_id`; `country_id` — empty for the language's countryless locale; `date_format`, `time_format`, `number_format`, `phone_format`, `address_format`, `measurement_system` (`metric` / `imperial`), `collation`; `enabled`. The pair is unique. |
| `hilos_language_name` | `id`; `language_id` — whose name; `in_language_id` — the language it is written in; `locale_id` — empty for the base name, otherwise an override for one locale of the writing language; `name`; `locked`; generated `locale_slot` for uniqueness. |
| `hilos_country_name` | `id`; `country_id` — whose name; `language_id` — the language it is written in; `locale_id` — the same base/override rule; `name`; `locked`; generated `locale_slot` for uniqueness. |

There is no `name`, `subdomain` or `sort_order` column on a language (owner's
decisions, 2026-09-11–12). There is no single name for a language: names are
written in other languages. A subdomain label can diverge from a code, while
an edition is addressed by code. Language ordering decides nothing.

The seven locale formats are chosen from the closed lists in `LocaleTemplates`
(ported from hleb). The create and update write doors refuse any other template,
including writes made by reflow.

The code is both an address and a reference: env and section addresses refer to
a language code, and translation strings will do so in Phase 2. Renaming it
would move three subsystems, so it stays immutable in every row state.

For each pair of *what is named × the language it is written in*, allow one
base name and at most one override per locale. Both tables store a generated
`locale_slot = COALESCE(locale_id, 0)` and use a unique key on the named subject,
writing language and slot, so a second base name is refused by the database too.
The override's locale must belong to the language the name is written in — for
example, a country's English name may have `the United States` as its `en-GB`
override. An override needs an existing base name for that subject and writing
language; the actions check both conditions before inserting it.
A language's own name is `native_name`; its names table is for names in other
languages.

Every column of `hilos_language`, `hilos_country` and `hilos_locale` is
declared nonpersonal in `_piiNotPersonal`. Every column of the two names
tables, including generated `locale_slot`, has the same verdict. Under
[backup anonymization](backup-anonymization.md), a restore leaves such columns
unchanged; under [admin view mode](admin-view-mode.md), the viewer can see
them all.

Locales have neither `first_week_day` nor `timezone` in this phase. HIL-1424
decides them (owner's decision, 2026-10-04).

## One Library Holds The Reference

One entity library, the i18n library, holds all five tables, and with them the
one-row record of the last reflow, `hilos_i18n_reflow`.
It also serves the section's pages.
Like every library, it has `SCOPE = CLUSTER` and `PLACEMENT = POLICY`.
See [entity libraries](entity-libraries.md) and [truth sources](truth-source.md).

This follows **one entity, one library**: the five tables are one entity for
that rule, because none of their rows can be written without reading the
others. Deleting a language carries away country names written in it; a
country's default locale is a language's locale; a locale is a language ×
country pair; the reflow writes countries and their names in one pass.
Splitting the tables between agents would turn each such write into an
inter-agent operation, with no reason to place these small, cold tables apart.
The notifications library is the precedent:
`AbstractNotificationsLibraryAgent::OWNS_DB` holds four tables.

The i18n library holds the **whole claim** — create, update and delete, the
default operation set.
Languages, countries and locales have no instance owner beside it: one
surface writes them, the section, and the startup reflow is the library's own
pass rather than a second writer. Their rows are few and cold. This is the
judgment called for by the [instance-owner rule](instance-owners.md).

The rule for a library beside instance owners, “A library never edits a row it
already wrote,” therefore does not apply here. `SettingsLibraryAgent`, the
only writer of its collection, is the same case; see
[entity libraries](entity-libraries.md). This ownership decision was made at
the epic's split with the owner on 2026-10-04.

## Known And Own Rows

A row is known when its code is in the built-in catalog and own when it is not.
There is no column for that distinction: ask the catalog by code. The reflow
walks the catalog rather than the database, so own rows are unreachable by
construction.

- Adding a known language prefills `native_name` and `rtl` from the catalog;
  the person may edit them before adding it (not in the code yet — HIL-1484).
- Adding it also brings the catalog's base country names in that language,
  unlocked, through `CountryNamesActions::takeAllFromCatalog()` — the piece the
  default language created at startup already uses (not in the code yet — HIL-1484).
- An unknown language code is allowed after confirmation that its own name
  and direction will not be refreshed, no country names will arrive for it,
  and framework updates will add nothing for it; the confirmation catches a
  typo such as `ez` instead of `es` (not in the code yet — HIL-1484).
- Phrases of the languages catalog callout regarding adding by code and custom
  languages appear together with "Add language" (not in the code yet — HIL-1484).
- A known locale pair arrives with all seven formats from the catalog;
  the add window prefills them and allows each to be changed before saving.
- Every known country arrives through reflow, and known countries cannot be
  deleted, so adding a known country is unreachable: its code is refused as
  already present (not in the code yet — HIL-1495).
- An unknown country is added through the same kind of confirmation
  (not in the code yet — HIL-1495).
- A newly added language starts switched off (not in the code yet — HIL-1484).
- A newly added locale starts switched off.
- A newly added country starts switched off (not in the code yet — HIL-1495).
- For a switched-off country with no default locale, the edit form prefills
  the catalog's default if that locale has been created; the person saves it
  (not in the code yet — HIL-1496).

Creation thus never encounters the freeze. The startup default language has
its own rule below.

## The Switch That Freezes A Row

`enabled` belongs to a language, country or locale. Those rows have no lock:
the switch protects them. A switched-off row is fully editable except for its
code.

The item actions of all three tables refuse edits of switched-on rows, whoever
calls them. The section and reflow share that write door; switching on or off
always remains available.

- The server refuses to edit a switched-on language, and its edit form is
  disabled with the instruction to switch off, edit and switch on
  (not in the code yet — HIL-1485).
- The same server refusal and disabled form apply to a switched-on country
  (not in the code yet — HIL-1496).
- The same server refusal and disabled form apply to a switched-on locale.
- The reflow skips a switched-on row: it checks the switch before it calls the
  item action, so the refusal never rolls a reflow back.

The accepted price: the default language can never be switched off, so its own
name is set once. There is also a narrow gap: a known row switched off, edited
and left off will be refreshed by the next reflow. The edit modal says so in
one line; no extra column protects that gap (owner's decision, 2026-09-12).

- The language item action refuses switching off the default language.
  The section's language card switches off another language through a confirmation
  window and reports success without a tab count; its locales and names stay in place.
  Tests create and enable a known language with `test:i18n:language:on <code>`.
- Switching on a language requires no completeness check, but warns when it
  has no countryless locale (not in the code yet — HIL-1487).
- A country can be switched on without a default locale
  (not in the code yet — HIL-1498).
- Switching a country off keeps its values (not in the code yet — HIL-1497).
- A locale is switched on from its own modal (not in the code yet — HIL-1493).
- A locale is switched off from its own modal (not in the code yet — HIL-1494).
- Switching off the countryless locale of a switched-on language warns;
  doing so for the default language is refused (not in the code yet — HIL-1494).
- Switching off a country's default locale is allowed with a warning: while
  it is off, the country takes formats from the language, and the country's
  `default_locale_id` is kept (not in the code yet — HIL-1494).

What a switched-off row hides from people belongs to HIL-1424. In this epic,
only the section reads the switch.

## The Lock On An Edited Name

`locked` belongs only to the two names tables. Names have no live on/off
state, while the reflow supplies 2,550 country names.

- Editing a language name sets its lock automatically; there is no separate
  “pin” button (not in the code yet — HIL-1489).
- Editing a country name sets its lock automatically, with no “pin” button
  (not in the code yet — HIL-1500).
- The reflow writes only unlocked names.

An empty string saved by a person is their value and is locked too. To give
a name back to the catalog, delete it:

- Deleting a base language name carries away its locale overrides
  (not in the code yet — HIL-1490).
- Deleting a base country name carries away its locale overrides
  (not in the code yet — HIL-1501).
- A base name of a known country in a known language immediately returns
  from the catalog as an unlocked row, through
  `CountryNamesActions::takeFromCatalog()` — the reflow's own piece for one name
  (not in the code yet — HIL-1501).
- A country name for an own country or in an own language disappears entirely
  (not in the code yet — HIL-1501).
- A deleted language name does not return: the catalog has no language names
  (not in the code yet — HIL-1490).
- Deleting a language-name override removes only that override, which never
  returns because the catalog supplies no overrides
  (not in the code yet — HIL-1490).
- Deleting a country-name override has the same effect: only that override
  disappears and does not return (not in the code yet — HIL-1501).

Immediate restoration of a known country's base name is the owner's decision
of 2026-10-04; restoration by a later synchronization belongs to HIL-1424.
Do not import hleb's lock that prohibited editing. The owner's experience with
that design rejected it: the lock here protects a person's edit from reflow.

## The Built-In Catalog

The catalog is five private array constants in
[`BuiltInI18nCatalog`](../../../framework/backend/I18n/Catalog/BuiltInI18nCatalog.php),
shipped with the framework version so changes are visible in the upgrade diff.
The catalog is about 171 KB, below the 1 MiB heavy-file guard in
[framework development](../framework-development.md).

| Contents | Size and values |
|---|---|
| Languages | 50: code, own name, `rtl`. |
| Countries | 51: currency symbol and code. |
| Locales | 128, including 8 countryless locales, with seven formats. |
| Country defaults | 87 default locales for countries. |
| Country names | 2,550 (51 × 50). |

The catalog supplies no language names. Its fingerprint changes with every
catalog edit. The fingerprint hashes all five recursively sorted maps as JSON,
then SHA-256; `I18nCatalogFingerprint` fixes that representation for reflow.
The currency-code map uses current tender currencies from Unicode CLDR 48.2;
the symbols and the other four groups come from the local hleb catalog.
The 87 country defaults preserve source hints even when a country is outside
the 51 built-in countries or the suggested locale has no built-in row: 38
country keys are outside that set and 21 locale references are absent. A
consumer checks that the suggested locale row exists before offering it.

## The Reflow

At startup the library compares the catalog fingerprint with the one recorded
in the database: a match causes no writes at all, the record included; a
mismatch runs the reflow and records the new fingerprint.

The recorded fingerprint is the one row of `hilos_i18n_reflow` (`id` always 1,
held there by a `CHECK`), written by `I18nReflowsActions::record()` as the last
step of the reflow, inside its transaction. It lives in the database because a
restore rewrites the database and not a file: a restored archive with an older
catalog must be reflowed on the next start, and a file would also differ between
the nodes of a cluster. It is not a row of `hilos_setting` either: the settings
library holds that table whole, and a key its catalog does not know is shown as
an orphan with a delete button, which would quietly trigger a reflow. The
comparison is equality, not "newer": a fresh installation, a new framework, an
older one rolled back to and a restored archive all reflow the same way.

There is no timer: the catalog changes with the framework version, so the
trigger is the version. There must be no window in which an edit silently
disappears “sometime in the next day.” The reflow:

- creates missing known countries, switched off, with no default locale;
- writes catalog base country names for each language already created, only
  into unlocked rows;
- refreshes switched-off known rows: a language's `native_name` and `rtl`, a
  country's currency, a locale's formats — a country keeps its default locale;
- leaves switched-on rows, locked names, locale overrides and own rows
  untouched;
- creates neither languages nor locales: a person adds those, with catalog
  values prefilled.

The last rule is the owner's decision of 2026-10-04; hleb's reflow created
everything. A fresh installation opens with 51 switched-off countries and
their names in the default language.

The reflow is one transaction, in the order countries, languages, locales,
names, record. A value already equal to the catalog's is not written. Any
failure rolls the whole reflow back together with the record: the start hook
fails loudly, the library serves the section on the data it had, and the next
start runs the reflow again. The default language is provisioned before it, in
a transaction of its own, so a failed reflow does not take that back. A default
language created on this start gets the catalog's country names in it inside
that first transaction — on a fresh installation there are no countries yet and
the reflow brings both, while after an env change to a new language its 51 names
arrive at once.
The reflow runs once regardless of how many nodes start
(not in the code yet — HIL-1505).
Reflow as an Operations action, with history and a manual trigger, belongs
to HIL-1424.

## The Default Language

`HILOS_DEFAULT_LANGUAGE` names the default language by its exact lowercase
built-in language code. It is neither a setting nor a column (owner's decision,
2026-09-11–12). Every project with `HilosFeature::I18N` supplies it; projects
without the feature need not set it. The daemon validates the code against the
static catalog after reading required env values, before booting or opening
servers. An empty or unknown code refuses startup with the key and original
value in the error; the library also resolves it from env when starting.

- On first startup, the i18n library creates that language with catalog values
  and switches it on. It does this in one transaction together with its
  countryless locale, if the catalog defines one.
- Nobody can switch it off or delete it, regardless of where the request
  originates: the language item actions refuse both writes, even if the row
  is already switched off.
- Its own name and direction are taken from the catalog only at creation;
  an existing row is switched on without replacing them. Reflow skips the
  switched-on row.
- An unknown code prevents the node from starting and names that code; an
  own default language is not allowed, catching `ez` instead of `es`.
- Changing env leaves the former default as an ordinary switched-on language,
  which can now be switched off; the new default is created and switched on
  at startup.
- Its countryless locale is created with it at startup if the catalog has
  one. The ordinary warning when no countryless locale exists belongs to
  HIL-1487 (not in the code yet — HIL-1487).
- A cluster handshake refuses a node whose env names a different default
  language (not in the code yet — HIL-1505).

The handshake follows the admin view mode precedent in
[daemon lifecycle](daemon-lifecycle.md). The language list cannot be empty:
the default language is always there.

## Deleting

The database refuses deletion while a locale references its language or country,
or while a country selects the locale as its default. The deletion checks below
will give those refusals their user-facing text.

- A language can be deleted only when it has no locales and no names written
  by a person, whether naming that language or written in it
  (not in the code yet — HIL-1488).
- Catalog country names in that language do not block deletion and go with
  it (not in the code yet — HIL-1488).
- The language item action refuses deleting the default language. The
  section's delete action and its other checks follow in HIL-1488.
- Deleting a locale is refused while it is switched on or selected as any
  country's default locale (not in the code yet — HIL-1492).
- Deleting a locale removes its seven formats; texts for that pair take the
  language's countryless locale (not in the code yet — HIL-1492).
- A known country is never deleted — reflow would bring it back; switch it
  off instead (not in the code yet — HIL-1499).
- An own country can be deleted only while no locale or name references it
  (not in the code yet — HIL-1499).

For names, see *The Lock On An Edited Name*. The checks for a language chosen
by someone or referenced by a translation string belong to HIL-1424.

## Addresses

A language or country is addressed by code, not row number. A code never
changes; a row number can change on a reload of the data, and a URL can be
sent by mail. Code addresses and the three new names/locales page keys are registered
together (HIL-1473). The Vue main language card is built (HIL-1478) and opens
by direct URL with one subscribed `languageCard` snapshot and live updates; the
Vue main country card is built the same way with `countryCard` (HIL-1482).
The Vue names page of a language is built too (HIL-1477): the names table opened
on the language the address names, standing under the card's shared header and
tabs fed by the same `languageCard` datum (HIL-1479). So is its Vue locales page
(HIL-1476): the locales table opened on that language, a row for the language
alone and one for every country, keyed by the pair written as its locale's code,
whether the pair has a locale or not, standing under the card's shared header and
tabs fed by the same `languageCard` datum. The Vue section root is built
(HIL-1474) and shows the Languages card; the Vue languages list is built whole
with its table (HIL-1474), the catalog tally callout above it (`builtInCatalog`),
and the marks legend below it (HIL-112), kept live by the table's own fan-out.
The Vue names page of a country is built too
(HIL-1483): the names table opened on that country, standing under the card's shared header and
tabs fed by the same `countryCard` datum. The Vue countries list is built
(HIL-1475): the root shows the Countries card, and the list is the countries
table, kept live by the table's own fan-out. React and Angular remain unbuilt
until HIL-1502/1503.

| Page key | Route |
|---|---|
| `hilos_i18n` | `/hilos/i18n` |
| `hilos_i18n_languages` | `/hilos/i18n/languages` |
| `hilos_i18n_language` | `/hilos/i18n/languages/{languageCode}` |
| `hilos_i18n_language_names` | `/hilos/i18n/languages/{languageCode}/names` |
| `hilos_i18n_language_locales` | `/hilos/i18n/languages/{languageCode}/locales` |
| `hilos_i18n_countries` | `/hilos/i18n/countries` |
| `hilos_i18n_country` | `/hilos/i18n/countries/{countryCode}` |
| `hilos_i18n_country_names` | `/hilos/i18n/countries/{countryCode}/names` |

See the [page registry](../frontend/page-registry.md). The
`/hilos/i18n/translate/**` branch goes away with HIL-1424.

## Switching The Section On

i18n is a framework feature.
The project:

- lists it in `Hilos::FEATURES`;
- registers the library's agent pair in `Hilos::AGENTS`;
- registers the three thin section pages in the server topology;
- migrates the six framework stubs: the five reference tables and `hilos_i18n_reflow`;
- supplies `HILOS_DEFAULT_LANGUAGE` with an exact built-in code;

The six demos have the feature, agent, server pages and migrations (HIL-1470).
The names tables of a language and of a country (`hilosI18nLanguageNames`,
`hilosI18nCountryNames`) are registered and bound to their pages in all six
(HIL-1477), and so is the locales table of a language (`hilosI18nLanguageLocales`,
HIL-1476). The languages table (`hilosI18nLanguages`) is registered and bound
to the languages list in all six (HIL-1474), and the countries table
(`hilosI18nCountries`) is registered and bound to the countries list in all six
(HIL-1475). All six demos
bind `languageCard` to the names and locales pages as well as to the main
language page (HIL-1479), and `countryCard` to both country card pages (HIL-1483). Vue serves
the section root at `/hilos/i18n` and the languages list at `/hilos/i18n/languages`
(HIL-1474), the main language card at `/hilos/i18n/languages/{languageCode}` (HIL-1478), its
names at `/hilos/i18n/languages/{languageCode}/names` (HIL-1477) and its locales
at `/hilos/i18n/languages/{languageCode}/locales` (HIL-1476). The card shows the code,
native name, direction, enabled state, locale/name counts, and read-only delete
verdict. The main card has a Switch off control (HIL-1486); its other action
controls belong to HIL-1485/1487/1488.
The Vue locales page adds a locale from a pair without one, edits a switched-off
locale, and shows a switched-on locale in the same window (HIL-1491). The server
actions serve all six demos; deletion is HIL-1492 and switching the locale from
its window is HIL-1493/1494.
Vue serves the countries list at `/hilos/i18n/countries` (HIL-1475), the main
country card at `/hilos/i18n/countries/{countryCode}` (HIL-1482) and its names
at `/hilos/i18n/countries/{countryCode}/names` (HIL-1483).
The card shows the name, code, currency, default locale, enabled state and
read-only delete verdict: a known country never, else locales, else any name
row, block deletion. Its future action controls belong to
HIL-1496/1497/1498/1499.
React and Angular stay unbuilt until HIL-1502/1503.

Startup refuses a missing page or agent; the topology tests check that the
six migration files are present after registration.
Follow [Feature Declaration](../app-topology.md) and the
[activation recipe](admin-feature-scaffold.md).
The six demos are `chat`, `tasks`, `polls`, `ecommerce-shop`,
`binance-btc-tracker` and `online-testing`.

Once built, the section root shows only Languages and Countries. Translation
subsections have no registered server page: the existing rule hides their cards
and answers 404 for a page the project does not serve. Build no separate
mechanism for that (owner's decision, 2026-10-04).

Edits of the languages table reach every open tab through the ordinary
[browser table source fan-out](browser-source-fanout.md) (HIL-1474), and so do
edits of the countries table (HIL-1475).
The columns are nonpersonal, so [admin view mode](admin-view-mode.md) shows
the whole section without allowing the viewer to change anything.
The country list uses names in the default language and falls back to the
code when no name exists (HIL-1475). The header of the
country card follows the same rule (HIL-1482).
Names in the reader's language belong to HIL-1424.

## What This Page Does Not Decide

These keys are pointers to later work, not implementation markers:

- Whether every process reads the reference locally, as with settings — the
  first reader outside the section, language selection in HIL-1424, decides.
- Language selection and moving open tabs when a language is switched off —
  HIL-1424.
- Translation — HIL-1424.
- Synchronization with restcountries and flags — HIL-1424.
- Reflow and synchronization as Operations actions — HIL-1424 and HIL-1053.
- `first_week_day` and `timezone` — HIL-1424.
- A working preview — HIL-1424.
- Multilingual CMS content — HIL-129; SSG language editions — HIL-130.

## Anti-Patterns

- **Wrong:** edit a switched-on row because it is known or the change is small;
  the switch protects the live value for everyone. Switch off, edit, switch on.
- **Wrong:** reflow by iterating database rows; that reaches own rows. Walk the
  built-in catalog instead.
- **Wrong:** add a “pin” button or put a lock on a language, country or locale;
  editing locks names automatically, while the three root tables use `enabled`.
- **Wrong:** put a row number in a language or country address; use its stable
  code so the address survives a reload of the data.
- **Wrong:** put reflow on a timer; compare fingerprints at startup when the
  framework version changes.
- **Wrong:** remove a name by saving an empty string; that saves a locked value.
  Delete the name to give it back.
- **Wrong:** add a second agent that writes these tables; the i18n library owns
  the whole reference, including its reflow.

## Related

- [entity-libraries.md](entity-libraries.md) — the unit, holder and placement axes.
- [truth-source.md](truth-source.md) — the whole write claim and its operations.
- [admin-features.md](admin-features.md) — framework ownership and project activation.
- [admin-feature-scaffold.md](admin-feature-scaffold.md) — the activation recipe.
- [admin-view-mode.md](admin-view-mode.md) — visible columns and refused mutations.
- [backup-anonymization.md](backup-anonymization.md) — the nonpersonal verdict.
- [browser-source-fanout.md](browser-source-fanout.md) — updates to open tables.
- [daemon-lifecycle.md](daemon-lifecycle.md) — cluster handshake markers.
- [../app-topology.md](../app-topology.md) — feature declaration and page visibility.
- [../code-style/table-names.md](../code-style/table-names.md) — entity before purpose.
- [../frontend/page-registry.md](../frontend/page-registry.md) — page keys and routes.
- [../rule-authoring.md](../rule-authoring.md) — clearing a marker when its code lands.
