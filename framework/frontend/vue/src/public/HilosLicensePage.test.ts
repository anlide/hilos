import { mount } from '@vue/test-utils'
import type {
  HilosLicenseEntry,
  HilosStaticPageNavigation,
} from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'

import HilosLicensePage from './HilosLicensePage.vue'

// The modal teleports to <body>, so its assertions query the document rather
// than the wrapper — the same as HilosModal's own test.
afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

function entry(over: Partial<HilosLicenseEntry> = {}): HilosLicenseEntry {
  return {
    name: 'vue',
    version: '3.5.35',
    language: 'js',
    license: 'MIT',
    licenseText: 'The MIT License (MIT)\n\nCopyright (c) 2018-present',
    repository: 'https://github.com/vuejs/core',
    ...over,
  }
}

const inventory = {
  project: 'demo-chat',
  projectLicenseText:
    'Custom Project License\n\n  Indented clause   with spaces\nTrailing newline\n',
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

function mountPage(): ReturnType<typeof mount> {
  return mount(HilosLicensePage, { props: { inventory } })
}

function publicNavigation(
  page = 'fixture_public_page',
  project = 'first',
): HilosStaticPageNavigation {
  return {
    page,
    tabs: [
      { page: 'fixture_public_page', label: 'Public page' },
      { page: 'fixture_history', label: 'Revision history' },
    ],
    params: { project },
    resolvePath: (target, params = {}) =>
      `/projects/${params.project}/${target}`,
    label: 'Public pages',
  }
}

describe('HilosLicensePage', () => {
  it('renders its exact public head and forwards optional navigation reactively', async () => {
    const wrapper = mountPage()

    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('[data-id="hilos-page-head-title"]').text()).toBe(
      'License',
    )
    const icon = wrapper.get(
      '[data-id="hilos-page-head-block"] i.bi-journal-code',
    )
    expect(icon.element.parentElement?.getAttribute('aria-hidden')).toBe('true')
    expect(wrapper.get('[data-id="hilos-page-head-block"] p').text()).toBe(
      'The license of this project, and of everything it is built on.',
    )
    expect(wrapper.find('[data-id="hilos-breadcrumb"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)

    await wrapper.setProps({ navigation: publicNavigation() })
    const tabs = wrapper.get('[data-id="hilos-page-tabs"]')
    expect(tabs.attributes('aria-label')).toBe('Public pages')
    expect(
      tabs.findAll('a').map((link) => [link.text(), link.attributes('href')]),
    ).toEqual([
      ['Public page', '/projects/first/fixture_public_page'],
      ['Revision history', '/projects/first/fixture_history'],
    ])
    expect(tabs.get('[aria-current="page"]').attributes('data-id')).toBe(
      'hilos-page-tab-fixture_public_page',
    )

    await wrapper.setProps({
      navigation: publicNavigation('fixture_history', 'next'),
    })
    expect(tabs.get('[aria-current="page"]').attributes('data-id')).toBe(
      'hilos-page-tab-fixture_history',
    )
    expect(
      tabs.get('[data-id="hilos-page-tab-fixture_history"]').attributes('href'),
    ).toBe('/projects/next/fixture_history')
  })

  it('draws one row per package the build stands on', () => {
    const wrapper = mountPage()
    const rows = wrapper.findAll('[data-id="license-row"]')
    expect(rows).toHaveLength(3)
    expect(rows[0].text()).toContain('vue')
    expect(rows[0].text()).toContain('3.5.35')
    // The language wire value is labelled in one place and never shown raw.
    expect(rows[2].text()).toContain('PHP')
    expect(rows[2].text()).toContain('Apache-2.0')
    // The version is the lockfile's, `v` prefix and all.
    expect(rows[2].text()).toContain('v1.5.0')
  })

  it('offers the licenses and languages the snapshot actually holds', () => {
    const wrapper = mountPage()
    const licenses = wrapper
      .find('[data-id="license-filter-license"]')
      .findAll('option')
      .map((option) => option.text())
    expect(licenses).toEqual(['All licenses', 'Apache-2.0', 'MIT'])
    const languages = wrapper
      .find('[data-id="license-filter-language"]')
      .findAll('option')
      .map((option) => option.text())
    expect(languages).toEqual(['All languages', 'JS', 'PHP'])
  })

  it('narrows the list by the search box', async () => {
    const wrapper = mountPage()
    await wrapper.find('[data-id="license-search"]').setValue('  BOOT ')
    const rows = wrapper.findAll('[data-id="license-row"]')
    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('bootstrap')
  })

  it('narrows the list by the license select', async () => {
    const wrapper = mountPage()
    await wrapper
      .find('[data-id="license-filter-license"]')
      .setValue('Apache-2.0')
    const rows = wrapper.findAll('[data-id="license-row"]')
    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('reactphp/event-loop')
  })

  it('says so when the filters admit nothing, and keeps the bar and the buttons', async () => {
    const wrapper = mountPage()
    await wrapper.find('[data-id="license-search"]').setValue('nothing-here')
    expect(wrapper.findAll('[data-id="license-row"]')).toHaveLength(0)
    expect(wrapper.find('[data-id="license-empty"]').text()).toBe(
      'Nothing in this build matches these filters',
    )
    // The empty result is the page's most valuable answer, so the choice that
    // produced it stays visible and removable, and the export stays live.
    expect(wrapper.find('[data-id="license-search"]').exists()).toBe(true)
    const copy = wrapper.find('[data-id="license-copy"]')
    expect(copy.exists()).toBe(true)
    expect(copy.attributes('disabled')).toBeUndefined()
    const download = wrapper.find('[data-id="license-download"]')
    expect(download.attributes('disabled')).toBeUndefined()
  })

  it('opens a row on its own license text', async () => {
    const wrapper = mountPage()
    await wrapper.findAll('[data-id="license-row"]')[0].trigger('click')
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(
      document.querySelector('[data-id="modal"]')?.getAttribute('aria-label'),
    ).toBe('vue 3.5.35 · MIT')
    expect(
      document.querySelector('[data-id="license-text"]')?.textContent,
    ).toContain('The MIT License (MIT)')
    expect(
      document.querySelector('[data-id="license-text-missing"]'),
    ).toBeNull()
    expect(
      document
        .querySelector('[data-id="license-repository"]')
        ?.getAttribute('href'),
    ).toBe('https://github.com/vuejs/core')
  })

  it('opens the license text in a wide dialog', async () => {
    const wrapper = mountPage()
    await wrapper.findAll('[data-id="license-row"]')[0].trigger('click')
    expect(
      document.querySelector('.modal-dialog')?.classList.contains('modal-lg'),
    ).toBe(true)
  })

  it('opens a package that ships no license file on the second state', async () => {
    const wrapper = mountPage()
    await wrapper.findAll('[data-id="license-row"]')[1].trigger('click')
    expect(document.querySelector('[data-id="license-text"]')).toBeNull()
    expect(
      document.querySelector('[data-id="license-text-missing"]')?.textContent,
    ).toContain('This package ships no license file')
    // The row itself stayed truthful — the type is in the header above.
    expect(
      document.querySelector('[data-id="modal"]')?.getAttribute('aria-label'),
    ).toBe('bootstrap 5.3.3 · MIT')
  })

  it('leaves out the repository button when the package declares no address', async () => {
    const wrapper = mountPage()
    await wrapper.findAll('[data-id="license-row"]')[2].trigger('click')
    // An absent action, not a disabled one: there is nothing to grey out.
    expect(document.querySelector('[data-id="license-repository"]')).toBeNull()
  })

  it('renders the copy status region before it has anything to say', () => {
    const wrapper = mountPage()
    const status = wrapper.find('[data-id="license-copy-status"]')
    expect(status.attributes('role')).toBe('status')
    expect(status.attributes('aria-live')).toBe('polite')
    expect(status.text()).toBe('')
  })

  it("hands the list over under the project's own file name", async () => {
    const wrapper = mountPage()

    const anchor = {
      href: '',
      download: '',
      click: vi.fn(),
      remove: vi.fn(),
    }
    vi.stubGlobal('document', {
      createElement: vi.fn(() => anchor),
      body: { appendChild: vi.fn() },
    })
    vi.stubGlobal('URL', {
      createObjectURL: vi.fn(() => 'blob:hilos/test'),
      revokeObjectURL: vi.fn(),
    })

    await wrapper.find('[data-id="license-download"]').trigger('click')

    expect(anchor.download).toBe('demo-chat-license-hilos-framework.csv')
    expect(anchor.click).toHaveBeenCalledOnce()
  })

  it('renders the project license text verbatim before the inventory list', () => {
    const wrapper = mountPage()
    const pre = wrapper.find<HTMLElement>(
      '[data-id="license-project-text"]',
    ).element
    expect(pre.textContent).toBe(inventory.projectLicenseText)
  })

  it('renders markup in project license text as plain text and produces no nested elements', () => {
    const markupText =
      'Custom License with <script>alert("xss")</script> and <b>bold</b>'
    const wrapper = mount(HilosLicensePage, {
      props: {
        inventory: {
          ...inventory,
          projectLicenseText: markupText,
        },
      },
    })
    const pre = wrapper.find<HTMLElement>(
      '[data-id="license-project-text"]',
    ).element
    expect(pre.textContent).toBe(markupText)
    expect(pre.children.length).toBe(0)
  })
})
