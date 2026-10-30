import {
  DOMWrapper,
  enableAutoUnmount,
  flushPromises,
  mount,
} from '@vue/test-utils'
import { HILOS_LICENSE_TABS, HilosPages } from '@hilos/core/base'
import type {
  HilosLicenseEntry,
  TableViewportController,
} from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'

import HilosModal from '../HilosModal.vue'
import HilosViewportTable from '../HilosViewportTable.vue'
import HilosLicenseDependenciesPage from './HilosLicenseDependenciesPage.vue'

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})
enableAutoUnmount(afterEach)

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

const inventory = {
  project: 'demo-chat',
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

function mountPage() {
  return mount(HilosLicenseDependenciesPage, {
    props: { inventory },
    attachTo: document.body,
  })
}

type Page = ReturnType<typeof mountPage>

/** Read the wide projection; the same cells also appear in responsive cards. */
function packages(wrapper: Page): string[] {
  return wrapper
    .findAll('table [data-id="license-package-open"]')
    .map((button) => button.text())
}

function dialog(): HTMLElement | null {
  return document.querySelector('[data-id="modal"]')
}

function controllerOf(
  wrapper: Page,
): TableViewportController<HilosLicenseEntry> {
  const { controller } = wrapper.getComponent(HilosViewportTable).props() as {
    controller: TableViewportController<HilosLicenseEntry>
  }
  return controller
}

/** Pick an offered value through the real table dropdown in either placement. */
async function chooseFilter(
  wrapper: Page,
  key: string,
  label: string,
  modal = false,
): Promise<void> {
  const selector = `[data-id="hilos-table-filter-${key}${modal ? '-modal' : ''}"]`
  const control = modal
    ? new DOMWrapper(document.querySelector<HTMLElement>(selector)!)
    : wrapper.get(selector)
  await control.get('[data-id="hilos-dropdown-toggle"]').trigger('click')
  const option = control
    .findAll('[role="option"]')
    .find((option) => option.text() === label)
  expect(option).toBeDefined()
  await option!.trigger('click')
}

describe('HilosLicenseDependenciesPage', () => {
  it('renders a complete named table and the same package cells in cards', () => {
    const wrapper = mountPage()
    expect(wrapper.findAll('h1')).toHaveLength(1)
    const heading = wrapper.get('h1')
    expect(heading.text()).toBe('License')
    expect(wrapper.get('table').attributes('aria-labelledby')).toBe(
      heading.attributes('id'),
    )
    expect(wrapper.get('[role="list"]').attributes('aria-labelledby')).toBe(
      heading.attributes('id'),
    )
    expect(wrapper.find('[data-id="hilos-table-title"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="license-project-text"]').exists()).toBe(
      false,
    )
    expect(packages(wrapper)).toEqual([
      'vue',
      'bootstrap',
      'reactphp/event-loop',
    ])
    expect(
      wrapper
        .get('[data-id="hilos-table-cards"]')
        .findAll('[data-id="license-package-open"]')
        .map((button) => button.text()),
    ).toEqual(packages(wrapper))
    for (const row of wrapper
      .findAll('table tbody tr')
      .filter((row) => row.find('[data-id="license-package-open"]').exists())) {
      expect(row.text()).toContain(
        row.text().includes('reactphp') ? 'PHP' : 'JS',
      )
    }
    expect(wrapper.get('table').text()).toContain('v1.5.0')
    expect(wrapper.get('table').text()).toContain('Apache-2.0')
    expect(wrapper.get('[data-id="hilos-table-cards"]').text()).toContain('PHP')
    expect(wrapper.get('[data-id="hilos-table-count"]').text()).toBe(
      '1 – 3 of 3',
    )
    expect(wrapper.find('[aria-label="Pagination"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-table-loading"]').exists()).toBe(false)
    expect(wrapper.get('[data-id="hilos-table-action-copy"]').text()).toBe(
      'Copy the list',
    )
    expect(wrapper.get('[data-id="hilos-table-action-download"]').text()).toBe(
      'Download as a file',
    )
  })

  it('uses the shared tabs and updates the current page and real addresses', async () => {
    const wrapper = mountPage()
    const navigation = {
      page: HilosPages.LICENSE_DEPENDENCIES,
      tabs: HILOS_LICENSE_TABS,
      resolvePath: (page: string) =>
        page === HilosPages.LICENSE ? '/license' : '/license/dependencies',
    }
    await wrapper.setProps({ navigation })
    const tabs = wrapper.get('[data-id="hilos-page-tabs"]')
    expect(
      tabs.findAll('a').map((link) => [link.text(), link.attributes('href')]),
    ).toEqual([
      ['License', '/license'],
      ['Dependencies', '/license/dependencies'],
    ])
    expect(tabs.get('[aria-current="page"]').text()).toBe('Dependencies')
    await wrapper.setProps({
      navigation: { ...navigation, page: HilosPages.LICENSE },
    })
    expect(tabs.get('[aria-current="page"]').text()).toBe('License')
  })

  it('filters by trimmed case-insensitive name or license and combines both selects', async () => {
    const wrapper = mountPage()
    const search = wrapper.get('[data-id="hilos-table-search"]')
    const license = wrapper.get('[data-id="hilos-table-filter-license"]')
    const language = wrapper.get('[data-id="hilos-table-filter-language"]')
    expect(search.attributes('aria-label')).toBe(
      'Search a package or a license',
    )
    expect(
      license.findAll('[role="option"]').map((option) => option.text()),
    ).toEqual(['All licenses', 'Apache-2.0', 'MIT'])
    expect(
      language.findAll('[role="option"]').map((option) => option.text()),
    ).toEqual(['All languages', 'JS', 'PHP'])
    await search.setValue('  BOOT ')
    expect(packages(wrapper)).toEqual(['bootstrap'])
    await search.setValue('  mIt ')
    expect(packages(wrapper)).toEqual(['vue', 'bootstrap'])
    await chooseFilter(wrapper, 'license', 'MIT')
    await chooseFilter(wrapper, 'language', 'PHP')
    expect(packages(wrapper)).toEqual([])
    expect(wrapper.get('[data-id="hilos-table-no-matches"]').text()).toContain(
      'Nothing found',
    )
    const terms = wrapper.get('[data-id="hilos-table-no-matches-terms"]').text()
    expect(terms).toContain('mIt')
    expect(terms).toContain('MIT')
    expect(terms).toContain('PHP')
    for (const key of ['copy', 'download']) {
      expect(
        wrapper
          .get(`[data-id="hilos-table-action-${key}"]`)
          .attributes('disabled'),
      ).toBeUndefined()
    }
    await wrapper
      .get('[data-id="hilos-table-no-matches-reset"]')
      .trigger('click')
    expect(packages(wrapper)).toEqual([
      'vue',
      'bootstrap',
      'reactphp/event-loop',
    ])
    expect((search.element as HTMLInputElement).value).toBe('')
    expect(license.get('[aria-selected="true"]').text()).toBe('All licenses')
    expect(language.get('[aria-selected="true"]').text()).toBe('All languages')
    await chooseFilter(wrapper, 'language', 'PHP')
    expect(packages(wrapper)).toEqual(['reactphp/event-loop'])
    await chooseFilter(wrapper, 'license', 'Apache-2.0')
    expect(packages(wrapper)).toEqual(['reactphp/event-loop'])
    expect(wrapper.get('[data-id="hilos-table-count"]').text()).toBe(
      '1 – 1 of 1',
    )
  })

  it('offers the same filters in the shared narrow-screen dialog', async () => {
    const wrapper = mountPage()
    await wrapper.get('[data-id="hilos-table-filters-open"]').trigger('click')
    await chooseFilter(wrapper, 'language', 'PHP', true)
    await flushPromises()
    document
      .querySelector<HTMLButtonElement>('[data-id="hilos-table-filters-done"]')!
      .click()
    await flushPromises()
    expect(dialog()).toBeNull()
    expect(packages(wrapper)).toEqual(['reactphp/event-loop'])
    expect(wrapper.get('[data-id="hilos-table-cards"]').text()).toContain(
      'v1.5.0',
    )
  })

  it('distinguishes an empty build from a filtered result and keeps exports available', async () => {
    const wrapper = mountPage()
    await wrapper.setProps({ inventory: { ...inventory, entries: [] } })
    expect(wrapper.get('[data-id="hilos-table-empty-title"]').text()).toBe(
      'No dependencies in this build',
    )
    expect(wrapper.find('[data-id="hilos-table-empty-action"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-table-no-matches"]').exists()).toBe(
      false,
    )
    expect(
      wrapper.get('[data-id="hilos-table-action-copy"]').attributes('disabled'),
    ).toBeUndefined()
    expect(
      wrapper
        .get('[data-id="hilos-table-action-download"]')
        .attributes('disabled'),
    ).toBeUndefined()
  })

  it('opens exact plain license text and a secondary safe repository link', async () => {
    const wrapper = mountPage()
    await wrapper.get('table [data-id="license-package-open"]').trigger('click')
    expect(dialog()?.getAttribute('aria-label')).toBe('vue 3.5.35 · MIT')
    const text = document.querySelector('[data-id="license-text"]')!
    expect(text.textContent).toBe(inventory.entries[0].licenseText)
    expect(text.children).toHaveLength(0)
    expect(
      document.querySelector('[data-id="license-text-missing"]'),
    ).toBeNull()
    const repository = document.querySelector<HTMLAnchorElement>(
      '[data-id="license-repository"]',
    )!
    expect(repository.href).toBe('https://github.com/vuejs/core')
    expect(repository.target).toBe('_blank')
    expect(repository.rel).toBe('noopener noreferrer')
    expect(repository.classList.contains('btn-outline-secondary')).toBe(true)
    expect(wrapper.findAllComponents(HilosModal).at(-1)!.props('size')).toBe(
      'wide',
    )
  })

  it('opens a card without a license file with manifest words and a primary repository action', async () => {
    const wrapper = mountPage()
    await wrapper
      .get('[data-id="hilos-table-cards"]')
      .findAll('[data-id="license-package-open"]')[1]
      .trigger('click')
    expect(dialog()?.getAttribute('aria-label')).toBe('bootstrap 5.3.3 · MIT')
    expect(document.querySelector('[data-id="license-text"]')).toBeNull()
    expect(
      document
        .querySelector('[data-id="license-text-missing"]')
        ?.textContent?.replace(/\s+/g, ' ')
        .trim(),
    ).toBe(
      'This package ships no license file; the type above comes from its manifest',
    )
    expect(
      document
        .querySelector('[data-id="license-repository"]')
        ?.classList.contains('btn-primary'),
    ).toBe(true)
  })

  it('omits the repository action when no address is declared', async () => {
    const wrapper = mountPage()
    await wrapper
      .findAll('table [data-id="license-package-open"]')[2]
      .trigger('click')
    expect(document.querySelector('[data-id="license-repository"]')).toBeNull()
  })

  it.each(['Escape', 'cross', 'Close'])(
    'focuses the dialog and returns to its native package button on %s',
    async (close) => {
      const wrapper = mountPage()
      const opener = wrapper.get<HTMLButtonElement>(
        'table [data-id="license-package-open"]',
      )
      expect(opener.element.tagName).toBe('BUTTON')
      expect(opener.attributes('type')).toBe('button')
      opener.element.focus()
      // A native button's keyboard activation arrives as a click with detail=0.
      await opener.trigger('click', { detail: 0 })
      await flushPromises()
      expect(document.activeElement).toBe(dialog())
      expect(dialog()?.getAttribute('role')).toBe('dialog')
      expect(dialog()?.getAttribute('aria-modal')).toBe('true')
      const closeAction = [...dialog()!.querySelectorAll('button')].find(
        (button) => button.textContent?.trim() === 'Close',
      )!
      closeAction.focus()
      closeAction.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }),
      )
      expect(document.activeElement).toBe(
        document.querySelector('[data-id="modal-close"]'),
      )
      if (close === 'Escape') {
        dialog()!.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      } else if (close === 'cross') {
        document
          .querySelector<HTMLButtonElement>('[data-id="modal-close"]')!
          .click()
      } else {
        closeAction.click()
      }
      await flushPromises()
      expect(dialog()).toBeNull()
      expect(document.activeElement).toBe(opener.element)
    },
  )

  it('copies just the current rows, announces both outcomes and clears the same live region on filtering', async () => {
    const wrapper = mountPage()
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })
    const status = wrapper.get('[data-id="license-copy-status"]')
    expect(status.attributes('role')).toBe('status')
    expect(status.attributes('aria-live')).toBe('polite')
    expect(status.text()).toBe('')
    expect(wrapper.findAll('[data-id="license-copy-status"]')).toHaveLength(1)
    await wrapper.get('[data-id="hilos-table-search"]').setValue('BOOT')
    await wrapper.get('[data-id="hilos-table-action-copy"]').trigger('click')
    await flushPromises()
    expect(writeText).toHaveBeenLastCalledWith(
      'package,version,language,license\r\nbootstrap,5.3.3,JS,MIT\r\n',
    )
    expect(status.text()).toBe('Copied')
    await wrapper.get('[data-id="hilos-table-search"]').setValue('missing')
    expect(wrapper.get('[data-id="license-copy-status"]').element).toBe(
      status.element,
    )
    expect(status.text()).toBe('')
    writeText.mockRejectedValue(new Error('Clipboard refused'))
    await wrapper.get('[data-id="hilos-table-action-copy"]').trigger('click')
    await flushPromises()
    expect(writeText).toHaveBeenLastCalledWith(
      'package,version,language,license\r\n',
    )
    expect(status.text()).toBe(
      'The browser did not allow copying — use Download',
    )
  })

  it('downloads the current selection under the replacement project name, including a header-only empty list', async () => {
    const wrapper = mountPage()
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
    await chooseFilter(wrapper, 'language', 'PHP')
    await wrapper.setProps({
      inventory: { ...inventory, project: 'replacement' },
    })
    await wrapper
      .get('[data-id="hilos-table-action-download"]')
      .trigger('click')
    expect(names).toEqual(['replacement-license-hilos-framework.csv'])
    expect(await blobs[0].text()).toBe(
      'package,version,language,license\r\nreactphp/event-loop,v1.5.0,PHP,Apache-2.0\r\n',
    )
    expect(blobs[0].type).toBe('text/csv;charset=utf-8')
    await wrapper.get('[data-id="hilos-table-search"]').setValue('missing')
    await wrapper
      .get('[data-id="hilos-table-action-download"]')
      .trigger('click')
    expect(await blobs[1].text()).toBe('package,version,language,license\r\n')
    expect(revokeObjectURL).toHaveBeenCalledTimes(2)
  })

  it('keeps controller identity and filters, refreshes options and matches an open package by language/name/version', async () => {
    const wrapper = mountPage()
    const controller = controllerOf(wrapper)
    await chooseFilter(wrapper, 'license', 'MIT')
    await wrapper.get('table [data-id="license-package-open"]').trigger('click')
    const updated = entry({
      licenseText: 'Updated literal license',
      repository: null,
    })
    await wrapper.setProps({
      inventory: {
        ...inventory,
        entries: [
          entry({ language: 'php', licenseText: 'Wrong language' }),
          entry({ version: '4', licenseText: 'Wrong version' }),
          updated,
          entry({ name: 'new-package', license: 'BSD-3-Clause' }),
        ],
      },
    })
    expect(controllerOf(wrapper)).toBe(controller)
    expect(packages(wrapper)).toEqual(['vue', 'vue', 'vue'])
    expect(
      wrapper
        .get('[data-id="hilos-table-filter-license"] [aria-selected="true"]')
        .text(),
    ).toBe('MIT')
    expect(
      wrapper
        .get('[data-id="hilos-table-filter-license"]')
        .findAll('[role="option"]')
        .map((option) => option.text()),
    ).toEqual(['All licenses', 'BSD-3-Clause', 'MIT'])
    expect(
      document.querySelector('[data-id="license-text"]')?.textContent,
    ).toBe('Updated literal license')
    expect(document.querySelector('[data-id="license-repository"]')).toBeNull()
    await wrapper.setProps({
      inventory: {
        ...inventory,
        entries: [entry({ language: 'php' }), entry({ version: '4' })],
      },
    })
    await flushPromises()
    expect(dialog()).toBeNull()
    expect(packages(wrapper)).toEqual(['vue', 'vue'])
  })

  it('rejects late copy results after filtering, replacement and unmount and starts a fresh visit with all rows', async () => {
    const wrapper = mountPage()
    const pending: (() => void)[] = []
    vi.stubGlobal('navigator', {
      clipboard: {
        writeText: () => new Promise<void>((resolve) => pending.push(resolve)),
      },
    })
    const copy = wrapper.get('[data-id="hilos-table-action-copy"]')
    const status = wrapper.get('[data-id="license-copy-status"]')
    await copy.trigger('click')
    await wrapper.get('[data-id="hilos-table-search"]').setValue('vue')
    pending.shift()!()
    await flushPromises()
    expect(status.text()).toBe('')
    await copy.trigger('click')
    await wrapper.setProps({ inventory: { ...inventory, project: 'next' } })
    pending.shift()!()
    await flushPromises()
    expect(status.text()).toBe('')
    const controller = controllerOf(wrapper)
    const dispose = vi.spyOn(controller, 'dispose')
    await copy.trigger('click')
    wrapper.unmount()
    expect(dispose).toHaveBeenCalledOnce()
    expect(document.querySelector('[data-id="license-copy-status"]')).toBeNull()
    const next = mountPage()
    pending.shift()!()
    await flushPromises()
    expect(packages(next)).toEqual(['vue', 'bootstrap', 'reactphp/event-loop'])
    expect(next.get('[data-id="license-copy-status"]').text()).toBe('')
  })
})
