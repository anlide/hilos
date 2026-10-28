import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import * as initialBase from '../../src/base/index.js'
import * as initialRoot from '../../src/index.js'
import {
  eraseBrowserValues,
  HILOS_BROWSER_VALUES,
} from '../../src/browser/browserValues.js'
import { announceBrowserValueErased } from '../../src/browser/browserValueChanges.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import { TableViewportController } from '../../src/table/TableViewportController.js'
import { type HilosTableViews } from '../../src/table/tableView.js'
import {
  bindTableViewPreference,
  TABLE_VIEW_BROWSER_VALUE,
  TABLE_VIEW_STORAGE_KEY,
} from '../../src/table/tableViewPreference.js'

function memoryStorage(): Storage {
  const entries = new Map<string, string>()

  return {
    get length() {
      return entries.size
    },
    clear: vi.fn(() => entries.clear()),
    getItem: vi.fn((key: string) => entries.get(key) ?? null),
    key: vi.fn((index: number) => [...entries.keys()][index] ?? null),
    removeItem: vi.fn((key: string) => entries.delete(key)),
    setItem: vi.fn((key: string, value: string) => entries.set(key, value)),
  }
}

let storage: Storage
let events: EventTarget
let addListener: ReturnType<typeof vi.fn>
let removeListener: ReturnType<typeof vi.fn>
const releases: (() => void)[] = []
const controllers: TableViewportController<TableRow>[] = []

function controller(
  tableKey = 'products',
  order: HilosTableViews['order'] = ['table', 'tiles'],
  declared = true,
): TableViewportController<TableRow> {
  const result = new TableViewportController<TableRow>({
    resolve: (row) => row,
    sendViewport: vi.fn(),
    frame: {
      columns: [{ key: 'picture', label: 'Picture' }],
      ...(declared
        ? { views: { tableKey, order, imageColumn: 'picture' } }
        : {}),
    },
  })
  controllers.push(result)

  return result
}

function bind(value: TableViewportController<TableRow>): () => void {
  const release = bindTableViewPreference(value)
  releases.push(release)

  return release
}

function seed(views: Record<string, unknown>): void {
  storage.setItem(TABLE_VIEW_STORAGE_KEY, JSON.stringify({ version: 1, views }))
  vi.mocked(storage.setItem).mockClear()
}

function saved(): unknown {
  return JSON.parse(storage.getItem(TABLE_VIEW_STORAGE_KEY)!)
}

function storageEvent(
  key: string | null,
  newValue: string | null = null,
  storageArea: Storage = storage,
): void {
  const event = new Event('storage')
  Object.defineProperties(event, {
    key: { value: key },
    newValue: { value: newValue },
    storageArea: { value: storageArea },
  })
  events.dispatchEvent(event)
}

beforeEach(() => {
  storage = memoryStorage()
  events = new EventTarget()
  addListener = vi.fn(events.addEventListener.bind(events))
  removeListener = vi.fn(events.removeEventListener.bind(events))
  vi.stubGlobal('window', globalThis)
  vi.stubGlobal('localStorage', storage)
  vi.stubGlobal('addEventListener', addListener)
  vi.stubGlobal('removeEventListener', removeListener)
})

afterEach(() => {
  for (const release of releases.splice(0)) {
    release()
  }
  for (const value of controllers.splice(0)) {
    value.dispose()
  }
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('table view browser preference', () => {
  it('declares only the local preference document for the ordinary privacy sweep', () => {
    expect(TABLE_VIEW_STORAGE_KEY).toBe('hilos.table.views')
    expect(TABLE_VIEW_BROWSER_VALUE).toEqual({
      store: 'local',
      key: TABLE_VIEW_STORAGE_KEY,
      label: 'Your table views in this browser',
    })
    expect(HILOS_BROWSER_VALUES).toContain(TABLE_VIEW_BROWSER_VALUE)
  })

  it('requires a controller with declared views before reaching storage', () => {
    expect(() => bind(controller('plain', undefined, false))).toThrow(TypeError)
    expect(storage.getItem).not.toHaveBeenCalled()
    expect(storage.setItem).not.toHaveBeenCalled()
    expect(addListener).not.toHaveBeenCalled()
  })

  it.each<{ order: HilosTableViews['order'] }>([
    { order: ['table', 'tiles'] },
    { order: ['tiles', 'table'] },
  ])(
    'keeps the first declared view in $order without saving a default',
    ({ order }) => {
      const value = controller('products', order)
      expect(storage.getItem).not.toHaveBeenCalled()
      bind(value)
      expect(value.frame.view.get()).toBe(order[0])
      value.setView(order[0])
      expect(storage.setItem).not.toHaveBeenCalled()
      expect(storage.length).toBe(0)
    },
  )

  it.each(['table', 'tiles'] as const)(
    'restores %s only for the named table without writing',
    (view) => {
      seed({ products: view, files: 'tiles' })
      const value = controller()
      bind(value)
      const other = controller('people')
      bind(other)
      expect(value.frame.view.get()).toBe(view)
      expect(other.frame.view.get()).toBe('table')
      expect(storage.setItem).not.toHaveBeenCalled()
    },
  )

  it.each([
    '{broken',
    'null',
    '[]',
    '1',
    '{}',
    '{"version":2,"views":{"products":"tiles"}}',
    '{"version":"1","views":{"products":"tiles"}}',
    '{"version":1,"views":null}',
    '{"version":1,"views":[]}',
    '{"version":1,"views":"tiles"}',
    '{"version":1,"views":{"products":"grid"}}',
    '{"version":1,"views":{"products":{"view":"tiles"}}}',
  ])('treats invalid or unsupported data as no choice: %s', (raw) => {
    storage.setItem(TABLE_VIEW_STORAGE_KEY, raw)
    vi.mocked(storage.setItem).mockClear()
    const value = controller()
    bind(value)
    expect(value.frame.view.get()).toBe('table')
    expect(storage.setItem).not.toHaveBeenCalled()
    value.setView('tiles')
    expect(saved()).toEqual({ version: 1, views: { products: 'tiles' } })
  })

  it('merges current valid entries and writes no row, user or window data', () => {
    seed({
      files: 'tiles',
      people: 'table',
      broken: 'grid',
      secret: { token: 'no' },
    })
    const value = controller()
    value.ingestSubscriptionWindow(
      [{ rowKey: 'private-row', slots: { picture: 'private-image' } }],
      1,
      true,
      null,
      null,
      10,
      undefined,
      [],
    )
    bind(value)
    value.setView('tiles')
    expect(saved()).toEqual({
      version: 1,
      views: { files: 'tiles', people: 'table', products: 'tiles' },
    })
    expect(storage.setItem).toHaveBeenCalledTimes(1)
    seed({ files: 'table', anotherTab: 'tiles' })
    value.setView('table')
    expect(saved()).toEqual({
      version: 1,
      views: { files: 'table', anotherTab: 'tiles', products: 'table' },
    })
  })

  it('isolates live mounts while letting the next mount restore the latest choice', () => {
    const first = controller()
    const second = controller()
    const files = controller('files')
    bind(first)
    bind(second)
    bind(files)
    first.setView('tiles')
    files.setView('tiles')
    storageEvent(
      TABLE_VIEW_STORAGE_KEY,
      storage.getItem(TABLE_VIEW_STORAGE_KEY),
    )
    expect(second.frame.view.get()).toBe('table')
    const next = controller()
    bind(next)
    expect(next.frame.view.get()).toBe('tiles')
    expect(saved()).toEqual({
      version: 1,
      views: { products: 'tiles', files: 'tiles' },
    })
  })

  it.each(['__proto__', 'constructor', 'toString'])(
    'treats %s as an independent table key',
    (key) => {
      const value = controller(key)
      bind(value)
      expect(value.frame.view.get()).toBe('table')
      value.setView('tiles')
      expect(saved()).toEqual({ version: 1, views: { [key]: 'tiles' } })
      const next = controller(key)
      bind(next)
      expect(next.frame.view.get()).toBe('tiles')
    },
  )

  it.each(['getter', 'getItem', 'setItem', 'absent'] as const)(
    'keeps switching in memory when storage refuses at %s',
    (failure) => {
      if (failure === 'getter') {
        Object.defineProperty(globalThis, 'localStorage', {
          configurable: true,
          get() {
            throw new DOMException('denied', 'SecurityError')
          },
        })
      } else if (failure === 'absent') {
        vi.stubGlobal('localStorage', undefined)
      } else {
        vi.mocked(storage[failure]).mockImplementation(() => {
          throw new DOMException('denied', 'SecurityError')
        })
      }
      const value = controller()
      expect(() => bind(value)).not.toThrow()
      expect(() => value.setView('tiles')).not.toThrow()
      expect(value.frame.view.get()).toBe('tiles')
      expect(() => value.setView('table')).not.toThrow()
      expect(value.frame.view.get()).toBe('table')
    },
  )

  it('does not read or bind storage during SSR or controller construction', () => {
    vi.stubGlobal('window', undefined)
    const value = controller()
    bind(value)
    value.setView('tiles')
    expect(value.frame.view.get()).toBe('tiles')
    expect(storage.getItem).not.toHaveBeenCalled()
    expect(storage.setItem).not.toHaveBeenCalled()
    expect(addListener).not.toHaveBeenCalled()
  })

  it('evaluates its module and public entrypoints without reaching browser storage', async () => {
    vi.resetModules()
    vi.stubGlobal('sessionStorage', undefined)
    const read = vi.fn(() => {
      throw new Error('module evaluation reached storage')
    })
    for (const key of ['localStorage', 'sessionStorage']) {
      Object.defineProperty(globalThis, key, { configurable: true, get: read })
    }
    const preference = await import('../../src/table/tableViewPreference.js')
    const root = await import('../../src/index.js')
    const base = await import('../../src/base/index.js')
    expect(root).not.toBe(initialRoot)
    expect(base).not.toBe(initialBase)
    expect(root.bindTableViewPreference).toBe(
      preference.bindTableViewPreference,
    )
    expect(base.bindTableViewPreference).toBe(
      preference.bindTableViewPreference,
    )
    expect(read).not.toHaveBeenCalled()
  })
})

describe('table view preference erase and lifetime', () => {
  it('resets every current-tab mount to its first declared view without resurrecting the key', () => {
    seed({ products: 'tiles', files: 'table' })
    const products = controller()
    const files = controller('files', ['tiles', 'table'])
    bind(products)
    bind(files)
    const swept = eraseBrowserValues(HILOS_BROWSER_VALUES, {
      sessionCookieName: undefined,
    })
    expect(swept).toContain('Your table views in this browser')
    expect(products.frame.view.get()).toBe('table')
    expect(files.frame.view.get()).toBe('tiles')
    expect(storage.getItem(TABLE_VIEW_STORAGE_KEY)).toBeNull()
    expect(storage.setItem).not.toHaveBeenCalled()
    products.setView('tiles')
    expect(saved()).toEqual({ version: 1, views: { products: 'tiles' } })
  })

  it.each([TABLE_VIEW_STORAGE_KEY, null])(
    'resets on a native removal/clear (%s) without writeback',
    (key) => {
      seed({ products: 'tiles' })
      const value = controller()
      bind(value)
      storage.clear()
      storageEvent(key)
      expect(value.frame.view.get()).toBe('table')
      expect(storage.setItem).not.toHaveBeenCalled()
      expect(storage.length).toBe(0)
    },
  )

  it('ignores ordinary foreign choices, other keys and session-storage erases', () => {
    seed({ products: 'tiles' })
    const value = controller()
    bind(value)
    storageEvent(
      TABLE_VIEW_STORAGE_KEY,
      '{"version":1,"views":{"products":"table"}}',
    )
    storageEvent('another-key')
    storageEvent(TABLE_VIEW_STORAGE_KEY, null, memoryStorage())
    storageEvent(null, null, memoryStorage())
    announceBrowserValueErased('another-key')
    expect(value.frame.view.get()).toBe('tiles')
    expect(storage.setItem).not.toHaveBeenCalled()
  })

  it('removes the native listener, same-tab callback and signal subscription on cleanup', () => {
    seed({ products: 'tiles' })
    const value = controller()
    const release = bind(value)
    release()
    release()
    expect(removeListener).toHaveBeenCalledWith(
      'storage',
      addListener.mock.calls[0]?.[1],
    )
    storageEvent(null)
    announceBrowserValueErased(TABLE_VIEW_STORAGE_KEY)
    expect(value.frame.view.get()).toBe('tiles')
    value.setView('table')
    expect(storage.setItem).not.toHaveBeenCalled()
  })

  it('does not retain the old table identity after controller replacement and disposal', () => {
    const old = controller('old')
    const release = bind(old)
    old.setView('tiles')
    release()
    old.dispose()
    const next = controller('next')
    bind(next)
    vi.mocked(storage.setItem).mockClear()
    old.setView('table')
    announceBrowserValueErased(TABLE_VIEW_STORAGE_KEY)
    next.setView('tiles')
    expect(storage.setItem).toHaveBeenCalledTimes(1)
    expect(saved()).toEqual({
      version: 1,
      views: { old: 'tiles', next: 'tiles' },
    })
    next.dispose()
    next.setView('table')
    storageEvent(null)
    expect(storage.setItem).toHaveBeenCalledTimes(1)
  })
})
