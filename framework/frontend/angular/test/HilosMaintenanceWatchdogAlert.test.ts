// The Angular port of vue/src/admin/maintenance/HilosMaintenanceWatchdogAlert.test.ts
// (HIL-1680), under the same case names: the reserved polite slot and its inert
// twin, the truncated row with its permanent Details, the read-only modal with
// every diagnostic fact, live details and the all-clear. Data, semantics and
// accessibility only — no Bootstrap drawing.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'
import {
  HILOS_MAINTENANCE_WATCHDOG_COPY,
  hilosMaintenanceWatchdogAlert,
  maintenanceStateSchema,
  type HilosMaintenanceState,
  type HilosMaintenanceWatchdogAlert as WatchdogAlert,
} from '@hilos/core/admin/maintenance'

import { HilosMaintenanceWatchdogAlert } from '../src/admin/maintenance/HilosMaintenanceWatchdogAlert.js'

/**
 * The core projection of a server alarm, from a frame with every fact published.
 *
 * @param verdict The server's verdict.
 * @param overrides The fields this frame says otherwise.
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

/** The open dialog, or null while it is closed. */
function dialog(): HTMLElement | null {
  return document.querySelector<HTMLElement>('[role="dialog"]')
}

/**
 * Let Angular settle a new input — its effect and the render after it.
 *
 * @param fixture The mounted alert.
 */
async function render(
  fixture: ComponentFixture<HilosMaintenanceWatchdogAlert>,
): Promise<void> {
  fixture.detectChanges()
  await fixture.whenStable()
  fixture.detectChanges()
}

/**
 * Mount the real alert with the given projection.
 *
 * @param alert The core projection, or null while clear.
 */
async function mountAlert(
  alert: WatchdogAlert | null = alarm(),
): Promise<ComponentFixture<HilosMaintenanceWatchdogAlert>> {
  const fixture = TestBed.createComponent(HilosMaintenanceWatchdogAlert)
  fixture.componentRef.setInput('alert', alert)
  await render(fixture)

  return fixture
}

/**
 * Hand the alert a new projection, as the Overview does on a new frame.
 *
 * @param fixture The mounted alert.
 * @param alert The new projection, or null on the all-clear.
 */
async function setAlert(
  fixture: ComponentFixture<HilosMaintenanceWatchdogAlert>,
  alert: WatchdogAlert | null,
): Promise<void> {
  fixture.componentRef.setInput('alert', alert)
  await render(fixture)
}

/**
 * Focus and press Details the way a person does.
 *
 * @param fixture The mounted alert.
 * @returns The opener, for the focus it must get back.
 */
async function openDetails(
  fixture: ComponentFixture<HilosMaintenanceWatchdogAlert>,
): Promise<HTMLButtonElement> {
  const opener = byId<HTMLButtonElement>(
    'hilos-maintenance-watchdog-details',
  ) as HTMLButtonElement
  opener.focus()
  opener.click()
  await render(fixture)

  return opener
}

/** The texts of the details' definition terms or descriptions. */
function factTexts(tag: 'dt' | 'dd'): (string | null)[] {
  return [
    ...(byId('hilos-maintenance-watchdog-full')?.querySelectorAll(tag) ?? []),
  ].map((node) => node.textContent)
}

describe('HilosMaintenanceWatchdogAlert', () => {
  it('keeps one permanent polite slot and an inert, non-interactive idle twin', async () => {
    const fixture = await mountAlert(null)
    const host = fixture.nativeElement as HTMLElement
    const slot = byId('hilos-maintenance-watchdog-slot') as HTMLElement
    expect(slot.getAttribute('role')).toBe('status')
    expect(slot.getAttribute('aria-live')).toBe('polite')
    const idle = slot.querySelector<HTMLElement>(
      '[data-id="hilos-maintenance-watchdog-idle"]',
    ) as HTMLElement
    expect(idle.getAttribute('aria-hidden')).toBe('true')
    expect(idle.hasAttribute('inert')).toBe(true)
    // The twin holds the room of the whole row, its Details included, as text.
    expect(idle.textContent).toContain(HILOS_MAINTENANCE_WATCHDOG_COPY.details)
    expect(host.querySelectorAll('button, a, input, [tabindex]')).toHaveLength(
      0,
    )
    expect(byId('hilos-maintenance-watchdog-alert')).toBeNull()

    await setAlert(fixture, alarm())
    expect(byId('hilos-maintenance-watchdog-slot')).toBe(slot)
    expect(host.querySelectorAll('[role="status"]')).toHaveLength(1)
    expect(host.querySelectorAll('[aria-live]')).toHaveLength(1)
    const row = slot.querySelector<HTMLElement>(
      '[data-id="hilos-maintenance-watchdog-alert"]',
    ) as HTMLElement
    expect(row.getAttribute('role')).toBeNull()
    expect(row.getAttribute('aria-live')).toBeNull()
    expect(row.hasAttribute('inert')).toBe(false)
    expect(row.getAttribute('aria-hidden')).toBeNull()
    expect(byId('hilos-maintenance-watchdog-idle')).toBeNull()
  })

  it.each([
    ['initiator-lost', 'The initiator is gone'],
    ['restored-from-disk', 'The node started already frozen'],
    ['quiesce-overdue', 'Nodes did not quiesce in time'],
    ['silent', 'No movement behind the freeze'],
  ] as const)(
    'renders %s in a single row with permanent details',
    async (verdict, title) => {
      const alert = alarm(verdict)
      await mountAlert(alert)
      const row = byId('hilos-maintenance-watchdog-alert') as HTMLElement
      const details = row.querySelector('button') as HTMLButtonElement
      expect(row.querySelector('strong')?.textContent).toBe(title)
      expect(row.querySelector('strong')?.parentElement?.textContent).toBe(
        `${title} — ${alert.summary}`,
      )
      expect(row.querySelectorAll('button')).toHaveLength(1)
      expect(details.getAttribute('aria-label')).toBe('Show details')
      expect(details.textContent?.trim()).toBe('Details')
      expect(details.disabled).toBe(false)
      expect(row.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
    },
  )

  it('opens an ordinary named modal with the full plain-text diagnostics and every pending node', async () => {
    const alert = alarm()
    const fixture = await mountAlert(alert)
    await openDetails(fixture)
    const open = dialog() as HTMLElement
    expect(open.getAttribute('aria-modal')).toBe('true')
    expect(open.getAttribute('aria-label')).toBe('Details')
    expect(document.activeElement).toBe(open)
    const full = byId('hilos-maintenance-watchdog-full') as HTMLElement
    expect(open.contains(full)).toBe(true)
    expect(full.querySelector('h3')?.textContent).toBe(alert.title)
    expect(full.querySelector('p')?.textContent).toBe(alert.summary)
    expect(factTexts('dt')).toHaveLength(12)
    expect(factTexts('dt')).toEqual(alert.facts.map((fact) => fact.label))
    expect(factTexts('dd')).toEqual(alert.facts.map((fact) => fact.value))
    expect(full.textContent).toContain(
      'node-3, node-4, <b>node-5</b>, node-with-a-very-long-name-that-must-remain-whole-in-details',
    )
    expect(full.querySelector('b')).toBeNull()
    expect(open.textContent).not.toContain('private@example.com')
    expect(open.querySelectorAll('input, select, textarea')).toHaveLength(0)
  })

  it('renders every named pending and diagnostic node from core as text and keeps unknown node ids', async () => {
    const fixture = await mountAlert(
      alarm('quiesce-overdue', {
        watchdogPendingNodes: [
          { nodeId: 'node-3', nodeName: 'Київ <b>三</b>' },
          { nodeId: 'node-4', nodeName: '東京 & "four"' },
          { nodeId: 'unknown-node', nodeName: null },
        ],
        diagnosticsNodeId: 'node-1',
        diagnosticsNodeName: '診断 <em>Ω</em>',
      }),
    )
    await openDetails(fixture)

    expect(factTexts('dd')).toContain(
      'Київ <b>三</b> (node-3), 東京 & "four" (node-4), unknown-node',
    )
    expect(factTexts('dd')).toContain('診断 <em>Ω</em> (node-1)')
    expect(
      byId('hilos-maintenance-watchdog-full')?.querySelector('b, em'),
    ).toBeNull()

    await setAlert(
      fixture,
      alarm('quiesce-overdue', {
        watchdogPendingNodes: [{ nodeId: 'node-3', nodeName: null }],
      }),
    )
    expect(factTexts('dd')).toContain('node-3')
    expect(factTexts('dd')).toContain('node-1')
    expect(byId('hilos-maintenance-watchdog-full')?.textContent).not.toContain(
      'Київ',
    )
  })

  it('leaves unpublished diagnostics and unnamed pending nodes absent without inventing a node', async () => {
    const fixture = await mountAlert(
      alarm('quiesce-overdue', {
        watchdogPendingNodes: [],
        diagnosticsNodeId: null,
        diagnosticsNodeName: null,
        stoppedAgentCount: null,
        preservedAgentTypes: null,
        agentStartGateClosed: null,
      }),
    )
    await openDetails(fixture)
    const full = byId('hilos-maintenance-watchdog-full') as HTMLElement
    expect(factTexts('dd')).toEqual([
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

  it.each(['Close', 'Escape'] as const)(
    'returns focus to Show details on %s and keeps the alarm',
    async (method) => {
      const fixture = await mountAlert()
      const opener = await openDetails(fixture)
      if (method === 'Close') {
        byId<HTMLButtonElement>('hilos-maintenance-watchdog-close')?.click()
      } else {
        dialog()?.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      }
      await render(fixture)
      expect(dialog()).toBeNull()
      expect(document.activeElement).toBe(opener)
      expect(byId('hilos-maintenance-watchdog-alert')?.textContent).toContain(
        'Nodes did not quiesce in time',
      )
    },
  )

  it('updates open details from a changed verdict and facts, closes on clear and stays closed on the next alarm', async () => {
    const fixture = await mountAlert()
    await openDetails(fixture)
    const changed = alarm('silent')
    await setAlert(fixture, {
      ...changed,
      facts: [{ label: 'Phase', value: 'active' }],
    })
    const full = byId('hilos-maintenance-watchdog-full') as HTMLElement
    expect(full.textContent).toContain('No movement behind the freeze')
    expect(full.textContent).toContain('active')
    expect(full.textContent).not.toContain('node-3')
    expect(full.textContent).not.toContain('Nodes did not quiesce in time')
    await setAlert(fixture, {
      ...changed,
      facts: [{ label: 'Phase', value: 'deactivating' }],
    })
    expect(byId('hilos-maintenance-watchdog-full')).toBe(full)
    expect(full.querySelector('dd')?.textContent).toBe('deactivating')

    await setAlert(fixture, null)
    expect(dialog()).toBeNull()
    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll('button'),
    ).toHaveLength(0)
    expect(document.body.textContent).not.toContain(
      'No movement behind the freeze',
    )
    await setAlert(fixture, alarm('initiator-lost'))
    expect(dialog()).toBeNull()
    expect(byId('hilos-maintenance-watchdog-alert')?.textContent).toContain(
      'The initiator is gone',
    )
  })
})
