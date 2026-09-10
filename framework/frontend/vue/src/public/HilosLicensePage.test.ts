import { mount } from '@vue/test-utils'
import type { HilosLicenseEntry } from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'

import HilosLicensePage from './HilosLicensePage.vue'

// The modal teleports to <body>, so its assertions query the document rather
// than the wrapper — the same as HilosModal's own test.
afterEach(() => {
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

describe('HilosLicensePage', () => {
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
})
