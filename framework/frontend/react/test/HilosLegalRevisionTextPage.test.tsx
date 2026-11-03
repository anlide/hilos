import { cleanup } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosPages } from '@hilos/core/base'
import { HilosLegalRevisionTextPage } from '../src/admin/legal/HilosLegalRevisionTextPage.js'
import { legalRevisionTestHarness } from './legalRevisionTestFixture.js'

afterEach(cleanup)

describe('React Revision Text', () => {
  it('renders the selected historical clauses in composed order with its deviation in place', () => {
    const h = legalRevisionTestHarness(HilosPages.LEGAL_REVISION_TEXT)
    const view = h.mount(<HilosLegalRevisionTextPage context={h.context} />)
    const clauses = view.container.querySelectorAll(
      '[data-id="legal-revision-text"] li',
    )
    expect(
      Array.from(clauses).map(
        (node) => node.querySelector('strong')?.textContent,
      ),
    ).toEqual([
      'Historical first.',
      'Historical deviation.',
      'Historical last.',
    ])
    expect(clauses[1]?.textContent).toContain(
      'The historical project retention.',
    )
    expect(
      clauses[1]?.querySelector('[data-id="legal-revision-clause-deviation"]')
        ?.textContent,
    ).toBe('Project deviation from: Limited retention · stricter')
    expect(view.container.querySelector('[data-id="legal-changes"]')).toBeNull()
  })

  it('mounts without a table window, viewport, counter or action', () => {
    const h = legalRevisionTestHarness(HilosPages.LEGAL_REVISION_TEXT)
    const view = h.mount(
      <HilosLegalRevisionTextPage context={h.context} />,
      true,
    )
    expect(h.liveWindows()).toEqual({})
    expect(h.register).not.toHaveBeenCalled()
    expect(h.listenerCount()).toBe(0)
    expect(h.sendViewport).not.toHaveBeenCalled()
    expect(h.dispatch).not.toHaveBeenCalled()
    expect(
      view.container.querySelectorAll('table, button, input, select, form'),
    ).toHaveLength(0)
    expect(
      view.container.querySelector('[data-id="legal-revision-accepted"]'),
    ).toBeNull()
    expect(view.queryByRole('link', { name: 'Open acceptances' })).toBeNull()
    view.unmount()
    expect(h.liveWindows()).toEqual({})
    expect(h.listenerCount()).toBe(0)
  })
})
