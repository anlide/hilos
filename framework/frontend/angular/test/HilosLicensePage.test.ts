// The Angular peer of the Vue and React public License page tests. The License
// page owns the project's literal text; the dependency inventory has its own
// page and component suite.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { HILOS_LICENSE_TABS, HilosPages } from '@hilos/core/base'
import type {
  HilosLicenseInventory,
  HilosStaticPageNavigation,
} from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosLicensePage } from '../src/public/HilosLicensePage.js'

const inventory: HilosLicenseInventory = {
  project: 'demo-polls',
  projectLicenseText:
    'Custom Project License\n\n  Indented clause   with spaces\nTrailing newline\n',
  entries: [],
}

afterEach(() => {
  vi.unstubAllGlobals()
})

function mountPage(
  pageInventory: HilosLicenseInventory = inventory,
): ComponentFixture<HilosLicensePage> {
  const fixture = TestBed.createComponent(HilosLicensePage)
  fixture.componentRef.setInput('inventory', pageInventory)
  fixture.detectChanges()

  return fixture
}

function byId(
  fixture: ComponentFixture<HilosLicensePage>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

function navigation(page: string): HilosStaticPageNavigation {
  return {
    page,
    tabs: HILOS_LICENSE_TABS,
    resolvePath: (target) =>
      target === HilosPages.LICENSE ? '/license' : '/license/dependencies',
  }
}

describe('HilosLicensePage', () => {
  it('renders one License heading and the exact project text without inventory UI', () => {
    const fixture = mountPage()
    const container = fixture.nativeElement as HTMLElement
    const heading = container.querySelector('h1')

    expect(container.querySelectorAll('h1')).toHaveLength(1)
    expect(heading?.textContent?.trim()).toBe('License')
    expect(byId(fixture, 'license-project-text')?.textContent).toBe(
      inventory.projectLicenseText,
    )
    expect(byId(fixture, 'hilos-viewport-table')).toBeNull()
    expect(byId(fixture, 'license-search')).toBeNull()
    expect(byId(fixture, 'license-copy')).toBeNull()
    expect(byId(fixture, 'license-download')).toBeNull()
    expect(byId(fixture, 'modal')).toBeNull()
  })

  it('renders markup in the project text as literal text', () => {
    const projectLicenseText =
      'Custom License with <script>alert("xss")</script> and <b>bold</b>'
    const fixture = mountPage({ ...inventory, projectLicenseText })
    const pre = byId(fixture, 'license-project-text')

    expect(pre?.textContent).toBe(projectLicenseText)
    expect(pre?.children).toHaveLength(0)
  })

  it('forwards public navigation and marks the current License page', () => {
    const fixture = mountPage()
    fixture.componentRef.setInput('navigation', navigation(HilosPages.LICENSE))
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
      'License',
    )

    fixture.componentRef.setInput(
      'navigation',
      navigation(HilosPages.LICENSE_DEPENDENCIES),
    )
    fixture.detectChanges()
    expect(tabs?.querySelector('[aria-current="page"]')?.textContent).toBe(
      'Dependencies',
    )
  })
})
