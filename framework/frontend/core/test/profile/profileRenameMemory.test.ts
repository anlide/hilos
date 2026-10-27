import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
// Transform the compatibility graph during collection, outside the individual
// test's timer. resetModules below still evaluates it afresh under denied
// storage; the fresh namespace and declaration assertions verify that boundary.
import * as initialBase from '../../src/base/index.js'
import * as initialRoot from '../../src/index.js'
import {
  createHilosRowEdit,
  hilosSignalRowEditSource,
} from '../../src/conflict/rowEditSession.js'
import { type HilosProfileRenameFields } from '../../src/profile/profileRename.js'
import {
  clearProfileRenameMemory,
  PROFILE_RENAME_MEMORY_STORAGE_KEY,
  profileRenameMemorySchema,
  readProfileRenameMemory,
  writeProfileRenameMemory,
  type HilosProfileRenameMemory,
} from '../../src/profile/profileRenameMemory.js'
import { createSignal } from '../../src/state/signal.js'

function memory(): HilosProfileRenameMemory {
  return {
    version: 1,
    userId: 42,
    open: true,
    step: 'form',
    draft: '  Still typing  ',
    checkpoint: {
      baseline: { values: { name: 'Original' }, refreshed: ['name'] },
      form: '  Still typing  ',
      awaitingOccupation: false,
    },
    requestId: 'rename-1',
  }
}

function storage(): Storage {
  const entries = new Map<string, string>()

  return {
    get length() {
      return entries.size
    },
    clear: () => entries.clear(),
    getItem: (key) => entries.get(key) ?? null,
    key: (index) => [...entries.keys()][index] ?? null,
    removeItem: (key) => {
      entries.delete(key)
    },
    setItem: (key, value) => {
      entries.set(key, value)
    },
  }
}

function without(key: keyof HilosProfileRenameMemory): unknown {
  const value: Partial<HilosProfileRenameMemory> = { ...memory() }
  delete value[key]

  return value
}

describe('profile rename tab memory', () => {
  beforeEach(() => {
    vi.stubGlobal('sessionStorage', storage())
  })

  afterEach(() => {
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  it.each([true, false])(
    'round-trips open=%s without changing edit data',
    (open) => {
      const value = { ...memory(), open }
      writeProfileRenameMemory(value)

      expect(readProfileRenameMemory()).toEqual(value)
      expect(globalThis.sessionStorage.length).toBe(1)
      expect(globalThis.sessionStorage.key(0)).toBe(
        'hilos.profile.rename.window',
      )
      expect(
        JSON.parse(
          globalThis.sessionStorage.getItem(PROFILE_RENAME_MEMORY_STORAGE_KEY)!,
        ),
      ).toEqual(value)
    },
  )

  it.each(['closed', 'step-up', 'form'] as const)(
    'keeps the %s presentation step with explicit nulls',
    (step) => {
      const value = { ...memory(), step, checkpoint: null, requestId: null }
      writeProfileRenameMemory(value)

      expect(readProfileRenameMemory()).toEqual(value)
    },
  )

  it.each(['', 'x', ' '.repeat(10), 'x'.repeat(1000)])(
    'preserves intermediate input %j without Save validation',
    (draft) => {
      const value = {
        ...memory(),
        draft,
        checkpoint: {
          baseline: { values: { name: '' }, refreshed: [] },
          form: draft,
          awaitingOccupation: true,
        },
      }
      writeProfileRenameMemory(value)

      expect(readProfileRenameMemory()).toEqual(value)
    },
  )

  it('returns absence for an empty tab and forgets the whole checkpoint', () => {
    expect(readProfileRenameMemory()).toBeNull()
    writeProfileRenameMemory(memory())
    clearProfileRenameMemory()
    clearProfileRenameMemory()

    expect(readProfileRenameMemory()).toBeNull()
    expect(globalThis.sessionStorage.length).toBe(0)
  })

  const invalid: [string, unknown][] = [
    ['null', null],
    ['array', []],
    ['wrong version', { ...memory(), version: 2 }],
    ['non-numeric owner', { ...memory(), userId: '42' }],
    ['missing owner', { ...memory(), userId: null }],
    ['fractional owner', { ...memory(), userId: 1.5 }],
    ['non-person owner', { ...memory(), userId: 0 }],
    ['non-boolean intent', { ...memory(), open: 'true' }],
    ['server step', { ...memory(), step: 'checking' }],
    ['non-string draft', { ...memory(), draft: 42 }],
    ['non-string request', { ...memory(), requestId: 42 }],
    ['empty request', { ...memory(), requestId: '' }],
    ...(
      [
        'password',
        'code',
        'authorizationProof',
        'phase',
        'refusal',
        'result',
      ] as const
    ).map((key): [string, unknown] => [
      key,
      { ...memory(), [key]: 'untrusted' },
    ]),
    ...(
      [
        'version',
        'userId',
        'open',
        'step',
        'draft',
        'checkpoint',
        'requestId',
      ] as const
    ).map((key): [string, unknown] => [`missing ${key}`, without(key)]),
    ...[
      {},
      { ...memory().checkpoint, form: 42 },
      { ...memory().checkpoint, awaitingOccupation: 'false' },
      { ...memory().checkpoint, publish: 'callback' },
      { ...memory().checkpoint, row: { name: 'Original' } },
      { ...memory().checkpoint, password: 'secret' },
      {
        ...memory().checkpoint,
        baseline: { values: { name: 42 }, refreshed: [] },
      },
      { ...memory().checkpoint, baseline: { values: {}, refreshed: [] } },
      {
        ...memory().checkpoint,
        baseline: {
          values: { name: 'Original', code: 'secret' },
          refreshed: [],
        },
      },
      { ...memory().checkpoint, baseline: { values: { name: 'Original' } } },
      {
        ...memory().checkpoint,
        baseline: { values: { name: 'Original' }, refreshed: ['email'] },
      },
      {
        ...memory().checkpoint,
        baseline: { values: { name: 'Original' }, refreshed: [1] },
      },
      {
        ...memory().checkpoint,
        baseline: {
          values: { name: 'Original' },
          refreshed: [],
          phase: 'done',
        },
      },
    ].map((checkpoint, index): [string, unknown] => [
      `invalid checkpoint ${index}`,
      { ...memory(), checkpoint },
    ]),
  ]

  it.each(invalid)(
    'rejects %s at the schema and storage boundary',
    (_, value) => {
      expect(profileRenameMemorySchema.safeParse(value).success).toBe(false)
      globalThis.sessionStorage.setItem(
        PROFILE_RENAME_MEMORY_STORAGE_KEY,
        JSON.stringify(value),
      )

      expect(readProfileRenameMemory()).toBeNull()
    },
  )

  it.each(['{', '', 'undefined'])('ignores malformed JSON %j', (raw) => {
    globalThis.sessionStorage.setItem(PROFILE_RENAME_MEMORY_STORAGE_KEY, raw)

    expect(readProfileRenameMemory()).toBeNull()
  })

  it('rejects structurally wider writes before serializing secrets or functions', () => {
    const set = vi.spyOn(globalThis.sessionStorage, 'setItem')
    const withSecret = { ...memory(), password: 'secret' }
    const withCallback = { ...memory(), publish: () => undefined }
    writeProfileRenameMemory(withSecret)
    writeProfileRenameMemory(withCallback)

    expect(set).not.toHaveBeenCalled()
    expect(readProfileRenameMemory()).toBeNull()
  })

  it('reads fresh detached data each time without caching a server attempt', () => {
    writeProfileRenameMemory(memory())
    const first = readProfileRenameMemory()!
    first.checkpoint!.baseline.values.name = 'Local mutation'

    expect(readProfileRenameMemory()).toEqual(memory())
    globalThis.sessionStorage.removeItem(PROFILE_RENAME_MEMORY_STORAGE_KEY)
    expect(readProfileRenameMemory()).toBeNull()
  })

  it('does not turn JSON into local save permission or a publish callback', () => {
    const live = createSignal<HilosProfileRenameFields | undefined>({
      name: 'Original',
    })
    const edit = createHilosRowEdit<
      HilosProfileRenameFields,
      HilosProfileRenameFields,
      string
    >(hilosSignalRowEditSource(live), {
      initial: '',
      fields: (row) => row,
      form: (row) => row.name,
      draft: (form) => ({ name: form }),
      take: (form, taken) => taken.name ?? form,
      notice: { conflict: () => 'Changed elsewhere' },
    })
    edit.open()
    edit.setForm('Typed')
    const checkpoint = edit.checkpoint()!
    writeProfileRenameMemory({ ...memory(), checkpoint })
    const restored = readProfileRenameMemory()!
    const publish = vi.fn()

    expect(
      edit.rebaseAfterSave({ name: 'Original' }, restored.checkpoint!, publish),
    ).toBe(false)
    expect(publish).not.toHaveBeenCalled()
    expect(edit.restore(restored.checkpoint!)).toBe(true)
    expect(edit.form.get()).toBe('Typed')
    expect(
      edit.rebaseAfterSave({ name: 'Original' }, restored.checkpoint!),
    ).toBe(false)
    expect(edit.rebaseAfterSave({ name: 'Original' }, edit.checkpoint()!)).toBe(
      true,
    )
    edit.dispose()
  })

  it('works without browser storage', () => {
    vi.stubGlobal('sessionStorage', undefined)

    expect(readProfileRenameMemory()).toBeNull()
    expect(() => writeProfileRenameMemory(memory())).not.toThrow()
    expect(() => clearProfileRenameMemory()).not.toThrow()
  })

  it('guards a throwing global getter on every operation', () => {
    Object.defineProperty(globalThis, 'sessionStorage', {
      configurable: true,
      get() {
        throw new DOMException('denied', 'SecurityError')
      },
    })

    expect(readProfileRenameMemory()).toBeNull()
    expect(() => writeProfileRenameMemory(memory())).not.toThrow()
    expect(() => clearProfileRenameMemory()).not.toThrow()
  })

  it.each(['getItem', 'setItem', 'removeItem'] as const)(
    'guards a throwing %s',
    (method) => {
      vi.spyOn(globalThis.sessionStorage, method).mockImplementation(() => {
        throw new DOMException('unavailable', 'QuotaExceededError')
      })

      expect(() => writeProfileRenameMemory(memory())).not.toThrow()
      expect(() => clearProfileRenameMemory()).not.toThrow()
      if (method === 'getItem') expect(readProfileRenameMemory()).toBeNull()
      else expect(() => readProfileRenameMemory()).not.toThrow()
    },
  )

  it('imports the owner, registry, root and base without reaching storage', async () => {
    vi.resetModules()
    const getter = vi.fn(() => {
      throw new DOMException('denied', 'SecurityError')
    })
    Object.defineProperty(globalThis, 'sessionStorage', {
      configurable: true,
      get: getter,
    })
    const owner = await import('../../src/profile/profileRenameMemory.js')
    const registry = await import('../../src/browser/browserValues.js')
    const root = await import('../../src/index.js')
    const base = await import('../../src/base/index.js')

    expect(root).not.toBe(initialRoot)
    expect(base).not.toBe(initialBase)
    expect(owner.PROFILE_RENAME_BROWSER_VALUE).not.toBe(
      initialRoot.PROFILE_RENAME_BROWSER_VALUE,
    )
    expect(getter).not.toHaveBeenCalled()
    expect(registry.HILOS_BROWSER_VALUES).toContain(
      owner.PROFILE_RENAME_BROWSER_VALUE,
    )
    for (const api of [root, base]) {
      expect(api.readProfileRenameMemory).toBe(owner.readProfileRenameMemory)
      expect(api.profileRenameMemorySchema).toBe(
        owner.profileRenameMemorySchema,
      )
    }
  })
})
