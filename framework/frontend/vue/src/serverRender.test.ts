// @vitest-environment node

import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { afterEach, describe, expect, it, vi } from 'vitest'

import HilosModal from './HilosModal.vue'
import HilosLicensePage from './public/HilosLicensePage.vue'
import HilosLicenseDependenciesPage from './public/HilosLicenseDependenciesPage.vue'

afterEach(() => vi.unstubAllGlobals())

const inventory = {
  project: 'demo-chat',
  projectLicenseText: 'Project license <script>alert(1)</script>',
  entries: [
    {
      name: 'vue',
      version: '3.5.35',
      language: 'js' as const,
      license: 'MIT',
      licenseText: null,
      repository: 'https://github.com/vuejs/core',
    },
    {
      name: 'reactphp/event-loop',
      version: 'v1.5.0',
      language: 'php' as const,
      license: 'Apache-2.0',
      licenseText: null,
      repository: null,
    },
  ],
}

describe('Vue server rendering', () => {
  it('renders a closed modal without a browser document', async () => {
    const html = await renderToString(
      createSSRApp({
        render: () => h(HilosModal, { modelValue: false }),
      }),
    )

    expect(html).not.toContain('data-id="modal"')
  })

  it('renders the exact escaped license document separately from the inventory', async () => {
    vi.stubGlobal('document', undefined)
    vi.stubGlobal('window', undefined)
    vi.stubGlobal('navigator', undefined)
    const html = await renderToString(
      createSSRApp({
        render: () => h(HilosLicensePage, { inventory }),
      }),
    )

    expect(html).toContain('Project license')
    expect(html).toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
    expect(html).not.toContain('<script>alert(1)</script>')
    expect(html).toContain('data-id="license-project-text"')
    expect(html).not.toContain('license-package-open')
    expect(html).not.toContain('hilos-table-search')
    expect(html).not.toContain('Copy the list')
    expect(html.match(/<h1\b/g)).toHaveLength(1)
  })

  it('renders the full dependencies window, filters and both exports without browser globals', async () => {
    vi.stubGlobal('document', undefined)
    vi.stubGlobal('window', undefined)
    vi.stubGlobal('navigator', undefined)
    const html = await renderToString(
      createSSRApp({
        render: () => h(HilosLicenseDependenciesPage, { inventory }),
      }),
    )

    expect(html).not.toContain('license-project-text')
    expect(html).not.toContain('Project license')
    expect(html).toContain('vue')
    expect(html).toContain('3.5.35')
    expect(html).toContain('reactphp/event-loop')
    expect(html).toContain('v1.5.0')
    expect(html).toContain('PHP')
    expect(html).toContain('Apache-2.0')
    expect(html).toContain('1 – 2 of 2')
    expect(html).toContain('Search a package or a license')
    expect(html).toContain('All licenses')
    expect(html).toContain('All languages')
    expect(html).toContain('Copy the list')
    expect(html).toContain('Download as a file')
    expect(html.match(/data-id="license-package-open"/g)).toHaveLength(4)
    expect(html).not.toContain('data-id="modal"')
    expect(html).not.toContain('data-id="hilos-table-loading"')
    expect(html.match(/<h1\b/g)).toHaveLength(1)
  })
})
