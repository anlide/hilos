import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'
import type { HilosLicenseInventory } from '@hilos/core/base'
import type { HilosStaticPageNavigation } from '@hilos/core/base'

import { HilosLicensePage } from '../src/public/HilosLicensePage.js'

afterEach(cleanup)

const inventory: HilosLicenseInventory = {
  project: 'demo-tasks',
  projectLicenseText:
    'Custom Project License\n\n  Indented clause   with spaces\nTrailing newline\n',
  entries: [
    {
      name: 'react',
      version: '19.2.7',
      language: 'js',
      license: 'MIT',
      licenseText: null,
      repository: 'https://github.com/facebook/react',
    },
  ],
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
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
  it('renders its exact head, project text and reactive optional navigation', () => {
    const { rerender } = render(<HilosLicensePage inventory={inventory} />)

    expect(document.querySelectorAll('h1')).toHaveLength(1)
    const heading = byId('hilos-page-head-title')
    expect(heading?.textContent).toBe('License')
    const icon = byId('hilos-page-head-block')?.querySelector(
      'i.bi-journal-code',
    )
    expect(icon?.parentElement?.getAttribute('aria-hidden')).toBe('true')
    expect(byId('hilos-page-head-block')?.querySelector('p')?.textContent).toBe(
      'The license of this project, and of everything it is built on.',
    )

    const projectText = byId('license-project-text')
    expect(projectText?.textContent).toBe(inventory.projectLicenseText)
    expect(projectText?.children).toHaveLength(0)
    expect(
      document.querySelector('input, select, table, [role="dialog"]'),
    ).toBeNull()
    expect(byId('hilos-viewport-table')).toBeNull()
    expect(byId('license-package-open')).toBeNull()
    expect(byId('license-copy-status')).toBeNull()

    rerender(
      <HilosLicensePage
        inventory={inventory}
        navigation={publicNavigation()}
      />,
    )
    const tabs = byId('hilos-page-tabs')
    expect(tabs?.getAttribute('aria-label')).toBe('Public pages')
    expect(
      [...(tabs?.querySelectorAll('a') ?? [])].map((link) => [
        link.textContent,
        link.getAttribute('href'),
      ]),
    ).toEqual([
      ['Public page', '/projects/first/fixture_public_page'],
      ['Revision history', '/projects/first/fixture_history'],
    ])
    expect(
      tabs?.querySelector('[aria-current="page"]')?.getAttribute('data-id'),
    ).toBe('hilos-page-tab-fixture_public_page')

    rerender(
      <HilosLicensePage
        inventory={inventory}
        navigation={publicNavigation('fixture_history', 'next')}
      />,
    )
    expect(byId('hilos-page-head-title')).toBe(heading)
    expect(
      tabs?.querySelector('[aria-current="page"]')?.getAttribute('data-id'),
    ).toBe('hilos-page-tab-fixture_history')
    expect(
      tabs
        ?.querySelector('[data-id="hilos-page-tab-fixture_history"]')
        ?.getAttribute('href'),
    ).toBe('/projects/next/fixture_history')
  })

  it('renders markup in project license text as plain text', () => {
    const projectLicenseText =
      'Custom License with <script>alert("xss")</script> and <b>bold</b>'
    render(
      <HilosLicensePage inventory={{ ...inventory, projectLicenseText }} />,
    )

    const pre = byId('license-project-text')
    expect(pre?.textContent).toBe(projectLicenseText)
    expect(pre?.children).toHaveLength(0)
  })
})
