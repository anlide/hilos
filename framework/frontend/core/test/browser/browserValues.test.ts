import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { browserValue } from '../../src/browser/browserValue.js'
import {
  eraseBrowserValues,
  HILOS_BROWSER_VALUES,
} from '../../src/browser/browserValues.js'

/** The smallest thing that answers like a `Storage`, plus a switch to make it refuse. */
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

/** Every string assigned to `document.cookie`, in order. */
let cookieWrites: string[] = []

function installBrowser(options: { throws?: boolean } = {}): void {
  const scope = globalThis as {
    sessionStorage?: Storage
    localStorage?: Storage
    document?: { cookie: string }
  }
  scope.sessionStorage = fakeStorage(options)
  scope.localStorage = fakeStorage(options)
  scope.document = {
    get cookie(): string {
      return ''
    },
    set cookie(written: string) {
      if (options.throws === true) {
        throw new Error('cookies are not available')
      }
      cookieWrites.push(written)
    },
  }
}

function uninstallBrowser(): void {
  const scope = globalThis as {
    sessionStorage?: Storage
    localStorage?: Storage
    document?: { cookie: string }
  }
  delete scope.sessionStorage
  delete scope.localStorage
  delete scope.document
}

const noCookieName = { sessionCookieName: undefined }
const cookieNamed = { sessionCookieName: 'hilos_session_token' }

describe('the framework registry', () => {
  it('is assembled with no browser anywhere in sight', () => {
    // The whole module graph was imported by this file with none of the three
    // globals installed: /privacy is prerendered, and a declaration is data.
    expect(HILOS_BROWSER_VALUES).toHaveLength(4)
  })

  it('names every value in a line a person can read', () => {
    for (const value of HILOS_BROWSER_VALUES) {
      expect(value.label).not.toBe('')
    }
  })

  it('declares the rotation ticket under the name this deployment gave it', () => {
    const rotation = HILOS_BROWSER_VALUES.find((one) => one.store === 'cookie')

    expect(typeof rotation?.key).toBe('function')
    expect(
      (rotation?.key as (context: typeof cookieNamed) => string | undefined)(
        cookieNamed,
      ),
    ).toBe('hilos_session_token_rotate')
  })
})

describe('eraseBrowserValues', () => {
  beforeEach(() => {
    cookieWrites = []
    installBrowser()
  })

  afterEach(() => {
    uninstallBrowser()
  })

  it('takes a session key out of session storage and nowhere else', () => {
    globalThis.sessionStorage.setItem('kept', 'yes')
    globalThis.localStorage.setItem('kept', 'yes')

    const swept = eraseBrowserValues(
      [browserValue({ store: 'session', key: 'kept', label: 'A tab value' })],
      noCookieName,
    )

    expect(swept).toEqual(['A tab value'])
    expect(globalThis.sessionStorage.getItem('kept')).toBeNull()
    expect(globalThis.localStorage.getItem('kept')).toBe('yes')
  })

  it('takes a local key out of local storage and nowhere else', () => {
    globalThis.sessionStorage.setItem('kept', 'yes')
    globalThis.localStorage.setItem('kept', 'yes')

    const swept = eraseBrowserValues(
      [browserValue({ store: 'local', key: 'kept', label: 'A browser value' })],
      noCookieName,
    )

    expect(swept).toEqual(['A browser value'])
    expect(globalThis.localStorage.getItem('kept')).toBeNull()
    expect(globalThis.sessionStorage.getItem('kept')).toBe('yes')
  })

  it('expires a cookie on the path every cookie of this framework is written to', () => {
    const swept = eraseBrowserValues(
      [
        browserValue({
          store: 'cookie',
          key: 'hilos_ticket',
          label: 'A ticket',
        }),
      ],
      noCookieName,
    )

    expect(swept).toEqual(['A ticket'])
    expect(cookieWrites).toEqual(['hilos_ticket=; Max-Age=0; Path=/'])
  })

  it('builds a key the deployment names from the context it is given', () => {
    eraseBrowserValues(
      [
        browserValue({
          store: 'cookie',
          key: (context) =>
            context.sessionCookieName === undefined
              ? undefined
              : `${context.sessionCookieName}_rotate`,
          label: 'A ticket',
        }),
      ],
      cookieNamed,
    )

    expect(cookieWrites).toEqual([
      'hilos_session_token_rotate=; Max-Age=0; Path=/',
    ])
  })

  it('skips an entry whose key the deployment has not named, and does not report it', () => {
    const swept = eraseBrowserValues(
      [
        browserValue({
          store: 'cookie',
          key: () => undefined,
          label: 'A ticket',
        }),
        browserValue({ store: 'local', key: 'kept', label: 'A browser value' }),
      ],
      noCookieName,
    )

    expect(swept).toEqual(['A browser value'])
    expect(cookieWrites).toEqual([])
  })

  it('reports what it swept in declaration order', () => {
    const swept = eraseBrowserValues(
      [
        browserValue({ store: 'local', key: 'a', label: 'First' }),
        browserValue({ store: 'cookie', key: 'b', label: 'Second' }),
        browserValue({ store: 'session', key: 'c', label: 'Third' }),
      ],
      noCookieName,
    )

    expect(swept).toEqual(['First', 'Second', 'Third'])
  })

  it('is not a failure where there is no browser at all, and reports nothing swept', () => {
    uninstallBrowser()

    const swept = eraseBrowserValues(
      [
        browserValue({ store: 'session', key: 'a', label: 'First' }),
        browserValue({ store: 'local', key: 'b', label: 'Second' }),
        browserValue({ store: 'cookie', key: 'c', label: 'Third' }),
      ],
      noCookieName,
    )

    expect(swept).toEqual([])
  })

  it('is not a failure on a profile whose storage refuses, and reports nothing swept', () => {
    installBrowser({ throws: true })

    const swept = eraseBrowserValues(
      [
        browserValue({ store: 'session', key: 'a', label: 'First' }),
        browserValue({ store: 'local', key: 'b', label: 'Second' }),
        browserValue({ store: 'cookie', key: 'c', label: 'Third' }),
      ],
      noCookieName,
    )

    expect(swept).toEqual([])
  })

  it('goes on to the next entry when one store refuses', () => {
    const scope = globalThis as { sessionStorage?: Storage }
    scope.sessionStorage = fakeStorage({ throws: true })

    const swept = eraseBrowserValues(
      [
        browserValue({ store: 'session', key: 'a', label: 'First' }),
        browserValue({ store: 'local', key: 'b', label: 'Second' }),
      ],
      noCookieName,
    )

    expect(swept).toEqual(['Second'])
  })
})
