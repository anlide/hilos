import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import {
  bindTimeZoneState,
  formatMoment,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core/base'

import User from './User.vue'
import { userDetail } from './userPage.js'

const inputs = await vi.hoisted(async () => {
  const { ScopeManager } =
    await vi.importActual<typeof import('@hilos/core/base')>('@hilos/core/base')
  return { scopes: new ScopeManager() }
})
vi.mock('../../bootstrap/session.js', () => ({ scopes: inputs.scopes }))
vi.mock('../../pages/routes.js', () => ({
  router: {
    path: (_page: string, params: { id: string }) => `/user/${params.id}`,
  },
}))

const listeners = new Set<(signal: ProjectSignal) => void>()
let release: () => void
enableAutoUnmount(afterEach)
beforeEach(() => {
  release = bindTimeZoneState({
    projectTimeZone: 'UTC',
    on(event: string, listener: (signal: ProjectSignal) => void) {
      if (event === 'projectSignal') listeners.add(listener)
      return () => {
        listeners.delete(listener)
      }
    },
  } as unknown as HilosConnection)
  const page = inputs.scopes.openPage('user')
  page.entities.upsert(
    { type: 'user', id: 7 },
    {
      id: 7,
      name: 'Ann',
      lastActivity: 0,
      timeZone: 'Asia/Tokyo',
    },
  )
  page.data.set('user', { type: 'user', id: 7 })
  page.data.set('userPresence', { presence: 'offline', onlineSessionCount: 0 })
})
afterEach(() => {
  choose(null)
  release()
})

/** Deliver the reader's own zone through the real session binding. */
function choose(timeZone: string | null): void {
  for (const listener of listeners) {
    listener({
      type: 'handshake_response',
      data: { entities: { currentUser: { id: 99, timeZone } } },
    } as ProjectSignal)
  }
}

describe('public user activity', () => {
  it('keeps the numeric moment and reformats epoch zero live in the reader zone and Automatic', async () => {
    const view = mount(User)
    const automatic = formatMoment(0)
    expect(userDetail.get()?.lastActivity).toBe(0)
    expect(view.get('[data-id="user-last-activity"]').text()).toBe(automatic)
    choose('Pacific/Kiritimati')
    await nextTick()
    expect(formatMoment(0)).not.toBe(automatic)
    expect(view.get('[data-id="user-last-activity"]').text()).toBe(
      formatMoment(0),
    )
    expect(userDetail.get()?.lastActivity).toBe(0)
    choose(null)
    await nextTick()
    expect(view.get('[data-id="user-last-activity"]').text()).toBe(automatic)
  })

  it.each([null, '2026-01-01 00:00:00', NaN, Infinity])(
    'omits the whole activity row for an absent or invalid moment %j',
    (lastActivity) => {
      inputs.scopes
        .page()!
        .entities.upsert({ type: 'user', id: 7 }, { lastActivity })
      const view = mount(User)
      expect(view.find('[data-id="user-last-activity"]').exists()).toBe(false)
      expect(view.text()).not.toContain('Last activity')
    },
  )

  it('preserves the merged account and survivor link while hiding its activity and sessions', async () => {
    const view = mount(User)
    inputs.scopes
      .page()!
      .data.set('userMerge', { mergedInto: 8, mergedIntoName: 'Bob' })
    await nextTick()
    expect(view.get('[data-id="user-merged-badge"]').text()).toBe('Merged')
    expect(
      view.get('[data-id="user-merged-into-link"]').attributes('href'),
    ).toBe('/user/8')
    expect(view.get('[data-id="user-merged-into-link"]').text()).toBe('Bob')
    expect(view.find('[data-id="user-last-activity"]').exists()).toBe(false)
    expect(view.find('[data-id="user-sessions"]').exists()).toBe(false)
  })
})
