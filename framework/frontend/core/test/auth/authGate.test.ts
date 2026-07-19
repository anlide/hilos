import { describe, expect, it } from 'vitest'
import { createAuthGate } from '../../src/auth/authGate.js'
import { createSignal, type WritableSignal } from '../../src/state/signal.js'
import { type PageSubscriptionError } from '../../src/protocol/pageError.js'

/** A router double: a writable page-error signal plus a clear() that records. */
function fakeRouter(pageError: PageSubscriptionError | null = null) {
  const signal = createSignal<PageSubscriptionError | null>(pageError)
  let cleared = 0

  return {
    pageError: signal,
    clearPageError: (): void => {
      cleared += 1
      signal.set(null)
    },
    clearedCount: (): number => cleared,
    setError: (error: PageSubscriptionError | null): void => signal.set(error),
  }
}

/** An action-error source: emit() drives the gate like a connection would. */
function fakeActionErrors() {
  const listeners: Array<(signal: { errorCode?: string | undefined }) => void> =
    []

  return {
    on(
      _event: 'actionError',
      listener: (signal: { errorCode?: string | undefined }) => void,
    ): () => void {
      listeners.push(listener)

      return () => {}
    },
    emit(errorCode?: string): void {
      for (const listener of listeners) {
        listener({ errorCode })
      }
    },
  }
}

const UNAUTHORIZED: PageSubscriptionError = {
  page: 'profile',
  httpCode: 401,
  errorCode: 'unauthorized',
  message: 'Authentication required',
}

const FORBIDDEN: PageSubscriptionError = {
  page: 'admin',
  httpCode: 403,
  errorCode: 'forbidden',
  message: 'Access forbidden',
}

function setup(pageError: PageSubscriptionError | null = null) {
  const router = fakeRouter(pageError)
  const currentUserId: WritableSignal<number | null> = createSignal<
    number | null
  >(null)
  const actionErrors = fakeActionErrors()
  const gate = createAuthGate({ router, currentUserId, actionErrors })

  return { router, currentUserId, actionErrors, gate }
}

describe('createAuthGate', () => {
  it('starts with the modal closed', () => {
    const { gate } = setup()
    expect(gate.modalOpen.get()).toBe(false)
  })

  it('opens the modal on requireAuth and closes it on dismiss', () => {
    const { gate } = setup()

    gate.requireAuth()
    expect(gate.modalOpen.get()).toBe(true)

    gate.dismiss()
    expect(gate.modalOpen.get()).toBe(false)
  })

  it('opens the modal on an unauthorized action error (the safety net)', () => {
    const { gate, actionErrors } = setup()

    actionErrors.emit('unauthorized')

    expect(gate.modalOpen.get()).toBe(true)
  })

  it('ignores an action error with any other code', () => {
    const { gate, actionErrors } = setup()

    actionErrors.emit('rate_limited')
    actionErrors.emit(undefined)

    expect(gate.modalOpen.get()).toBe(false)
  })

  it('clears a 401 page error when the session authenticates (resume in place)', () => {
    const { gate, router, currentUserId } = setup(UNAUTHORIZED)

    currentUserId.set(42)

    expect(router.clearedCount()).toBe(1)
    expect(router.pageError.get()).toBeNull()
    expect(gate.modalOpen.get()).toBe(false)
  })

  it('closes the modal when the session authenticates', () => {
    const { gate, currentUserId } = setup()
    gate.requireAuth()

    currentUserId.set(7)

    expect(gate.modalOpen.get()).toBe(false)
  })

  it('leaves a non-401 page error untouched on authentication', () => {
    const { router, currentUserId } = setup(FORBIDDEN)

    currentUserId.set(1)

    expect(router.clearedCount()).toBe(0)
    expect(router.pageError.get()?.httpCode).toBe(403)
  })

  it('does not resume while the session stays anonymous', () => {
    const { router, currentUserId } = setup(UNAUTHORIZED)

    // A redundant null write must not trip the clear.
    currentUserId.set(null)

    expect(router.clearedCount()).toBe(0)
    expect(router.pageError.get()?.httpCode).toBe(401)
  })

  it('works without an action source (page-case only)', () => {
    const router = fakeRouter(UNAUTHORIZED)
    const currentUserId = createSignal<number | null>(null)
    const gate = createAuthGate({ router, currentUserId })

    gate.requireAuth()
    expect(gate.modalOpen.get()).toBe(true)

    currentUserId.set(9)
    expect(router.pageError.get()).toBeNull()
    expect(gate.modalOpen.get()).toBe(false)
  })
})
