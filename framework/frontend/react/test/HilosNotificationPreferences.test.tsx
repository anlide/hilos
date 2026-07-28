import {
  createHilosNotificationPreferencesStore,
  NOTIFICATION_ACTION_CHANNEL_SET,
  type HilosConnection,
} from '@hilos/core'
import { cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosNotificationPreferences } from '../src/HilosNotificationPreferences.js'

afterEach(() => cleanup())

// A minimal connection stub: the section only ever calls sendAction on it.
function fakeConnection(sendResult = true): {
  connection: HilosConnection
  sendAction: ReturnType<typeof vi.fn>
} {
  const sendAction = vi.fn().mockReturnValue(sendResult)
  return {
    connection: { sendAction } as unknown as HilosConnection,
    sendAction,
  }
}

function storeWith(mandatoryNote = false) {
  const store = createHilosNotificationPreferencesStore()
  store.applySection({
    channels: [
      { channel: 'email', label: 'Email', allowed: true, hasAddress: true },
      { channel: 'sms', label: 'SMS', allowed: false, hasAddress: false },
    ],
    mandatoryNote,
  })
  return store
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

describe('HilosNotificationPreferences', () => {
  it('renders a switch per channel and disables a no-address channel with a hint', () => {
    const { connection } = fakeConnection()
    render(
      <HilosNotificationPreferences
        connection={connection}
        store={storeWith()}
      />,
    )

    const email = byId(
      'hilos-notification-preference-toggle-email',
    ) as HTMLInputElement
    expect(email.checked).toBe(true)
    expect(email.disabled).toBe(false)

    const sms = byId(
      'hilos-notification-preference-toggle-sms',
    ) as HTMLInputElement
    expect(sms.disabled).toBe(true)
    const hint = byId('hilos-notification-preference-hint-sms') as HTMLElement
    expect(hint).not.toBeNull()
    // The disabled switch points at its hint for assistive tech.
    expect(sms.getAttribute('aria-describedby')).toBe(hint.getAttribute('id'))
  })

  it('toggles a channel by sending the action and marks the row pending (no optimistic update)', () => {
    const { connection, sendAction } = fakeConnection()
    const store = storeWith()
    render(
      <HilosNotificationPreferences connection={connection} store={store} />,
    )

    fireEvent.click(
      byId('hilos-notification-preference-toggle-email') as Element,
    )

    expect(sendAction).toHaveBeenCalledWith(NOTIFICATION_ACTION_CHANNEL_SET, {
      channel: 'email',
      enabled: false,
    })
    // The row is pending until the changed signal fans back; state is untouched.
    expect(store.pending.get().has('email')).toBe(true)
    expect(store.channels.get()[0].allowed).toBe(true)
    expect(byId('hilos-notification-preference-pending-email')).not.toBeNull()
  })

  it('clears the pending row when the send never leaves', () => {
    const { connection } = fakeConnection(false)
    const store = storeWith()
    render(
      <HilosNotificationPreferences connection={connection} store={store} />,
    )

    fireEvent.click(
      byId('hilos-notification-preference-toggle-email') as Element,
    )

    expect(store.pending.get().has('email')).toBe(false)
  })

  it('shows the mandatory note only when the section declares one', () => {
    const { connection } = fakeConnection()

    const without = render(
      <HilosNotificationPreferences
        connection={connection}
        store={storeWith(false)}
      />,
    )
    expect(byId('hilos-notification-preferences-mandatory')).toBeNull()
    without.unmount()

    render(
      <HilosNotificationPreferences
        connection={connection}
        store={storeWith(true)}
      />,
    )
    expect(byId('hilos-notification-preferences-mandatory')).not.toBeNull()
  })

  it('shows the empty state when there are no channels', () => {
    const { connection } = fakeConnection()
    render(
      <HilosNotificationPreferences
        connection={connection}
        store={createHilosNotificationPreferencesStore()}
      />,
    )

    expect(byId('hilos-notification-preferences-empty')).not.toBeNull()
  })
})
