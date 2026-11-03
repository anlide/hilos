import { cleanup } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosPages } from '@hilos/core/base'
import { HilosLegalRevisionPage } from '../src/admin/legal/HilosLegalRevisionPage.js'
import {
  acceptanceRows,
  legalRevision,
  legalRevisionTestHarness,
} from './legalRevisionTestFixture.js'

afterEach(cleanup)

describe('React Revision Overview', () => {
  it('shows the selected comparison or the first-revision state without assembled text', () => {
    const h = legalRevisionTestHarness()
    const view = h.mount(<HilosLegalRevisionPage context={h.context} />)
    const changes = view.container.querySelector('[data-id="legal-changes"]')!
    expect(changes.textContent).toContain('Previous retention')
    expect(changes.textContent).toContain('Historical deviation')
    expect(
      view.container.querySelector('[data-id="legal-revision-text"]'),
    ).toBeNull()
    expect(view.container.textContent).not.toContain(
      'The historical project retention.',
    )
    h.setPageData('legalRevision', {
      ...legalRevision,
      predecessorId: null,
      changes: null,
    })
    expect(view.container.querySelector('[data-id="legal-changes"]')).toBeNull()
    expect(
      view.getByText(
        'This is the first revision; there is nothing to compare it with.',
      ),
    ).not.toBeNull()
    view.unmount()
    expect(h.liveWindows()).toEqual({})
    expect(h.listenerCount()).toBe(0)
  })

  it('distinguishes loading, confirmed zero, refusal and live exact count with a restartable controller', () => {
    const h = legalRevisionTestHarness(HilosPages.LEGAL_REVISION, {
      window: false,
    })
    const view = h.mount(<HilosLegalRevisionPage context={h.context} />, true)
    const summary = () =>
      view.container.querySelector('[data-id="legal-revision-accepted"]')
        ?.textContent
    expect(summary()).toBe('Loading acceptance count…')
    h.window([])
    expect(summary()).toBe('0 acceptances')
    h.refuse()
    expect(summary()).toBe('Acceptance count unavailable.')
    h.window(acceptanceRows(8))
    expect(summary()).toBe('8 acceptances')
    const row = acceptanceRows(9)[0]!
    h.emit('tableViewportDelta', {
      type: 'table_viewport_delta',
      data: {
        page: HilosPages.LEGAL_REVISION,
        tableKey: h.tableKey,
        kind: 'row_updated',
        rowKey: row.rowKey,
        row,
      },
    })
    expect(summary()).toBe('9 acceptances')
    expect(h.liveWindows()).toEqual({ hilosLegalRevisions: 1 })
    expect(h.register).toHaveBeenCalledTimes(2)
    expect(h.unregister).toHaveBeenCalledTimes(1)
    expect(h.controllers.size).toBe(1)
    expect([...h.controllers][0]?.filter.get()).toEqual({
      document: 'terms',
      revision: 'historical/revision',
    })
    expect(h.sendViewport).not.toHaveBeenCalled()
    view.unmount()
    expect(h.unregister).toHaveBeenCalledTimes(2)
    expect(h.liveWindows()).toEqual({})
    expect(h.listenerCount()).toBe(0)
    const late = acceptanceRows(10)[0]!
    h.emit('tableViewportDelta', {
      type: 'table_viewport_delta',
      data: {
        page: HilosPages.LEGAL_REVISION,
        tableKey: h.tableKey,
        kind: 'row_updated',
        rowKey: late.rowKey,
        row: late,
      },
    })
    for (const controller of h.controllers)
      expect(controller.rows.get()).toMatchObject([
        { row: { acceptedCount: 9 } },
      ])
  })

  it('keeps Open acceptances as a read-only link to the general page', () => {
    const h = legalRevisionTestHarness()
    const view = h.mount(<HilosLegalRevisionPage context={h.context} />)
    expect(
      view.getByRole('link', { name: 'Open acceptances' }).getAttribute('href'),
    ).toBe('/hilos/legal/acceptances')
    expect(
      view.container.querySelectorAll('button, input, select, form'),
    ).toHaveLength(0)
    expect(h.dispatch).not.toHaveBeenCalled()
  })
})
