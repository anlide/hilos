import { enableAutoUnmount, mount } from '@vue/test-utils'
import type {
  HilosLicenseEntry,
  HilosStaticPageNavigation,
} from '@hilos/core/base'
import { afterEach, describe, expect, it } from 'vitest'

import HilosLicensePage from './HilosLicensePage.vue'

enableAutoUnmount(afterEach)

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

  it('renders the project license text verbatim without inventory controls', () => {
    const wrapper = mountPage()
    const pre = wrapper.find<HTMLElement>(
      '[data-id="license-project-text"]',
    ).element
    expect(pre.textContent).toBe(inventory.projectLicenseText)
    expect(wrapper.find('input, select, table, [role="dialog"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-viewport-table"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="license-copy-status"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="license-package-open"]').exists()).toBe(
      false,
    )
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
