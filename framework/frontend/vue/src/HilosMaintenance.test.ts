import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import {
  BACKUP_RESTORE_PROGRESS_SIGNAL,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosRestoreStatus,
  ProtectedModeStatus,
  RtStalenessStatus,
} from '@hilos/core'

import HilosLayout from './HilosLayout.vue'
import HilosMaintenance from './HilosMaintenance.vue'

const FROZEN: ProtectedModeStatus = {
  active: true,
  operation: 'restore',
  title: 'Restoring a backup',
  message: 'The application will be back in a few minutes.',
  bannerMessage: undefined,
  acceptsPass: false,
  passIssued: false,
  passRejected: false,
}

const FROZEN_WITHOUT_COPY: ProtectedModeStatus = {
  active: true,
  operation: undefined,
  title: undefined,
  message: undefined,
  bannerMessage: undefined,
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

/** The same window seen from inside it: the operator, or a verifier let through. */
const ADMITTED: ProtectedModeStatus = {
  ...VERIFYING,
  active: false,
  title: undefined,
  message: undefined,
  bannerMessage: 'The restore is being verified.',
}

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

/** A page reading a replica whose owner node went away at a known moment. */
const FROZEN_DATA: RtStalenessStatus = { stale: true, since: 1_760_000_000_000 }

// A minimal connection stub: the shell only reads the transport state, the
// protected-mode state and the frozen-replica state off it, and subscribes to
// all three. `push` drives the freeze the way the daemon's pushed frame would,
// `pushStaleness` the worker's frozen-replica frame, and `pushRestore` the
// addressed frame the backup agent sends to every tab of the initiator's session.
function fakeConnection(
  initial: ProtectedModeStatus,
  initialStaleness: RtStalenessStatus = RT_STALENESS_FRESH,
): {
  connection: HilosConnection
  push: (next: ProtectedModeStatus) => void
  pushStaleness: (next: RtStalenessStatus) => void
  pushRestore: (frame: HilosRestoreStatus) => void
  presented: string[]
} {
  let current = initial
  let currentStaleness = initialStaleness
  const listeners: ((next: ProtectedModeStatus) => void)[] = []
  const stalenessListeners: ((next: RtStalenessStatus) => void)[] = []
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
    get rtStaleness(): RtStalenessStatus {
      return currentStaleness
    },
    on(event: string, listener: (next: never) => void): () => void {
      if (event === 'protectedMode') {
        listeners.push(listener as (next: ProtectedModeStatus) => void)
      }
      if (event === 'rtStaleness') {
        stalenessListeners.push(listener as (next: RtStalenessStatus) => void)
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
    pushStaleness(next: RtStalenessStatus): void {
      currentStaleness = next
      for (const listener of stalenessListeners) {
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

  it('draws the bar of a restore still in flight, and captions it', async () => {
    const { connection, pushRestore } = fakeConnection(FROZEN)
    const wrapper = mount(HilosMaintenance, {
      props: { status: FROZEN, connection, adminSurface: true },
    })

    pushRestore(restoreFrame())
    await nextTick()

    // Nothing estimates this run, so the bar is the indeterminate one and the
    // caption falls back to naming the phase alone.
    const bar = wrapper.find('[data-id="maintenance-restore-bar"]')
    expect(bar.exists()).toBe(true)
    expect(bar.attributes('aria-valuenow')).toBeUndefined()
    expect(
      wrapper.find('[data-id="maintenance-restore-progress"]').text(),
    ).toBe('importing')
  })

  it('reads the bar off the estimate once the run carries one', async () => {
    const { connection, pushRestore } = fakeConnection(FROZEN)
    const wrapper = mount(HilosMaintenance, {
      props: { status: FROZEN, connection, adminSurface: true },
    })

    pushRestore(
      restoreFrame({
        estimatedSeconds: 600,
        phaseStartedAt: new Date().toISOString(),
      }),
    )
    await nextTick()

    const bar = wrapper.find('[data-id="maintenance-restore-bar"]')
    expect(bar.attributes('aria-valuenow')).not.toBeUndefined()
    const label = wrapper
      .find('[data-id="maintenance-restore-progress"]')
      .text()
    expect(label).toContain('importing')
    expect(label).toContain('%')
  })

  it('drops the bar once the run has an outcome to report', async () => {
    const { connection, pushRestore } = fakeConnection(FROZEN)
    const wrapper = mount(HilosMaintenance, {
      props: { status: FROZEN, connection, adminSurface: true },
    })

    pushRestore(
      restoreFrame({ running: false, phase: 'done', outcome: 'success' }),
    )
    await nextTick()

    expect(wrapper.find('[data-id="maintenance-restore-bar"]').exists()).toBe(
      false,
    )
    expect(
      wrapper.find('[data-id="maintenance-restore-progress"]').exists(),
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

  it('marks the connection indicator when part of the page is a frozen replica', async () => {
    const { connection, pushStaleness } = fakeConnection(
      PROTECTED_MODE_INACTIVE,
    )
    const wrapper = mountShell(connection)

    pushStaleness(FROZEN_DATA)
    await nextTick()

    const indicator = wrapper.find('[data-id="conn-state"]')
    expect(indicator.find('i').classes()).toContain('bi-snow')
    // The link is up, so the colour is the one a healthy socket has: what the
    // mark says is that part of what is shown may be old, not that anything broke.
    expect(indicator.classes()).toContain('text-success')
    expect(indicator.attributes('title')).toContain('may be out of date')
  })

  it('takes the mark off again when the frozen data becomes current', async () => {
    const { connection, pushStaleness } = fakeConnection(
      PROTECTED_MODE_INACTIVE,
      FROZEN_DATA,
    )
    const wrapper = mountShell(connection)

    pushStaleness(RT_STALENESS_FRESH)
    await nextTick()

    expect(
      wrapper.find('[data-id="conn-state"]').find('i').classes(),
    ).toContain('bi-check-circle-fill')
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

  it('carries a banner over the application for whoever is inside the window', () => {
    const wrapper = mountShell(fakeConnection(ADMITTED).connection)

    // The application itself is untouched - that is the whole point of the phase,
    // and the banner is the only thing saying it is not open to anybody else.
    expect(wrapper.find('[data-id="page-body"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="maintenance"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="protected-mode-banner"]').text()).toContain(
      'The restore is being verified.',
    )
  })

  it('raises no banner over a shell the mode is holding out', () => {
    const wrapper = mountShell(fakeConnection(VERIFYING).connection)

    expect(wrapper.find('[data-id="protected-mode-banner"]').exists()).toBe(
      false,
    )
  })

  it('raises no banner when no mode holds the node at all', () => {
    const wrapper = mountShell(
      fakeConnection(PROTECTED_MODE_INACTIVE).connection,
    )

    expect(wrapper.find('[data-id="protected-mode-banner"]').exists()).toBe(
      false,
    )
  })

  it('drops the banner the moment the window closes', async () => {
    // The lift is announced with both bits down, and the client reloads on it -
    // but the banner must be gone by the frame itself, not by the reload.
    const { connection, push } = fakeConnection(ADMITTED)
    const wrapper = mountShell(connection)

    push(PROTECTED_MODE_INACTIVE)
    await nextTick()

    expect(wrapper.find('[data-id="protected-mode-banner"]').exists()).toBe(
      false,
    )
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
