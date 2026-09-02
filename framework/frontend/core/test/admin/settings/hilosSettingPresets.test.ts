import { describe, expect, it } from 'vitest'

import {
  createHilosSettingPresets,
  createHilosSettingPresetsActions,
  differencesOf,
  hasDifferences,
  isPresetApplied,
  isSelectionUnknown,
  presetsOf,
  selectedPresetOf,
  settingPresetsSignalSchemas,
  type HilosSettingPresetsContext,
  type HilosSettingPresetsState,
} from '../../../src/admin/settings/hilosSettingPresets.js'
import { type ActionHandle } from '../../../src/connection/actionLifecycle.js'
import { type ProjectSignal } from '../../../src/index.js'

const SIGNAL = 'subscription_page_hilos_logs_settings'

/** Build a group frame, overriding whichever of its four fields the case is about. */
function groupState(
  overrides: Partial<HilosSettingPresetsState> = {},
): HilosSettingPresetsState {
  return {
    group: 'logs',
    selected: 'normal',
    presets: [
      { name: 'frugal', values: { 'logs.write_level': 'WARNING' } },
      { name: 'normal', values: { 'logs.write_level': 'INFO' } },
      { name: 'investigation', values: { 'logs.write_level': 'DEBUG' } },
    ],
    differences: [],
    ...overrides,
  }
}

/**
 * A connection double replaying project signals. It is the slice
 * createHilosSettingPresets touches — `on('projectSignal')` and the unsubscribe it
 * returns — cast to the full connection type.
 */
function fakeConnection() {
  const listeners: Array<(signal: ProjectSignal) => void> = []

  return {
    offCalls: 0,
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => {
        this.offCalls += 1
        const at = listeners.indexOf(
          listener as (signal: ProjectSignal) => void,
        )
        if (at !== -1) {
          listeners.splice(at, 1)
        }
      }
    },
    emit(type: string, data: Record<string, unknown>): void {
      const signal = {
        kind: 'project',
        type,
        data,
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of listeners) {
        listener(signal)
      }
    },
  }
}

/** A context whose connection replays frames and whose lifecycle records dispatches. */
function fakeContext(): {
  context: HilosSettingPresetsContext
  connection: ReturnType<typeof fakeConnection>
  calls: Array<{ action: string; payload: Record<string, unknown> }>
} {
  const connection = fakeConnection()
  const calls: Array<{ action: string; payload: Record<string, unknown> }> = []
  const context = {
    connection,
    actions: {
      dispatch(action: string, payload: Record<string, unknown>): ActionHandle {
        calls.push({ action, payload })

        return {} as ActionHandle
      },
    },
  } as unknown as HilosSettingPresetsContext

  return { context, connection, calls }
}

describe('settingPresetsSignalSchemas', () => {
  it('keys the one payload schema under the section signal it is asked for', () => {
    expect(Object.keys(settingPresetsSignalSchemas(SIGNAL))).toEqual([SIGNAL])
  })

  it('is a set of its own, so no neighbouring screen has to land first', () => {
    const other = settingPresetsSignalSchemas('subscription_page_other_presets')

    expect(Object.keys(other)).toEqual(['subscription_page_other_presets'])
    expect(Object.keys(settingPresetsSignalSchemas(SIGNAL))).toEqual([SIGNAL])
  })

  it('accepts a group frame whose values are counted in anything', () => {
    const parsed = settingPresetsSignalSchemas(SIGNAL)[SIGNAL].safeParse(
      groupState({
        presets: [
          {
            name: 'frugal',
            values: {
              'logs.write_level': 'WARNING',
              'logs.rotation.max_live_size_bytes': 268435456,
              'logs.rotation.max_age_seconds': 0,
            },
          },
        ],
      }),
    )

    expect(parsed.success).toBe(true)
  })

  it('accepts a null selection, which is a stored mode nobody offers any more', () => {
    const parsed = settingPresetsSignalSchemas(SIGNAL)[SIGNAL].safeParse(
      groupState({ selected: null }),
    )

    expect(parsed.success).toBe(true)
    expect(parsed.success && parsed.data.selected).toBeNull()
  })

  it('accepts a difference whose two values are of unlike types', () => {
    const parsed = settingPresetsSignalSchemas(SIGNAL)[SIGNAL].safeParse(
      groupState({
        differences: [
          {
            key: 'logs.archive_retention.max_age_seconds',
            presetValue: 2592000,
            currentValue: null,
          },
        ],
      }),
    )

    expect(parsed.success).toBe(true)
  })

  it('refuses a frame that names no group', () => {
    const withoutGroup: Record<string, unknown> = { ...groupState() }
    delete withoutGroup.group

    expect(
      settingPresetsSignalSchemas(SIGNAL)[SIGNAL].safeParse(withoutGroup)
        .success,
    ).toBe(false)
  })

  it('refuses a preset entry that carries no name', () => {
    const parsed = settingPresetsSignalSchemas(SIGNAL)[SIGNAL].safeParse(
      groupState({
        presets: [
          { values: {} } as unknown as HilosSettingPresetsState['presets'][0],
        ],
      }),
    )

    expect(parsed.success).toBe(false)
  })

  it('refuses a difference entry whose key is not a string', () => {
    const parsed = settingPresetsSignalSchemas(SIGNAL)[SIGNAL].safeParse({
      ...groupState(),
      differences: [{ key: 7, presetValue: 1, currentValue: 2 }],
    })

    expect(parsed.success).toBe(false)
  })
})

describe('createHilosSettingPresets', () => {
  it('holds nothing before the first frame', () => {
    const { context } = fakeContext()
    const handle = createHilosSettingPresets(context, SIGNAL)
    handle.start()

    expect(handle.state.get()).toBeNull()
  })

  it('takes the frame that arrives under its own signal', () => {
    const { context, connection } = fakeContext()
    const handle = createHilosSettingPresets(context, SIGNAL)
    handle.start()
    connection.emit(SIGNAL, groupState())

    expect(handle.state.get()?.group).toBe('logs')
  })

  it('ignores a frame of another section riding the same connection', () => {
    const { context, connection } = fakeContext()
    const handle = createHilosSettingPresets(context, SIGNAL)
    handle.start()
    connection.emit(
      'subscription_page_other_presets',
      groupState({ group: 'other' }),
    )

    expect(handle.state.get()).toBeNull()
  })

  it('replaces the state on every later push rather than merging into it', () => {
    const { context, connection } = fakeContext()
    const handle = createHilosSettingPresets(context, SIGNAL)
    handle.start()
    connection.emit(
      SIGNAL,
      groupState({
        differences: [
          {
            key: 'logs.write_level',
            presetValue: 'INFO',
            currentValue: 'DEBUG',
          },
        ],
      }),
    )
    connection.emit(SIGNAL, groupState({ selected: 'investigation' }))

    expect(handle.state.get()?.selected).toBe('investigation')
    expect(handle.state.get()?.differences).toEqual([])
  })

  it('unsubscribes on dispose and stops taking frames', () => {
    const { context, connection } = fakeContext()
    const handle = createHilosSettingPresets(context, SIGNAL)
    handle.start()
    handle.dispose()

    expect(connection.offCalls).toBe(1)

    connection.emit(SIGNAL, groupState({ selected: 'frugal' }))

    expect(handle.state.get()).toBeNull()
  })
})

describe('createHilosSettingPresetsActions', () => {
  it('dispatches the apply with the preset name and nothing else', () => {
    const { context, calls } = fakeContext()
    createHilosSettingPresetsActions(context).sendSettingPresetApply('frugal')

    expect(calls).toEqual([
      { action: 'setting_preset_apply', payload: { preset: 'frugal' } },
    ])
  })

  it('names no group, because the page the action is routed to declares it', () => {
    const { context, calls } = fakeContext()
    createHilosSettingPresetsActions(context).sendSettingPresetApply('normal')

    expect(Object.keys(calls[0].payload)).toEqual(['preset'])
  })
})

describe('selectors', () => {
  it('read an absent frame as an empty screen rather than as a broken one', () => {
    expect(presetsOf(null)).toEqual([])
    expect(selectedPresetOf(null)).toBeNull()
    expect(differencesOf(null)).toEqual([])
    expect(hasDifferences(null)).toBe(false)
    expect(isPresetApplied(null, 'normal')).toBe(false)
  })

  it('keeps the presets in the order the recipe declared them', () => {
    expect(presetsOf(groupState()).map((preset) => preset.name)).toEqual([
      'frugal',
      'normal',
      'investigation',
    ])
  })

  it('lights the applied preset and nothing else', () => {
    const state = groupState()

    expect(isPresetApplied(state, 'normal')).toBe(true)
    expect(isPresetApplied(state, 'frugal')).toBe(false)
    expect(isPresetApplied(state, 'investigation')).toBe(false)
  })

  it('tells a selected preset without drift from one with it', () => {
    const clean = groupState()
    const drifted = groupState({
      differences: [
        { key: 'logs.write_level', presetValue: 'INFO', currentValue: 'DEBUG' },
      ],
    })

    expect(hasDifferences(clean)).toBe(false)
    expect(hasDifferences(drifted)).toBe(true)
    expect(isPresetApplied(drifted, 'normal')).toBe(true)
  })

  it('keeps the differences in the order the backend listed them', () => {
    const state = groupState({
      differences: [
        { key: 'logs.write_level', presetValue: 'INFO', currentValue: 'DEBUG' },
        {
          key: 'logs.archive_retention.max_age_seconds',
          presetValue: 2592000,
          currentValue: 1209600,
        },
      ],
    })

    expect(differencesOf(state).map((difference) => difference.key)).toEqual([
      'logs.write_level',
      'logs.archive_retention.max_age_seconds',
    ])
  })

  it('reads a null selection as an unknown mode, not as a missing frame', () => {
    expect(isSelectionUnknown(null)).toBe(false)
    expect(isSelectionUnknown(groupState({ selected: null }))).toBe(true)
    expect(isSelectionUnknown(groupState())).toBe(false)
  })

  it('lights no card when the stored mode is unknown', () => {
    const state = groupState({ selected: null })

    expect(
      presetsOf(state).some((preset) => isPresetApplied(state, preset.name)),
    ).toBe(false)
  })
})
