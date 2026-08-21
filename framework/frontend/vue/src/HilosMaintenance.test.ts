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
  acceptsPass: false,
  passRejected: false,
}

const FROZEN_WITHOUT_COPY: ProtectedModeStatus = {
  active: true,
  operation: undefined,
  title: undefined,
  message: undefined,
  acceptsPass: false,
  passRejected: false,
}

/** The verification window: still locked out, but a code will open it. */
const VERIFYING: ProtectedModeStatus = { ...FROZEN, acceptsPass: true }

/** The window, after a code that opened nothing. */
const REJECTED: ProtectedModeStatus = { ...VERIFYING, passRejected: true }

// A minimal connection stub: the shell only reads the transport state and the
// protected-mode state off it, and subscribes to both. `push` drives the freeze
// the way the daemon's pushed frame would.
function fakeConnection(initial: ProtectedModeStatus): {
  connection: HilosConnection
  push: (next: ProtectedModeStatus) => void
  presented: string[]
} {
  let current = initial
  const listeners: ((next: ProtectedModeStatus) => void)[] = []
  const presented: string[] = []
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
    presentProtectedModePass(pass: string): void {
      presented.push(pass)
    },
  } as unknown as HilosConnection

  return {
    connection,
    presented,
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
    const wrapper = mount(HilosMaintenance, {
      props: {
        status: FROZEN,
        connection: fakeConnection(FROZEN).connection,
        adminSurface: true,
      },
    })

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
      props: {
        status: FROZEN_WITHOUT_COPY,
        connection: fakeConnection(FROZEN_WITHOUT_COPY).connection,
        adminSurface: true,
      },
    })

    expect(
      wrapper.find('[data-id="maintenance"]').attributes(),
    ).not.toHaveProperty('data-operation')
    expect(wrapper.find('[data-id="maintenance-title"]').text()).toBe(
      'Maintenance in progress',
    )
    expect(wrapper.find('[data-id="maintenance-message"]').text()).not.toBe('')
  })

  it('offers no code field on a freeze that admits nobody', () => {
    // The frozen phases have no window to be let into, so a field there would
    // promise a way in that does not exist.
    const wrapper = mount(HilosMaintenance, {
      props: {
        status: FROZEN,
        connection: fakeConnection(FROZEN).connection,
        adminSurface: true,
      },
    })

    expect(wrapper.find('[data-id="maintenance-pass-form"]').exists()).toBe(
      false,
    )
  })

  it('offers the code field on an administrative surface only', () => {
    // The verification window is not a public announcement: a visitor holds no
    // code, so on a public url he sees the same screen as in the active phase.
    const onPublic = mount(HilosMaintenance, {
      props: {
        status: VERIFYING,
        connection: fakeConnection(VERIFYING).connection,
        adminSurface: false,
      },
    })
    expect(onPublic.find('[data-id="maintenance-pass-form"]').exists()).toBe(
      false,
    )

    const onAdmin = mount(HilosMaintenance, {
      props: {
        status: VERIFYING,
        connection: fakeConnection(VERIFYING).connection,
        adminSurface: true,
      },
    })
    expect(onAdmin.find('[data-id="maintenance-pass-form"]').exists()).toBe(
      true,
    )
  })

  it('presents the typed code through the connection', async () => {
    const { connection, presented } = fakeConnection(VERIFYING)
    const wrapper = mount(HilosMaintenance, {
      props: { status: VERIFYING, connection, adminSurface: true },
    })

    await wrapper.find('[data-id="maintenance-pass"]').setValue('the-key')
    await wrapper.find('[data-id="maintenance-pass-form"]').trigger('submit')

    expect(presented).toEqual(['the-key'])
  })

  it('says so when the code was not accepted', () => {
    const wrapper = mount(HilosMaintenance, {
      props: {
        status: REJECTED,
        connection: fakeConnection(REJECTED).connection,
        adminSurface: true,
      },
    })

    expect(wrapper.find('[data-id="maintenance-pass-error"]').exists()).toBe(
      true,
    )
    expect(
      wrapper.find('[data-id="maintenance-pass"]').attributes('aria-invalid'),
    ).toBe('true')
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
