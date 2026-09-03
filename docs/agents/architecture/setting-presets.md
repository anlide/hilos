# Setting Presets (A Mode Is A Complete Statement)

Read this before offering a group of presets on an admin section — the modes an
administrator picks as cards — or before adding a setting to a section that
already has them.

A preset is a named set of setting values, a "mode" as the administrator's
screen calls it. The mechanism is `framework/backend/Database/Settings/Preset/`
— the group, the preset, the difference, the resolver that reads and applies
them, and the subscriber that tells the screens a row moved — with one page base
above it, `AbstractHilosSettingPresetsPage`, and one headless module beneath the
per-framework views, `@hilos/core` `admin/settings/hilosSettingPresets.ts`. The
mechanism knows nothing about any one section; what a group contains is the
section's recipe. The first consumer is the Logs section, and its three modes
are documented with the feature in [logs.md](logs.md), not here.

This document holds one scenario: I am assembling the preset group of my own
section. What each class does is documented on the class.

## Core Rule

A preset is a complete statement about its subject. It names **every** key of
its group, the switched-off axes included; it is applied all or nothing, through
the doors of the settings layer and never around them; and the name of the
applied preset is stored as a setting of its own, never derived from the values.
A "mode" that leaves a key unsaid is not a mode, because the value that key
happens to hold will be obeyed as though the mode had chosen it.

## Declaring A Group (HIL-762)

A section declares its group with a static provider, the way a catalog is
declared: a group is a declaration of the installation rather than an object
anybody owns, and the page that serves it names the provider class instead of
holding an instance.

```php
final class BackupSettingsPresets implements SettingPresetGroupProviderInterface
{
    public static function presetGroup(): SettingPresetGroup
    {
        return new SettingPresetGroup(
            'backup',                          // group key, as it travels the wire
            BackupSettingsCatalog::PRESET,     // setting key the applied name is stored under
            [
                new SettingPreset('light', [ /* every member key => value */ ]),
                new SettingPreset('full', [ /* the same keys, other values */ ]),
            ],
        );
    }
}
```

What the declaration says, and what it does not:

- **Names are machine names.** The title a card shows above a preset is written
  by the frontend, so no text meant for a human lives in the recipe. That split
  is what lets one preset serve a screen in any language without the mechanism
  knowing one.
- **Selection and values are two facts stored as two.** The chosen name goes
  under the selection key, the values under the keys the presets name. Deriving
  the choice from the values — "which set matches?" — would lose it the moment an
  administrator edited one value by hand, and the button offering to put the set
  back would have nowhere to put it back to.
- **The selection key is an ordinary cataloged setting** with a rule that
  accepts the group's names or the empty string, asked of the recipe rather than
  listed twice (`LogPresetNameRule` is the sample). The rule exists although an
  unknown name is harmless to the page: a typo made on the general settings
  screen is refused at the moment of writing, while a name that stopped existing
  after it was written is read tolerantly as "none applied". Its catalog default
  is a literal of the recipe, not an environment value — the environment says
  what a node does, not which mode an administrator picked.
- **The order of the presets is the order of the cards.**

The page costs one subclass of `AbstractHilosSettingPresetsPage` that declares
four things — its page key, its reach, its subscription signal and the provider
of its group — and nothing else. A section page that had to carry behavior of
its own would mean the mechanism was never general; `AbstractHilosLogsSettingsPage`
is the proof by emptiness. The page is registered like any admin page
([app-topology.md](../app-topology.md)); the action it answers,
`setting_preset_apply`, carries only the preset name, because the **group is
the page's**: no browser sitting on one section's screen can apply a preset of
another, and the server has no membership to check by hand. An action name
routes to one page in a project today, so a second section offering presets
will need an action name of its own; how it gets one is that section's leaf to
decide, not something to arrange in advance.

On the frontend the common module owns the layout, the states and the one
action; the section fills in `HilosSettingPresetsVocabulary` in a module of its
own — the intro, the headings, how a value and a difference are read aloud —
and touches nothing of the common one (`admin/logs/hilosLogSettings.ts` is the
sample). Nothing there is guessed: a preset name the recipe grew after the
module was written, or a difference on a key the module does not know, comes
out as itself, so the backend recipe stays free to move first.

## A Preset Names Every Key Of Its Group

Every preset of a group declares the same keys, and `apply()` refuses a preset
that declares one of them without a value (`SettingPresetIncompleteException`).
The reason is on the cards themselves: a card names the axes that make the mode,
and an axis it does not name is not thereby switched off — whatever value it
holds from an earlier configuration is obeyed alongside the card, against what
the card says. The Logs recipe carries `logs.rotation.max_age_seconds` at zero
in all three modes for exactly that: no card mentions the age axis, and a mode
that left it standing would rotate on a schedule the administrator never chose.

Applying a preset therefore writes a row for **every** member, including one
whose value already equals the catalog default. The default of these keys is an
environment value — per node — while a settings row is shared by the whole
database; a member left without a row would sit at a different value on every
node, and a preset is a statement about the installation.

The write is all or nothing. Every value is checked against the rule its key
declares **before** the first of them is written, so a refusal leaves nothing
behind: a half-applied preset is the worst state the screen can be in — the
card would say the mode is on while half the values stayed behind, and the
differences would explain that as an administrator's own edit. The absent value
is checked separately because a rule cannot catch it: a key with no rule
accepts anything, `null` included, and `null` reaches the settings table as a
reset, which it refuses as an action of its own — refused there, it would be
refused with earlier members already written. The selection is written **last**,
for the same reason it is written at all: it is a signature under work already
done, and an interruption in the middle leaves "the old preset, with
differences", which the screen knows how to show, rather than "the new preset,
untouched values", which reads as somebody's hand edit.

Values go through `HilosSettingsTable::actions->add()` — the idempotent set the
settings layer already has — and never around it into the ORM. A preset writing
its own way would be a second way to store a setting, and the settings screen's
own viewers would not see the row move. Applying a preset that is already on
and has no differences is a success that changes nothing: two administrators
may press the same card at once, and a race between them must not turn into an
error on either screen.

## Which Key Stays Outside Every Preset

Not every key of a section's fragment belongs in its modes. A key stays outside
every preset when a mode would have no opinion about it — when the question it
answers is not the question the cards ask. Two kinds recur:

- **A safety net.** The Logs section's `logs.archive_retention.keep_batches`
  says how many newest batches are always kept whatever their age; that is a
  floor under every mode, not a loudness, and a mode that lowered it would be
  trading safety for a card.
- **Transport.** `logs.index.push_interval_ms` says how often a node reports
  its index to the cluster; it is a fact about the wire between nodes and has
  nothing to do with how much a node writes.

The test is the one the Core Rule implies: if every mode would set the key to
the same value, the key is not a member of the group, and putting it in would
make every preset a statement about a subject that is not its own. Such keys
are edited on the general settings screen, they never appear as differences,
and the recipe does not mention them. The selection key itself is likewise
outside the values of every preset — it is where the answer is written, not a
member of the answer.

## How A Difference Reaches The Screen

The state of a group is three facts and no phrases, and it travels whole in
one frame (`HilosSettingPresetsSignalData`): the name of the applied preset,
what each preset declares, and where the settings have drifted from the applied
one.

- **A difference is a fact.** `SettingPresetDifference` carries the key, the
  value the preset declares and the value in force today, both normalized to
  the catalog type of the key so the comparison is not a textual one. The
  sentence a difference is read as — "keep for 14 days instead of 30" — is built
  by the section's frontend vocabulary out of those three fields. Units,
  plurals and language folded into the payload would make the mechanism useless
  to the next section, whose settings are counted in something else.
- **An unknown selection is a state, not a fault.** `selected` is `null` when
  the stored name is not one the recipe declares — a preset renamed or dropped
  after somebody applied it. No card is lit, there are no differences, and any
  click repairs it. Guessing a preset instead would put a signature under work
  nobody did; refusing the subscription would take the screen down over a line
  of text.
- **The frame is sent ahead of `page_response`**, because that frame means the
  subscription is answered in full, and again whenever the state changes.

State arrives by event and not by polling. A settings row written anywhere in
the cluster — from this screen, from the general settings screen, from another
node — travels the sync every process already listens to, and its arrival is
announced on the source bus like any other change.
`SettingPresetChangeSubscriber` turns that announcement into a debt: it marks
every preset page that has an audience as stale, and the next tick of the
section's agent rebuilds the state and pushes it, with a fingerprint stopping an
unchanged state from going out twice. The key in the event is deliberately not
examined: an update carries only the columns that changed and so names no key,
and a group covers several keys rather than one, so narrowing would have to
guess. Marking a page too often costs a rebuild in memory that the fingerprint
throws away; missing one would leave a card lit over values that no longer
match. A page nobody is watching is not marked at all — it rebuilds from
scratch when somebody subscribes.

This is also why the preset page rebuilds **only when marked**, where its
neighbour in the Logs section rebuilds on every tick: that page's picture is
files growing on their own, with no event to hear, while settings do not change
by themselves. Re-reading them every hundred milliseconds would be a re-fetch
for freshness in a place that already has a push. After a click, nothing is
re-requested either: the initiator gets an ack, and the new state is pushed to
every open tab of the section, the clicker's included.

## Anti-Patterns

```php
// Wrong: a mode that names only the keys its card mentions.
new SettingPreset('light', [
    BackupSettingsCatalog::SCHEDULE => '0 4 * * *',
]);
```

An axis the card does not name is still obeyed. Name every key of the group,
switched-off axes at their off value.

```php
// Wrong: deriving the applied preset from the values.
foreach ($group->presets as $preset) {
    if ($preset->values === $current) {
        return $preset->name;
    }
}
```

One hand edit and the mode is gone, with nothing to put back. Store the name
under the group's selection key and read it from there.

```php
// Wrong: writing the values around the settings layer.
Hilos::$db->settings->actions->add($key, $value, $catalog);
```

Write through `HilosSettingsTable::actions->add()`, which is what
`SettingPresetResolver::apply()` does; a second way to store a setting is a
second place for it to be wrong.

```php
// Wrong: a phrase in the payload.
new SettingPresetDifference($key, '30 days', '14 days');
```

Carry the two values as the catalog types them; the sentence is the frontend's.

```php
// Wrong: re-reading the settings on every tick of the page agent.
public static function onAgentTick(PageAgentInterface $agent): void
{
    self::push(static::buildPresetsSignalData());
}
```

Settings change by event. Mark the page stale from the source bus and rebuild
only then.

## Validation

- `composer run test:framework:unit` — the resolver's three questions and its
  all-or-nothing apply (`SettingPresetResolverTest`), the page's subscribe, push
  and stale-marking (`HilosSettingPresetsPageSubscribeTest`), and the Logs recipe
  as the worked example: every preset covers the same keys and every value
  passes its key's rule (`LogSettingsPresetsTest`), the selection rule
  (`LogPresetNameRuleTest`).
- `demo/chat` `composer run test:phpunit` — a preset applied through the settings
  doors, the differences after a hand edit, the revert, the refused unknown name
  (`SettingPresetApplyTest`), and the topology snapshot that pins the action to
  its page (`ChatTopologyRegistryTest`).
- `composer run test:framework:frontend` — the common headless module
  (`framework/frontend/core/test/admin/settings/hilosSettingPresets.test.ts`)
  and the Logs vocabulary (`admin/logs/hilosLogSettings.test.ts`).
- `demo/simple-poll` e2e `logs.spec.ts` — the logging-modes screen rendered over
  the live socket and the way out of it into the general settings.
