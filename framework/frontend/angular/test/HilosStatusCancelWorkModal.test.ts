import { HILOS_VIEW_MODE_STRIP_TEXT_ID, hilosToasts } from '@hilos/core/base'
import { describe, expect, it, vi } from 'vitest'

import {
  announcement,
  el,
  query,
  settled,
  workRow,
} from './helpers/hilosStatus.js'
import { worksPage } from './helpers/hilosStatusWorks.js'

function open(viewer = false) {
  const h = worksPage(viewer)
  h.window()
  h.detect()
  const opener = h.open('cancel')
  return { ...h, opener }
}
const confirm = () => el<HTMLButtonElement>('hilos-status-work-cancel-confirm')
function submit(): void {
  el('hilos-status-work-cancel-form').dispatchEvent(
    new Event('submit', { bubbles: true, cancelable: true }),
  )
}

describe('HilosStatusCancelWorkModal over the captured live work', () => {
  it('keeps the live caption and current version for its immutable public identity and waits for its acknowledgement', async () => {
    const h = open()
    expect(el('modal').getAttribute('aria-label')).toBe(
      'Cancel scheduled maintenance',
    )
    const interval = el('hilos-status-work-cancel-interval').textContent
    h.chooseZone('Asia/Tokyo')
    expect(el('hilos-status-work-cancel-interval').textContent).not.toBe(
      interval,
    )
    h.update({ title: 'Changed title', version: 3 })
    h.detect()
    expect(el('hilos-status-work-cancel-target').textContent).toContain(
      'Changed title',
    )
    h.act(submit)
    expect(h.sent).toEqual([
      {
        action: 'hilos_status_work_cancel',
        requestId: expect.any(String),
        data: {
          workId: 7,
          publicId: announcement.publicId,
          expectedVersion: 3,
        },
      },
    ])
    expect(confirm().getAttribute('aria-busy')).toBe('true')
    h.act(submit)
    h.act(() => el('hilos-status-work-cancel-close').click())
    h.act(() => el('modal-close').click())
    expect(query('modal')).not.toBeNull()
    expect(h.sent).toHaveLength(1)
    expect(hilosToasts.toasts.get()).toEqual([])
    h.reply(true)
    await settled(h.fixture)
    expect(query('modal')).toBeNull()
    expect(document.activeElement).toBe(h.opener)
    expect(hilosToasts.toasts.get().map((toast) => toast.message)).toEqual([
      'Announcement canceled.',
    ])
    expect(query('hilos-status-work-title-7')).not.toBeNull()
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted', own: true })
    h.detect()
    expect(query('hilos-status-work-title-7')).toBeNull()
  })

  it.each(['deleted', 'replaced', 'consumed', 'expired', 'stale'] as const)(
    'blocks cancellation after the captured announcement is %s',
    (outcome) => {
      const h = open()
      if (outcome === 'deleted') {
        h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
        h.window([workRow({ id: 9 })])
      } else if (outcome === 'replaced')
        h.update({ publicId: 'another-public-identity' })
      else if (outcome === 'stale')
        h.delta({ kind: 'row_stale', rowKey: '7', staleSources: ['work'] })
      else h.update({ [`${outcome}At`]: 1_900_000_000_000 })
      h.detect()
      expect(confirm().disabled).toBe(true)
      expect(
        el('hilos-status-work-cancel-blocked').textContent?.trim(),
      ).not.toBe('')
      h.act(submit)
      expect(h.sent).toEqual([])
      h.act(() => el('hilos-status-work-cancel-close').click())
      expect(query('modal')).toBeNull()
    },
  )

  it('preserves a safe refusal and an unconfirmed timeout without accepting a late acknowledgement', async () => {
    const h = open()
    const slot = el('hilos-status-work-cancel-error-slot')
    h.act(submit)
    h.reply(false, {
      reason: 'The announcement changed. Check its latest version.',
      errorType: 'WorkFailure',
      errorDetail: 'Diagnostic detail',
    })
    await settled(h.fixture)
    expect(el('hilos-status-work-cancel-error-slot')).toBe(slot)
    expect(el('hilos-status-work-cancel-error').textContent).toContain(
      'The announcement changed. Check its latest version.',
    )
    expect(el('hilos-status-work-cancel-target').textContent).toContain(
      'Database upgrade',
    )
    h.act(submit)
    await vi.advanceTimersByTimeAsync(1001)
    await settled(h.fixture)
    expect(el('hilos-status-work-cancel-error').textContent).toContain(
      'The result is unconfirmed. The operation may have completed.',
    )
    h.reply(true)
    await settled(h.fixture)
    expect(query('modal')).not.toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('lets a viewer read the target and return while the shared mode controls block cancellation', () => {
    const h = open(true)
    expect(el('hilos-status-work-cancel-target').textContent).toContain(
      'Database upgrade',
    )
    expect(confirm().disabled).toBe(true)
    expect(confirm().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    h.act(submit)
    expect(h.sent).toEqual([])
    h.act(() => el('hilos-status-work-cancel-close').click())
    expect(query('modal')).toBeNull()
  })
})
