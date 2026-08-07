import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import { PROTECTED_MODE_INACTIVE } from '@hilos/core'
import type { HilosConnection, ProtectedModeStatus } from '@hilos/core'

import HilosLayout from './HilosLayout.vue'
import HilosMaintenance from './HilosMaintenance.vue'

const FROZEN: ProtectedModeStatus = {
  active: true,
  operation: 'restore',
  title: 'Restoring a backup',
  message: 'The application will be back in a few minutes.',
}

const FROZEN_WITHOUT_COPY: ProtectedModeStatus = {
  active: true,
  operation: undefined,
  title: undefined,
  message: undefined,
}

// A minimal connection stub: the shell only reads the transport state and the
// protected-mode state off it, and subscribes to both. `push` drives the freeze
// the way the daemon's pushed frame would.
function fakeConnection(initial: ProtectedModeStatus): {
  connection: HilosConnection
  push: (next: ProtectedModeStatus) => void
} {
  let current = initial
  const listeners: ((next: ProtectedModeStatus) => void)[] = []
  const connection = {
    state: 'connected',
    get protectedMode(): ProtectedModeStatus {
      return current
    },
    on(event: string, listener: (next: never) => void): () => void {
      if (event === 'protectedMode') {
        listeners.push(listener as (next: ProtectedModeStatus) => void)
      }
      return () => {}
    },
  } as unknown as HilosConnection

  return {
    connection,
    push(next: ProtectedModeStatus): void {
      current = next
      for (const listener of listeners) {
        listener(next)
      }
    },
  }
}

function mountShell(connection: HilosConnection) {
  return mount(HilosLayout, {
    props: { connection },
    slots: { default: '<p data-id="page-body">Page</p>' },
  })
}

describe('HilosMaintenance', () => {
  it('renders the copy the backend authored', () => {
    const wrapper = mount(HilosMaintenance, { props: { status: FROZEN } })

    const surface = wrapper.find('[data-id="maintenance"]')
    expect(surface.attributes('data-operation')).toBe('restore')
    expect(wrapper.find('[data-id="maintenance-title"]').text()).toBe(
      'Restoring a backup',
    )
    expect(wrapper.find('[data-id="maintenance-message"]').text()).toBe(
      'The application will be back in a few minutes.',
    )
  })

  it('falls back to its own copy when the frame carried none', () => {
    const wrapper = mount(HilosMaintenance, {
      props: { status: FROZEN_WITHOUT_COPY },
    })

    expect(
      wrapper.find('[data-id="maintenance"]').attributes(),
    ).not.toHaveProperty('data-operation')
    expect(wrapper.find('[data-id="maintenance-title"]').text()).toBe(
      'Maintenance in progress',
    )
    expect(wrapper.find('[data-id="maintenance-message"]').text()).not.toBe('')
  })
})

describe('HilosLayout under protected mode', () => {
  it('renders the ordinary shell while the mode is off', () => {
    const wrapper = mountShell(
      fakeConnection(PROTECTED_MODE_INACTIVE).connection,
    )

    expect(wrapper.find('[data-id="page-body"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="maintenance"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="nav-brand"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="app-footer"]').exists()).toBe(true)
  })

  it('replaces the page and every link with the maintenance surface', () => {
    const wrapper = mountShell(fakeConnection(FROZEN).connection)

    expect(wrapper.find('[data-id="maintenance"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="page-body"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="nav-brand"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="nav-admin"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="app-footer"]').exists()).toBe(false)
    // The one status worth telling the visitor during planned work.
    expect(wrapper.find('[data-id="conn-state"]').exists()).toBe(true)
  })

  it('raises the surface when the mode arrives on an open connection', async () => {
    const { connection, push } = fakeConnection(PROTECTED_MODE_INACTIVE)
    const wrapper = mountShell(connection)

    push(FROZEN)
    await nextTick()

    expect(wrapper.find('[data-id="maintenance-title"]').text()).toBe(
      'Restoring a backup',
    )
    expect(wrapper.find('[data-id="page-body"]').exists()).toBe(false)
  })
})
