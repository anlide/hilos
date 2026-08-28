import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
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

import { HilosLayout } from '../src/HilosLayout.js'
import { HilosMaintenance } from '../src/HilosMaintenance.js'

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

/** A page reading a replica whose owner node went away at a known moment. */
const FROZEN_DATA: RtStalenessStatus = { stale: true, since: 1_760_000_000_000 }

/** The verification window: still locked out, but a code will open it. */
const VERIFYING: ProtectedModeStatus = { ...FROZEN, acceptsPass: true }

/** The same window once the operator minted a code. */
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
  const listeners: (() => void)[] = []
  const stalenessListeners: (() => void)[] = []
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
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'protectedMode') {
        listeners.push(listener as () => void)
      }
      if (event === 'rtStaleness') {
        stalenessListeners.push(listener as () => void)
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
        listener()
      }
    },
    pushStaleness(next: RtStalenessStatus): void {
      currentStaleness = next
      for (const listener of stalenessListeners) {
        listener()
      }
    },
    pushRestore(frame: HilosRestoreStatus): void {
      for (const listener of projectListeners) {
        listener({ type: BACKUP_RESTORE_PROGRESS_SIGNAL, data: frame })
      }
    },
  }
}

function renderShell(connection: HilosConnection): HTMLElement {
  return render(
    <HilosLayout connection={connection}>
      <p data-id="page-body">Page</p>
    </HilosLayout>,
  ).container
}

function surface(container: HTMLElement, id: string): Element | null {
  return container.querySelector(`[data-id="${id}"]`)
}

describe('HilosMaintenance', () => {
  afterEach(cleanup)

  it('renders the copy the backend authored', () => {
    const { container } = render(
      <HilosMaintenance
        status={FROZEN}
        connection={fakeConnection(FROZEN).connection}
        adminSurface
      />,
    )

    expect(
      surface(container, 'maintenance')?.getAttribute('data-operation'),
    ).toBe('restore')
    expect(surface(container, 'maintenance-title')?.textContent).toBe(
      'Restoring a backup',
    )
    expect(surface(container, 'maintenance-message')?.textContent).toBe(
      'The application will be back in a few minutes.',
    )
  })

  it('falls back to its own copy when the frame carried none', () => {
    const { container } = render(
      <HilosMaintenance
        status={FROZEN_WITHOUT_COPY}
        connection={fakeConnection(FROZEN_WITHOUT_COPY).connection}
        adminSurface
      />,
    )

    expect(
      surface(container, 'maintenance')?.hasAttribute('data-operation'),
    ).toBe(false)
    expect(surface(container, 'maintenance-title')?.textContent).toBe(
      'Maintenance in progress',
    )
    expect(surface(container, 'maintenance-message')?.textContent).not.toBe('')
  })

  it('offers no code field on a freeze that admits nobody', () => {
    // The frozen phases have no window to be let into, so a field there would
    // promise a way in that does not exist.
    const { container } = render(
      <HilosMaintenance
        status={FROZEN}
        connection={fakeConnection(FROZEN).connection}
        adminSurface
      />,
    )

    expect(surface(container, 'maintenance-pass-form')).toBeNull()
  })

  it('offers the code field on an administrative surface only', () => {
    // The verification window is not a public announcement: a visitor holds no
    // code, so on a public url he sees the same screen as in the active phase -
    // neither the field nor the sentence that stands in for it.
    const onPublic = render(
      <HilosMaintenance
        status={MINTED}
        connection={fakeConnection(MINTED).connection}
        adminSurface={false}
      />,
    )
    expect(surface(onPublic.container, 'maintenance-pass-form')).toBeNull()
    expect(surface(onPublic.container, 'maintenance-pass-pending')).toBeNull()
    cleanup()

    const onAdmin = render(
      <HilosMaintenance
        status={MINTED}
        connection={fakeConnection(MINTED).connection}
        adminSurface
      />,
    )
    expect(surface(onAdmin.container, 'maintenance-pass-form')).not.toBeNull()
  })

  it('says a code is not out yet instead of offering an empty field', () => {
    // The window opens before the operator mints anything, and a field there
    // would take nothing that could be typed into it.
    const { container } = render(
      <HilosMaintenance
        status={VERIFYING}
        connection={fakeConnection(VERIFYING).connection}
        adminSurface
      />,
    )

    expect(surface(container, 'maintenance-pass-form')).toBeNull()
    expect(
      surface(container, 'maintenance-pass-pending')?.textContent,
    ).not.toBe('')
  })

  it('swaps the sentence for the field when the first code lands', () => {
    // No navigation and no reload: the pushed frame alone turns the waiting
    // sentence into the field the verifier is already looking for.
    const { container, rerender } = render(
      <HilosMaintenance
        status={VERIFYING}
        connection={fakeConnection(VERIFYING).connection}
        adminSurface
      />,
    )

    act(() => {
      rerender(
        <HilosMaintenance
          status={MINTED}
          connection={fakeConnection(MINTED).connection}
          adminSurface
        />,
      )
    })

    expect(surface(container, 'maintenance-pass-form')).not.toBeNull()
    expect(surface(container, 'maintenance-pass-pending')).toBeNull()
  })

  it('offers no waiting sentence on a freeze that admits nobody', () => {
    const { container } = render(
      <HilosMaintenance
        status={FROZEN}
        connection={fakeConnection(FROZEN).connection}
        adminSurface
      />,
    )

    expect(surface(container, 'maintenance-pass-pending')).toBeNull()
  })

  it('presents the typed code through the connection', () => {
    const { connection, presented } = fakeConnection(MINTED)
    const { container } = render(
      <HilosMaintenance status={MINTED} connection={connection} adminSurface />,
    )
    const field = surface(container, 'maintenance-pass') as HTMLInputElement

    act(() => {
      fireEvent.change(field, { target: { value: 'the-key' } })
    })
    act(() => {
      fireEvent.submit(surface(container, 'maintenance-pass-form') as Element)
    })

    expect(presented).toEqual(['the-key'])
  })

  it('says so when the code was not accepted', () => {
    const { container } = render(
      <HilosMaintenance
        status={REJECTED}
        connection={fakeConnection(REJECTED).connection}
        adminSurface
      />,
    )

    expect(surface(container, 'maintenance-pass-error')).not.toBeNull()
    expect(
      surface(container, 'maintenance-pass')?.getAttribute('aria-invalid'),
    ).toBe('true')
  })

  it('shows no restore panel to a visitor whose session asked for nothing', () => {
    const { container } = render(
      <HilosMaintenance
        status={FROZEN}
        connection={fakeConnection(FROZEN).connection}
        adminSurface
      />,
    )

    expect(surface(container, 'maintenance-restore')).toBeNull()
  })

  it('reports the phase of the restore addressed to this session', () => {
    const { connection, pushRestore } = fakeConnection(FROZEN)
    const { container } = render(
      <HilosMaintenance status={FROZEN} connection={connection} adminSurface />,
    )

    act(() => pushRestore(restoreFrame()))

    expect(surface(container, 'maintenance-restore-phase')?.textContent).toBe(
      'Restore 2026-08-24-full · importing',
    )
    // Nothing has ended, so nothing is said about how it ended.
    expect(surface(container, 'maintenance-restore-outcome')).toBeNull()
  })

  it('says how the restore ended, and what a failure left behind', () => {
    const { connection, pushRestore } = fakeConnection(FROZEN)
    const { container } = render(
      <HilosMaintenance status={FROZEN} connection={connection} adminSurface />,
    )

    act(() =>
      pushRestore(
        restoreFrame({
          running: false,
          phase: 'failed',
          outcome: 'error',
          failureReason: 'the archive checksum did not match',
          databaseTouched: true,
          rehydrateComplete: false,
        }),
      ),
    )

    const outcome = surface(container, 'maintenance-restore-outcome')
    expect(outcome?.textContent).toContain('checksum did not match')
    expect(outcome?.textContent).toContain('already being replaced')
    expect(outcome?.textContent).toContain('stays closed')
    expect(surface(container, 'maintenance-restore')?.className).toContain(
      'alert-danger',
    )
  })
})

describe('HilosLayout under protected mode', () => {
  afterEach(cleanup)

  it('renders the ordinary shell while the mode is off', () => {
    const container = renderShell(
      fakeConnection(PROTECTED_MODE_INACTIVE).connection,
    )

    expect(surface(container, 'page-body')).not.toBeNull()
    expect(surface(container, 'maintenance')).toBeNull()
    expect(surface(container, 'nav-brand')).not.toBeNull()
    expect(surface(container, 'app-footer')).not.toBeNull()
  })

  it('marks the connection indicator when part of the page is a frozen replica', () => {
    const { connection, pushStaleness } = fakeConnection(
      PROTECTED_MODE_INACTIVE,
    )
    const container = renderShell(connection)

    act(() => {
      pushStaleness(FROZEN_DATA)
    })

    const indicator = container.querySelector('[data-id="conn-state"]')
    expect(indicator?.querySelector('i')?.className).toContain('bi-snow')
    // The link is up, so the colour is the one a healthy socket has: what the
    // mark says is that part of what is shown may be old, not that anything broke.
    expect(indicator?.className).toContain('text-success')
    expect(indicator?.getAttribute('title')).toContain('may be out of date')
  })

  it('takes the mark off again when the frozen data becomes current', () => {
    const { connection, pushStaleness } = fakeConnection(
      PROTECTED_MODE_INACTIVE,
      FROZEN_DATA,
    )
    const container = renderShell(connection)

    act(() => {
      pushStaleness(RT_STALENESS_FRESH)
    })

    expect(
      container.querySelector('[data-id="conn-state"]')?.querySelector('i')
        ?.className,
    ).toContain('bi-check-circle-fill')
  })

  it('replaces the page and every link with the maintenance surface', () => {
    const container = renderShell(fakeConnection(FROZEN).connection)

    expect(surface(container, 'maintenance')).not.toBeNull()
    expect(surface(container, 'page-body')).toBeNull()
    expect(surface(container, 'nav-brand')).toBeNull()
    expect(surface(container, 'nav-admin')).toBeNull()
    expect(surface(container, 'app-footer')).toBeNull()
    // The one status worth telling the visitor during planned work.
    expect(surface(container, 'conn-state')).not.toBeNull()
  })

  it('raises the surface when the mode arrives on an open connection', () => {
    const { connection, push } = fakeConnection(PROTECTED_MODE_INACTIVE)
    const container = renderShell(connection)

    act(() => {
      push(FROZEN)
    })

    expect(surface(container, 'maintenance-title')?.textContent).toBe(
      'Restoring a backup',
    )
    expect(surface(container, 'page-body')).toBeNull()
  })
})
