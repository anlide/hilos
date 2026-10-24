import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  createHilosStatusIncidentController,
  createHilosStatusState,
  HILOS_STATUS_ACTION_COPY,
  type HilosStatusIncidentOperation,
} from '../../../src/admin/status/index.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { hilosToasts } from '../../../src/state/toasts.js'
import { harness, incident, overview } from './fixtures.js'

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(1_800_000_000_000)
})
afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

function setup(published = false) {
  const h = harness()
  h.setOverview(
    overview({ incidents: [incident({ publishedAt: published ? 1 : null })] }),
  )
  const state = createHilosStatusState(h.context)
  state.start()
  const controller = createHilosStatusIncidentController(h.context, state)
  controller.start()
  controller.start()
  const toast = vi.spyOn(hilosToasts, 'push').mockReturnValue(1)
  return {
    ...h,
    state,
    controller,
    toast,
    answer(
      success: boolean,
      extra: Record<string, unknown> = {},
      index = h.sent.length - 1,
    ) {
      const sent = h.sent[index]!
      h.emit(success ? 'actionSuccess' : 'actionError', {
        action: sent.action,
        requestId: sent.requestId,
        ...extra,
      })
    },
    dispose() {
      controller.dispose()
      state.dispose()
      h.releaseAccess()
    },
  }
}

describe('Status mutation controller', () => {
  it.each([
    [
      'cancel-publication',
      'hilos_status_cancel_publication',
      'Publication canceled.',
    ],
    ['publish-now', 'hilos_status_publish_now', 'Outage published.'],
    ['add-update', 'hilos_status_add_update', 'Update published.'],
  ] as const)(
    'tracks %s through one busy guard and closes only on ack',
    async (operation, action, success) => {
      const h = setup(operation === 'add-update')
      h.controller.open(operation, 11)
      h.controller.setDraft('  Recovery is under way.  ')
      const submit = h.controller.submit()
      expect(h.controller.busy.get()).toBe(true)
      expect(h.controller.loading.get()).toBe(false)
      expect(h.controller.canSubmit.get()).toBe(false)
      expect(h.sent).toEqual([
        {
          action,
          requestId: '1',
          data:
            operation === 'add-update'
              ? { incidentId: 11, text: '  Recovery is under way.  ' }
              : { incidentId: 11 },
        },
      ])
      expect(await h.controller.submit()).toBe(false)
      h.controller.close()
      h.controller.setDraft('replacement')
      h.controller.open('cancel-publication', 11)
      expect(h.controller.dialog.get()?.operation).toBe(operation)
      expect(h.controller.draft.get()).toBe('  Recovery is under way.  ')
      vi.advanceTimersByTime(30)
      expect(h.controller.loading.get()).toBe(true)
      h.answer(true)
      expect(await submit).toBe(true)
      expect(h.controller.dialog.get()).toBeNull()
      expect(h.controller.busy.get()).toBe(false)
      expect(h.toast).toHaveBeenCalledExactlyOnceWith(success, {
        severity: 'success',
      })
      h.dispose()
    },
  )

  it('starts empty, blocks empty submits and lets the backend validate Unicode length', async () => {
    const h = setup(true)
    h.controller.open('add-update', 11)
    expect(h.controller.draft.get()).toBe('')
    h.controller.setDraft(' \n\t')
    expect(await h.controller.submit()).toBe(false)
    expect(h.sent).toEqual([])
    const text = '🙂'.repeat(5001)
    h.controller.setDraft(text)
    expect(h.controller.canSubmit.get()).toBe(true)
    const submit = h.controller.submit()
    h.answer(false, {
      reason: 'The update must contain no more than 5000 characters.',
      errorCode: 'validation',
    })
    expect(await submit).toBe(false)
    expect(h.controller.draft.get()).toBe(text)
    expect(h.controller.error.get()).toBe(
      'The update must contain no more than 5000 characters.',
    )
    expect(h.controller.failure.get()?.errorCode).toBe('validation')
    expect(h.controller.unconfirmed.get()).toBe(false)
    expect(h.controller.canSubmit.get()).toBe(true)
    h.dispose()
  })

  it('keeps the original incident id when that component gets a new incident', async () => {
    const h = setup(true)
    h.controller.open('add-update', 11)
    h.controller.setDraft('Keep this draft')
    h.setOverview(
      overview({ incidents: [incident({ incidentId: 12, publishedAt: 2 })] }),
    )
    expect(h.controller.dialog.get()?.incident.incidentId).toBe(11)
    expect(h.controller.target.get()).toBeNull()
    expect(h.controller.blockedReason.get()).toBe(
      HILOS_STATUS_ACTION_COPY.ended,
    )
    expect(h.controller.draft.get()).toBe('Keep this draft')
    expect(await h.controller.submit()).toBe(false)
    expect(h.sent).toEqual([])
    h.dispose()
  })

  it.each([
    [
      'cancel-publication',
      { publishedAt: 2 },
      HILOS_STATUS_ACTION_COPY.published,
    ],
    ['publish-now', { publishedAt: 2 }, HILOS_STATUS_ACTION_COPY.published],
    ['publish-now', { suppressedAt: 2 }, HILOS_STATUS_ACTION_COPY.suppressed],
    ['add-update', { suppressedAt: 2 }, HILOS_STATUS_ACTION_COPY.suppressed],
    ['add-update', { publishedAt: null }, HILOS_STATUS_ACTION_COPY.unpublished],
  ] as const)(
    'keeps a blocked %s dialog readable after the live state changes',
    async (operation, patch, reason) => {
      const h = setup(operation === 'add-update')
      h.controller.open(operation, 11)
      h.controller.setDraft('Copy me')
      h.setOverview(
        overview({
          incidents: [
            incident({
              publishedAt: operation === 'add-update' ? 1 : null,
              ...patch,
            }),
          ],
        }),
      )
      expect(h.controller.blockedReason.get()).toBe(reason)
      expect(h.controller.draft.get()).toBe('Copy me')
      expect(await h.controller.submit()).toBe(false)
      expect(h.controller.dialog.get()).not.toBeNull()
      h.dispose()
    },
  )

  it('does not merge a concurrent timeline append into the draft and preserves it through DB unavailability', async () => {
    const h = setup(true)
    h.controller.open('add-update', 11)
    h.controller.setDraft('My update')
    h.setOverview(
      overview({
        incidents: [
          incident({
            publishedAt: 1,
            updates: [
              {
                id: 4,
                kind: 'team',
                severity: 'major_outage',
                text: 'Another team member',
                at: 4,
              },
            ],
          }),
        ],
      }),
    )
    expect(h.controller.target.get()?.updates[0]?.text).toBe(
      'Another team member',
    )
    expect(h.controller.draft.get()).toBe('My update')
    expect(h.controller.canSubmit.get()).toBe(true)
    h.setOverview(overview({ healthKnown: false, incidents: null }))
    expect(h.controller.blockedReason.get()).toBe(
      HILOS_STATUS_ACTION_COPY.unavailable,
    )
    expect(await h.controller.submit()).toBe(false)
    expect(h.controller.draft.get()).toBe('My update')
    h.dispose()
  })

  it('timeout preserves the draft, gives no claim of no write, and a new explicit submit mints a new id', async () => {
    const h = setup(true)
    h.controller.open('add-update', 11)
    h.controller.setDraft('My update')
    const first = h.controller.submit()
    await vi.advanceTimersByTimeAsync(1000)
    expect(await first).toBe(false)
    expect(h.controller.unconfirmed.get()).toBe(true)
    expect(h.controller.error.get()).toBe(HILOS_STATUS_ACTION_COPY.unconfirmed)
    expect(h.controller.draft.get()).toBe('My update')
    expect(h.sent).toHaveLength(1)
    h.answer(true, {}, 0)
    await Promise.resolve()
    expect(h.controller.dialog.get()).not.toBeNull()
    expect(h.sent).toHaveLength(1)
    const second = h.controller.submit()
    expect(h.sent.map((item) => item.requestId)).toEqual(['1', '2'])
    h.answer(true)
    expect(await second).toBe(true)
    h.answer(true)
    expect(h.sent).toHaveLength(2)
    expect(
      h.toast.mock.calls.filter((call) => call[1]?.severity === 'success'),
    ).toHaveLength(1)
    h.dispose()
  })

  it('retains the draft and diagnostic failure on backend decline in a racing publication', async () => {
    const h = setup()
    h.controller.open('publish-now', 11)
    h.controller.setDraft('draft')
    const submit = h.controller.submit()
    h.answer(false, {
      reason: 'Publication has been canceled for this incident.',
      errorType: 'StatusRefusal',
      errorDetail: 'detail',
    })
    expect(await submit).toBe(false)
    expect(h.controller.error.get()).toBe(
      'Publication has been canceled for this incident.',
    )
    expect(h.controller.failure.get()?.errorDetail).toBe('detail')
    expect(h.controller.draft.get()).toBe('draft')
    h.dispose()
  })

  it.each([
    'cancel-publication',
    'publish-now',
    'add-update',
  ] satisfies HilosStatusIncidentOperation[])(
    'blocks forged %s submits in view mode',
    async (operation) => {
      const h = setup(operation === 'add-update')
      h.controller.open(operation, 11)
      h.controller.setDraft('draft')
      h.viewer()
      expect(h.controller.canSubmit.get()).toBe(false)
      expect(await h.controller.submit()).toBe(false)
      expect(h.sent).toEqual([])
      h.dispose()
    },
  )

  it.each(['scope', 'dispose'] as const)(
    'drops loading subscriptions and ignores late replies after %s',
    async (end) => {
      const h = setup(true)
      h.controller.open('add-update', 11)
      h.controller.setDraft('draft')
      const submit = h.controller.submit()
      if (end === 'scope') h.scopes.openPage(HilosPages.STATUS_COMPONENTS)
      else h.controller.dispose()
      expect(h.controller.dialog.get()).toBeNull()
      expect(h.controller.busy.get()).toBe(false)
      vi.advanceTimersByTime(30)
      expect(h.controller.loading.get()).toBe(false)
      h.answer(true)
      expect(await submit).toBe(false)
      expect(h.toast).not.toHaveBeenCalled()
      expect(h.controller.dialog.get()).toBeNull()
      h.dispose()
    },
  )
})
