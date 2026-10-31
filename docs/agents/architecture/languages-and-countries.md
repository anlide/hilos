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

A public address resolves a language, country or locale by its immutable code.
Internal references and action targets carry the captured primary ID, under
[entity references](../orm/entity-references.md).
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

Environment values and section URLs use language codes as external addresses.
Catalog lookups use codes as definition keys. The entry boundary resolves these
values; internal relationships use primary IDs. Codes remain immutable in every
row state; this distinction introduces no rename operation.

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

Locales have no `first_week_day` in this phase; HIL-1424 decides it. A locale
does not store `timezone` (owner's decision, 2026-10-16). Time-zone selection
and application belong to HIL-1528; see [time-zones.md](time-zones.md).

## One Library Holds The Reference

One entity library, `hilos_i18n_library`, holds all five tables, and with them the
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
`AbstractNotificationsLibraryAgent` holds the notification tables together,
three by claim and the channel preferences by reading.

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
  the person may edit them before adding it.
- Adding it also brings the catalog's base country names in that language,
  unlocked: `LanguagesActions::add()` creates the language and calls
  `CountryNamesActions::takeAllFromCatalog()` in one transaction — the piece the
  default language created at startup already uses.
- An unknown language code is allowed after confirmation that its own name
  and direction will not be refreshed, no country names will arrive for it,
  and framework updates will add nothing for it; the confirmation catches a
  typo such as `ez` instead of `es`.
- Phrases of the languages catalog callout regarding adding by code and custom
  languages appear together with "Add language".
- The add form tells known from own by the page data `builtInLanguages` of the
  languages list (the 50 catalog entries), with no request; the server picks
  the success text by the same catalog. A repeated code is refused by
  `LanguagesActions::create()`, so every door refuses it.
- A known locale pair arrives with all seven formats from the catalog;
  the add window prefills them and allows each to be changed before saving.
- Every known country arrives through reflow, and known countries cannot be
  deleted, so adding a known country is unreachable: `CountriesActions::create()`
  refuses its code with "Country '<code>' already exists."; all creation doors
  receive that refusal.
- An unknown country is added after confirmation. The form distinguishes known
  and own codes without another request: the countries page response carries
  `builtInCountryCodes`, the 51 built-in codes. The server uses the same catalog
  to choose the success message.
- Phrases of the countries catalog callout regarding adding by code and custom
  countries appear together with "Add country".
- A newly added language starts switched off.
- A newly added locale starts switched off.
- A newly added country starts switched off.
- For a switched-off country with no default locale, the edit form prefills
  the catalog's default if that locale has been created, even when that locale
  is switched off; the person saves it. The table row and the card summary
  carry `locales` and `catalogDefaultLocaleCode`, so opening the window needs
  no extra request. The suggestion alone enables Save but does not ask to
  discard changes. Locale choices stay live; a vanished selection stays
  selected and marked as gone until the person changes it or the server
  refuses the save.

Creation thus never encounters the freeze. The startup default language has
its own rule below.

## The Switch That Freezes A Row

`enabled` belongs to a language, country or locale. Those rows have no lock:
the switch protects them. A switched-off row is fully editable except for its
code.

The item actions of all three tables refuse edits of switched-on rows, whoever
calls them. The section and reflow share that write door; switching on or off
always remains available.

- The server refuses to edit a switched-on language (`LanguageActions::update()`);
  the language window shows its form disabled with the instruction to switch off,
  edit and switch on. The window opens from a row of the languages table and from
  the Main section of the language card. One action cannot belong to two pages,
  so the card sends `hilos_i18n_language_update` and the row
  `hilos_i18n_language_row_update`; both save through one page trait into the same
  door. For a known language the window says in one line that the reflow brings
  the catalog's values back while the language stays off; an own language is
  never refreshed.
- The same server refusal applies to a switched-on country. Its view window
  shows all four fields disabled and explains the freeze. Both the table row
  and the Main section of the card open the shared edit/view window; the mode
  is fixed on opening. One action belongs to one page: the card sends
  `hilos_i18n_country_update`, the row `hilos_i18n_country_row_update`.
  Both pass through `CountryUpdate::apply()` to `CountryActions::update()`.
  The edit window keeps its draft if the country is switched on or deleted
  elsewhere, blocks Save, and offers the shared conflict choices when the
  same field changed on both sides. A view stays a view after switch-off.
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
  A test removes its language fixture in teardown with `test:i18n:language:delete <code>`:
  the library calls the existing item delete action, answers after commit, and accepts
  an already absent fixture. The default-language and database reference guards still
  apply; the command neither removes dependent rows nor supplies the section's delete UI.
- The language card switches on a disabled language through a confirmation
  window. It shows the number of names and whether the countryless locale is
  missing, switched off or switched on. A missing or switched-off countryless
  locale raises a warning; neither state blocks switching on the language.
  Browser tests set the starting state with `test:i18n:language:off <code>` and
  `test:i18n:locale:on <code>`.
- A country can be switched on without a default locale. The section's country
  card switches on a country through a confirmation window; no default locale
  is needed, and the window notes when it is missing or switched off without
  blocking the switch. Tests switch off a known country with
  `test:i18n:country:off <code>`.
- Switching a country off keeps its values: the button on the card opens a
  confirmation window with the count of locales and names, and they stay in
  place. Tests switch on a known country with `test:i18n:country:on <code>`.
- A switched-off locale is switched on from its edit window: Switch on next to
  Save turns the same modal into a confirmation step. Unsaved changes must be
  saved first; switching on an enabled locale succeeds without a write.
- A switched-on locale is switched off from its view window: Switch off next
  to Close turns the same modal into a confirmation step, and Back returns to
  the view without a request. The tracked `hilos_i18n_locale_switch_off` action
  takes `languageId`, required nullable `countryId` and `localeId`, keeps formats
  and names, and closes the window on success with `Locale <code> switched off.`.
  The table removes Enabled and replaces the eye with the edit control in
  every open tab without a reload; reopening the edit window unlocks the seven
  formats. Switching off an ordinary disabled locale succeeds without a write.
- Switching off the countryless locale of a switched-on language warns;
  while it is off, formats fall back to the default language. For the default
  language, the step explains the protection and disables confirmation. The
  item write door independently refuses with
  `Default language locale '{code}' cannot be switched off`, even if the locale
  is already disabled: this guard runs before the idempotent return.
- Switching off a country's default locale is allowed with a warning: while
  it is off, the country takes formats from the language, and the country's
  `default_locale_id` is kept. The warning applies whether the country is on
  or off. The table's computed `isCountryDefault` compares the locale id with
  that choice, is false without a country or locale, and follows country
  updates in every language window through the existing table source.

The switch-off step reads the live language card and focused locale row.
Another tab switching the locale off disables confirmation and says
`Switched off elsewhere just now.`; deletion of the locale, country or language
says `Deleted elsewhere — your text stays to copy.`. A pair whose locale vanished
can remain as a table row: a null locale code or formats still closes the write
door. The action's own source echo is not a foreign change, and its pending
request blocks closing and another submit until acknowledgement. An admin-view
reader can open the step, with confirmation disabled. A known locale also warns
that the next reflow can replace its formats while it stays off.

What a switched-off row hides from people belongs to HIL-1424. In this epic,
only the section reads the switch.

## The Lock On An Edited Name

`locked` belongs only to the two names tables. Names have no live on/off
state, while the reflow supplies 2,550 country names.

- Editing a language name sets its lock automatically; there is no separate
  “pin” button.
- Editing a country name sets its lock automatically, with no “pin” button.
- The reflow writes only unlocked names.

An empty string saved by a person is their value and is locked too. To give
a name back to the catalog, delete it:

- Deleting a base language name carries away its locale overrides.
- Deleting a base country name carries away its locale overrides.
- A base name of a known country in a known language immediately returns
  from the catalog as an unlocked row, through
  `CountryNamesActions::takeFromCatalog()` — the reflow's own piece for one name.
- A country name for an own country or in an own language disappears entirely.
- A deleted language name does not return: the catalog has no language names.
- Deleting a language-name override removes only that override, which never
  returns because the catalog supplies no overrides.
- Deleting a country-name override has the same effect: only that override
  disappears and does not return.

The ADMIN language names page offers `hilos_i18n_language_name_add` and
`hilos_i18n_language_name_update`. Both carry `languageId`, `inLanguageId`, required nullable `localeId`
(`null` for the base), and the literal `name`. ADD also carries required nullable
`baseNameId`: null for a new base, the captured base ID for a correction.
UPDATE carries the existing row's `nameId`. Creation
and editing save `locked = true`, preserving empty strings and whitespace.
A saved empty base permits a locale correction; a saved empty correction is
its own value, not inheritance. The Vue table shows a pencil for either saved
value and a plus only for `null`. Clearing the field never deletes a name.

Update also carries `expectedName`: the latest committed text acknowledged by
the row-edit session, including after Keep mine or Take theirs. The i18n library
compares it and writes in one synchronous handler, refusing a missing or changed
name; ADD refuses an existing base or correction. A replacement with equal text is refused when its ID differs. The expected text
protects changes to the same row; the captured ID protects delete/recreate.

The Vue window takes the parent row into focus and fixes its base/correction
coordinates and ADD/EDIT mode on opening. A late creation leaves ADD open with
its draft and receives the duplicate refusal in the modal. EDIT follows the
live name through the shared row-edit session and offers Keep mine and Take
theirs on conflict. A missing source leaves the draft available to copy and
blocks Save as Deleted. Busy and admin-view guards close the write controls;
success closes the window, and committed names reach other open tabs without
Apply or reload. The language and locale switches do not gate these name writes.

The ADMIN country names page offers `hilos_i18n_country_name_add` and
`hilos_i18n_country_name_update`, with `countryId`, `inLanguageId`, required nullable
`localeId`, and literal `name`. ADD carries `baseNameId` and UPDATE `nameId`,
with the same contracts as language names. They follow the same creation, editing and
locked-value rules, including empty strings and whitespace. UPDATE carries the
latest `expectedName` acknowledged by the row-edit session; ADD refuses an
existing name. The country, language and locale switches do not gate these
writes. The Vue window fixes its coordinates and mode on opening, keeps its
draft on refusal or disappearance, and shares one table focus with deletion.
Committed names and the country header reach both open tabs without Apply.

The ADMIN language names page offers `hilos_i18n_language_name_delete` and
`hilos_i18n_language_name_override_delete`. Both carry `languageId`,
`inLanguageId`, `nameId` and the literal `expectedName` captured after focusing the row;
the override also carries a positive `localeId`. The library refuses a missing or changed
name before writing. An empty string is a saved value, while `null` means no
name or inheritance. Names have no `enabled` field: neither the language nor
the locale needs switching off, and `locked` does not forbid manual deletion.

The ADMIN country names page offers `hilos_i18n_country_name_delete` and
`hilos_i18n_country_name_override_delete`, with `countryId`, `inLanguageId`,
`nameId`, the literal `expectedName`, and `localeId` for the override. Its confirmation
freezes the fresh focused value, while the live source blocks confirmation if
the country, base name or correction disappears. A changed name is refused by
the library; an equal-text replacement with another ID is refused too. The base confirmation warns
that all current corrections go, including those added after it opened.

`CountryNameActions::deleteAndRestoreCatalog()` deletes the base and its
corrections and calls `CountryNamesActions::takeFromCatalog()` inside one
transaction. A failed restoration rolls back the rows and their cached state
and releases no announcements. The existing `delete()` keeps deletion without
restoration for system calls and fixture cleanup. An override uses its own
`delete()` and never calls the catalog.

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

On an ordinary shared startup, the existing POLICY placement gives
`hilos_i18n_library` one holder, so it takes the catalog once regardless of how
many nodes start. A restarted holder compares the database fingerprint and
skips the same catalog. After a successful commit, `reflowIfChanged()` writes
`I18n catalog reflow committed: <fingerprint>` at INFO, outside the rollback
catch. A matching fingerprint or a rolled-back reflow writes no such line.
`I18nCatalogReflowTest` in chat proves the transaction is closed and the data
and fingerprint recorded before INFO, along with skip, rollback and retry.
A crash between commit and logging can leave a committed reflow without that
line; restart idempotence comes from the database fingerprint.

HIL-1505's cluster proofs are scenarios **41/42** and the five live i18n browser
checks on binance-btc-tracker: one startup and committed reflow, no repeat after
holder restart, another default language refused on both ends, and edits
delivered between masters. Their checks and pointed commands are in
[the cluster testing guide](../testing.md#the-cluster-stands--three-demos-three-shapes).
Manual reflow and its history belong to the languages and countries section in
HIL-1424.

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
  one. The language card warns when the countryless locale is missing or off.
- A cluster handshake refuses a node whose env names a different default
  language, through the `default-language` marker.

The handshake follows the admin view mode precedent in
[daemon lifecycle](daemon-lifecycle.md). The language list cannot be empty:
the default language is always there.

## Deleting

The database refuses deletion while a locale references its language or country,
or while a country selects the locale as its default. The deletion checks below
will give those refusals their user-facing text.

- A language can be deleted only when it has no locales and no names written
  by a person, whether naming that language or written in it. Locked country
  names written in it also refuse deletion.
- Catalog country names in that language do not block deletion and go with
  it: the unlocked base names and the language are removed in one transaction.
  A refusal or failed write rolls back both the rows and their ORM memory.
- The language item action refuses deleting the default language and checks
  every dependency again inside that transaction. Both page actions —
  `hilos_i18n_language_delete` on the card and
  `hilos_i18n_language_row_delete` on the list — take only `languageId` and
  call that item action. They acknowledge success with `Language <native name> (<code>) deleted.`
- Deleting a locale is refused while it is switched on or selected as any
  country's default locale, even when that country is switched off. Any language
  or country name override referring to the locale also refuses deletion,
  including an empty or unlocked override. Remove those overrides first;
  deletion never cascades into names.
- The locale item action checks those three reasons again at write time, in
  that order. The ADMIN page action `hilos_i18n_locale_delete` takes only
  `languageId`, required nullable `countryId` and `localeId`, calls that item action,
  and acknowledges success with `Locale <code> deleted.`
- Deleting a locale removes its seven formats and leaves its language/country
  pair in the table. The intended Phase-2 fallback for that pair is the language's
  countryless locale; without that locale, it is the default language. This
  deletion flow does not implement the formatting resolver (HIL-1424).
- A known country is never deleted — reflow would bring it back; switch it
  off instead.
- An own country can be deleted only while no locale or name references it
  — any locale, on or off, and any name row, including an empty base name or
  a locale override. The country's enabled state does not block deletion.
- The country item action checks the built-in catalog and every dependency
  again at write time; it removes no referencing rows. A refusal leaves the
  country and its cached data intact, and foreign keys protect a late reference.
  Both page actions — `hilos_i18n_country_delete` on the card and
  `hilos_i18n_country_row_delete` on the list — take only `countryId` and
  call that item action. Success acknowledges `Country <name> (<code>) deleted.`
- The Vue list's last actions column and the card's State section open one
  shared confirmation dialog. The list focuses the fresh row on open and
  releases it on close or unmount. The row and card expose the live server
  hint `canDelete` / `deleteReason`, ordered known, locales, names. Any locale
  or name change refreshes the row hint, including deletion of the last
  reference; the hint never replaces the server's write-time check.
- A late blocked hint leaves an open confirmation available for the server's
  refusal. An idle dialog whose source disappears stays open with
  `Deleted elsewhere.` and Cancel. During its own request, the dialog keeps
  its chosen target and tracked handle through the deletion echo, closes only
  on success, and only then navigates from the card to the list. Any failure
  remains in the dialog. A viewer can open it, but cannot confirm deletion.

For names, see *The Lock On An Edited Name*. The checks for a language chosen
by someone or referenced by a translation string belong to HIL-1424.

## Addresses

A public language or country URL is addressed by code. A code never
changes; a row number can change on a reload of the data, and a URL can be sent
by mail. Code addresses and the three new names/locales page keys are registered
together (HIL-1473). The Vue main language card is built (HIL-1478) and opens by
direct URL with one subscribed `languageCard` snapshot and live updates; its
Names and Locales pages use the same datum (HIL-1479). The Language entity head
and Overview / Names / Locales row are adopted in Vue, React and Angular. The
shared breadcrumb passes through Languages and ends at the live native name;
the row links only pages whose route exists in that project. The Vue and Angular
language Overview has three route-aware summary tiles — Names, Locales, and the
locale without a country — followed by Main and State. The Locales tile shows total
and enabled counts. The Vue and Angular Names editors explain base names and
locale corrections. The React language Overview likewise has route-aware Names,
Locales, and locale-without-a-country tiles followed by Main and State; its
Locales tile shows total and enabled counts, and its Main section shows the
writing-direction checkbox disabled. Its Names editor explains base names and
locale corrections.

The Vue names page of a language is built (HIL-1477): its table opens on the
language named by the address, under the shared head and tabs fed by
`languageCard`. So is its Vue locales page (HIL-1476): the table has a row for
the language alone and one for every country, keyed by
`<languageId>:<countryId>` or `<languageId>:null`, whether the pair has a locale
or not, under the same head and tabs. The Vue main country card is built the
same way with `countryCard` (HIL-1482). The Vue section root (HIL-1474)
mounts a context-bound overview with live, read-only Languages and Countries
tiles; each tile follows the section tabs' route eligibility. The Vue languages
list is built whole with its table (HIL-1474), the catalog tally callout above it
(`builtInCatalog`), and the marks legend below it (HIL-112), kept live by the
table's own fan-out. The language-list marks legend has five entries and no
preview row. Its row actions in all three kits include a `?` opener that shows
the current reason from the existing delete verdict; the opener is not added to
the language card. On the Vue card, State keeps that read-only reason visible
as words. The language Names pages in all three kits use a primary button to
add a missing base name or correction and an outline-primary button to edit an
existing one. The Vue names page of a country is built too (HIL-1483):
the names table opened on that country, standing under the card's shared header
and tabs fed by the same `countryCard` datum. The Vue countries list is built
whole with its table (HIL-1475), the catalog tally callout above it
(`builtInCatalog`), and the marks legend below it (HIL-1481), kept live by the
table's own fan-out. It has an Add country main action with the own-country
confirmation (HIL-1495). The callout and legend are the shared components of
the languages and countries lists. React also serves all eight reference pages
(HIL-1502); its section root remains the context-free default shell, with
overview tiles and their context in HIL-1726. Angular serves the same eight
pages (HIL-1503), with its section root also the context-free default shell;
overview tiles and their context are HIL-1727.

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

## Internal IDs On The Wire

IDs are positive integer JSON numbers, with required `null` for an absent
optional child; numeric strings, booleans, floats, zero and omitted nullable
keys are refused. Language and country ADD keep their code attributes. Their
other actions use `languageId` or `countryId`. Country UPDATE carries
`defaultLocaleId`, and each locale choice carries `localeId` beside its code
label. The catalog suggestion selects that existing choice's ID on opening.
Locale ADD carries the parent IDs; UPDATE, DELETE and both switches also carry
the captured `localeId` and check the parent pair before the item action.

The first page response carries `languageCard.languageId` or
`countryCard.countryId`. `languageCard.summary.enabledLocaleCount` is a required
nonnegative integer: the enabled locales of that language, including its
countryless locale. `localeCount` still counts every locale, enabled or not;
`nameCount`, `countrylessLocale`, `canDelete` and `deleteReason` remain in the
summary. `Hilos::$db->languages->cardSummary()` uses the typed
`Hilos::$db->locales->countEnabledForLanguage()` View API, and the existing
`languageCard` BrowserData flow carries it. The whole `summary` has the
nonpersonal verdict. This adds no SQL, cache, RT state or request.

Names and locales windows take their numeric parent
filters from that card; no invented zero or code is sent before it arrives.
Lists use decimal ID row keys. Names rows use `String(inLanguageId)` and carry
`nameId: null` for a missing base. Corrections carry `localeId`, `countryId`
and their own nullable `nameId`. ID and text come from the same typed View row.
All these fields retain their column-backed admin-view policies.

Open windows capture those identities after focus. Live text on the same name
merges normally; a different name, locale or subject ID leaves the draft
available and blocks the stale operation. There is no fallback to the code,
parent pair or equal text of a replacement. Vue, React and Angular share that
contract and preserve the code labels and public URLs.

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
(HIL-1477), and so is the locales table of a language
(`hilosI18nLanguageLocales`, HIL-1476). The languages table
(`hilosI18nLanguages`) is registered and bound to the languages list in all six
(HIL-1474), and the countries table (`hilosI18nCountries`) is registered and
bound to the countries list in all six (HIL-1475). All six demos bind
`languageCard` to the names and locales pages as well as to the main language
page (HIL-1479), and `countryCard` to both country card pages (HIL-1483). Vue
serves the section root at `/hilos/i18n` and the languages list at
`/hilos/i18n/languages` (HIL-1474), the main language card at
`/hilos/i18n/languages/{languageCode}` (HIL-1478), its names at
`/hilos/i18n/languages/{languageCode}/names` (HIL-1477) and its locales at
`/hilos/i18n/languages/{languageCode}/locales` (HIL-1476). The Binance BTC
Tracker and Chat mount thin, lazy Vue wrappers for the section overview and both
lists outside `hilosAdminViews()`: the overview receives its current page scopes,
and each list receives its project context. Both lists draw the shared section
head with `head-kind="section"` and `tabs-label="Internationalization pages"`.
The adopted language head shows the code beside the live native name and keeps
writing direction in its description; the live name appears in its shared h1,
breadcrumb and document title. The Vue language Overview has the three summary tiles,
followed by Main and State. The main card has Switch off and Switch on controls
(HIL-1486/1487) and the edit or view control of its Main section, which also
carries the frozen mark (HIL-1485). A row of the languages table carries the
same edit or view control (HIL-1485), with a trash button beside it. The card
summary and live table fields `canDelete` / `deleteReason` are hints: the
server judges again after the window opens.
Both Vue entries open the same delete modal, fixing the primary ID, code and native name
on open; the list first takes the fresh row into focus and follows it live.
A dependency appearing under the open window leaves confirmation available
for a server refusal, drawn in the modal's reserved error row. Busy blocks
closing and another submit. A vanished idle source says `Deleted elsewhere.`;
an own deletion keeps its action alive until acknowledgement. Success closes
the modal; the card then navigates to the languages list, and the list keeps
its address and the table's normal removal/Apply model. An admin-view reader
may open a free language's modal, with confirmation disabled.
The Vue locales page adds a locale from a pair without one, edits a switched-off
locale, and shows a switched-on locale in the same window (HIL-1491). The server
actions serve all six demos. Each existing locale also has a trash button,
including a switched-on or country-default locale. Its separate confirmation
focuses the fresh row, freezes the parent IDs, locale ID, codes and labels, and releases focus on close
or unmount. A late switch or default selection leaves Delete available for the
server's refusal. An idle missing locale, country or language says
`Deleted elsewhere.`; the window stays open with Delete disabled. During its own request it retains
the target and tracked action through the deletion echo, closing only on success.
Failures stay in the reserved error row; pending blocks closing and repeat sends.
A viewer can read the window with Delete disabled. The table keeps the same pair
in place, immediately shows `No locale` and the plus instead of its tick and
edit/view/delete controls in every tab, and lowers the card's locale count.
The edit window switches a locale on (HIL-1493); switching off from the view
window is HIL-1494.
Vue serves the countries list at `/hilos/i18n/countries` (HIL-1475), the main
country card at `/hilos/i18n/countries/{countryCode}` (HIL-1482) and its names
at `/hilos/i18n/countries/{countryCode}/names` (HIL-1483). The card shows the
name, code, currency, default locale, enabled state, the switch-on window's
default locale note, the switch-off window's locale and name counts, and
read-only delete verdict: a known country never, else locales, else any name
row, block deletion. The main card has Switch on and Switch off controls
(HIL-1498, HIL-1497). Its Main section and each row of the list open the shared
country window: a pencil edits a switched-off country, an eye views a frozen
one (HIL-1496). The list's legend explains Custom country, View and Edit,
followed by Deletion blocked. The table row's trash control and the card's
State section keep their live deletion verdict and open the shared delete
confirmation (HIL-1499). A viewer may open these windows and fill the edit
fields; Save and the delete confirmation are disabled by the admin view mode.
React exports `HilosI18nLanguagesPage`, `HilosI18nLanguagePage`,
`HilosI18nLanguageNamesPage`, `HilosI18nLanguageLocalesPage`,
`HilosI18nCountriesPage`, `HilosI18nCountryPage` and
`HilosI18nCountryNamesPage`, with their public props (HIL-1502). The tasks and
ecommerce-shop demos mount seven thin consumers over their own
`hilosI18nLanguageContext` and `hilosI18nCountryContext`, each bound to that
demo's connection, actions and scopes. These context-required pages stay outside
`hilosAdminViews()`; the section root remains its default shell.
Angular exports the same seven pages, each taking its `context` input
(HIL-1503), and draws the same windows over the same core sessions: a language's
add, view/edit, switch on and off, delete, name and locale windows, and a
country's add with the own-country confirmation, view/edit, switch on and off,
delete and name windows. The polls and online-testing demos mount seven thin
OnPush wrappers over their own `hilosI18nLanguageContext` and
`hilosI18nCountryContext`, each bound to that demo's connection, actions and
scopes. These pages too stay outside `hilosAdminViews()`; the section root
remains its default shell.

Startup refuses a missing page or agent; the topology tests check that the
six migration files are present after registration.
The section's e2e are organized into five family specs in binance-btc-tracker
(Vue), tasks (React) and polls (Angular): language, country, locale,
language-names and country-names. The separate `i18n-overview.spec.ts` remains
in binance-btc-tracker. Shared browser checks live in
`framework/frontend/e2e/i18n.ts`; test-scoped fixtures and command wrappers live
in `framework/frontend/scripts/i18nFixtures.mjs` and
`framework/frontend/scripts/i18n.mjs`. Setup and cleanup call the `test:i18n:*`
commands through these shared wrappers (HIL-1824).
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
Every success message of the section names its object the same way — a language
by native name and code, a country by that name and code (the code alone without
one), a name by whose and in which language, a correction and a locale by the
locale code (HIL-1538).
Names in the reader's language belong to HIL-1424.

## What This Page Does Not Decide

These keys are pointers to later work, not implementation markers:

- Whether every process reads the reference locally, as with settings — the
  first reader outside the section, language selection in HIL-1424, decides.
- Language selection and moving open tabs when a language is switched off —
  HIL-1424.
- Translation — HIL-1424.
- Synchronization with restcountries and flags — HIL-1424.
- Manual reflow, its history, and synchronization in this section — HIL-1424.
- `first_week_day` — HIL-1424.
- Time-zone selection and application — HIL-1528; see [time-zones.md](time-zones.md).
- A working preview — HIL-1424.
- Multilingual CMS content — HIL-129; SSG language editions — HIL-130.

## Anti-Patterns

- **Wrong:** edit a switched-on row because it is known or the change is small;
  the switch protects the live value for everyone. Switch off, edit, switch on.
- **Wrong:** reflow by iterating database rows; that reaches own rows. Walk the
  built-in catalog instead.
- **Wrong:** add a “pin” button or put a lock on a language, country or locale;
  editing locks names automatically, while the three root tables use `enabled`.
- **Wrong:** target an internal mutation by code after capturing a row. Carry
  its primary ID and refuse if it disappeared. Public URLs keep their stable
  code and resolve it only at entry.
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
