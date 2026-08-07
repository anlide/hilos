import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import { PROTECTED_MODE_INACTIVE } from '@hilos/core'
import type { HilosConnection, ProtectedModeStatus } from '@hilos/core'

import { HilosLayout } from '../src/HilosLayout.js'
import { HilosMaintenance } from '../src/HilosMaintenance.js'

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
  const listeners: (() => void)[] = []
  const connection = {
    state: 'connected',
    get protectedMode(): ProtectedModeStatus {
      return current
    },
    on(event: string, listener: () => void): () => void {
      if (event === 'protectedMode') {
        listeners.push(listener)
      }
      return () => {}
    },
  } as unknown as HilosConnection

  return {
    connection,
    push(next: ProtectedModeStatus): void {
      current = next
      for (const listener of listeners) {
        listener()
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
    const { container } = render(<HilosMaintenance status={FROZEN} />)

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
      <HilosMaintenance status={FROZEN_WITHOUT_COPY} />,
    )

    expect(
      surface(container, 'maintenance')?.hasAttribute('data-operation'),
    ).toBe(false)
    expect(surface(container, 'maintenance-title')?.textContent).toBe(
      'Maintenance in progress',
    )
    expect(surface(container, 'maintenance-message')?.textContent).not.toBe('')
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
