import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionError,
  createSignal,
  type ActionHandle,
  type HilosProfileSession,
  type HilosProfileSessionActions,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosProfileSessions } from '../src/profile/HilosProfileSessions.js'

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

async function settle(fixture: ComponentFixture<unknown>): Promise<void> {
  await fixture.whenStable()
  fixture.detectChanges()
}

function byId(id: string): HTMLElement {
  const found = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (!found) throw new Error(`Missing ${id}`)
  return found
}

function makeSession(
  id: number,
  options: { current?: boolean; impersonated?: boolean } = {},
): HilosProfileSession {
  return {
    id,
    deviceName: `Device ${id}`,
    createdAt: '2026-09-30T10:00:00Z',
    lastSeenAt: '2026-09-30T10:00:00Z',
    expiresAt: '2026-10-30T10:00:00Z',
    current: options.current ?? false,
    impersonated: options.impersonated ?? false,
    tabs: [],
  }
}

function makeHandle(promise: Promise<unknown>): ActionHandle {
  return {
    requestId: 'req-1',
    done: promise as Promise<{ message?: string; reply?: unknown }>,
    loading: createSignal(false),
  }
}

function setup(
  initialSessions: readonly HilosProfileSession[] = [
    makeSession(1, { current: true }),
    makeSession(2),
  ],
  endOtherResult: () => Promise<unknown> = () =>
    Promise.resolve({ reply: null }),
) {
  const endOtherSessions = vi.fn(() => makeHandle(endOtherResult()))
  const actions: HilosProfileSessionActions = {
    endSession: vi.fn(),
    endOtherSessions,
  }
  const fixture = TestBed.createComponent(HilosProfileSessions)
  fixture.componentRef.setInput('sessions', initialSessions)
  fixture.componentRef.setInput('actions', actions)
  fixture.detectChanges()

  return { actions, endOtherSessions, fixture }
}

async function open(fixture: ComponentFixture<unknown>): Promise<void> {
  byId('profile-sessions-end-others').click()
  await settle(fixture)
}

describe('HilosProfileSessions (Angular)', () => {
  it('opens confirmation modal without calling endOtherSessions on button click', async () => {
    const { endOtherSessions, fixture } = setup()
    expect(
      document.querySelector('[data-id="profile-sessions-end-others-confirm"]'),
    ).toBeNull()

    await open(fixture)

    expect(
      document.querySelector('[data-id="profile-sessions-end-others-confirm"]'),
    ).not.toBeNull()
    expect(endOtherSessions).not.toHaveBeenCalled()
  })

  it('shows count 1 when sessions include this one, an impersonated session, and one regular session', async () => {
    const { fixture } = setup([
      makeSession(1, { current: true }),
      makeSession(2, { impersonated: true }),
      makeSession(3),
    ])
    await open(fixture)

    expect(byId('profile-sessions-end-others-count').textContent?.trim()).toBe(
      '1',
    )
  })

  it('reactively updates count under the open modal and disables confirm when all other sessions end', async () => {
    const { actions, fixture } = setup()
    await open(fixture)
    expect(byId('profile-sessions-end-others-count').textContent?.trim()).toBe(
      '1',
    )

    fixture.componentRef.setInput('sessions', [
      makeSession(1, { current: true }),
      makeSession(2),
      makeSession(3),
    ])
    await settle(fixture)
    expect(byId('profile-sessions-end-others-count').textContent?.trim()).toBe(
      '2',
    )

    fixture.componentRef.setInput('sessions', [
      makeSession(1, { current: true }),
    ])
    await settle(fixture)
    expect(byId('profile-sessions-end-others-count').textContent?.trim()).toBe(
      '0',
    )
    expect(byId('profile-sessions-end-others-error').textContent).toContain(
      'Your other sessions have already ended',
    )
    expect(
      byId('profile-sessions-end-others-confirm').hasAttribute('disabled'),
    ).toBe(true)
    expect(actions.endOtherSessions).not.toHaveBeenCalled()
  })

  it('calls endOtherSessions once on Sign out and closes modal on success', async () => {
    const { endOtherSessions, fixture } = setup()
    await open(fixture)

    byId('profile-sessions-end-others-confirm').click()
    await settle(fixture)

    expect(endOtherSessions).toHaveBeenCalledTimes(1)
    expect(
      document.querySelector('[data-id="profile-sessions-end-others-confirm"]'),
    ).toBeNull()
  })

  it('keeps modal open and displays refusal on action failure', async () => {
    const { endOtherSessions, fixture } = setup(undefined, () =>
      Promise.reject(
        new ActionError(
          'hilos_sessions_end_others',
          'fail',
          'Denied by policy',
        ),
      ),
    )
    await open(fixture)

    byId('profile-sessions-end-others-confirm').click()
    await settle(fixture)

    expect(endOtherSessions).toHaveBeenCalledTimes(1)
    expect(
      document.querySelector('[data-id="profile-sessions-end-others-confirm"]'),
    ).not.toBeNull()
    expect(byId('profile-sessions-end-others-error').textContent).toContain(
      'Denied by policy',
    )
  })

  it('closes modal on Cancel without calling endOtherSessions', async () => {
    const { endOtherSessions, fixture } = setup()
    await open(fixture)

    const cancel = document.querySelector<HTMLButtonElement>(
      '.modal.show .modal-footer .btn-secondary',
    )
    expect(cancel).not.toBeNull()
    cancel?.click()
    await settle(fixture)

    expect(
      document.querySelector('[data-id="profile-sessions-end-others-confirm"]'),
    ).toBeNull()
    expect(endOtherSessions).not.toHaveBeenCalled()
  })

  it('disables page button when there are no other sessions to end', () => {
    setup([makeSession(1, { current: true })])
    expect(byId('profile-sessions-end-others').hasAttribute('disabled')).toBe(
      true,
    )
  })
})
