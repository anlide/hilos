import { HilosPages } from '@hilos/core/base'
import { describe, expect, it } from 'vitest'
import HilosLegalRevisionTextPage from './HilosLegalRevisionTextPage.vue'
import { revisionHarness } from '../../../test/legalRevisionFixture.js'

describe('Revision Text', () => {
  it('renders the selected historical clauses in composed order with its deviation in place', () => {
    const h = revisionHarness(
      HilosLegalRevisionTextPage,
      HilosPages.LEGAL_REVISION_TEXT,
    )
    try {
      const clauses = h.view
        .get('[data-id="legal-revision-text"]')
        .findAll('li')
      expect(clauses.map((node) => node.get('strong').text())).toEqual([
        'Historical first.',
        'Historical deviation.',
        'Historical last.',
      ])
      expect(clauses[1]!.text()).toContain('The historical project retention.')
      expect(
        clauses[1]!.get('[data-id="legal-revision-clause-deviation"]').text(),
      ).toBe('Project deviation from: Limited retention · stricter')
      expect(h.view.find('[data-id="legal-changes"]').exists()).toBe(false)
    } finally {
      h.view.unmount()
    }
  })

  it('mounts without a table window, viewport, counter or action', () => {
    const h = revisionHarness(
      HilosLegalRevisionTextPage,
      HilosPages.LEGAL_REVISION_TEXT,
    )
    try {
      expect(h.registered.size).toBe(0)
      expect(h.listenerCount()).toBe(0)
      expect(h.sendViewport).not.toHaveBeenCalled()
      expect(h.dispatch).not.toHaveBeenCalled()
      expect(h.view.findAll('table, button, input, select, form')).toHaveLength(
        0,
      )
      expect(h.view.find('[data-id="legal-revision-accepted"]').exists()).toBe(
        false,
      )
      expect(h.view.find('[data-id="legal-open-acceptances"]').exists()).toBe(
        false,
      )
    } finally {
      h.view.unmount()
    }
  })
})
