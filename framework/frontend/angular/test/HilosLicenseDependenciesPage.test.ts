// The Angular component coverage for the local dependency inventory, real table
// and modal. The table's wide and card projections render the same cell templates.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { By } from '@angular/platform-browser'
import { HILOS_LICENSE_TABS, HilosPages } from '@hilos/core/base'
import type {
  HilosLicenseEntry,
  HilosLicenseInventory,
  HilosStaticPageNavigation,
  TableViewportController,
} from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosViewportTable } from '../src/HilosViewportTable.js'
import { HilosLicenseDependenciesPage } from '../src/public/HilosLicenseDependenciesPage.js'

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
  project: 'demo-polls',
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

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.classList.remove('modal-open')
})

function mountPage(
  pageInventory: HilosLicenseInventory = inventory,
): ComponentFixture<HilosLicenseDependenciesPage> {
  const fixture = TestBed.createComponent(HilosLicenseDependenciesPage)
  fixture.componentRef.setInput('inventory', pageInventory)
  fixture.detectChanges()

  return fixture
}

function byId(
  fixture: ComponentFixture<unknown>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

function allById(
  fixture: ComponentFixture<unknown>,
  id: string,
): HTMLElement[] {
  return [
    ...(fixture.nativeElement as HTMLElement).querySelectorAll<HTMLElement>(
      `[data-id="${id}"]`,
    ),
  ]
}

function navigation(page: string): HilosStaticPageNavigation {
  return {
    page,
    tabs: HILOS_LICENSE_TABS,
    resolvePath: (target) =>
      target === HilosPages.LICENSE ? '/license' : '/license/dependencies',
  }
}

function tableController(
  fixture: ComponentFixture<HilosLicenseDependenciesPage>,
): TableViewportController<HilosLicenseEntry> {
  const table = fixture.debugElement.query(By.directive(HilosViewportTable))
  if (table === null) throw new Error('the dependency table is not mounted')

  return (
    table.componentInstance as HilosViewportTable<HilosLicenseEntry>
  ).controller()
}

function packageButtons(
  fixture: ComponentFixture<HilosLicenseDependenciesPage>,
  projection: 'wide' | 'card' = 'wide',
): HTMLButtonElement[] {
  const root = fixture.nativeElement as HTMLElement
  const view =
    projection === 'wide'
      ? root.querySelector('table')
      : byId(fixture, 'hilos-table-cards')
  if (view === null)
    throw new Error(`the ${projection} projection is not mounted`)

  return [
    ...view.querySelectorAll<HTMLButtonElement>(
      '[data-id="license-package-open"]',
    ),
  ]
}

function chooseFilter(
  fixture: ComponentFixture<HilosLicenseDependenciesPage>,
  key: 'license' | 'language',
  label: string,
  placement: 'bar' | 'modal' = 'bar',
): void {
  const filter = byId(
    fixture,
    `hilos-table-filter-${key}${placement === 'modal' ? '-modal' : ''}`,
  )
  const toggle = filter?.querySelector<HTMLButtonElement>(
    '[data-id="hilos-dropdown-toggle"]',
  )
  if (filter === null || toggle === null || toggle === undefined) {
    throw new Error(`the ${key} filter is not mounted in the ${placement}`)
  }
  toggle.click()
  fixture.detectChanges()

  const option = [
    ...filter.querySelectorAll<HTMLElement>('[role="option"]'),
  ].find((candidate) => candidate.textContent?.trim() === label)
  if (option === undefined) {
    throw new Error(`the ${key} filter does not offer ${label}`)
  }
  option.click()
  fixture.detectChanges()
}

function setSearch(
  fixture: ComponentFixture<HilosLicenseDependenciesPage>,
  value: string,
): void {
  const search = byId(fixture, 'hilos-table-search') as HTMLInputElement | null
  if (search === null) throw new Error('the table search is not mounted')
  search.value = value
  search.dispatchEvent(new Event('input'))
  fixture.detectChanges()
}

function openPackage(
  fixture: ComponentFixture<HilosLicenseDependenciesPage>,
  projection: 'wide' | 'card',
  index: number,
): HTMLButtonElement {
  const opener = packageButtons(fixture, projection)[index]
  if (opener === undefined) throw new Error(`package ${index} is not mounted`)
  opener.focus()
  opener.dispatchEvent(new MouseEvent('click', { bubbles: true, detail: 0 }))
  fixture.detectChanges()

  return opener
}

describe('HilosLicenseDependenciesPage', () => {
  it('renders a named real table and projects each package cell into cards', () => {
    const fixture = mountPage()
    const root = fixture.nativeElement as HTMLElement
    const heading = root.querySelector('h1')
    const table = root.querySelector('table')
    const cards = byId(fixture, 'hilos-table-cards')
    const names = packageButtons(fixture).map((button) =>
      button.textContent?.replace(/\s+/g, ' ').trim(),
    )

    expect(root.querySelectorAll('h1')).toHaveLength(1)
    expect(heading?.textContent?.trim()).toBe('License')
    expect(table?.getAttribute('aria-labelledby')).toBe(heading?.id)
    expect(byId(fixture, 'hilos-table-title')).toBeNull()
    expect(byId(fixture, 'license-project-text')).toBeNull()
    expect(names).toEqual(['vue', 'bootstrap', 'reactphp/event-loop'])
    expect(
      packageButtons(fixture, 'card').map((button) =>
        button.textContent?.replace(/\s+/g, ' ').trim(),
      ),
    ).toEqual(names)
    expect(table?.textContent).toContain('3.5.35')
    expect(table?.textContent).toContain('5.3.3')
    expect(table?.textContent).toContain('v1.5.0')
    expect(cards?.textContent).toContain('3.5.35')
    expect(cards?.textContent).toContain('5.3.3')
    expect(cards?.textContent).toContain('v1.5.0')
    expect(table?.textContent).toContain('PHP')
    expect(table?.textContent).toContain('Apache-2.0')
    expect(byId(fixture, 'hilos-table-count')?.textContent?.trim()).toBe(
      '1 – 3 of 3',
    )
    expect(byId(fixture, 'hilos-table-loading')).toBeNull()
    expect(byId(fixture, 'hilos-table-action-copy')?.textContent).toContain(
      'Copy the list',
    )
    expect(byId(fixture, 'hilos-table-action-download')?.textContent).toContain(
      'Download as a file',
    )
  })

  it('uses the shared public tabs, their real paths and the current page key', () => {
    const fixture = mountPage()
    fixture.componentRef.setInput(
      'navigation',
      navigation(HilosPages.LICENSE_DEPENDENCIES),
    )
    fixture.detectChanges()

    const tabs = byId(fixture, 'hilos-page-tabs')
    expect(
      [...(tabs?.querySelectorAll('a') ?? [])].map((link) => [
        link.textContent?.trim(),
        link.getAttribute('href'),
      ]),
    ).toEqual([
      ['License', '/license'],
      ['Dependencies', '/license/dependencies'],
    ])
    expect(tabs?.querySelector('[aria-current="page"]')?.textContent).toBe(
      'Dependencies',
    )
  })

  it('searches by trimmed name or license, combines both filters, and resets all conditions', () => {
    const fixture = mountPage()
    const search = byId(fixture, 'hilos-table-search') as HTMLInputElement

    expect(search.getAttribute('aria-label')).toBe(
      'Search a package or a license',
    )
    chooseFilter(fixture, 'license', 'MIT')
    chooseFilter(fixture, 'language', 'JS')
    expect(
      packageButtons(fixture).map((button) => button.textContent?.trim()),
    ).toEqual(['vue', 'bootstrap'])
    setSearch(fixture, '  BOOT ')
    expect(
      packageButtons(fixture).map((button) => button.textContent?.trim()),
    ).toEqual(['bootstrap'])
    setSearch(fixture, '  mIt ')
    expect(packageButtons(fixture)).toHaveLength(2)
    chooseFilter(fixture, 'language', 'PHP')
    expect(allById(fixture, 'hilos-table-no-matches')).toHaveLength(2)
    expect(
      byId(fixture, 'hilos-table-no-matches-terms')?.textContent,
    ).toContain('MIT')
    expect(
      byId(fixture, 'hilos-table-no-matches-terms')?.textContent,
    ).toContain('PHP')
    expect(
      byId(fixture, 'hilos-table-action-copy')?.hasAttribute('disabled'),
    ).toBe(false)
    expect(
      byId(fixture, 'hilos-table-action-download')?.hasAttribute('disabled'),
    ).toBe(false)

    byId(fixture, 'hilos-table-no-matches-reset')?.click()
    fixture.detectChanges()
    expect(search.value).toBe('')
    expect(packageButtons(fixture)).toHaveLength(3)
    expect(byId(fixture, 'hilos-table-filter-license')?.textContent).toContain(
      'All licenses',
    )
    expect(byId(fixture, 'hilos-table-filter-language')?.textContent).toContain(
      'All languages',
    )
  })

  it('offers the same filters in the shared narrow-screen dialog', () => {
    const fixture = mountPage()
    byId(fixture, 'hilos-table-filters-open')?.click()
    fixture.detectChanges()
    expect(byId(fixture, 'modal')).not.toBeNull()

    chooseFilter(fixture, 'language', 'PHP', 'modal')
    byId(fixture, 'hilos-table-filters-done')?.click()
    fixture.detectChanges()
    expect(byId(fixture, 'modal')).toBeNull()
    expect(packageButtons(fixture)).toHaveLength(1)
    expect(packageButtons(fixture)[0]?.textContent).toContain(
      'reactphp/event-loop',
    )
  })

  it('distinguishes an empty build from no matches and keeps both exports available', () => {
    const fixture = mountPage({ ...inventory, entries: [] })

    expect(byId(fixture, 'hilos-table-empty-title')?.textContent?.trim()).toBe(
      'No dependencies in this build',
    )
    expect(byId(fixture, 'hilos-table-empty-action')).toBeNull()
    expect(byId(fixture, 'hilos-table-no-matches')).toBeNull()
    expect(
      byId(fixture, 'hilos-table-action-copy')?.hasAttribute('disabled'),
    ).toBe(false)
    expect(
      byId(fixture, 'hilos-table-action-download')?.hasAttribute('disabled'),
    ).toBe(false)
  })

  it('opens exact plain license text in a wide dialog with a secondary repository link', () => {
    const fixture = mountPage()
    openPackage(fixture, 'wide', 0)
    const dialog = byId(fixture, 'modal')
    const text = byId(fixture, 'license-text')
    const repository = byId(fixture, 'license-repository') as HTMLAnchorElement

    expect(dialog?.getAttribute('role')).toBe('dialog')
    expect(dialog?.getAttribute('aria-modal')).toBe('true')
    expect(dialog?.getAttribute('aria-label')).toBe('vue 3.5.35 · MIT')
    expect(text?.textContent).toBe(inventory.entries[0]?.licenseText)
    expect(text?.children).toHaveLength(0)
    expect(byId(fixture, 'license-text-missing')).toBeNull()
    expect(repository.href).toBe('https://github.com/vuejs/core')
    expect(repository.target).toBe('_blank')
    expect(repository.rel).toBe('noopener noreferrer')
    expect(repository.classList.contains('btn-outline-secondary')).toBe(true)
    expect(
      dialog?.querySelector('.modal-dialog')?.classList.contains('modal-lg'),
    ).toBe(true)
  })

  it('shows the manifest explanation and a primary repository action when license text is absent', () => {
    const fixture = mountPage()
    openPackage(fixture, 'card', 1)
    expect(byId(fixture, 'license-text')).toBeNull()
    expect(
      byId(fixture, 'license-text-missing')
        ?.textContent?.replace(/\s+/g, ' ')
        .trim(),
    ).toBe(
      'This package ships no license file; the type above comes from its manifest',
    )
    expect(
      byId(fixture, 'license-repository')?.classList.contains('btn-primary'),
    ).toBe(true)
  })

  it('omits the repository link when the package has no repository address', () => {
    const fixture = mountPage()
    openPackage(fixture, 'wide', 2)
    expect(byId(fixture, 'license-repository')).toBeNull()
  })

  it.each(['Escape', 'cross', 'Close'] as const)(
    'opens from the keyboard-accessible package button and returns focus on %s',
    (close) => {
      const fixture = mountPage()
      const opener = openPackage(fixture, 'wide', 0)
      expect(opener.tagName).toBe('BUTTON')
      expect(opener.getAttribute('type')).toBe('button')
      expect(document.activeElement).toBe(byId(fixture, 'modal'))

      if (close === 'Escape') {
        byId(fixture, 'modal')?.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      } else if (close === 'cross') {
        byId(fixture, 'modal-close')?.click()
      } else {
        const closeAction = [
          ...(byId(fixture, 'modal')?.querySelectorAll('button') ?? []),
        ].find((button) => button.textContent?.trim() === 'Close')
        closeAction?.click()
      }
      fixture.detectChanges()

      expect(byId(fixture, 'modal')).toBeNull()
      expect(document.activeElement).toBe(opener)
    },
  )

  it('keeps a permanent live status with reserved room and copies the current CSV result', async () => {
    const fixture = mountPage()
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    const status = byId(fixture, 'license-copy-status')
    const stack = status?.parentElement

    expect(status?.getAttribute('role')).toBe('status')
    expect(status?.getAttribute('aria-live')).toBe('polite')
    expect(allById(fixture, 'license-copy-status')).toHaveLength(1)
    expect(
      stack?.querySelectorAll('.invisible[aria-hidden="true"]'),
    ).toHaveLength(2)
    expect(stack?.querySelectorAll('[aria-live]')).toHaveLength(1)

    setSearch(fixture, 'BOOT')
    byId(fixture, 'hilos-table-action-copy')?.click()
    await fixture.whenStable()
    fixture.detectChanges()
    expect(writeText).toHaveBeenCalledWith(
      'package,version,language,license\r\nbootstrap,5.3.3,JS,MIT\r\n',
    )
    expect(status?.textContent).toBe('Copied')

    setSearch(fixture, 'no match')
    fixture.detectChanges()
    expect(byId(fixture, 'license-copy-status')).toBe(status)
    expect(status?.textContent).toBe('')

    writeText.mockRejectedValue(new Error('Clipboard refused'))
    byId(fixture, 'hilos-table-action-copy')?.click()
    await fixture.whenStable()
    fixture.detectChanges()
    expect(status?.textContent).toBe(
      'The browser did not allow copying — use Download',
    )
  })

  it('downloads the current filtered CSV with the current project name and a header for no rows', async () => {
    const fixture = mountPage()
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

    chooseFilter(fixture, 'language', 'PHP')
    fixture.componentRef.setInput('inventory', {
      ...inventory,
      project: 'replacement',
    })
    fixture.detectChanges()
    byId(fixture, 'hilos-table-action-download')?.click()
    expect(names).toEqual(['replacement-license-hilos-framework.csv'])
    expect(await blobs[0]?.text()).toBe(
      'package,version,language,license\r\nreactphp/event-loop,v1.5.0,PHP,Apache-2.0\r\n',
    )
    expect(blobs[0]?.type).toBe('text/csv;charset=utf-8')

    setSearch(fixture, 'no match')
    byId(fixture, 'hilos-table-action-download')?.click()
    expect(await blobs[1]?.text()).toBe('package,version,language,license\r\n')
    expect(revokeObjectURL).toHaveBeenCalledTimes(2)
  })

  it('keeps the controller and filters, refreshes the same open entry, and closes a disappeared entry', () => {
    const fixture = mountPage()
    const controller = tableController(fixture)
    chooseFilter(fixture, 'license', 'MIT')
    openPackage(fixture, 'wide', 0)

    fixture.componentRef.setInput('inventory', {
      ...inventory,
      entries: [
        entry({ licenseText: 'Updated literal license', repository: null }),
        entry({ version: '4.0.0', licenseText: 'A different package version' }),
        entry({ name: 'new-package', license: 'BSD-3-Clause' }),
      ],
    })
    fixture.detectChanges()
    expect(tableController(fixture)).toBe(controller)
    expect(byId(fixture, 'hilos-table-filter-license')?.textContent).toContain(
      'MIT',
    )
    expect(byId(fixture, 'license-text')?.textContent).toBe(
      'Updated literal license',
    )
    expect(byId(fixture, 'license-repository')).toBeNull()

    fixture.componentRef.setInput('inventory', {
      ...inventory,
      entries: [entry({ language: 'php' }), entry({ version: '4.0.0' })],
    })
    fixture.detectChanges()
    expect(byId(fixture, 'modal')).toBeNull()
  })

  it('rejects late copy results after a selection change and after disposal', async () => {
    const fixture = mountPage()
    const pending: (() => void)[] = []
    vi.stubGlobal('navigator', {
      clipboard: {
        writeText: () => new Promise<void>((resolve) => pending.push(resolve)),
      },
    })

    byId(fixture, 'hilos-table-action-copy')?.click()
    setSearch(fixture, 'vue')
    pending.shift()?.()
    await fixture.whenStable()
    fixture.detectChanges()
    expect(byId(fixture, 'license-copy-status')?.textContent).toBe('')

    byId(fixture, 'hilos-table-action-copy')?.click()
    const resolveAfterDispose = pending.shift()
    const status = byId(fixture, 'license-copy-status')
    const dispose = vi.spyOn(tableController(fixture), 'dispose')
    fixture.destroy()
    expect(dispose).toHaveBeenCalledOnce()
    resolveAfterDispose?.()
    await Promise.resolve()
    expect(status?.textContent).toBe('')
  })
})
