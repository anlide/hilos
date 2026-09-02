// The framework setting-presets admin headless: the group state, the schema its frame
// is parsed by, the selectors a view reads it through, the one action it sends, and
// the vocabulary interface a section fills in. It is framework-agnostic (imports no UI
// framework) and reads only @hilos/core primitives, so the Vue/React/Angular preset
// views stay thin (multiframework-core.md).
//
// The module knows nothing about any one section. A preset group is "settings that
// move together with one name on them", and which settings those are, what a value
// means, and how a drift from them is read aloud all belong to the section — they
// arrive through HilosSettingPresetsVocabulary. That is the whole point: the backend
// mechanism (HIL-762) promised the next section costs a recipe and a class, and a
// screen written with one section inside it would end that promise on the first one.
//
// Everything arrives in ONE frame — the page's own signal, sent ahead of the frame
// that releases the page and again on the agent's tick. There is no table and no
// second wire, and nothing is ever re-requested: after the action lands, the new
// state is pushed to every open tab rather than answered to the one that clicked.

import {
  ActionLifecycle,
  type ActionHandle,
} from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { createSignal, type ReadonlySignal } from '../../state/signal.js'
import { z } from 'zod'

/** Client→server action name applying a preset to its group (PHP `SETTING_PRESET_APPLY`). */
export const SETTING_PRESETS_APPLY_ACTION = 'setting_preset_apply'

// Payload keys of the group frame and of the entries inside it. They are declared
// here because this module owns the state they resolve into, and they are what the
// schema below is built from, so the wire name has one owner instead of a copy per
// schema and per view.

/** Payload key of the group these presets belong to. */
export const PRESET_GROUP_FIELD = 'group'

/** Payload key of the applied preset's name, null when the stored one is unknown. */
export const PRESET_SELECTED_FIELD = 'selected'

/** Payload key of the presets, in the order their cards are drawn. */
export const PRESET_PRESETS_FIELD = 'presets'

/** Payload key of the members of the applied preset whose value differs today. */
export const PRESET_DIFFERENCES_FIELD = 'differences'

/** Payload key of a preset entry: its machine name. */
export const PRESET_NAME_FIELD = 'name'

/** Payload key of a preset entry: the values it declares, by setting key. */
export const PRESET_VALUES_FIELD = 'values'

/** Payload key of a difference entry: the setting key the two values disagree on. */
export const PRESET_DIFFERENCE_KEY_FIELD = 'key'

/** Payload key of a difference entry: the value the applied preset declares. */
export const PRESET_DIFFERENCE_PRESET_VALUE_FIELD = 'presetValue'

/** Payload key of a difference entry: the value the setting reads today. */
export const PRESET_DIFFERENCE_CURRENT_VALUE_FIELD = 'currentValue'

/** One preset: a name and every value it writes (PHP `SettingPreset`). */
const presetSchema = z.looseObject({
  [PRESET_NAME_FIELD]: z.string(),
  [PRESET_VALUES_FIELD]: z.record(z.string(), z.unknown()),
})

/**
 * One drift from the applied preset (PHP `SettingPresetDifference`).
 *
 * Both values are unknown on purpose: a setting is worth whatever its own catalog
 * says, and a group whose members are counted in seconds sits beside one counted in
 * bytes. Reading them is the section vocabulary's job.
 */
const differenceSchema = z.looseObject({
  [PRESET_DIFFERENCE_KEY_FIELD]: z.string(),
  [PRESET_DIFFERENCE_PRESET_VALUE_FIELD]: z.unknown(),
  [PRESET_DIFFERENCE_CURRENT_VALUE_FIELD]: z.unknown(),
})

/**
 * Payload of the whole screen: which preset is applied, what each of them declares,
 * and where the settings have drifted from the applied one (PHP
 * `HilosSettingPresetsSignalData`).
 */
const settingPresetsSchema = z.looseObject({
  [PRESET_GROUP_FIELD]: z.string(),
  [PRESET_SELECTED_FIELD]: z.string().nullable(),
  [PRESET_PRESETS_FIELD]: z.array(presetSchema),
  [PRESET_DIFFERENCES_FIELD]: z.array(differenceSchema),
})

/** One preset as its card draws it. */
export type HilosSettingPreset = z.infer<typeof presetSchema>

/** One member of the applied preset whose value differs today. */
export type HilosSettingPresetDifference = z.infer<typeof differenceSchema>

/** The group as the page answers a subscription with it. */
export type HilosSettingPresetsState = z.infer<typeof settingPresetsSchema>

/**
 * The group frame's schema keyed for a connection's `projectSchemas` under the signal
 * name of one section, so the parse boundary validates the frames
 * {@link createHilosSettingPresets} ingests. A section exports its own set and
 * {@link createHilosConnection} merges it in, so a project never restates it.
 *
 * A function rather than a constant because the payload is one per mechanism while
 * the signal name is one per section. A set of its own per section, rather than
 * entries in a neighbour's: two independent screens must not depend on which of them
 * landed first.
 *
 * @param signal Server→client signal `type` this section's group frame arrives under.
 */
export function settingPresetsSignalSchemas(
  signal: string,
): Record<string, typeof settingPresetsSchema> {
  return { [signal]: settingPresetsSchema }
}

/**
 * The project-supplied context the setting-presets admin reads from.
 *
 * Two fields, where the settings table beside it carries three: this screen owns no
 * table window, so it normalizes nothing into a page scope. A scope manager here
 * would promise page-scoped data the screen never reads.
 */
export interface HilosSettingPresetsContext {
  /** The connection the group frames arrive on. */
  readonly connection: HilosConnection
  /** The action lifecycle the apply action dispatches over. */
  readonly actions: ActionLifecycle
}

/** The group handle a view drives: the state signal plus its mount lifecycle. */
export interface HilosSettingPresetsHandle {
  /** The latest group state, or null before the first frame arrives. */
  readonly state: ReadonlySignal<HilosSettingPresetsState | null>
  /** Start listening for frames — call on mount. */
  start(): void
  /** Stop listening — call on unmount. */
  dispose(): void
}

/** The preset mutation surface a preset view binds to. */
export interface HilosSettingPresetsActions {
  /**
   * Write every value the named preset declares, as a tracked action.
   *
   * The one action of the screen, and both of its gestures send it: clicking an
   * unselected card and pressing "put the mode's values back" inside the selected one
   * say the same thing — make it as this preset says. The handle's `done` resolves on
   * the backend's `::success` ack and rejects on `::fail`; the new state arrives
   * separately, on the next tick, to every open tab.
   *
   * @param preset Machine name of the preset to write.
   * @return The action handle whose `done` settles on the backend's verdict.
   */
  sendSettingPresetApply(preset: string): ActionHandle
}

/**
 * Everything a section says about its own presets, which is everything this screen
 * shows in words. The module owns the layout, the states and the behavior; not one
 * phrase of it is about any particular group of settings.
 *
 * Written to one client, and honestly so: the second section to grow presets will
 * most likely move it. What it must not do is grow a branch per section — a
 * vocabulary that asks which group it is has stopped being a vocabulary.
 */
export interface HilosSettingPresetsVocabulary {
  /** The paragraph above the cards: what a mode is and what choosing one does. */
  readonly intro: string
  /** The heading over the row of cards. */
  readonly groupHeading: string
  /** The heading of the difference block inside the selected card. */
  readonly differencesHeading: string
  /** The label of the button that writes the selected preset's values back. */
  readonly revertLabel: string
  /** The note under the cards explaining why the differences live inside one. */
  readonly footnote: string
  /** The title of the strip leading to the same values in the general settings. */
  readonly generalSettingsTitle: string
  /** The lead of that strip. */
  readonly generalSettingsLead: string
  /** The label of that strip's button. */
  readonly generalSettingsLabel: string
  /** The page key that strip's button leads to. */
  readonly generalSettingsPage: string
  /** The line shown when the applied preset is not one the recipe declares. */
  readonly unknownSelectionNote: string
  /** The title of the overwrite confirmation. */
  readonly confirmTitle: string
  /**
   * The body of the overwrite confirmation.
   *
   * @param title Title of the preset about to be written.
   */
  confirmBody(title: string): string
  /**
   * The label of the confirmation's accepting button.
   *
   * @param title Title of the preset about to be written.
   */
  confirmLabel(title: string): string
  /**
   * The title on a preset's card.
   *
   * @param name Machine name of the preset.
   */
  presetTitle(name: string): string
  /**
   * The subtitle on a preset's card.
   *
   * @param name Machine name of the preset.
   */
  presetSubtitle(name: string): string
  /**
   * The icon class on a preset's card.
   *
   * @param name Machine name of the preset.
   */
  presetIcon(name: string): string
  /**
   * The lines a preset's card lists, read out of the values it declares.
   *
   * @param values The values the preset writes, by setting key.
   */
  valueLines(values: Record<string, unknown>): string[]
  /**
   * One drift from the applied preset, as a sentence.
   *
   * @param difference The setting key and the two values that disagree on it.
   */
  differenceLine(difference: HilosSettingPresetDifference): string
}

/**
 * The group, reactively: the answer to the subscription and every later push the page
 * makes when a preset is applied or a member setting is edited on its own.
 *
 * It rides the connection rather than a page scope because it is the page's own
 * signal, sent ahead of the frame that releases the page and again on the agent's
 * tick. Nothing is ever re-requested — freshness arrives by push.
 *
 * @param context The project context (the connection the frames arrive on).
 * @param signal Server→client signal `type` this section's group frame arrives under.
 */
export function createHilosSettingPresets(
  context: HilosSettingPresetsContext,
  signal: string,
): HilosSettingPresetsHandle {
  const state = createSignal<HilosSettingPresetsState | null>(null)
  const teardown: Array<() => void> = []

  return {
    state,
    start() {
      teardown.push(
        context.connection.on('projectSignal', (frame) => {
          if (frame.type === signal) {
            // Validated against settingPresetsSchema at the parse boundary; this cast
            // is the declared typed selector for that schema's output.
            state.set(frame.data as HilosSettingPresetsState)
          }
        }),
      )
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/**
 * The preset mutation surface: the apply submit as a tracked action over the
 * lifecycle.
 *
 * @param context The project context (the action lifecycle the action dispatches over).
 */
export function createHilosSettingPresetsActions(
  context: HilosSettingPresetsContext,
): HilosSettingPresetsActions {
  return {
    sendSettingPresetApply(preset) {
      return context.actions.dispatch(SETTING_PRESETS_APPLY_ACTION, { preset })
    },
  }
}

/**
 * The presets to draw cards for, in the order the recipe declares them.
 *
 * Empty before the first frame arrives, which is the same thing the screen shows
 * then: the shell holds the first frame, so there is no state in which a card could
 * be drawn out of nothing.
 *
 * @param state The latest group state, or null before the first frame arrives.
 */
export function presetsOf(
  state: HilosSettingPresetsState | null,
): HilosSettingPreset[] {
  return state ? state[PRESET_PRESETS_FIELD] : []
}

/**
 * The applied preset's machine name, or null when none is.
 *
 * Null carries both "no frame yet" and "the stored name is not one this installation
 * offers any more" — the two look alike to a card, which is lit by neither. The
 * screen tells them apart with {@link isSelectionUnknown}, which is what the
 * explanatory line hangs on.
 *
 * @param state The latest group state, or null before the first frame arrives.
 */
export function selectedPresetOf(
  state: HilosSettingPresetsState | null,
): string | null {
  return state ? state[PRESET_SELECTED_FIELD] : null
}

/**
 * The members of the applied preset whose value differs today, in the order the
 * backend listed them, which is the order of the keys inside the preset.
 *
 * The frontend adds no sorting of its own: it would disagree with the order of the
 * lines on the card the differences are drawn inside.
 *
 * @param state The latest group state, or null before the first frame arrives.
 */
export function differencesOf(
  state: HilosSettingPresetsState | null,
): HilosSettingPresetDifference[] {
  return state ? state[PRESET_DIFFERENCES_FIELD] : []
}

/**
 * Whether this preset is the applied one, which is what lights its card.
 *
 * @param state The latest group state, or null before the first frame arrives.
 * @param name Machine name of the preset the card draws.
 */
export function isPresetApplied(
  state: HilosSettingPresetsState | null,
  name: string,
): boolean {
  return selectedPresetOf(state) === name
}

/**
 * Whether the applied preset has drifted, which is what turns its card into a warning
 * and puts the difference block and its button inside it.
 *
 * @param state The latest group state, or null before the first frame arrives.
 */
export function hasDifferences(
  state: HilosSettingPresetsState | null,
): boolean {
  return differencesOf(state).length > 0
}

/**
 * Whether the applied preset is one this installation does not offer any more —
 * renamed or dropped after somebody applied it.
 *
 * True only once a frame has arrived: before that, nothing is known about the
 * selection, and saying the stored mode is unknown would be a claim nobody made.
 *
 * @param state The latest group state, or null before the first frame arrives.
 */
export function isSelectionUnknown(
  state: HilosSettingPresetsState | null,
): boolean {
  return state !== null && state[PRESET_SELECTED_FIELD] === null
}
