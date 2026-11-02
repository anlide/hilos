import {
  HIDDEN_VALUE,
  HilosPages,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  hilosToasts,
} from '@hilos/core/base'
import { describe, expect, it, vi } from 'vitest'

import { HilosStatusPage } from '../src/admin/status/HilosStatusPage.js'
import {
  el,
  enter,
  incident,
  mountStatus,
  overview,
  query,
  setText,
  settled,
  statusHarness,
  submit,
  text,
} from './helpers/hilosStatus.js'

function page(published = false) {
  return mountStatus(
    HilosStatusPage,
    HilosPages.STATUS,
    overview({
      incidents: [incident({ publishedAt: published ? Date.now() : null })],
    }),
  )
}
function open(
  h: ReturnType<typeof page>,
  operation: string,
): HTMLButtonElement {
  const opener = el<HTMLButtonElement>(`hilos-status-${operation}-11`)
  opener.focus()
  h.act(() => opener.click())
  return opener
}
const confirm = () => el<HTMLButtonElement>('hilos-status-incident-confirm')

describe('HilosStatusIncidentModal over the real page controller', () => {
  it.each([
    [
      'cancel-publication',
      'hilos_status_cancel_publication',
      'Publication canceled.',
    ],
    ['publish-now', 'hilos_status_publish_now', 'Outage published.'],
  ])(
    'confirms %s once and waits for its own tracked acknowledgement',
    async (operation, action, message) => {
      const h = page()
      const opener = open(h, operation)
      expect(document.activeElement).toBe(el('modal'))
      expect(text('hilos-status-incident-target')).toBe('Service · #11')
      h.act(() => enter(el('hilos-status-incident-form')))
      expect(h.sent).toEqual([
        { action, data: { incidentId: 11 }, requestId: expect.any(String) },
      ])
      expect(confirm().getAttribute('aria-busy')).toBe('true')
      expect(confirm().disabled).toBe(false)
      const fieldset = el('hilos-status-incident-form').closest('fieldset')!
      expect(fieldset.disabled).toBe(true)
      expect(el<HTMLFieldSetElement>('modal-interaction-scope').disabled).toBe(
        false,
      )
      h.act(() => {
        confirm().click()
        submit()
        enter(el('hilos-status-incident-form'))
        el('modal').dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      })
      expect(h.sent).toHaveLength(1)
      expect(query('modal')).not.toBeNull()
      expect(
        el<HTMLButtonElement>('hilos-status-incident-cancel').disabled,
      ).toBe(true)
      h.reply(true)
      await settled(h.fixture)
      expect(query('modal')).toBeNull()
      expect(hilosToasts.toasts.get().map((toast) => toast.message)).toEqual([
        message,
      ])
      expect(document.activeElement).toBe(opener)
    },
  )

  it('preserves the update node and Unicode plain-text draft on refusal, then closes only on success', async () => {
    const h = page(true)
    open(h, 'add-update')
    const input = el<HTMLTextAreaElement>('hilos-status-update-text')
    expect(input.value).toBe('')
    expect(document.activeElement).toBe(input)
    expect(confirm().disabled).toBe(true)
    h.act(() => setText('hilos-status-update-text', '  '))
    expect(confirm().disabled).toBe(true)
    const draft = '🙂'.repeat(5000)
    h.act(() => setText('hilos-status-update-text', draft))
    expect(confirm().disabled).toBe(false)
    h.act(() => enter(input))
    expect(h.sent).toEqual([])
    h.act(() => enter(input, { ctrlKey: true }))
    expect(h.sent[0]?.data).toEqual({ incidentId: 11, text: draft })
    h.reply(false, { reason: 'The incident changed. Please check it.' })
    await settled(h.fixture)
    expect(text('hilos-status-incident-error')).toContain(
      'The incident changed. Please check it.',
    )
    expect(el('hilos-status-update-text')).toBe(input)
    expect(input.value).toBe(draft)
    expect(input.closest('fieldset')?.disabled).toBe(false)
    h.act(() => setText('hilos-status-update-text', '  <b>明</b>  '))
    h.act(() => confirm().click())
    expect(h.sent[1]?.data).toEqual({ incidentId: 11, text: '  <b>明</b>  ' })
    expect(h.sent[1]?.requestId).not.toBe(h.sent[0]?.requestId)
    h.reply(true)
    await settled(h.fixture)
    expect(query('modal')).toBeNull()
    expect(query('hilos-status-timeline-11')).toBeNull()
  })

  it('keeps the captured id and extractable draft when a new incident replaces it on the same component', () => {
    const h = page(true)
    open(h, 'add-update')
    h.act(() => setText('hilos-status-update-text', 'Keep this draft'))
    h.act(() =>
      h.setOverview(
        overview({
          incidents: [incident({ incidentId: 12, publishedAt: Date.now() })],
        }),
      ),
    )
    expect(text('hilos-status-incident-target')).toBe('Service · #11')
    expect(text('hilos-status-incident-blocked')).toContain(
      'This incident has ended.',
    )
    expect(el<HTMLTextAreaElement>('hilos-status-update-text').value).toBe(
      'Keep this draft',
    )
    expect(confirm().disabled).toBe(true)
    h.act(() => {
      submit()
      enter(el('hilos-status-update-text'), { metaKey: true })
    })
    expect(h.sent).toEqual([])
  })

  it('blocks a newly suppressed or already published target and preserves Enter on a notice details button', () => {
    const h = page()
    open(h, 'publish-now')
    for (const change of [
      { suppressedAt: Date.now() },
      { publishedAt: Date.now() },
    ]) {
      h.act(() => h.setOverview(overview({ incidents: [incident(change)] })))
      expect(text('hilos-status-incident-blocked')).toMatch(
        /Publication was canceled|already published/,
      )
      expect(confirm().disabled).toBe(true)
      h.act(submit)
    }
    const details = el('hilos-status-incident-blocked-details')
    expect(enter(details).defaultPrevented).toBe(false)
    h.act(() => details.click())
    expect(document.querySelectorAll('[data-id="modal"]')).toHaveLength(2)
    expect(h.sent).toEqual([])
  })

  it('keeps unavailable facts and hidden data safe in an open viewer window while every submit path refuses', () => {
    const h = page(true)
    open(h, 'add-update')
    h.act(() => setText('hilos-status-update-text', 'A draft to copy'))
    h.act(() =>
      h.setOverview(overview({ healthKnown: false, incidents: null })),
    )
    expect(text('hilos-status-incident-blocked')).toContain(
      'Incident data is unavailable.',
    )
    expect(el<HTMLTextAreaElement>('hilos-status-update-text').value).toBe(
      'A draft to copy',
    )
    h.act(() => {
      h.setOverview(
        overview({
          publishDelaySeconds: HIDDEN_VALUE,
          incidents: [
            incident({
              publishedAt: Date.now(),
              updates: [
                {
                  id: 9,
                  kind: 'team',
                  severity: 'major_outage',
                  at: Date.now(),
                  text: HIDDEN_VALUE,
                },
              ],
            }),
          ],
        }),
      )
      h.viewer()
    })
    expect(text('hilos-status-delay')).toBe('Hidden')
    expect(text('hilos-status-timeline-11')).toContain('Hidden')
    expect(el('hilos-status-timeline-11').textContent).not.toContain(
      '[object Object]',
    )
    expect(el('hilos-status-update-text').closest('fieldset')?.disabled).toBe(
      false,
    )
    expect(confirm().disabled).toBe(true)
    expect(confirm().getAttribute('aria-describedby')).toBe(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    h.act(() => {
      submit()
      enter(el('hilos-status-update-text'), { ctrlKey: true })
    })
    expect(h.sent).toEqual([])
    h.act(() => el('hilos-status-incident-cancel').click())
    expect(query('modal')).toBeNull()
  })

  it('describes timeout as unconfirmed, retains the draft and ignores its late acknowledgement', async () => {
    const h = page(true)
    open(h, 'add-update')
    h.act(() => setText('hilos-status-update-text', 'Still available'))
    h.act(submit)
    await vi.advanceTimersByTimeAsync(1001)
    await settled(h.fixture)
    expect(text('hilos-status-incident-error')).toContain(
      'The operation may have completed.',
    )
    expect(el<HTMLTextAreaElement>('hilos-status-update-text').value).toBe(
      'Still available',
    )
    const notices = hilosToasts.toasts.get()
    expect(notices).toEqual([
      expect.objectContaining({
        severity: 'error',
        message: expect.stringContaining('The operation may have completed.'),
      }),
    ])
    h.reply(true)
    await settled(h.fixture)
    expect(query('modal')).not.toBeNull()
    expect(hilosToasts.toasts.get()).toEqual(notices)
  })

  it('updates open-window moments on a zone change and drops a pending result when context is replaced', async () => {
    const h = page()
    h.chooseZone('Europe/Warsaw')
    open(h, 'publish-now')
    const before = text('hilos-status-incident-time')
    h.chooseZone('Asia/Tokyo')
    expect(text('hilos-status-incident-time')).not.toBe(before)
    h.act(submit)
    const second = statusHarness()
    try {
      second.setOverview(
        overview({ incidents: [incident({ incidentId: 12 })] }),
      )
      h.fixture.componentRef.setInput('context', second.context)
      h.detect()
      h.reply(true)
      await settled(h.fixture)
      expect(query('modal')).toBeNull()
      expect(query('hilos-status-incident-12')).not.toBeNull()
      expect(hilosToasts.toasts.get()).toEqual([])
    } finally {
      second.dispose()
    }
  })
})
