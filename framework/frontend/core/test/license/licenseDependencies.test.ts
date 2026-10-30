import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createHilosLicenseDependencies,
  type HilosLicenseDependenciesController,
} from '../../src/license/licenseDependencies.js'
import {
  type HilosLicenseEntry,
  type HilosLicenseInventory,
} from '../../src/license/licenseInventory.js'
import { computedSignal, subscribeSignal } from '../../src/state/signal.js'
import { TableViewportController } from '../../src/table/TableViewportController.js'

const php: HilosLicenseEntry = {
  name: 'shared',
  version: '1.0',
  language: 'php',
  license: 'MIT',
  licenseText: 'Exact license text',
  repository: 'https://example.com/shared',
}
const js2: HilosLicenseEntry = {
  ...php,
  language: 'js',
  version: '2.0',
  license: 'Apache-2.0',
}
const js1: HilosLicenseEntry = { ...php, language: 'js' }
const escaped: HilosLicenseEntry = {
  ...js1,
  name: 'zeta,"tool"',
  license: 'UNKNOWN',
  licenseText: null,
  repository: null,
}
const inventory: HilosLicenseInventory = {
  project: 'Example Project',
  projectLicenseText: 'The project license',
  entries: [php, js2, js1, escaped],
}
const mounts: HilosLicenseDependenciesController[] = []

function mount(snapshot = inventory): HilosLicenseDependenciesController {
  const controller = createHilosLicenseDependencies(snapshot)
  mounts.push(controller)
  return controller
}

/** Browser effects remain real core/dom calls; only their host APIs are replaced. */
function browser() {
  const writeText = vi.fn<(text: string) => Promise<void>>().mockResolvedValue()
  const anchor = { href: '', download: '', click: vi.fn(), remove: vi.fn() }
  const createObjectURL = vi.fn<(blob: Blob) => string>(() => 'blob:inventory')
  const revokeObjectURL = vi.fn()
  vi.stubGlobal('navigator', { clipboard: { writeText } })
  vi.stubGlobal('document', {
    createElement: vi.fn(() => anchor),
    body: { appendChild: vi.fn() },
  })
  vi.stubGlobal('URL', { createObjectURL, revokeObjectURL })

  return { writeText, anchor, createObjectURL, revokeObjectURL }
}

function pendingCopy() {
  let resolve!: () => void
  let reject!: (reason: Error) => void
  const promise = new Promise<void>((yes, no) => {
    resolve = yes
    reject = no
  })

  return { promise, resolve, reject }
}

afterEach(() => {
  for (const controller of mounts.splice(0)) controller.dispose()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

describe('createHilosLicenseDependencies', () => {
  it('constructs without browser globals, with every real row before the first render', () => {
    vi.stubGlobal('window', undefined)
    vi.stubGlobal('document', undefined)
    vi.stubGlobal('navigator', undefined)
    vi.stubGlobal('Blob', undefined)
    vi.stubGlobal('URL', undefined)
    vi.useFakeTimers()
    const ingest = vi.spyOn(TableViewportController.prototype, 'ingestWindow')
    const controller = mount()
    const { table } = controller

    expect(table).toBeInstanceOf(TableViewportController)
    expect(controller.visible.get()).toEqual([php, js2, js1, escaped])
    expect(table.rows.get().map(({ row }) => row)).toEqual([
      php,
      js2,
      js1,
      escaped,
    ])
    expect(table.rows.get().map(({ rowKey }) => rowKey)).toEqual([
      '["php","shared","1.0"]',
      '["js","shared","2.0"]',
      '["js","shared","1.0"]',
      '["js","zeta,\\"tool\\"","1.0"]',
    ])
    expect(table.rows.get()[0]?.row).toBe(php)
    expect(ingest).toHaveBeenCalledWith(
      [
        { rowKey: '["php","shared","1.0"]', slots: {} },
        { rowKey: '["js","shared","2.0"]', slots: {} },
        { rowKey: '["js","shared","1.0"]', slots: {} },
        { rowKey: '["js","zeta,\\"tool\\"","1.0"]', slots: {} },
      ],
      4,
      true,
      null,
      null,
      4,
      0,
      undefined,
    )
    expect(table.loaded.get()).toBe(true)
    expect(table.frame.body.get()).toBe('rows')
    expect(table.frame.footer.get()).toEqual({
      firstRow: 1,
      lastRow: 4,
      totalCount: 4,
      totalExact: true,
      page: 0,
      pageCount: 1,
      hasPreviousPage: false,
      hasNextPage: false,
      paginated: false,
    })
    expect(vi.getTimerCount()).toBe(0)
    expect(controller.entry.get()).toBeNull()
    expect(controller.copyStatus.get()).toBe('')
  })

  it('declares the shared frame without a second heading or server-only capabilities', () => {
    const effects = browser()
    const { table } = mount()
    expect(table.frame.searchPlaceholder.get()).toBe(
      'Search a package or a license',
    )
    expect(table.frame.columns.get()).toEqual([
      { key: 'name', label: 'Package', card: 'title' },
      { key: 'language', label: 'Language' },
      { key: 'license', label: 'License' },
    ])
    const declaration = table.frame.declaration
    expect(declaration.title).toBeUndefined()
    expect(declaration.views).toBeUndefined()
    expect(declaration.rowHeight).toBeUndefined()
    expect(declaration.bulkActions).toBeUndefined()
    expect(declaration.mainAction).toBeUndefined()
    expect(table.frame.orders.get()).toEqual([])
    expect(declaration.empty).toEqual({
      title: 'No dependencies in this build',
    })
    expect(
      declaration.auxiliaryActions?.map(({ key, label }) => ({ key, label })),
    ).toEqual([
      { key: 'copy', label: 'Copy the list' },
      { key: 'download', label: 'Download as a file' },
    ])
    expect(effects.writeText).not.toHaveBeenCalled()
    expect(effects.createObjectURL).not.toHaveBeenCalled()
  })

  it('ANDs both selects with trimmed case-insensitive name or license search, then resets all', () => {
    vi.useFakeTimers()
    const { table, visible } = mount()
    table.setFilter('language', 'js')
    expect(visible.get()).toEqual([js2, js1, escaped])
    const firstRequest = table.lastAppliedWindowRequestId.get()
    expect(firstRequest).toEqual(expect.any(String))
    table.setFilter('license', 'MIT')
    expect(visible.get()).toEqual([js1])
    table.setSearch(' SHaReD ')
    expect(visible.get()).toEqual([js1])
    expect(table.frame.activeFilterCount.get()).toBe(2)
    expect(table.frame.footer.get()).toMatchObject({
      totalCount: 1,
      totalExact: true,
      paginated: false,
    })
    table.setSearch(' aPaChE ')
    expect(visible.get()).toEqual([])
    expect(table.frame.body.get()).toBe('empty_filtered')
    expect(table.frame.footer.get()).toMatchObject({
      firstRow: 0,
      lastRow: 0,
      totalCount: 0,
      totalExact: true,
      paginated: false,
    })
    table.setFilter('license', '')
    expect(visible.get()).toEqual([js2])
    expect(table.lastAppliedWindowRequestId.get()).not.toBe(firstRequest)
    table.resetFilters()
    expect(table.filter.get()).toEqual({})
    expect(table.search.get()).toBe('')
    expect(table.frame.activeFilterCount.get()).toBe(0)
    expect(visible.get()).toEqual(inventory.entries)
    expect(table.rows.get().map(({ row }) => row)).toEqual(inventory.entries)
    expect(table.frame.footer.get().totalCount).toBe(4)
    expect(table.frame.body.get()).toBe('rows')
    expect(vi.getTimerCount()).toBe(0)
  })

  it('offers unique full-snapshot options, labels languages, and updates options reactively', () => {
    const controller = mount()
    const optionLists = computedSignal(() =>
      controller.table.frame.filters.get().map(({ filter }) => {
        expect(filter.kind).toBe('select')
        return filter.kind === 'select'
          ? {
              key: filter.key,
              label: filter.label,
              anyLabel: filter.anyLabel,
              options: filter.options(),
            }
          : null
      }),
    )
    expect(optionLists.get()).toEqual([
      {
        key: 'license',
        label: 'License',
        anyLabel: 'All licenses',
        options: [
          { value: 'Apache-2.0', label: 'Apache-2.0' },
          { value: 'MIT', label: 'MIT' },
          { value: 'UNKNOWN', label: 'UNKNOWN' },
        ],
      },
      {
        key: 'language',
        label: 'Language',
        anyLabel: 'All languages',
        options: [
          { value: 'js', label: 'JS' },
          { value: 'php', label: 'PHP' },
        ],
      },
    ])
    controller.table.setSearch('missing')
    expect(optionLists.get()[0]?.options).toHaveLength(3)
    const listener = vi.fn()
    const unsubscribe = subscribeSignal(
      controller.table.frame.filters,
      listener,
    )
    controller.setInventory({
      ...inventory,
      entries: [{ ...php, language: 'rust', license: 'BSD-3-Clause' }],
    })
    expect(listener).toHaveBeenCalledOnce()
    expect(optionLists.get()[1]?.options).toEqual([
      { value: 'rust', label: 'rust' },
    ])
    expect(controller.table.search.get()).toBe('missing')
    unsubscribe()
  })

  it.each([
    { destination: 'a different triple key', entries: [js2] },
    { destination: 'an empty snapshot', entries: [] },
  ])(
    'keeps synchronous row readers safe through replacement with $destination',
    ({ entries }) => {
      const controller = mount({ ...inventory, entries: [php] })
      controller.open(php)
      const publications: (string | null)[][] = []
      const unsubscribe = subscribeSignal(controller.table.rows, (rows) => {
        publications.push(
          rows.map(({ row }) => (row === null ? null : row.name)),
        )
        for (const { row } of rows) {
          expect(row).not.toBeUndefined()
          expect([php, js2]).toContain(row)
        }
      })

      try {
        for (const next of [entries, [php], entries]) {
          const before = publications.length
          expect(() =>
            controller.setInventory({ ...inventory, entries: next }),
          ).not.toThrow()
          expect(publications.length).toBeGreaterThan(before)
          expect(publications.at(-1)).toEqual(next.map(({ name }) => name))
          expect(controller.table.rows.get().map(({ row }) => row)).toEqual(
            next,
          )
          expect(controller.visible.get()).toEqual(next)
          expect(controller.table.frame.footer.get()).toMatchObject({
            totalCount: next.length,
            totalExact: true,
            paginated: false,
          })
        }
        expect(controller.entry.get()).toBeNull()
        controller.open(php)
        expect(controller.entry.get()).toBeNull()
        controller.open(js2)
        expect(controller.entry.get()).toBe(entries[0] ?? null)
        expect(
          controller.table.frame.filters
            .get()
            .map(({ filter }) =>
              filter.kind === 'select' ? filter.options() : null,
            ),
        ).toEqual(
          entries.length === 0
            ? [[], []]
            : [
                [{ value: 'Apache-2.0', label: 'Apache-2.0' }],
                [{ value: 'js', label: 'JS' }],
              ],
        )
      } finally {
        unsubscribe()
      }
    },
  )

  it('preserves conditions and dialog triple identity across snapshot replacement', () => {
    const controller = mount()
    controller.open(js1)
    controller.table.setFilter('language', 'php')
    expect(controller.entry.get()).toBe(js1)
    const updated = {
      ...js1,
      licenseText: 'Replacement text',
      license: 'BSD-3-Clause',
    }
    controller.setInventory({ ...inventory, entries: [updated, php] })
    expect(controller.visible.get()).toEqual([php])
    expect(controller.entry.get()).toBe(updated)
    controller.table.resetFilters()
    expect(controller.table.rows.get().map(({ row }) => row)).toEqual([
      updated,
      php,
    ])
    controller.open(js2)
    expect(controller.entry.get()).toBeNull()
    controller.open(js1)
    expect(controller.entry.get()).toBe(updated)
    controller.close()
    expect(controller.entry.get()).toBeNull()
    controller.open(updated)
    controller.table.setFilter('license', 'BSD-3-Clause')
    controller.setInventory({ ...inventory, entries: [js2, php] })
    expect(controller.entry.get()).toBeNull()
    expect(controller.table.filter.get()).toEqual({ license: 'BSD-3-Clause' })
    expect(controller.visible.get()).toEqual([])
    expect(controller.table.frame.body.get()).toBe('empty_filtered')
  })

  it('keeps an empty snapshot ready with a nonzero window limit and independent mounts', () => {
    const empty = mount({ ...inventory, entries: [] })
    const full = mount()
    expect(empty.visible.get()).toEqual([])
    expect(empty.table.loaded.get()).toBe(true)
    expect(empty.table.frame.body.get()).toBe('empty')
    expect(empty.table.descriptor()).toMatchObject({ limit: 1, anchor: null })
    expect(empty.table.frame.footer.get()).toMatchObject({
      totalCount: 0,
      totalExact: true,
      paginated: false,
    })
    empty.table.setSearch('shared')
    empty.setInventory(inventory)
    expect(empty.visible.get()).toEqual([php, js2, js1])
    expect(empty.table.descriptor()?.limit).toBe(4)
    expect(full.table.filter.get()).toEqual({})
    expect(full.visible.get()).toEqual(inventory.entries)
    empty.open(php)
    expect(full.entry.get()).toBeNull()
  })

  it('sends one visible CSV to both destinations and uses the current project filename', async () => {
    const effects = browser()
    const controller = mount()
    controller.table.setSearch('zeta')
    const csv =
      'package,version,language,license\r\n"zeta,""tool""",1.0,JS,UNKNOWN\r\n'
    const actions = controller.table.frame.declaration.auxiliaryActions!
    actions[0]!.press()
    await vi.waitFor(() => expect(controller.copyStatus.get()).toBe('Copied'))
    actions[1]!.press()
    expect(effects.writeText).toHaveBeenCalledWith(csv)
    const blob = effects.createObjectURL.mock.calls[0]![0]
    expect(await blob.text()).toBe(csv)
    expect(blob.type).toBe('text/csv;charset=utf-8')
    expect(effects.anchor.download).toBe(
      'example-project-license-hilos-framework.csv',
    )
    expect(effects.anchor.click).toHaveBeenCalledOnce()
    expect(effects.revokeObjectURL).toHaveBeenCalledWith('blob:inventory')
    controller.setInventory({ ...inventory, project: 'Next Project' })
    expect(controller.copyStatus.get()).toBe('')
    controller.download()
    expect(effects.anchor.download).toBe(
      'next-project-license-hilos-framework.csv',
    )
    controller.table.setSearch('nothing')
    await controller.copy()
    controller.download()
    expect(effects.writeText).toHaveBeenLastCalledWith(
      'package,version,language,license\r\n',
    )
    expect(await effects.createObjectURL.mock.calls.at(-1)![0].text()).toBe(
      'package,version,language,license\r\n',
    )
    controller.table.resetFilters()
    await controller.copy()
    expect(effects.writeText).toHaveBeenLastCalledWith(
      'package,version,language,license\r\nshared,1.0,PHP,MIT\r\nshared,2.0,JS,Apache-2.0\r\nshared,1.0,JS,MIT\r\n"zeta,""tool""",1.0,JS,UNKNOWN\r\n',
    )
  })

  it('reports clipboard refusal and clears feedback when a condition or snapshot changes', async () => {
    const effects = browser()
    effects.writeText.mockRejectedValue(new Error('browser detail'))
    const controller = mount()
    await controller.copy()
    expect(controller.copyStatus.get()).toBe(
      'The browser did not allow copying — use Download',
    )
    controller.table.setFilter('language', 'js')
    expect(controller.copyStatus.get()).toBe('')
    effects.writeText.mockResolvedValue()
    await controller.copy()
    expect(controller.copyStatus.get()).toBe('Copied')
    controller.setInventory(inventory)
    expect(controller.copyStatus.get()).toBe('')
  })

  it('ignores late copies after filter or inventory changes and after a newer copy', async () => {
    const effects = browser()
    const controller = mount()
    const first = pendingCopy()
    effects.writeText.mockReturnValueOnce(first.promise)
    const copying = controller.copy()
    controller.table.setSearch('shared')
    first.resolve()
    await copying
    expect(controller.copyStatus.get()).toBe('')
    const second = pendingCopy()
    effects.writeText.mockReturnValueOnce(second.promise)
    const replacing = controller.copy()
    controller.setInventory(inventory)
    second.resolve()
    await replacing
    expect(controller.copyStatus.get()).toBe('')
    const old = pendingCopy()
    effects.writeText.mockReturnValueOnce(old.promise)
    const older = controller.copy()
    await controller.copy()
    expect(controller.copyStatus.get()).toBe('Copied')
    old.reject(new Error('older refusal'))
    await older
    expect(controller.copyStatus.get()).toBe('Copied')
  })

  it('disposes the real table and prevents all later actions and copy publication', async () => {
    vi.useFakeTimers()
    const effects = browser()
    const pending = pendingCopy()
    effects.writeText.mockReturnValueOnce(pending.promise)
    const controller = mount()
    controller.open(php)
    const copying = controller.copy()
    controller.dispose()
    const changed = vi.fn()
    const unsubscribes = [
      controller.visible,
      controller.entry,
      controller.copyStatus,
    ].map((signal) => subscribeSignal(signal, changed))
    pending.resolve()
    await copying
    controller.open(js1)
    controller.close()
    controller.setInventory(inventory)
    controller.table.setSearch('shared')
    await controller.copy()
    controller.download()
    controller.dispose()
    expect(changed).not.toHaveBeenCalled()
    expect(controller.table.rows.get()).toEqual([])
    expect(controller.visible.get()).toEqual([])
    expect(controller.entry.get()).toBeNull()
    expect(controller.copyStatus.get()).toBe('')
    expect(effects.writeText).toHaveBeenCalledOnce()
    expect(effects.createObjectURL).not.toHaveBeenCalled()
    expect(vi.getTimerCount()).toBe(0)
    for (const unsubscribe of unsubscribes) unsubscribe()
  })
})
