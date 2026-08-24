import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import {
  BACKUP_RESTORE_PROGRESS_SIGNAL,
  PROTECTED_MODE_INACTIVE,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosRestoreStatus,
  ProtectedModeStatus,
} from '@hilos/core'

import HilosLayout from './HilosLayout.vue'
import HilosMaintenance from './HilosMaintenance.vue'

const FROZEN: ProtectedModeStatus = {
  active: true,
  operation: 'restore',
  title: 'Restoring a backup',
  message: 'The application will be back in a few minutes.',
  acceptsPass: false,
  passIssued: false,
  passRejected: false,
}

const FROZEN_WITHOUT_COPY: ProtectedModeStatus = {
  active: true,
  operation: undefined,
  title: undefined,
  message: undefined,
  acceptsPass: false,
  passIssued: false,
  passRejected: false,
}

/** The verification window at the moment it opens: no code has been minted yet. */
const VERIFYING: ProtectedModeStatus = { ...FROZEN, acceptsPass: true }

/** The same window once the operator minted a code: still locked out, but a code will open it. */
const MINTED: ProtectedModeStatus = { ...VERIFYING, passIssued: true }

/** The window, after a code that opened nothing. */
const REJECTED: ProtectedModeStatus = { ...MINTED, passRejected: true }

/** A restore frame as the backup agent addresses it to the initiator's session. */
function restoreFrame(
  overrides: Partial<HilosRestoreStatus> = {},
): HilosRestoreStatus {
  return {
    running: true,
    backupId: '2026-08-24-full',
    scope: 'full',
    phase: 'importing',
    phaseStartedAt: null,
    startedAt: null,
    finishedAt: null,
    outcome: null,
    failureReason: null,
    estimatedSeconds: null,
    rehydrateComplete: true,
    rehydrateProblems: [],
    databaseTouched: false,
    ...overrides,
  }
}

// A minimal connection stub: the shell only reads the transport state and the
// protected-mode state off it, and subscribes to both. `push` drives the freeze
// the way the daemon's pushed frame would, and `pushRestore` the addressed frame
// the backup agent sends to every tab of the initiator's session.
function fakeConnection(initial: ProtectedModeStatus): {
  connection: HilosConnection
  push: (next: ProtectedModeStatus) => void
  pushRestore: (frame: HilosRestoreStatus) => void
  presented: string[]
} {
  let current = initial
  const listeners: ((next: ProtectedModeStatus) => void)[] = []
  const projectListeners: ((signal: {
    type: string
    data: unknown
  }) => void)[] = []
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
      if (event === 'projectSignal') {
        projectListeners.push(
          listener as unknown as (signal: {
            type: string
            data: unknown
          }) => void,
        )
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
    pushRestore(frame: HilosRestoreStatus): void {
      for (const listener of projectListeners) {
        listener({ type: BACKUP_RESTORE_PROGRESS_SIGNAL, data: frame })
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
    // code, so on a public url he sees the same screen as in the active phase -
    // neither the field nor the sentence that stands in for it.
    const onPublic = mount(HilosMaintenance, {
      props: {
        status: MINTED,
        connection: fakeConnection(MINTED).connection,
        adminSurface: false,
      },
    })
    expect(onPublic.find('[data-id="maintenance-pass-form"]').exists()).toBe(
      false,
    )
    expect(onPublic.find('[data-id="maintenance-pass-pending"]').exists()).toBe(
      false,
    )

    const onAdmin = mount(HilosMaintenance, {
      props: {
        status: MINTED,
        connection: fakeConnection(MINTED).connection,
        adminSurface: true,
      },
    })
    expect(onAdmin.find('[data-id="maintenance-pass-form"]').exists()).toBe(
      true,
    )
  })

  it('says a code is not out yet instead of offering an empty field', () => {
    // The window opens before the operator mints anything, and a field there
    // would take nothing that could be typed into it.
    const wrapper = mount(HilosMaintenance, {
      props: {
        status: VERIFYING,
        connection: fakeConnection(VERIFYING).connection,
        adminSurface: true,
      },
    })

    expect(wrapper.find('[data-id="maintenance-pass-form"]').exists()).toBe(
      false,
    )
    expect(
      wrapper.find('[data-id="maintenance-pass-pending"]').text(),
    ).not.toBe('')
  })

  it('swaps the sentence for the field when the first code lands', async () => {
    // No navigation and no reload: the pushed frame alone turns the waiting
    // sentence into the field the verifier is already looking for.
    const wrapper = mount(HilosMaintenance, {
      props: {
        status: VERIFYING,
        connection: fakeConnection(VERIFYING).connection,
        adminSurface: true,
      },
    })

    await wrapper.setProps({ status: MINTED })

    expect(wrapper.find('[data-id="maintenance-pass-form"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('[data-id="maintenance-pass-pending"]').exists()).toBe(
      false,
    )
  })

  it('offers no waiting sentence on a freeze that admits nobody', () => {
    const wrapper = mount(HilosMaintenance, {
      props: {
        status: FROZEN,
        connection: fakeConnection(FROZEN).connection,
        adminSurface: true,
      },
    })

    expect(wrapper.find('[data-id="maintenance-pass-pending"]').exists()).toBe(
      false,
    )
  })

  it('presents the typed code through the connection', async () => {
    const { connection, presented } = fakeConnection(MINTED)
    const wrapper = mount(HilosMaintenance, {
      props: { status: MINTED, connection, adminSurface: true },
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

  it('shows no restore panel to a visitor whose session asked for nothing', () => {
    const wrapper = mount(HilosMaintenance, {
      props: {
        status: FROZEN,
        connection: fakeConnection(FROZEN).connection,
        adminSurface: true,
      },
    })

    expect(wrapper.find('[data-id="maintenance-restore"]').exists()).toBe(false)
  })

  it('reports the phase of the restore addressed to this session', async () => {
    const { connection, pushRestore } = fakeConnection(FROZEN)
    const wrapper = mount(HilosMaintenance, {
      props: { status: FROZEN, connection, adminSurface: true },
    })

    pushRestore(restoreFrame())
    await nextTick()

    expect(wrapper.find('[data-id="maintenance-restore-phase"]').text()).toBe(
      'Restore 2026-08-24-full · importing',
    )
    // Nothing has ended, so nothing is said about how it ended.
    expect(
      wrapper.find('[data-id="maintenance-restore-outcome"]').exists(),
    ).toBe(false)
  })

  it('says how the restore ended, and what a failure left behind', async () => {
    const { connection, pushRestore } = fakeConnection(FROZEN)
    const wrapper = mount(HilosMaintenance, {
      props: { status: FROZEN, connection, adminSurface: true },
    })

    pushRestore(
      restoreFrame({
        running: false,
        phase: 'failed',
        outcome: 'error',
        failureReason: 'the archive checksum did not match',
        databaseTouched: true,
        rehydrateComplete: false,
      }),
    )
    await nextTick()

    const outcome = wrapper.find('[data-id="maintenance-restore-outcome"]')
    expect(outcome.text()).toContain('checksum did not match')
    expect(outcome.text()).toContain('already being replaced')
    expect(outcome.text()).toContain('stays closed')
    expect(wrapper.find('[data-id="maintenance-restore"]').classes()).toContain(
      'alert-danger',
    )
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
