import { HilosPages } from '@hilos/core/base'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import HilosLegalRevisionPage from './HilosLegalRevisionPage.vue'
import {
  acceptanceRows,
  legalRevision,
  revisionHarness,
} from '../../../test/legalRevisionFixture.js'

describe('Revision Overview', () => {
  it('shows the selected comparison or the first-revision state without assembled text', async () => {
    const h = revisionHarness(HilosLegalRevisionPage)
    try {
      expect(h.view.get('[data-id="legal-changes"]').text()).toContain(
        'Previous retention',
      )
      expect(h.view.get('[data-id="legal-changes"]').text()).toContain(
        'Historical deviation',
      )
      expect(h.view.find('[data-id="legal-revision-text"]').exists()).toBe(
        false,
      )
      h.scope.data.set('legalRevision', {
        ...legalRevision,
        predecessorId: null,
        changes: null,
      })
      await nextTick()
      expect(h.view.find('[data-id="legal-changes"]').exists()).toBe(false)
      expect(h.view.text()).toContain(
        'This is the first revision; there is nothing to compare it with.',
      )
    } finally {
      h.view.unmount()
    }
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
  })

  it('distinguishes loading, confirmed zero, refusal and live exact acceptance updates', async () => {
    const h = revisionHarness(
      HilosLegalRevisionPage,
      HilosPages.LEGAL_REVISION,
      { window: false },
    )
    try {
      const summary = () =>
        h.view.get('[data-id="legal-revision-accepted"]').text()
      expect(summary()).toBe('Loading acceptance count…')
      h.window([])
      await nextTick()
      expect(summary()).toBe('0 acceptances')
      h.refuse()
      await nextTick()
      expect(summary()).toBe('Acceptance count unavailable.')
      h.window(acceptanceRows(8))
      await nextTick()
      expect(summary()).toBe('8 acceptances')
      const row = acceptanceRows(9)[0]!
      h.emit('tableViewportDelta', {
        type: 'table_viewport_delta',
        data: {
          page: HilosPages.LEGAL_REVISION,
          tableKey: 'hilosLegalRevisions',
          kind: 'row_updated',
          rowKey: row.rowKey,
          row,
        },
      })
      await nextTick()
      expect(summary()).toBe('9 acceptances')
      expect(h.registered.size).toBe(1)
      expect(h.sendViewport).not.toHaveBeenCalled()
    } finally {
      h.view.unmount()
    }
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
  })

  it('keeps Open acceptances as a read-only link to the general page', () => {
    const h = revisionHarness(HilosLegalRevisionPage)
    try {
      expect(
        h.view.get('a[data-id="legal-open-acceptances"]').attributes('href'),
      ).toBe('/hilos/legal/acceptances')
      expect(h.view.findAll('button, input, select, form')).toHaveLength(0)
      expect(h.dispatch).not.toHaveBeenCalled()
    } finally {
      h.view.unmount()
    }
  })
})
