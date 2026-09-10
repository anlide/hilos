import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'
import type { HilosLicenseEntry } from '@hilos/core'

import { HilosLicensePage } from '../src/public/HilosLicensePage.js'

// The React peer of vue/src/public/HilosLicensePage.test.ts: the same cases by
// the same names, so a drift between the two view layers shows up as one of them
// failing rather than as a page nobody compared. The modal portals to <body>, so
// its assertions query the document rather than the render — the same as
// HilosModal's own test.
afterEach(() => {
  cleanup()
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

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function allById(id: string): HTMLElement[] {
  return [...document.querySelectorAll<HTMLElement>(`[data-id="${id}"]`)]
}

/** Mount the page and hand back nothing: every assertion reads the document. */
function mountPage(): void {
  render(<HilosLicensePage inventory={inventory} />)
}

/**
 * Click the row at `index`, the way the person does.
 *
 * @param index The row's position in the list currently shown.
 * @throws Error When the list is not that long.
 */
function clickRow(index: number): void {
  const row = allById('license-row')[index]
  if (row === undefined) {
    throw new Error(`the page is not showing a row at index ${index}`)
  }
  fireEvent.click(row)
}

/**
 * Type into a field or pick in a select, the way the person does.
 *
 * @param id The `data-id` the page renders on the control.
 * @param value The value the control is left holding.
 * @throws Error When this state does not render the control at all.
 */
function setValue(id: string, value: string): void {
  const control = byId(id)
  if (control === null) {
    throw new Error(`the page is not offering a control with data-id="${id}"`)
  }
  fireEvent.change(control, { target: { value } })
}

describe('HilosLicensePage', () => {
  it('draws one row per package the build stands on', () => {
    mountPage()
    const rows = allById('license-row')
    expect(rows).toHaveLength(3)
    expect(rows[0]?.textContent).toContain('vue')
    expect(rows[0]?.textContent).toContain('3.5.35')
    // The language wire value is labelled in one place and never shown raw.
    expect(rows[2]?.textContent).toContain('PHP')
    expect(rows[2]?.textContent).toContain('Apache-2.0')
    // The version is the lockfile's, `v` prefix and all.
    expect(rows[2]?.textContent).toContain('v1.5.0')
  })

  it('offers the licenses and languages the snapshot actually holds', () => {
    mountPage()
    const licenses = [
      ...(byId('license-filter-license')?.querySelectorAll('option') ?? []),
    ].map((option) => option.textContent)
    expect(licenses).toEqual(['All licenses', 'Apache-2.0', 'MIT'])
    const languages = [
      ...(byId('license-filter-language')?.querySelectorAll('option') ?? []),
    ].map((option) => option.textContent)
    expect(languages).toEqual(['All languages', 'JS', 'PHP'])
  })

  it('narrows the list by the search box', () => {
    mountPage()
    setValue('license-search', '  BOOT ')
    const rows = allById('license-row')
    expect(rows).toHaveLength(1)
    expect(rows[0]?.textContent).toContain('bootstrap')
  })

  it('narrows the list by the license select', () => {
    mountPage()
    setValue('license-filter-license', 'Apache-2.0')
    const rows = allById('license-row')
    expect(rows).toHaveLength(1)
    expect(rows[0]?.textContent).toContain('reactphp/event-loop')
  })

  it('says so when the filters admit nothing, and keeps the bar and the buttons', () => {
    mountPage()
    setValue('license-search', 'nothing-here')
    expect(allById('license-row')).toHaveLength(0)
    expect(byId('license-empty')?.textContent).toBe(
      'Nothing in this build matches these filters',
    )
    // The empty result is the page's most valuable answer, so the choice that
    // produced it stays visible and removable, and the export stays live.
    expect(byId('license-search')).not.toBeNull()
    expect(byId('license-copy')?.hasAttribute('disabled')).toBe(false)
    expect(byId('license-download')?.hasAttribute('disabled')).toBe(false)
  })

  it('opens a row on its own license text', () => {
    mountPage()
    clickRow(0)
    expect(byId('modal')).not.toBeNull()
    expect(byId('modal')?.getAttribute('aria-label')).toBe('vue 3.5.35 · MIT')
    expect(byId('license-text')?.textContent).toContain('The MIT License (MIT)')
    expect(byId('license-text-missing')).toBeNull()
    expect(byId('license-repository')?.getAttribute('href')).toBe(
      'https://github.com/vuejs/core',
    )
  })

  it('opens a package that ships no license file on the second state', () => {
    mountPage()
    clickRow(1)
    expect(byId('license-text')).toBeNull()
    expect(byId('license-text-missing')?.textContent).toContain(
      'This package ships no license file',
    )
    // The row itself stayed truthful — the type is in the header above.
    expect(byId('modal')?.getAttribute('aria-label')).toBe(
      'bootstrap 5.3.3 · MIT',
    )
  })

  it('leaves out the repository button when the package declares no address', () => {
    mountPage()
    clickRow(2)
    // An absent action, not a disabled one: there is nothing to grey out.
    expect(byId('license-repository')).toBeNull()
  })

  it('renders the copy status region before it has anything to say', () => {
    mountPage()
    const status = byId('license-copy-status')
    expect(status?.getAttribute('role')).toBe('status')
    expect(status?.getAttribute('aria-live')).toBe('polite')
    expect(status?.textContent).toBe('')
  })
})
