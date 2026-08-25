import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import {
  PROTECTED_MODE_HINT_STORAGE_KEY,
  readProtectedModeHint,
  writeProtectedModeHint,
} from './maintenanceHint.js'

/** The smallest thing that answers like `localStorage`, plus a switch to make it refuse. */
function fakeStorage(options: { throws?: boolean } = {}): Storage {
  const entries = new Map<string, string>()
  const guard = (): void => {
    if (options.throws === true) {
      throw new Error('storage is not available')
    }
  }

  return {
    get length(): number {
      return entries.size
    },
    clear(): void {
      entries.clear()
    },
    getItem(key: string): string | null {
      guard()

      return entries.get(key) ?? null
    },
    key(index: number): string | null {
      return [...entries.keys()][index] ?? null
    },
    removeItem(key: string): void {
      guard()
      entries.delete(key)
    },
    setItem(key: string, value: string): void {
      guard()
      entries.set(key, value)
    },
  }
}

function installStorage(storage: Storage | undefined): void {
  const scope = globalThis as { localStorage?: Storage }
  if (storage === undefined) {
    delete scope.localStorage

    return
  }
  scope.localStorage = storage
}

describe('protected-mode maintenance hint', () => {
  beforeEach(() => {
    installStorage(fakeStorage())
  })

  afterEach(() => {
    installStorage(undefined)
  })

  it('reads as absent before anything wrote it', () => {
    expect(readProtectedModeHint()).toBe(false)
  })

  it('remembers maintenance it was told about', () => {
    writeProtectedModeHint(true)
    expect(readProtectedModeHint()).toBe(true)
  })

  it('forgets maintenance the moment a frame says it is over', () => {
    writeProtectedModeHint(true)
    writeProtectedModeHint(false)
    expect(readProtectedModeHint()).toBe(false)
  })

  it('keeps the hint under the announced key, so a tool can seed it', () => {
    writeProtectedModeHint(true)
    expect(
      globalThis.localStorage.getItem(PROTECTED_MODE_HINT_STORAGE_KEY),
    ).not.toBeNull()
  })

  it('reads any stored value as a hint, whatever wrote it', () => {
    globalThis.localStorage.setItem(PROTECTED_MODE_HINT_STORAGE_KEY, 'true')
    expect(readProtectedModeHint()).toBe(true)
  })

  it('behaves as if there were no hint where there is no storage at all', () => {
    installStorage(undefined)
    expect(readProtectedModeHint()).toBe(false)
    expect(() => {
      writeProtectedModeHint(true)
    }).not.toThrow()
  })

  it('behaves as if there were no hint when storage refuses', () => {
    installStorage(fakeStorage({ throws: true }))
    expect(readProtectedModeHint()).toBe(false)
    expect(() => {
      writeProtectedModeHint(true)
    }).not.toThrow()
  })
})
