// @vitest-environment node

import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'

import HilosModal from './HilosModal.vue'
import HilosLicensePage from './public/HilosLicensePage.vue'

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

  it('renders the license page without a browser document', async () => {
    const html = await renderToString(
      createSSRApp({
        render: () => h(HilosLicensePage, { inventory }),
      }),
    )

    expect(html).toContain('Project license')
    expect(html).toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
    expect(html).not.toContain('<script>alert(1)</script>')
    expect(html).toContain('vue')
    expect(html).toContain('3.5.35')
  })
})
