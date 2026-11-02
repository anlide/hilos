import { act, cleanup, fireEvent, render, within } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import {
  hilosMaintenanceWatchdogAlert,
  maintenanceStateSchema,
  type HilosMaintenanceState,
  type HilosMaintenanceWatchdogAlert as WatchdogAlert,
} from '@hilos/core/admin/maintenance'

import { HilosMaintenanceWatchdogAlert } from '../src/admin/maintenance/HilosMaintenanceWatchdogAlert.js'

// The details are a HilosModal, which portals to <body>: assertions about them
// query the document, not the render.
afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
})

/**
 * The core projection of a server alarm over one restore frame.
 *
 * @param verdict The server's current verdict.
 * @param overrides The frame fields this case changes.
 */
function alarm(
  verdict: WatchdogAlert['verdict'] = 'quiesce-overdue',
  overrides: Partial<HilosMaintenanceState> = {},
): WatchdogAlert {
  return hilosMaintenanceWatchdogAlert(
    maintenanceStateSchema.parse({
      initiatorNodeName: null,
      diagnosticsNodeName: null,
      phase: 'activating',
      operation: 'restore',
      manual: false,
      startedAt: null,
      enteredFromBrowser: false,
      passCount: 0,
      circleNamed: 0,
      circleAdmitted: 0,
      watchdogVerdict: verdict,
      watchdogPendingNodes: [
        { nodeId: 'node-3', nodeName: null },
        { nodeId: 'node-4', nodeName: null },
        { nodeId: '<b>node-5</b>', nodeName: null },
        {
          nodeId:
            'node-with-a-very-long-name-that-must-remain-whole-in-details',
          nodeName: null,
        },
      ],
      diagnosticsNodeId: 'node-1',
      stoppedAgentCount: 6,
      preservedAgentTypes: ['hilos_mail', 'hilos_logs'],
      agentStartGateClosed: true,
      watchdogAlertEmails: ['private@example.com'],
      ...overrides,
    }),
  )!
}

/**
 * An element by its data-id anywhere in the document, or null while it is not
 * drawn.
 *
 * @param dataId The element's data-id.
 */
function byId<T extends HTMLElement = HTMLElement>(dataId: string): T | null {
  return document.querySelector<T>(`[data-id="${dataId}"]`)
}

/** The open details dialog, or null while it is closed. */
function dialog(): HTMLElement | null {
  return document.querySelector<HTMLElement>('[role="dialog"]')
}

/**
 * Focus Show details and press it, the way a keyboard reader opens the details.
 *
 * @returns The trigger focus must come back to.
 */
async function openDetails(): Promise<HTMLButtonElement> {
  const opener = byId<HTMLButtonElement>(
    'hilos-maintenance-watchdog-details',
  ) as HTMLButtonElement
  opener.focus()
  await act(async () => {
    fireEvent.click(opener)
  })

  return opener
}

describe('HilosMaintenanceWatchdogAlert', () => {
  it('keeps one permanent polite slot and an idle twin nobody can reach', () => {
    const view = render(<HilosMaintenanceWatchdogAlert alert={null} />)
    const slot = byId('hilos-maintenance-watchdog-slot') as HTMLElement
    expect(slot.getAttribute('role')).toBe('status')
    expect(slot.getAttribute('aria-live')).toBe('polite')
    const idle = byId('hilos-maintenance-watchdog-idle') as HTMLElement
    expect(slot.contains(idle)).toBe(true)
    expect(idle.getAttribute('aria-hidden')).toBe('true')
    expect(idle.hasAttribute('inert')).toBe(true)
    // The twin's stand-in reads what the real control reads, and is no control.
    expect(idle.textContent).toContain('Details')
    expect(slot.querySelectorAll('button, a, input, [tabindex]')).toHaveLength(
      0,
    )
    expect(within(slot).queryAllByRole('button')).toHaveLength(0)
    expect(
      within(slot).queryByRole('button', { name: 'Show details' }),
    ).toBeNull()

    view.rerender(<HilosMaintenanceWatchdogAlert alert={alarm()} />)

    expect(byId('hilos-maintenance-watchdog-slot')).toBe(slot)
    expect(view.container.querySelectorAll('[role="status"]')).toHaveLength(1)
    expect(view.container.querySelectorAll('[aria-live]')).toHaveLength(1)
    const row = byId('hilos-maintenance-watchdog-alert') as HTMLElement
    expect(slot.contains(row)).toBe(true)
    expect(row.getAttribute('role')).toBeNull()
    expect(row.getAttribute('aria-live')).toBeNull()
    expect(row.getAttribute('aria-hidden')).toBeNull()
    expect(row.hasAttribute('inert')).toBe(false)
    expect(row.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
    expect(byId('hilos-maintenance-watchdog-idle')).toBeNull()
    const details = within(slot).getByRole('button', { name: 'Show details' })
    details.focus()
    expect(document.activeElement).toBe(details)

    view.rerender(<HilosMaintenanceWatchdogAlert alert={null} />)

    const twin = byId('hilos-maintenance-watchdog-idle') as HTMLElement
    expect(slot.contains(twin)).toBe(true)
    expect(twin.getAttribute('aria-hidden')).toBe('true')
    expect(twin.hasAttribute('inert')).toBe(true)
    expect(within(slot).queryAllByRole('button')).toHaveLength(0)
  })

  it.each([
    ['initiator-lost', 'The initiator is gone'],
    ['restored-from-disk', 'The node started already frozen'],
    ['quiesce-overdue', 'Nodes did not quiesce in time'],
    ['silent', 'No movement behind the freeze'],
  ] as const)(
    'says %s in words on one line with a permanent Show details',
    (verdict, title) => {
      const alert = alarm(verdict)
      render(<HilosMaintenanceWatchdogAlert alert={alert} />)
      const row = byId('hilos-maintenance-watchdog-alert') as HTMLElement
      expect(alert.verdict).toBe(verdict)
      // The verdict is read, not only seen: its title and summary are the text.
      expect(row.textContent).toBe(`${title} — ${alert.summary}Details`)
      expect(row.querySelector('strong')?.textContent).toBe(title)
      const buttons = within(row).getAllByRole('button')
      expect(buttons).toHaveLength(1)
      expect(within(row).getByRole('button', { name: 'Show details' })).toBe(
        buttons[0],
      )
      expect(buttons[0]!.textContent).toBe('Details')
      expect(dialog()).toBeNull()
    },
  )

  it('opens an ordinary named modal with the full plain-text diagnostics and every pending node', async () => {
    const alert = alarm()
    render(<HilosMaintenanceWatchdogAlert alert={alert} />)
    await openDetails()
    const details = dialog() as HTMLElement
    expect(details.getAttribute('aria-modal')).toBe('true')
    expect(details.getAttribute('aria-label')).toBe('Details')
    expect(document.activeElement).toBe(details)
    const full = byId('hilos-maintenance-watchdog-full') as HTMLElement
    expect(details.contains(full)).toBe(true)
    expect(within(full).getByRole('heading', { level: 3 }).textContent).toBe(
      alert.title,
    )
    expect(full.querySelector('p')?.textContent).toBe(alert.summary)
    expect(full.querySelectorAll('dt')).toHaveLength(12)
    expect(
      [...full.querySelectorAll('dt')].map((node) => node.textContent),
    ).toEqual(alert.facts.map((fact) => fact.label))
    expect(
      [...full.querySelectorAll('dd')].map((node) => node.textContent),
    ).toEqual(alert.facts.map((fact) => fact.value))
    expect(full.textContent).toContain(
      'node-3, node-4, <b>node-5</b>, node-with-a-very-long-name-that-must-remain-whole-in-details',
    )
    // A name that looks like markup stays text.
    expect(full.querySelector('b')).toBeNull()
    expect(details.textContent).not.toContain('private@example.com')
    expect(details.querySelectorAll('input, select, textarea')).toHaveLength(0)
    expect(
      within(details)
        .getAllByRole('button')
        .map((button) => button.textContent),
    ).not.toContain('Show details')
  })

  it('leaves unpublished diagnostics and unnamed pending nodes absent without inventing a node', async () => {
    render(
      <HilosMaintenanceWatchdogAlert
        alert={alarm('quiesce-overdue', {
          watchdogPendingNodes: [],
          diagnosticsNodeId: null,
          diagnosticsNodeName: null,
          stoppedAgentCount: null,
          preservedAgentTypes: null,
          agentStartGateClosed: null,
        })}
      />,
    )
    await openDetails()
    const full = byId('hilos-maintenance-watchdog-full') as HTMLElement
    expect(
      [...full.querySelectorAll('dd')].map((node) => node.textContent),
    ).toEqual([
      'activating',
      'restore',
      'No',
      '— · node —',
      '—',
      '—',
      '—',
      '—',
      '—',
      '—',
      '—',
      '—',
    ])
    expect(full.textContent).toContain('Nodes awaiting quiescence')
    expect(full.textContent).not.toContain('node-1')
    expect(full.textContent).not.toContain('node-3')
  })

  it.each(['close', 'header', 'escape'] as const)(
    'returns focus to Show details on %s and leaves the alarm standing',
    async (method) => {
      render(<HilosMaintenanceWatchdogAlert alert={alarm()} />)
      const opener = await openDetails()
      const details = dialog() as HTMLElement
      await act(async () => {
        if (method === 'close') {
          fireEvent.click(
            byId<HTMLButtonElement>(
              'hilos-maintenance-watchdog-close',
            ) as HTMLButtonElement,
          )
        }
        if (method === 'header') {
          fireEvent.click(
            details.querySelector<HTMLButtonElement>(
              '[data-id="modal-close"]',
            ) as HTMLButtonElement,
          )
        }
        if (method === 'escape') {
          fireEvent.keyDown(details, { key: 'Escape' })
        }
      })
      expect(dialog()).toBeNull()
      expect(document.activeElement).toBe(opener)
      expect(byId('hilos-maintenance-watchdog-alert')?.textContent).toContain(
        'Nodes did not quiesce in time',
      )
    },
  )

  it('follows a changed verdict and facts while open, closes on clear and stays closed on the next alarm', async () => {
    const view = render(<HilosMaintenanceWatchdogAlert alert={alarm()} />)
    await openDetails()
    const changed = alarm('silent')
    view.rerender(
      <HilosMaintenanceWatchdogAlert
        alert={{ ...changed, facts: [{ label: 'Phase', value: 'active' }] }}
      />,
    )
    const full = byId('hilos-maintenance-watchdog-full') as HTMLElement
    expect(full.textContent).toContain('No movement behind the freeze')
    expect(full.textContent).toContain('active')
    expect(full.textContent).not.toContain('node-3')
    expect(full.textContent).not.toContain('Nodes did not quiesce in time')
    view.rerender(
      <HilosMaintenanceWatchdogAlert
        alert={{
          ...changed,
          facts: [{ label: 'Phase', value: 'deactivating' }],
        }}
      />,
    )
    expect(byId('hilos-maintenance-watchdog-full')).toBe(full)
    expect(full.querySelector('dd')?.textContent).toBe('deactivating')

    await act(async () => {
      view.rerender(<HilosMaintenanceWatchdogAlert alert={null} />)
    })
    expect(dialog()).toBeNull()
    expect(document.querySelectorAll('button')).toHaveLength(0)
    expect(document.body.textContent).not.toContain(
      'No movement behind the freeze',
    )
    expect(byId('hilos-maintenance-watchdog-idle')).not.toBeNull()

    await act(async () => {
      view.rerender(
        <HilosMaintenanceWatchdogAlert alert={alarm('initiator-lost')} />,
      )
    })
    expect(dialog()).toBeNull()
    expect(byId('hilos-maintenance-watchdog-alert')?.textContent).toContain(
      'The initiator is gone',
    )
  })
})
