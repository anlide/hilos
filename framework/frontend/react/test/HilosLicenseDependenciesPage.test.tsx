import { afterEach, describe, expect, it, vi } from 'vitest'
import { Activity, StrictMode, Suspense, use } from 'react'
import {
  act,
  cleanup,
  fireEvent,
  render,
  waitFor,
} from '@testing-library/react'
import {
  HILOS_LICENSE_TABS,
  HilosPages,
  TableViewportController,
} from '@hilos/core/base'
import * as core from '@hilos/core/base'
import type { HilosLicenseEntry, HilosLicenseInventory } from '@hilos/core/base'

import { HilosLicenseDependenciesPage } from '../src/public/HilosLicenseDependenciesPage.js'

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  cleanup()
  document.body.classList.remove('modal-open')
})

function entry(over: Partial<HilosLicenseEntry> = {}): HilosLicenseEntry {
  return {
    name: 'vue',
    version: '3.5.35',
    language: 'js',
    license: 'MIT',
    licenseText: 'The MIT License (MIT)\n\n  <b>Literal clause</b>\n',
    repository: 'https://github.com/vuejs/core',
    ...over,
  }
}

const inventory: HilosLicenseInventory = {
  project: 'demo-tasks',
  projectLicenseText: 'Project license belongs on the other page',
  entries: [
    entry(),
    entry({ name: 'bootstrap', version: '5.3.3', licenseText: null }),
    entry({
      name: 'reactphp/event-loop',
      version: 'v1.5.0',
      language: 'php',
      license: 'Apache-2.0',
      repository: null,
    }),
  ],
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function allById(id: string): HTMLElement[] {
  return [...document.querySelectorAll<HTMLElement>(`[data-id="${id}"]`)]
}

function packages(projection: 'table' | 'cards'): string[] {
  const selector =
    projection === 'table'
      ? 'table [data-id="license-package-open"]'
      : '[data-id="hilos-table-cards"] [data-id="license-package-open"]'
  return [...document.querySelectorAll<HTMLElement>(selector)].map(
    (button) => button.textContent ?? '',
  )
}

function mountPage(inventorySnapshot = inventory) {
  return render(<HilosLicenseDependenciesPage inventory={inventorySnapshot} />)
}

function chooseFilter(key: string, label: string, modal = false): void {
  const suffix = modal ? '-modal' : ''
  const control = document.querySelector<HTMLElement>(
    `[data-id="hilos-table-filter-${key}${suffix}"]`,
  )
  if (control === null) {
    throw new Error(`the table is not offering the ${key} filter`)
  }
  const toggle = control.querySelector<HTMLElement>(
    '[data-id="hilos-dropdown-toggle"]',
  )
  if (toggle === null) throw new Error(`the ${key} filter has no menu toggle`)
  fireEvent.click(toggle)
  const option = [
    ...control.querySelectorAll<HTMLElement>('[role="option"]'),
  ].find((candidate) => candidate.textContent?.trim() === label)
  if (option === undefined) {
    throw new Error(`the ${key} filter is not offering ${label}`)
  }
  fireEvent.click(option)
}

/** A sibling's resource, settled independently of the Dependencies page. */
function deferred(): { promise: Promise<void>; resolve: () => void } {
  let resolve = () => {}
  const promise = new Promise<void>((settle) => {
    resolve = settle
  })
  return { promise, resolve }
}

function AsyncSibling({ until }: { until: Promise<void> | null }) {
  if (until !== null) use(until)
  return <span data-testid="resource-ready">Ready</span>
}

describe('HilosLicenseDependenciesPage', () => {
  it.each([false, true])(
    'preserves the live resource, filters and reader through committed Suspense hide/reveal (StrictMode: %s)',
    async (strict) => {
      const factory = vi.spyOn(core, 'createHilosLicenseDependencies')
      const logged = vi.spyOn(console, 'error')
      const writeText = vi.fn().mockResolvedValue(undefined)
      vi.stubGlobal('navigator', { clipboard: { writeText } })
      const blobs: Blob[] = []
      vi.stubGlobal('URL', {
        createObjectURL: (blob: Blob) => {
          blobs.push(blob)
          return 'blob:hilos/test'
        },
        revokeObjectURL: vi.fn(),
      })
      const names: string[] = []
      vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(
        function (this: HTMLAnchorElement) {
          names.push(this.download)
        },
      )
      const page = (until: Promise<void> | null = null) => {
        const content = (
          <Suspense fallback={<p data-testid="fallback">Loading resource</p>}>
            <HilosLicenseDependenciesPage inventory={inventory} />
            <AsyncSibling until={until} />
          </Suspense>
        )
        return strict ? <StrictMode>{content}</StrictMode> : content
      }
      const { rerender, unmount, queryByTestId } = render(page())
      expect(packages('table')).toEqual([
        'vue',
        'bootstrap',
        'reactphp/event-loop',
      ])
      expect(queryByTestId('resource-ready')?.textContent).toBe('Ready')
      const active = factory.mock.results.at(-1)!.value
      const dispose = vi.spyOn(active, 'dispose')
      const creations = factory.mock.calls.length
      const table = document.querySelector('table')
      const search = byId('hilos-table-search') as HTMLInputElement
      chooseFilter('license', 'MIT')
      chooseFilter('language', 'JS')
      fireEvent.change(search, { target: { value: 'vue' } })
      expect(packages('table')).toEqual(['vue'])
      fireEvent.click(
        document.querySelector('table [data-id="license-package-open"]')!,
      )
      const dialog = byId('modal')
      expect(dialog?.getAttribute('aria-label')).toBe('vue 3.5.35 · MIT')

      const pending = deferred()
      // An ordinary update must commit fallback over the already working page.
      await act(async () => {
        rerender(page(pending.promise))
      })
      await waitFor(() =>
        expect(queryByTestId('fallback')?.textContent).toBe('Loading resource'),
      )
      await act(async () => {
        pending.resolve()
        await pending.promise
      })

      expect(queryByTestId('fallback')).toBeNull()
      expect(queryByTestId('resource-ready')?.textContent).toBe('Ready')
      expect(factory).toHaveBeenCalledTimes(creations)
      expect(dispose).not.toHaveBeenCalled()
      expect(document.querySelector('table')).toBe(table)
      expect(byId('hilos-table-search')).toBe(search)
      expect(search.value).toBe('vue')
      expect(active.table.filter.get()).toEqual({
        search: 'vue',
        license: 'MIT',
        language: 'js',
      })
      expect(packages('table')).toEqual(['vue'])
      expect(packages('cards')).toEqual(['vue'])
      expect(byId('modal')).toBe(dialog)
      expect(byId('license-text')?.textContent).toBe(
        inventory.entries[0]!.licenseText,
      )
      fireEvent.keyDown(dialog!, { key: 'Escape' })
      await waitFor(() => expect(byId('modal')).toBeNull())

      fireEvent.change(search, { target: { value: 'boot' } })
      expect(packages('table')).toEqual(['bootstrap'])
      expect(byId('hilos-table-count')?.textContent).toBe('1 – 1 of 1')
      fireEvent.click(byId('hilos-table-action-copy')!)
      await waitFor(() =>
        expect(byId('license-copy-status')?.textContent).toBe('Copied'),
      )
      const csv =
        'package,version,language,license\r\nbootstrap,5.3.3,JS,MIT\r\n'
      expect(writeText).toHaveBeenLastCalledWith(csv)
      fireEvent.click(byId('hilos-table-action-download')!)
      expect(names).toEqual(['demo-tasks-license-hilos-framework.csv'])
      expect(await blobs[0]!.text()).toBe(csv)
      fireEvent.click(
        document.querySelector('table [data-id="license-package-open"]')!,
      )
      expect(byId('modal')?.getAttribute('aria-label')).toBe(
        'bootstrap 5.3.3 · MIT',
      )
      expect(byId('license-text-missing')).not.toBeNull()

      unmount()
      expect(dispose).toHaveBeenCalledOnce()
      expect(active.table.rows.get()).toEqual([])
      expect(byId('modal')).toBeNull()
      expect(logged).not.toHaveBeenCalled()
    },
  )

  it('preserves the controller, filters and reader through Activity hide/reveal', async () => {
    const factory = vi.spyOn(core, 'createHilosLicenseDependencies')
    const logged = vi.spyOn(console, 'error')
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    const page = (mode: 'visible' | 'hidden', snapshot = inventory) => (
      <Activity mode={mode}>
        <HilosLicenseDependenciesPage inventory={snapshot} />
      </Activity>
    )
    const { rerender, unmount } = render(page('visible'))
    expect(packages('table')).toEqual([
      'vue',
      'bootstrap',
      'reactphp/event-loop',
    ])
    const active = factory.mock.results.at(-1)!.value
    const dispose = vi.spyOn(active, 'dispose')
    const table = document.querySelector('table')
    const search = byId('hilos-table-search') as HTMLInputElement
    chooseFilter('license', 'MIT')
    chooseFilter('language', 'JS')
    fireEvent.change(search, { target: { value: 'vue' } })
    fireEvent.click(
      document.querySelector('table [data-id="license-package-open"]')!,
    )
    const dialog = byId('modal')
    expect(dialog?.getAttribute('aria-label')).toBe('vue 3.5.35 · MIT')

    await act(async () => rerender(page('hidden')))
    expect(dispose).not.toHaveBeenCalled()
    const replacement = {
      ...inventory,
      entries: [entry({ licenseText: 'Updated while hidden' })],
    }
    await act(async () => rerender(page('hidden', replacement)))
    await act(async () => rerender(page('visible', replacement)))

    expect(factory).toHaveBeenCalledOnce()
    expect(dispose).not.toHaveBeenCalled()
    expect(document.querySelector('table')).toBe(table)
    expect(byId('hilos-table-search')).toBe(search)
    expect(search.value).toBe('vue')
    expect(active.table.filter.get()).toEqual({
      search: 'vue',
      license: 'MIT',
      language: 'js',
    })
    expect(packages('table')).toEqual(['vue'])
    expect(packages('cards')).toEqual(['vue'])
    expect(byId('modal')).toBe(dialog)
    expect(byId('license-text')?.textContent).toBe('Updated while hidden')
    fireEvent.keyDown(dialog!, { key: 'Escape' })
    await waitFor(() => expect(byId('modal')).toBeNull())
    fireEvent.change(search, { target: { value: 'missing' } })
    expect(packages('table')).toEqual([])
    fireEvent.change(search, { target: { value: '' } })
    expect(packages('table')).toEqual(['vue'])
    fireEvent.click(byId('hilos-table-action-copy')!)
    await waitFor(() =>
      expect(byId('license-copy-status')?.textContent).toBe('Copied'),
    )
    expect(writeText).toHaveBeenCalledWith(
      'package,version,language,license\r\nvue,3.5.35,JS,MIT\r\n',
    )
    unmount()
    expect(dispose).toHaveBeenCalledOnce()
    expect(active.table.rows.get()).toEqual([])
    expect(logged).not.toHaveBeenCalled()
  })

  it.each([false, true])(
    'acquires no controller until an initially hidden Activity is shown (reveal: %s)',
    async (reveal) => {
      const factory = vi.spyOn(core, 'createHilosLicenseDependencies')
      const page = (mode: 'visible' | 'hidden', snapshot = inventory) => (
        <Activity mode={mode}>
          <HilosLicenseDependenciesPage inventory={snapshot} />
        </Activity>
      )
      const { rerender, unmount } = render(page('hidden'))
      await act(async () => {})
      expect(factory).not.toHaveBeenCalled()
      expect(document.querySelector('table')).toBeNull()
      if (reveal) {
        const replacement = { ...inventory, entries: [entry()] }
        await act(async () => rerender(page('hidden', replacement)))
        expect(factory).not.toHaveBeenCalled()
        await act(async () => rerender(page('visible', replacement)))
        expect(factory).toHaveBeenCalledOnce()
        expect(packages('table')).toEqual(['vue'])
        expect(byId('hilos-table-count')?.textContent).toBe('1 – 1 of 1')
      }
      unmount()
      for (const resource of factory.mock.results) {
        expect(resource.value.table.rows.get()).toEqual([])
      }
    },
  )

  it('creates no browser resource for an initially suspended render', async () => {
    const factory = vi.spyOn(core, 'createHilosLicenseDependencies')
    const pending = deferred()
    await act(async () => {
      render(
        <Suspense fallback={<p data-testid="fallback">Loading resource</p>}>
          <HilosLicenseDependenciesPage inventory={inventory} />
          <AsyncSibling until={pending.promise} />
        </Suspense>,
      )
    })
    expect(
      document.querySelector('[data-testid="fallback"]')?.textContent,
    ).toBe('Loading resource')
    expect(factory).not.toHaveBeenCalled()
    expect(document.querySelector('table')).toBeNull()

    await act(async () => {
      pending.resolve()
      await pending.promise
    })
    expect(packages('table')).toEqual([
      'vue',
      'bootstrap',
      'reactphp/event-loop',
    ])
  })

  it('keeps rows, filters, exports and the package reader live after StrictMode reconnects effects', async () => {
    const factory = vi.spyOn(core, 'createHilosLicenseDependencies')
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    const blobs: Blob[] = []
    vi.stubGlobal('URL', {
      createObjectURL: (blob: Blob) => {
        blobs.push(blob)
        return 'blob:hilos/test'
      },
      revokeObjectURL: vi.fn(),
    })
    const names: string[] = []
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
      this: HTMLAnchorElement,
    ) {
      names.push(this.download)
    })
    const page = (snapshot: HilosLicenseInventory) => (
      <StrictMode>
        <HilosLicenseDependenciesPage inventory={snapshot} />
      </StrictMode>
    )
    const { rerender } = render(page(inventory))
    expect(packages('table')).toEqual([
      'vue',
      'bootstrap',
      'reactphp/event-loop',
    ])
    expect(packages('cards')).toEqual(packages('table'))
    const active = factory.mock.results.at(-1)!.value
    const creations = factory.mock.calls.length
    const table = document.querySelector('table')
    const search = byId('hilos-table-search') as HTMLInputElement
    chooseFilter('license', 'MIT')
    chooseFilter('language', 'JS')
    fireEvent.change(search, { target: { value: 'boot' } })
    expect(packages('table')).toEqual(['bootstrap'])
    expect(byId('hilos-table-count')?.textContent).toBe('1 – 1 of 1')
    fireEvent.click(byId('hilos-table-action-copy')!)
    await waitFor(() =>
      expect(byId('license-copy-status')?.textContent).toBe('Copied'),
    )
    expect(writeText).toHaveBeenLastCalledWith(
      'package,version,language,license\r\nbootstrap,5.3.3,JS,MIT\r\n',
    )
    fireEvent.click(byId('hilos-table-action-download')!)
    expect(names).toEqual(['demo-tasks-license-hilos-framework.csv'])
    expect(await blobs[0]!.text()).toBe(writeText.mock.calls[0]![0])
    fireEvent.click(
      document.querySelector('table [data-id="license-package-open"]')!,
    )
    const dialog = byId('modal')
    expect(dialog?.getAttribute('aria-label')).toBe('bootstrap 5.3.3 · MIT')
    expect(document.activeElement).toBe(dialog)

    const replacement = {
      ...inventory,
      project: 'replacement',
      entries: [
        entry({
          name: 'bootstrap',
          version: '5.3.3',
          licenseText: 'Updated license',
        }),
        entry({ name: 'bootstrap-addon', version: '1' }),
        entry({ name: 'boot-php', language: 'php' }),
        entry({ name: 'boot-bsd', license: 'BSD-3-Clause' }),
        entry(),
      ],
    }
    rerender(page(replacement))
    expect(factory).toHaveBeenCalledTimes(creations)
    expect(factory.mock.results.at(-1)!.value).toBe(active)
    expect(document.querySelector('table')).toBe(table)
    expect(byId('hilos-table-search')).toBe(search)
    expect(search.value).toBe('boot')
    expect(packages('table')).toEqual(['bootstrap', 'bootstrap-addon'])
    expect(packages('cards')).toEqual(packages('table'))
    expect(byId('hilos-table-count')?.textContent).toBe('1 – 2 of 2')
    expect(byId('modal')).toBe(dialog)
    expect(byId('license-text')?.textContent).toBe('Updated license')
    expect(byId('license-copy-status')?.textContent).toBe('')
    fireEvent.click(byId('hilos-table-action-download')!)
    expect(names[1]).toBe('replacement-license-hilos-framework.csv')
    expect(await blobs[1]!.text()).toBe(
      'package,version,language,license\r\nbootstrap,5.3.3,JS,MIT\r\nbootstrap-addon,1,JS,MIT\r\n',
    )
    rerender(page({ ...replacement, entries: [replacement.entries[1]!] }))
    expect(packages('table')).toEqual(['bootstrap-addon'])
    expect(byId('modal')).toBeNull()
    expect(factory).toHaveBeenCalledTimes(creations)
  })

  it.each(['visible', 'Suspense', 'Activity', 'Activity removal'] as const)(
    'releases subscriptions and disposes before a late copy settles (%s)',
    async (boundary) => {
      const factory = vi.spyOn(core, 'createHilosLicenseDependencies')
      const logged = vi.spyOn(console, 'error')
      const subscribe = core.subscribeSignal
      const subscriptions = new Set<() => void>()
      vi.spyOn(core, 'subscribeSignal').mockImplementation(
        <T,>(source: core.ReadonlySignal<T>, listener: (value: T) => void) => {
          const stop = subscribe(source, listener)
          subscriptions.add(stop)
          return () => {
            subscriptions.delete(stop)
            stop()
          }
        },
      )
      let resolveCopy!: () => void
      const writeText = vi.fn(
        () =>
          new Promise<void>((resolve) => {
            resolveCopy = resolve
          }),
      )
      vi.stubGlobal('navigator', { clipboard: { writeText } })
      const pending = deferred()
      const activity = boundary.startsWith('Activity')
      const page = (until: Promise<void> | null = null) =>
        activity ? (
          <Activity mode={until === null ? 'visible' : 'hidden'}>
            <HilosLicenseDependenciesPage inventory={inventory} />
          </Activity>
        ) : (
          <StrictMode>
            <Suspense fallback={<p data-testid="fallback">Loading resource</p>}>
              <HilosLicenseDependenciesPage inventory={inventory} />
              <AsyncSibling until={until} />
            </Suspense>
          </StrictMode>
        )
      const { rerender, unmount, queryByTestId } = render(page())
      expect(packages('table')).toEqual([
        'vue',
        'bootstrap',
        'reactphp/event-loop',
      ])
      const active = factory.mock.results.at(-1)!.value
      const release = active.dispose
      const dispose = vi.spyOn(active, 'dispose').mockImplementation(() => {
        if (activity) expect(subscriptions.size).toBe(0)
        release()
      })
      const publication = vi.fn()
      const stop = subscribe(active.copyStatus, publication)
      try {
        expect(subscriptions.size).toBeGreaterThan(0)
        fireEvent.click(byId('hilos-table-action-copy')!)
        expect(writeText).toHaveBeenCalledOnce()
        if (boundary !== 'visible') {
          await act(async () => {
            rerender(page(pending.promise))
          })
          if (activity) {
            // All real useSyncExternalStore/geometry subscriptions have left:
            // the Activity passive disconnect has completed before deletion.
            expect(subscriptions.size).toBe(0)
          } else {
            expect(queryByTestId('fallback')?.textContent).toBe(
              'Loading resource',
            )
          }
          expect(dispose).not.toHaveBeenCalled()
        }
        if (boundary === 'Activity removal') {
          // Queue completion before synchronous deletion, without a reveal.
          resolveCopy()
          rerender(<p>Closed</p>)
        } else {
          unmount()
        }
        expect.soft(dispose).toHaveBeenCalledOnce()
        expect(subscriptions.size).toBe(0)
        for (const resource of factory.mock.results) {
          expect.soft(resource.value.table.rows.get()).toEqual([])
          expect.soft(resource.value.visible.get()).toEqual([])
        }
        publication.mockClear()
        await act(async () => resolveCopy())
        expect(active.copyStatus.get()).toBe('')
        expect(publication).not.toHaveBeenCalled()
        expect(() =>
          active.table.geometry.register(() => ({ records: [] })),
        ).toThrow('Cannot register a disposed table geometry owner')
        expect(byId('license-copy-status')).toBeNull()
        expect(logged).not.toHaveBeenCalled()
      } finally {
        stop()
      }
    },
  )

  it('renders one named table with shared cells in its wide and card projections', () => {
    mountPage()

    expect(document.querySelectorAll('h1')).toHaveLength(1)
    const heading = document.querySelector('h1')
    expect(heading?.textContent).toBe('License')
    expect(
      document.querySelector('table')?.getAttribute('aria-labelledby'),
    ).toBe(heading?.getAttribute('id'))
    expect(
      document.querySelector('[role="list"]')?.getAttribute('aria-labelledby'),
    ).toBe(heading?.getAttribute('id'))
    expect(byId('hilos-table-title')).toBeNull()
    expect(byId('license-project-text')).toBeNull()
    expect(packages('table')).toEqual([
      'vue',
      'bootstrap',
      'reactphp/event-loop',
    ])
    expect(packages('cards')).toEqual(packages('table'))

    const row = document
      .querySelector('table [data-id="license-package-open"]')
      ?.closest('tr')
    expect(row?.textContent).toContain('3.5.35')
    expect(row?.textContent).toContain('JS')
    expect(row?.textContent).toContain('MIT')
    expect(document.querySelector('table')?.textContent).toContain('v1.5.0')
    expect(document.querySelector('table')?.textContent).toContain('Apache-2.0')
    expect(byId('hilos-table-count')?.textContent).toBe('1 – 3 of 3')
    expect(document.querySelector('[aria-label="Pagination"]')).toBeNull()
    expect(byId('hilos-table-loading')).toBeNull()
    expect(byId('hilos-table-action-copy')?.textContent).toContain(
      'Copy the list',
    )
    expect(byId('hilos-table-action-download')?.textContent).toContain(
      'Download as a file',
    )
  })

  it('uses the shared public tabs and resolves their current addresses', () => {
    const navigation = {
      page: HilosPages.LICENSE_DEPENDENCIES,
      tabs: HILOS_LICENSE_TABS,
      resolvePath: (page: string) =>
        page === HilosPages.LICENSE ? '/license' : '/license/dependencies',
    }
    const { rerender } = render(
      <HilosLicenseDependenciesPage
        inventory={inventory}
        navigation={navigation}
      />,
    )
    const tabs = byId('hilos-page-tabs')
    expect(
      [...(tabs?.querySelectorAll('a') ?? [])].map((link) => [
        link.textContent,
        link.getAttribute('href'),
      ]),
    ).toEqual([
      ['License', '/license'],
      ['Dependencies', '/license/dependencies'],
    ])
    expect(tabs?.querySelector('[aria-current="page"]')?.textContent).toBe(
      'Dependencies',
    )
    rerender(
      <HilosLicenseDependenciesPage
        inventory={inventory}
        navigation={{ ...navigation, page: HilosPages.LICENSE }}
      />,
    )
    expect(tabs?.querySelector('[aria-current="page"]')?.textContent).toBe(
      'License',
    )
  })

  it('searches by trimmed case-insensitive name or license and combines both selects', () => {
    mountPage()
    const search = byId('hilos-table-search') as HTMLInputElement
    expect(search.getAttribute('aria-label')).toBe(
      'Search a package or a license',
    )
    chooseFilter('license', 'MIT')
    chooseFilter('language', 'PHP')
    expect(packages('table')).toEqual([])
    expect(byId('hilos-table-no-matches')?.textContent).toContain(
      'Nothing found',
    )
    expect(byId('hilos-table-no-matches-terms')?.textContent).toContain('MIT')
    expect(byId('hilos-table-no-matches-terms')?.textContent).toContain('PHP')
    for (const key of ['copy', 'download']) {
      expect(byId(`hilos-table-action-${key}`)?.hasAttribute('disabled')).toBe(
        false,
      )
    }

    fireEvent.change(search, { target: { value: '  mIt ' } })
    chooseFilter('language', 'JS')
    expect(packages('table')).toEqual(['vue', 'bootstrap'])
    fireEvent.change(search, { target: { value: '  BOOT ' } })
    expect(packages('table')).toEqual(['bootstrap'])

    fireEvent.change(search, { target: { value: 'missing' } })
    expect(packages('table')).toEqual([])
    fireEvent.click(byId('hilos-table-no-matches-reset')!)
    expect(packages('table')).toEqual([
      'vue',
      'bootstrap',
      'reactphp/event-loop',
    ])
    expect(search.value).toBe('')
    expect(
      byId('hilos-table-filter-license')?.querySelector(
        '[aria-selected="true"]',
      )?.textContent,
    ).toBe('All licenses')
    expect(
      byId('hilos-table-filter-language')?.querySelector(
        '[aria-selected="true"]',
      )?.textContent,
    ).toBe('All languages')
    chooseFilter('language', 'PHP')
    chooseFilter('license', 'Apache-2.0')
    expect(packages('table')).toEqual(['reactphp/event-loop'])
    expect(byId('hilos-table-count')?.textContent).toBe('1 – 1 of 1')
  })

  it('offers the shared filters in the narrow-screen dialog and applies them to cards', async () => {
    mountPage()
    fireEvent.click(byId('hilos-table-filters-open')!)
    chooseFilter('language', 'PHP', true)
    fireEvent.click(byId('hilos-table-filters-done')!)
    await waitFor(() => expect(byId('modal')).toBeNull())
    expect(packages('table')).toEqual(['reactphp/event-loop'])
    expect(
      document.querySelector('[data-id="hilos-table-cards"]')?.textContent,
    ).toContain('v1.5.0')
  })

  it('distinguishes an empty build and keeps both exports available', () => {
    const { rerender } = mountPage()
    rerender(
      <HilosLicenseDependenciesPage
        inventory={{ ...inventory, entries: [] }}
      />,
    )
    expect(byId('hilos-table-empty-title')?.textContent).toBe(
      'No dependencies in this build',
    )
    expect(byId('hilos-table-empty-action')).toBeNull()
    expect(byId('hilos-table-no-matches')).toBeNull()
    expect(byId('hilos-table-action-copy')?.hasAttribute('disabled')).toBe(
      false,
    )
    expect(byId('hilos-table-action-download')?.hasAttribute('disabled')).toBe(
      false,
    )
  })

  it('opens exact plain license text with a secondary safe repository link', () => {
    mountPage()
    const opener = document.querySelector<HTMLButtonElement>(
      'table [data-id="license-package-open"]',
    )!
    expect(opener.tagName).toBe('BUTTON')
    expect(opener.type).toBe('button')
    fireEvent.click(opener)

    const dialog = byId('modal')
    expect(dialog?.getAttribute('aria-label')).toBe('vue 3.5.35 · MIT')
    const text = byId('license-text')
    expect(text?.textContent).toBe(inventory.entries[0]?.licenseText)
    expect(text?.children).toHaveLength(0)
    expect(byId('license-text-missing')).toBeNull()
    const repository = byId('license-repository') as HTMLAnchorElement
    expect(repository.href).toBe('https://github.com/vuejs/core')
    expect(repository.target).toBe('_blank')
    expect(repository.rel).toBe('noopener noreferrer')
    expect(repository.classList.contains('btn-outline-secondary')).toBe(true)
    expect(
      document.querySelector('.modal-dialog')?.classList.contains('modal-lg'),
    ).toBe(true)
  })

  it('opens a card without a license file and offers its repository action', () => {
    mountPage()
    const opener = document.querySelectorAll<HTMLButtonElement>(
      '[data-id="hilos-table-cards"] [data-id="license-package-open"]',
    )[1]!
    fireEvent.click(opener)

    expect(byId('modal')?.getAttribute('aria-label')).toBe(
      'bootstrap 5.3.3 · MIT',
    )
    expect(byId('license-text')).toBeNull()
    expect(
      byId('license-text-missing')?.textContent?.replace(/\s+/g, ' ').trim(),
    ).toBe(
      'This package ships no license file; the type above comes from its manifest',
    )
    expect(byId('license-repository')?.classList.contains('btn-primary')).toBe(
      true,
    )
  })

  it('omits the repository action when a package has no repository address', () => {
    mountPage()
    const opener = document.querySelectorAll<HTMLButtonElement>(
      'table [data-id="license-package-open"]',
    )[2]!
    fireEvent.click(opener)
    expect(byId('license-repository')).toBeNull()
  })

  it.each(['Escape', 'cross', 'Close'])(
    'opens from the keyboard and returns focus after closing with %s',
    async (close) => {
      mountPage()
      const opener = document.querySelector<HTMLButtonElement>(
        'table [data-id="license-package-open"]',
      )!
      opener.focus()
      fireEvent.click(opener, { detail: 0 })
      expect(document.activeElement).toBe(byId('modal'))
      expect(byId('modal')?.getAttribute('role')).toBe('dialog')
      expect(byId('modal')?.getAttribute('aria-modal')).toBe('true')

      if (close === 'Escape') {
        fireEvent.keyDown(byId('modal')!, { key: 'Escape' })
      } else if (close === 'cross') {
        fireEvent.click(byId('modal-close')!)
      } else {
        const closeAction = [...byId('modal')!.querySelectorAll('button')].find(
          (button) => button.textContent?.trim() === 'Close',
        )!
        fireEvent.click(closeAction)
      }

      await waitFor(() => expect(byId('modal')).toBeNull())
      expect(document.activeElement).toBe(opener)
    },
  )

  it('keeps one permanent copy-status region and announces both copy outcomes', async () => {
    mountPage()
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    const status = byId('license-copy-status')!
    expect(status.getAttribute('role')).toBe('status')
    expect(status.getAttribute('aria-live')).toBe('polite')
    expect(allById('license-copy-status')).toHaveLength(1)
    expect(status.textContent).toBe('')
    const twins = [
      ...status.parentElement!.querySelectorAll<HTMLElement>(
        '[aria-hidden="true"]',
      ),
    ]
    expect(twins.map((twin) => twin.textContent?.trim())).toEqual([
      'Copied',
      'The browser did not allow copying — use Download',
    ])

    fireEvent.change(byId('hilos-table-search')!, {
      target: { value: 'BOOT' },
    })
    fireEvent.click(byId('hilos-table-action-copy')!)
    await waitFor(() => expect(status.textContent).toBe('Copied'))
    expect(writeText).toHaveBeenLastCalledWith(
      'package,version,language,license\r\nbootstrap,5.3.3,JS,MIT\r\n',
    )

    fireEvent.change(byId('hilos-table-search')!, {
      target: { value: 'missing' },
    })
    expect(byId('license-copy-status')).toBe(status)
    expect(status.textContent).toBe('')
    writeText.mockRejectedValue(new Error('clipboard refused'))
    fireEvent.click(byId('hilos-table-action-copy')!)
    await waitFor(() =>
      expect(status.textContent).toBe(
        'The browser did not allow copying — use Download',
      ),
    )
    expect(writeText).toHaveBeenLastCalledWith(
      'package,version,language,license\r\n',
    )
  })

  it('downloads the current rows with the replacement project name, including an empty result', async () => {
    const { rerender } = mountPage()
    const blobs: Blob[] = []
    const createObjectURL = vi.fn((blob: Blob) => {
      blobs.push(blob)
      return 'blob:hilos/test'
    })
    const revokeObjectURL = vi.fn()
    vi.stubGlobal('URL', { createObjectURL, revokeObjectURL })
    const names: string[] = []
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
      this: HTMLAnchorElement,
    ) {
      names.push(this.download)
    })

    chooseFilter('language', 'PHP')
    rerender(
      <HilosLicenseDependenciesPage
        inventory={{ ...inventory, project: 'replacement' }}
      />,
    )
    fireEvent.click(byId('hilos-table-action-download')!)
    expect(names).toEqual(['replacement-license-hilos-framework.csv'])
    expect(await blobs[0]!.text()).toBe(
      'package,version,language,license\r\nreactphp/event-loop,v1.5.0,PHP,Apache-2.0\r\n',
    )
    expect(blobs[0]?.type).toBe('text/csv;charset=utf-8')

    fireEvent.change(byId('hilos-table-search')!, {
      target: { value: 'missing' },
    })
    fireEvent.click(byId('hilos-table-action-download')!)
    expect(await blobs[1]!.text()).toBe('package,version,language,license\r\n')
    expect(revokeObjectURL).toHaveBeenCalledTimes(2)
  })

  it('keeps the table and current package open across replacement, then closes a removed package', () => {
    const { rerender } = mountPage()
    const table = document.querySelector('table')
    const search = byId('hilos-table-search') as HTMLInputElement
    fireEvent.change(search, { target: { value: 'vue' } })
    chooseFilter('license', 'MIT')
    const opener = document.querySelector<HTMLButtonElement>(
      'table [data-id="license-package-open"]',
    )!
    fireEvent.click(opener)
    const dialog = byId('modal')

    const updated = entry({
      licenseText: 'Updated literal license',
      repository: null,
    })
    rerender(
      <HilosLicenseDependenciesPage
        inventory={{
          ...inventory,
          entries: [
            entry({ language: 'php', licenseText: 'Different language' }),
            entry({ version: '4', licenseText: 'Different version' }),
            updated,
            entry({ name: 'new-package', license: 'BSD-3-Clause' }),
          ],
        }}
      />,
    )

    expect(document.querySelector('table')).toBe(table)
    expect(byId('hilos-table-search')).toBe(search)
    expect(search.value).toBe('vue')
    expect(packages('table')).toEqual(['vue', 'vue', 'vue'])
    expect(byId('modal')).toBe(dialog)
    expect(byId('license-text')?.textContent).toBe('Updated literal license')
    expect(byId('license-repository')).toBeNull()

    fireEvent.click(
      byId('hilos-table-filter-license')!.querySelector(
        '[data-id="hilos-dropdown-toggle"]',
      )!,
    )
    expect(
      [
        ...byId('hilos-table-filter-license')!.querySelectorAll(
          '[role="option"]',
        ),
      ].map((option) => option.textContent?.trim()),
    ).toEqual(['All licenses', 'BSD-3-Clause', 'MIT'])

    rerender(
      <HilosLicenseDependenciesPage
        inventory={{
          ...inventory,
          entries: [
            entry({ name: 'other-language', language: 'php' }),
            entry({ name: 'other-version', version: '4' }),
          ],
        }}
      />,
    )
    expect(byId('modal')).toBeNull()
    expect(packages('table')).toEqual([])
  })

  it('disposes on unmount and ignores copy results made stale by filters or replacement', async () => {
    const pending: Array<() => void> = []
    vi.stubGlobal('navigator', {
      clipboard: {
        writeText: () => new Promise<void>((resolve) => pending.push(resolve)),
      },
    })
    const dispose = vi.spyOn(TableViewportController.prototype, 'dispose')
    const { rerender, unmount } = mountPage()
    const status = byId('license-copy-status')!

    fireEvent.click(byId('hilos-table-action-copy')!)
    fireEvent.change(byId('hilos-table-search')!, {
      target: { value: 'vue' },
    })
    await act(async () => {
      pending.shift()!()
    })
    expect(status.textContent).toBe('')

    fireEvent.click(byId('hilos-table-action-copy')!)
    rerender(
      <HilosLicenseDependenciesPage
        inventory={{ ...inventory, project: 'replacement' }}
      />,
    )
    await act(async () => {
      pending.shift()!()
    })
    expect(status.textContent).toBe('')

    fireEvent.click(byId('hilos-table-action-copy')!)
    const resolveAfterUnmount = pending.shift()!
    unmount()
    expect(dispose).toHaveBeenCalledOnce()
    await act(async () => {
      resolveAfterUnmount()
    })
    expect(byId('license-copy-status')).toBeNull()
  })
})
