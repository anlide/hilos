import { afterEach, describe, expect, it } from 'vitest'

import { browserStorage } from '../../src/browser/browserStorage.js'

/** The smallest thing that answers like a `Storage`. */
function fakeStorage(): Storage {
  const entries = new Map<string, string>()

  return {
    get length(): number {
      return entries.size
    },
    clear(): void {
      entries.clear()
    },
    getItem(key: string): string | null {
      return entries.get(key) ?? null
    },
    key(index: number): string | null {
      return [...entries.keys()][index] ?? null
    },
    removeItem(key: string): void {
      entries.delete(key)
    },
    setItem(key: string, value: string): void {
      entries.set(key, value)
    },
  }
}

describe('browserStorage', () => {
  afterEach(() => {
    delete (globalThis as { localStorage?: Storage }).localStorage
    delete (globalThis as { sessionStorage?: Storage }).sessionStorage
  })

  it('answers undefined where the runtime has no such store', () => {
    expect(browserStorage('localStorage')).toBeUndefined()
    expect(browserStorage('sessionStorage')).toBeUndefined()
  })

  it('answers the store the browser has', () => {
    const local = fakeStorage()
    const session = fakeStorage()
    const scope = globalThis as {
      localStorage?: Storage
      sessionStorage?: Storage
    }
    scope.localStorage = local
    scope.sessionStorage = session

    expect(browserStorage('localStorage')).toBe(local)
    expect(browserStorage('sessionStorage')).toBe(session)
  })

  it('answers undefined when the browser refuses even to name the store', () => {
    // Chrome with every cookie blocked throws from the global getter itself;
    // `typeof` would not have caught it.
    Object.defineProperty(globalThis, 'localStorage', {
      configurable: true,
      get() {
        throw new DOMException('denied', 'SecurityError')
      },
    })

    expect(browserStorage('localStorage')).toBeUndefined()
  })
})
