import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { createHilosToastStore } from './toasts.js'
import type {
  HilosSessionToast,
  HilosToastStore,
  HilosToastViewer,
} from './toasts.js'

// A card is only really on screen once a host has drawn it and reported how tall
// it is, so almost every case here needs a stand-in host. The window is 600px, a
// card is 100px, and a third of the window is therefore exactly two cards — the
// smallest stack in which the cap can be seen at all.
const VIEWPORT_HEIGHT = 600
const CARD_HEIGHT = 100

/**
 * Attach a stand-in host and tell the store how tall its window is.
 *
 * @param store The stack under test.
 * @param viewportHeight The window height to report.
 */
function watch(
  store: HilosToastStore,
  viewportHeight: number = VIEWPORT_HEIGHT,
): HilosToastViewer {
  const viewer = store.attach()
  viewer.setViewportHeight(viewportHeight)

  return viewer
}

/**
 * Report a height for every card the host has not measured yet — what a host
 * does on the frame after the store handed it a new notice.
 *
 * @param store The stack under test.
 * @param viewer The stand-in host.
 * @param cardHeight The height to report for each card.
 */
function draw(
  store: HilosToastStore,
  viewer: HilosToastViewer,
  cardHeight: number = CARD_HEIGHT,
): void {
  for (const toast of store.toasts.get()) {
    if (!toast.measured) {
      viewer.reportHeight(toast.id, cardHeight)
    }
  }
}

/**
 * One card of the session's stack as the server would send it.
 *
 * @param fields What this case cares about; the rest is the backup card the first
 *   real sender raises.
 */
function sessionToast(
  fields: Partial<HilosSessionToast> & { key: string },
): HilosSessionToast {
  return {
    message: 'Backup "2026-09-03" is ready.',
    severity: 'success',
    source: 'Backup',
    destination: '/hilos/backup',
    repeats: 1,
    ...fields,
  }
}

/** The messages currently on screen, oldest first. */
function messages(store: HilosToastStore): string[] {
  return store.toasts.get().map((toast) => toast.message)
}

describe('createHilosToastStore', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('starts empty, with nothing waiting and nothing missed', () => {
    const store = createHilosToastStore()

    expect(store.toasts.get()).toEqual([])
    expect(store.overflow.get()).toEqual({ waiting: 0, missed: 0 })
  })

  it('queues a notice with its severity, oldest first', () => {
    const store = createHilosToastStore()
    store.push('first', { severity: 'error' })
    store.push('second', { severity: 'success' })

    expect(messages(store)).toEqual(['first', 'second'])
    expect(store.toasts.get()[0].severity).toBe('error')
  })

  it('defaults an unqualified notice to an info the connection sent itself', () => {
    const store = createHilosToastStore()
    store.push('plain')

    const [toast] = store.toasts.get()
    expect(toast.severity).toBe('info')
    expect(toast.scope).toBe('connection')
    expect(toast.source).toBeNull()
    expect(toast.destination).toBeNull()
    expect(toast.repeats).toBe(1)
    expect(toast.measured).toBe(false)
  })

  it('carries the sender and the route of a notice the session was sent', () => {
    const store = createHilosToastStore()
    store.syncSession([
      sessionToast({ key: 'k1', message: 'Backup finished.' }),
    ])

    const [toast] = store.toasts.get()
    expect(toast.scope).toBe('session')
    expect(toast.source).toBe('Backup')
    expect(toast.destination).toBe('/hilos/backup')
    expect(toast.sessionKey).toBe('k1')
  })

  it('does not run the countdown of a card nobody has measured yet', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.push('drawn later', { severity: 'success' })

    vi.advanceTimersByTime(60_000)
    expect(store.toasts.get()).toHaveLength(1)

    draw(store, viewer)
    vi.advanceTimersByTime(19_999)
    expect(store.toasts.get()).toHaveLength(1)

    vi.advanceTimersByTime(1)
    expect(store.toasts.get()).toEqual([])
  })

  it('marks a card measured once its height is reported', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.push('measure me', { severity: 'success' })
    draw(store, viewer)

    expect(store.toasts.get()[0].measured).toBe(true)
  })

  it('does not run the countdown while no host is attached', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.push('read me', { severity: 'success' })
    draw(store, viewer)

    viewer.detach()
    vi.advanceTimersByTime(60_000)
    expect(store.toasts.get()).toHaveLength(1)

    watch(store)
    vi.advanceTimersByTime(20_000)
    expect(store.toasts.get()).toEqual([])
  })

  it('expires a success notice after twenty seconds', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.push('done', { severity: 'success' })
    draw(store, viewer)

    vi.advanceTimersByTime(19_999)
    expect(store.toasts.get()).toHaveLength(1)

    vi.advanceTimersByTime(1)
    expect(store.toasts.get()).toEqual([])
  })

  it('never expires an error — the user closes it', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    const id = store.push('failed', { severity: 'error' })
    draw(store, viewer)

    vi.advanceTimersByTime(600_000)
    expect(store.toasts.get()).toHaveLength(1)

    store.dismiss(id)
    expect(store.toasts.get()).toEqual([])
  })

  it('freezes the countdown while a host holds it', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.push('read me', { severity: 'success' })
    draw(store, viewer)

    viewer.hold('cursor')
    vi.advanceTimersByTime(60_000)

    expect(store.toasts.get()).toHaveLength(1)
  })

  it('resumes the countdown from what is left, not from the full lifetime', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.push('read me', { severity: 'success' })
    draw(store, viewer)

    vi.advanceTimersByTime(8000)
    viewer.hold('cursor')
    vi.advanceTimersByTime(60_000)
    viewer.release('cursor')

    vi.advanceTimersByTime(11_999)
    expect(store.toasts.get()).toHaveLength(1)

    vi.advanceTimersByTime(1)
    expect(store.toasts.get()).toEqual([])
  })

  it('counts the holds, so the cursor cannot release the one the tab took', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.push('read me', { severity: 'success' })
    draw(store, viewer)

    viewer.hold('tab')
    viewer.hold('cursor')
    viewer.release('cursor')

    vi.advanceTimersByTime(60_000)
    expect(store.toasts.get()).toHaveLength(1)

    viewer.release('tab')
    vi.advanceTimersByTime(20_000)
    expect(store.toasts.get()).toEqual([])
  })

  it('lets no host release a hold another host is holding', () => {
    const store = createHilosToastStore()
    const first = watch(store)
    const second = store.attach()
    store.push('read me', { severity: 'success' })
    draw(store, first)

    first.hold('cursor')
    second.release('cursor')

    vi.advanceTimersByTime(60_000)
    expect(store.toasts.get()).toHaveLength(1)

    first.release('cursor')
    vi.advanceTimersByTime(20_000)
    expect(store.toasts.get()).toEqual([])
  })

  it('gives back the holds of a host that goes away', () => {
    const store = createHilosToastStore()
    const first = watch(store)
    const second = store.attach()
    store.push('read me', { severity: 'success' })
    draw(store, second)

    first.hold('cursor')
    first.detach()

    vi.advanceTimersByTime(20_000)
    expect(store.toasts.get()).toEqual([])
  })

  it('starts a notice pushed into a held stack only once the hold is released', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    viewer.hold('cursor')
    store.push('late arrival', { severity: 'success' })
    draw(store, viewer)

    vi.advanceTimersByTime(60_000)
    expect(store.toasts.get()).toHaveLength(1)

    viewer.release('cursor')
    vi.advanceTimersByTime(19_999)
    expect(store.toasts.get()).toHaveLength(1)

    vi.advanceTimersByTime(1)
    expect(store.toasts.get()).toEqual([])
  })

  it('keeps the stack within a third of the reported window height', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.push('first')
    store.push('second')
    store.push('third')
    draw(store, viewer)

    expect(messages(store)).toEqual(['first', 'second'])
    expect(store.overflow.get()).toEqual({ waiting: 0, missed: 1 })
  })

  it('counts the budget in cards while no height has been reported', () => {
    const store = createHilosToastStore()
    for (const message of ['first', 'second', 'third', 'fourth', 'fifth']) {
      store.push(message)
    }

    expect(messages(store)).toEqual(['first', 'second', 'third', 'fourth'])
    expect(store.overflow.get()).toEqual({ waiting: 0, missed: 1 })
  })

  it('shows a single card taller than a third of the window anyway', () => {
    const store = createHilosToastStore()
    const viewer = watch(store, 300)
    store.push('one very long message')
    draw(store, viewer, 500)

    expect(store.toasts.get()).toHaveLength(1)
    expect(store.overflow.get()).toEqual({ waiting: 0, missed: 0 })
  })

  it('takes nothing off a stack that no longer fits a shrunken window', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.push('first')
    store.push('second')
    draw(store, viewer)

    viewer.setViewportHeight(100)

    expect(messages(store)).toEqual(['first', 'second'])
    expect(store.overflow.get()).toEqual({ waiting: 0, missed: 0 })
  })

  it('never evicts a card once it is measured, however tall it turns out', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.push('first')
    store.push('second')
    draw(store, viewer)

    viewer.reportHeight(store.toasts.get()[1].id, 5000)

    expect(messages(store)).toEqual(['first', 'second'])
  })

  it('queues an error that does not fit and misses everything else', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.push('first')
    store.push('second')
    draw(store, viewer)

    store.push('failed', { severity: 'error' })
    draw(store, viewer)
    store.push('worked', { severity: 'success' })
    draw(store, viewer)

    expect(messages(store)).toEqual(['first', 'second'])
    expect(store.overflow.get()).toEqual({ waiting: 1, missed: 1 })
  })

  it('lets the first waiting error into a slot that comes free', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.push('first')
    const second = store.push('second')
    draw(store, viewer)
    store.push('failed', { severity: 'error' })
    draw(store, viewer)

    store.dismiss(second)

    expect(messages(store)).toEqual(['first', 'failed'])
    expect(store.toasts.get()[1].measured).toBe(false)
    expect(store.overflow.get()).toEqual({ waiting: 0, missed: 0 })
  })

  it('refuses to dismiss a notice nobody has seen', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.push('first')
    store.push('second')
    draw(store, viewer)
    const waiting = store.push('failed', { severity: 'error' })
    draw(store, viewer)

    store.dismiss(waiting)

    expect(store.overflow.get().waiting).toBe(1)
  })

  it('forgets the missed count once the stack and the queue are empty', () => {
    const store = createHilosToastStore()
    for (const message of ['first', 'second', 'third', 'fourth', 'fifth']) {
      store.push(message)
    }
    expect(store.overflow.get().missed).toBe(1)

    for (const toast of store.toasts.get()) {
      store.dismiss(toast.id)
    }

    expect(store.overflow.get()).toEqual({ waiting: 0, missed: 0 })
  })

  it('counts a repeat on the first card instead of adding a second', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    const first = store.push('Marked as read.', { severity: 'success' })
    draw(store, viewer)

    expect(store.push('Marked as read.', { severity: 'success' })).toBe(first)
    expect(store.toasts.get()).toHaveLength(1)
    expect(store.toasts.get()[0].repeats).toBe(2)
  })

  it('restarts the countdown of the card a repeat merged into', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.push('Marked as read.', { severity: 'success' })
    draw(store, viewer)

    vi.advanceTimersByTime(15_000)
    store.push('Marked as read.', { severity: 'success' })

    vi.advanceTimersByTime(19_999)
    expect(store.toasts.get()).toHaveLength(1)

    vi.advanceTimersByTime(1)
    expect(store.toasts.get()).toEqual([])
  })

  it('keeps two notices apart when only their sender differs', () => {
    const store = createHilosToastStore()
    store.syncSession([
      sessionToast({ key: 'k1', message: 'Finished.', source: 'Backup' }),
      sessionToast({ key: 'k2', message: 'Finished.', source: 'Delivery' }),
    ])

    // Two keys are two cards, whatever they say: what is one notice and what is
    // two is the server's judgement for a card of the session, not the store's.
    expect(store.toasts.get()).toHaveLength(2)
  })

  it('counts a repeat of an error that is still waiting for a slot', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.push('first')
    store.push('second')
    draw(store, viewer)
    const waiting = store.push('failed', { severity: 'error' })
    draw(store, viewer)

    expect(store.push('failed', { severity: 'error' })).toBe(waiting)
    expect(store.overflow.get().waiting).toBe(1)

    store.dismiss(store.toasts.get()[1].id)
    expect(store.toasts.get()[1].repeats).toBe(2)
  })

  it('dismisses one notice without touching the others', () => {
    const store = createHilosToastStore()
    const first = store.push('first')
    store.push('second')

    store.dismiss(first)

    expect(messages(store)).toEqual(['second'])
  })

  it('ignores a dismiss of an id that is already gone', () => {
    const store = createHilosToastStore()
    const id = store.push('once')
    store.dismiss(id)

    expect(() => store.dismiss(id)).not.toThrow()
    expect(store.toasts.get()).toEqual([])
  })

  it('clears the whole stack, its queue and its pending timers', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.push('first')
    store.push('second')
    draw(store, viewer)
    store.push('failed', { severity: 'error' })
    draw(store, viewer)

    store.clear()
    expect(store.toasts.get()).toEqual([])
    expect(store.overflow.get()).toEqual({ waiting: 0, missed: 0 })

    // No timer may fire into the cleared stack afterwards.
    vi.advanceTimersByTime(60_000)
    expect(store.toasts.get()).toEqual([])
  })

  it('leaves a host holding its hold across a clear of the stack', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    viewer.hold('cursor')
    store.clear()
    viewer.release('cursor')

    // The release above has to land on the hold the host actually took: zeroed
    // out by the clear, it would drive the count below zero and leave the stack
    // frozen for as long as nothing rests on it.
    store.push('after the clear', { severity: 'success' })
    draw(store, viewer)
    vi.advanceTimersByTime(20_000)

    expect(store.toasts.get()).toEqual([])
  })

  it('gives every notice its own id', () => {
    const store = createHilosToastStore()

    expect(store.push('a')).not.toBe(store.push('b'))
  })

  it('takes away the card the server no longer sends', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.syncSession([sessionToast({ key: 'k1' })])
    draw(store, viewer)

    store.syncSession([])

    expect(store.toasts.get()).toEqual([])
  })

  it('takes a session card out of the waiting queue too', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.push('first error', { severity: 'error' })
    store.push('second error', { severity: 'error' })
    store.syncSession([sessionToast({ key: 'k1', severity: 'error' })])
    draw(store, viewer)
    expect(store.overflow.get().waiting).toBe(1)

    store.syncSession([])

    // A card can be waiting for a slot when the answer arrives, and one left
    // there would walk back onto the screen the moment a slot came free.
    expect(store.overflow.get().waiting).toBe(0)
    store.dismiss(store.toasts.get()[0].id)
    expect(messages(store)).toEqual(['second error'])
  })

  it('gives a card its time back when the server counts it again', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.syncSession([sessionToast({ key: 'k1' })])
    draw(store, viewer)

    vi.advanceTimersByTime(15_000)
    store.syncSession([sessionToast({ key: 'k1', repeats: 2 })])

    expect(store.toasts.get()[0].repeats).toBe(2)
    vi.advanceTimersByTime(19_999)
    expect(store.toasts.get()).toHaveLength(1)
    vi.advanceTimersByTime(1)
    expect(store.expiredSessionKeys.get()).toEqual(['k1'])
  })

  it('reports a burned-down countdown instead of hiding the card', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.syncSession([sessionToast({ key: 'k1' })])
    draw(store, viewer)

    vi.advanceTimersByTime(20_000)

    // Hiding it here is exactly the disagreement the leaf was written against:
    // this tab's twenty seconds are its own, and the neighbour may be reading.
    expect(store.toasts.get()).toHaveLength(1)
    expect(store.expiredSessionKeys.get()).toEqual(['k1'])
  })

  it('never reports a countdown for an error, which has none', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.syncSession([sessionToast({ key: 'k1', severity: 'error' })])
    draw(store, viewer)

    vi.advanceTimersByTime(60_000)

    expect(store.toasts.get()).toHaveLength(1)
    expect(store.expiredSessionKeys.get()).toEqual([])
  })

  it('answers a close of a session card instead of taking it off the screen', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.syncSession([sessionToast({ key: 'k1' })])
    draw(store, viewer)

    store.dismiss(store.toasts.get()[0].id)

    expect(store.toasts.get()).toHaveLength(1)
    expect(store.dismissedSessionKeys.get()).toEqual(['k1'])

    store.syncSession([])
    expect(store.toasts.get()).toEqual([])
    expect(store.dismissedSessionKeys.get()).toEqual([])
  })

  it('reports only the holds a person is behind', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)

    viewer.hold('tab')
    expect(store.reading.get()).toBe(false)

    viewer.hold('cursor')
    expect(store.reading.get()).toBe(true)

    viewer.release('cursor')
    expect(store.reading.get()).toBe(false)
  })

  it('freezes the countdown for a hidden tab even though nobody is reading', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    store.syncSession([sessionToast({ key: 'k1' })])
    draw(store, viewer)

    viewer.hold('tab')
    vi.advanceTimersByTime(60_000)

    expect(store.expiredSessionKeys.get()).toEqual([])
  })

  it('gives the reading answer back when the host that held it goes away', () => {
    const store = createHilosToastStore()
    const viewer = watch(store)
    viewer.hold('focus')

    viewer.detach()

    expect(store.reading.get()).toBe(false)
  })
})
