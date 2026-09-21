// @vitest-environment node

import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'

import { HilosModal } from '../src/HilosModal.js'
import { HilosLicensePage } from '../src/public/HilosLicensePage.js'

const inventory = {
  project: 'demo-tasks',
  entries: [
    {
      name: 'react',
      version: '19.2.7',
      language: 'js' as const,
      license: 'MIT',
      licenseText: null,
      repository: 'https://github.com/facebook/react',
    },
  ],
}

describe('React server rendering', () => {
  it('renders a closed modal without a browser document', () => {
    const html = renderToStaticMarkup(<HilosModal open={false} />)

    expect(html).toBe('')
  })

  it('renders the license page without a browser document', () => {
    const html = renderToStaticMarkup(
      <HilosLicensePage inventory={inventory}>
        Project license
      </HilosLicensePage>,
    )

    expect(html).toContain('Project license')
    expect(html).toContain('react')
    expect(html).toContain('19.2.7')
  })
})
