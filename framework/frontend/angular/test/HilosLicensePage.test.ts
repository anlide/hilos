// The Angular peer of vue/src/public/HilosLicensePage.test.ts and
// react/test/HilosLicensePage.test.tsx: the same cases by the same names, so a
// drift between the three view layers shows up as one of them failing rather
// than as a page nobody compared. What is Angular's own is the mount (TestBed),
// the fact that a frame is read by running change detection, and that the modal
// renders in place rather than through a portal — one root covers the page and
// its dialog alike.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import type { HilosLicenseEntry } from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosLicensePage } from '../src/public/HilosLicensePage.js'

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

/**
 * Mount the page with the snapshot a project's build hands it.
 *
 * @returns The mounted fixture, already rendered once.
 */
function mountPage(): ComponentFixture<HilosLicensePage> {
  const fixture = TestBed.createComponent(HilosLicensePage)
  fixture.componentRef.setInput('inventory', inventory)
  fixture.detectChanges()

  return fixture
}

/**
 * Find a node of the mounted page by its stable test id.
 *
 * @param fixture The mounted page to look inside.
 * @param id The `data-id` the page renders on the node.
 * @returns The node, or null when this state does not render it.
 */
function byId(
  fixture: ComponentFixture<HilosLicensePage>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

/**
 * Every node of the mounted page carrying one test id.
 *
 * @param fixture The mounted page to look inside.
 * @param id The `data-id` the page renders on the nodes.
 * @returns The nodes, in document order.
 */
function allById(
  fixture: ComponentFixture<HilosLicensePage>,
  id: string,
): HTMLElement[] {
  return [
    ...(fixture.nativeElement as HTMLElement).querySelectorAll<HTMLElement>(
      `[data-id="${id}"]`,
    ),
  ]
}

/**
 * Click the row at `index`, the way the person does, and render what follows.
 *
 * @param fixture The mounted page to click inside.
 * @param index The row's position in the list currently shown.
 * @throws Error When the list is not that long.
 */
function clickRow(
  fixture: ComponentFixture<HilosLicensePage>,
  index: number,
): void {
  const row = allById(fixture, 'license-row')[index]
  if (row === undefined) {
    throw new Error(`the page is not showing a row at index ${index}`)
  }
  row.click()
  fixture.detectChanges()
}

/**
 * Type into a field or pick in a select, then render what follows.
 *
 * @param fixture The mounted page to act on.
 * @param id The `data-id` the page renders on the control.
 * @param value The value the control is left holding.
 * @param event The event the control announces the change with.
 * @throws Error When this state does not render the control at all.
 */
function setValue(
  fixture: ComponentFixture<HilosLicensePage>,
  id: string,
  value: string,
  event: 'input' | 'change',
): void {
  const control = byId(fixture, id)
  if (control === null) {
    throw new Error(`the page is not offering a control with data-id="${id}"`)
  }
  ;(control as HTMLInputElement | HTMLSelectElement).value = value
  control.dispatchEvent(new Event(event))
  fixture.detectChanges()
}

describe('HilosLicensePage', () => {
  it('draws one row per package the build stands on', () => {
    const fixture = mountPage()
    const rows = allById(fixture, 'license-row')
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
    const fixture = mountPage()
    const licenses = [
      ...(byId(fixture, 'license-filter-license')?.querySelectorAll('option') ??
        []),
    ].map((option) => option.textContent?.trim())
    expect(licenses).toEqual(['All licenses', 'Apache-2.0', 'MIT'])
    const languages = [
      ...(byId(fixture, 'license-filter-language')?.querySelectorAll(
        'option',
      ) ?? []),
    ].map((option) => option.textContent?.trim())
    expect(languages).toEqual(['All languages', 'JS', 'PHP'])
  })

  it('narrows the list by the search box', () => {
    const fixture = mountPage()
    setValue(fixture, 'license-search', '  BOOT ', 'input')
    const rows = allById(fixture, 'license-row')
    expect(rows).toHaveLength(1)
    expect(rows[0]?.textContent).toContain('bootstrap')
  })

  it('narrows the list by the license select', () => {
    const fixture = mountPage()
    setValue(fixture, 'license-filter-license', 'Apache-2.0', 'change')
    const rows = allById(fixture, 'license-row')
    expect(rows).toHaveLength(1)
    expect(rows[0]?.textContent).toContain('reactphp/event-loop')
  })

  it('says so when the filters admit nothing, and keeps the bar and the buttons', () => {
    const fixture = mountPage()
    setValue(fixture, 'license-search', 'nothing-here', 'input')
    expect(allById(fixture, 'license-row')).toHaveLength(0)
    expect(byId(fixture, 'license-empty')?.textContent?.trim()).toBe(
      'Nothing in this build matches these filters',
    )
    // The empty result is the page's most valuable answer, so the choice that
    // produced it stays visible and removable, and the export stays live.
    expect(byId(fixture, 'license-search')).not.toBeNull()
    expect(byId(fixture, 'license-copy')?.hasAttribute('disabled')).toBe(false)
    expect(byId(fixture, 'license-download')?.hasAttribute('disabled')).toBe(
      false,
    )
  })

  it('opens a row on its own license text', () => {
    const fixture = mountPage()
    clickRow(fixture, 0)
    expect(byId(fixture, 'modal')).not.toBeNull()
    expect(byId(fixture, 'modal')?.getAttribute('aria-label')).toBe(
      'vue 3.5.35 · MIT',
    )
    expect(byId(fixture, 'license-text')?.textContent).toContain(
      'The MIT License (MIT)',
    )
    expect(byId(fixture, 'license-text-missing')).toBeNull()
    expect(byId(fixture, 'license-repository')?.getAttribute('href')).toBe(
      'https://github.com/vuejs/core',
    )
  })

  it('opens a package that ships no license file on the second state', () => {
    const fixture = mountPage()
    clickRow(fixture, 1)
    expect(byId(fixture, 'license-text')).toBeNull()
    expect(byId(fixture, 'license-text-missing')?.textContent).toContain(
      'This package ships no license file',
    )
    // The row itself stayed truthful — the type is in the header above.
    expect(byId(fixture, 'modal')?.getAttribute('aria-label')).toBe(
      'bootstrap 5.3.3 · MIT',
    )
  })

  it('leaves out the repository button when the package declares no address', () => {
    const fixture = mountPage()
    clickRow(fixture, 2)
    // An absent action, not a disabled one: there is nothing to grey out.
    expect(byId(fixture, 'license-repository')).toBeNull()
  })

  it('renders the copy status region before it has anything to say', () => {
    const fixture = mountPage()
    const status = byId(fixture, 'license-copy-status')
    expect(status?.getAttribute('role')).toBe('status')
    expect(status?.getAttribute('aria-live')).toBe('polite')
    expect(status?.textContent).toBe('')
  })
})
