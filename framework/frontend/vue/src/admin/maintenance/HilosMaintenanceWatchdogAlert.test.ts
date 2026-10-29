import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import {
  hilosMaintenanceWatchdogAlert,
  maintenanceStateSchema,
  type HilosMaintenanceState,
  type HilosMaintenanceWatchdogAlert as WatchdogAlert,
} from '@hilos/core/admin/maintenance'

import HilosMaintenanceWatchdogAlert from './HilosMaintenanceWatchdogAlert.vue'

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})
enableAutoUnmount(afterEach)

function alarm(
  verdict: WatchdogAlert['verdict'] = 'quiesce-overdue',
  overrides: Partial<HilosMaintenanceState> = {},
) {
  return hilosMaintenanceWatchdogAlert(
    maintenanceStateSchema.parse({
      phase: 'activating',
      operation: 'restore',
      manual: false,
      startedAt: null,
      enteredFromBrowser: false,
      passCount: 0,
      circleNamed: 0,
      circleAdmitted: 0,
      watchdogVerdict: verdict,
      watchdogPendingNodeIds: [
        'node-3',
        'node-4',
        '<b>node-5</b>',
        'node-with-a-very-long-name-that-must-remain-whole-in-details',
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

function mountAlert(alert: WatchdogAlert | null = alarm()) {
  return mount(HilosMaintenanceWatchdogAlert, {
    props: { alert },
    attachTo: document.body,
  })
}

async function openDetails(wrapper: ReturnType<typeof mountAlert>) {
  const opener = wrapper.get<HTMLButtonElement>(
    '[data-id="hilos-maintenance-watchdog-details"]',
  )
  opener.element.focus()
  await opener.trigger('click')
  await flushPromises()
  return opener.element
}

describe('HilosMaintenanceWatchdogAlert', () => {
  it('keeps one permanent polite slot and an inert, non-interactive idle twin', async () => {
    const wrapper = mountAlert(null)
    const slot = wrapper.get('[data-id="hilos-maintenance-watchdog-slot"]')
    const original = slot.element
    expect(slot.attributes('role')).toBe('status')
    expect(slot.attributes('aria-live')).toBe('polite')
    const idle = slot.get('[data-id="hilos-maintenance-watchdog-idle"]')
    expect(idle.classes()).toContain('invisible')
    expect(idle.attributes('aria-hidden')).toBe('true')
    expect(idle.attributes('inert')).toBeDefined()
    expect(idle.find('i.bi-exclamation-triangle').exists()).toBe(true)
    expect(idle.find('span.text-truncate').exists()).toBe(true)
    expect(idle.get('span.btn').text()).toBe('Details')
    expect(wrapper.findAll('button, a, input, [tabindex]')).toHaveLength(0)

    await wrapper.setProps({ alert: alarm() })
    expect(slot.element).toBe(original)
    expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
    expect(wrapper.findAll('[aria-live]')).toHaveLength(1)
    const row = slot.get('[data-id="hilos-maintenance-watchdog-alert"]')
    expect(row.attributes('role')).toBeUndefined()
    expect(row.attributes('aria-live')).toBeUndefined()
    expect(row.classes()).toEqual(
      idle.classes().filter((name) => name !== 'invisible'),
    )
    expect(row.get('button').classes()).toEqual(idle.get('span.btn').classes())
    expect(
      wrapper.find('[data-id="hilos-maintenance-watchdog-idle"]').exists(),
    ).toBe(false)
  })

  it.each([
    ['initiator-lost', 'danger', 'The initiator is gone'],
    ['restored-from-disk', 'danger', 'The node started already frozen'],
    ['quiesce-overdue', 'warning', 'Nodes did not quiesce in time'],
    ['silent', 'warning', 'No movement behind the freeze'],
  ] as const)(
    'renders %s in a single truncated row with permanent details',
    (verdict, tone, title) => {
      const alert = alarm(verdict)
      const wrapper = mountAlert(alert)
      const row = wrapper.get('[data-id="hilos-maintenance-watchdog-alert"]')
      expect(row.classes()).toContain(`alert-${tone}`)
      expect(row.findAll('.text-truncate')).toHaveLength(1)
      expect(row.get('.text-truncate').text()).toBe(
        `${title} — ${alert.summary}`,
      )
      expect(row.get('strong').text()).toBe(title)
      expect(row.findAll('button')).toHaveLength(1)
      expect(row.get('button').attributes('aria-label')).toBe('Show details')
      expect(row.get('button').text()).toBe('Details')
    },
  )

  it('opens an ordinary named modal with the full plain-text diagnostics and every pending node', async () => {
    const alert = alarm()
    const wrapper = mountAlert(alert)
    await openDetails(wrapper)
    const dialog = document.querySelector<HTMLElement>('[role="dialog"]')!
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    expect(dialog.getAttribute('aria-label')).toBe('Details')
    expect(dialog.querySelector('.modal-title')?.textContent).toBe('Details')
    expect(document.activeElement).toBe(dialog)
    const full = dialog.querySelector(
      '[data-id="hilos-maintenance-watchdog-full"]',
    )!
    expect(full.querySelector('h3')?.textContent).toBe(alert.title)
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
    expect(full.querySelector('b')).toBeNull()
    expect(dialog.textContent).not.toContain('private@example.com')
    expect(dialog.querySelectorAll('input, select, textarea')).toHaveLength(0)
  })

  it('leaves unpublished diagnostics and unnamed pending nodes absent without inventing a node', async () => {
    const wrapper = mountAlert(
      alarm('quiesce-overdue', {
        watchdogPendingNodeIds: [],
        diagnosticsNodeId: null,
        stoppedAgentCount: null,
        preservedAgentTypes: null,
        agentStartGateClosed: null,
      }),
    )
    await openDetails(wrapper)
    const full = document.querySelector(
      '[data-id="hilos-maintenance-watchdog-full"]',
    )!
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

  it.each(['Close', 'Escape'])(
    'returns focus to Show details on %s',
    async (method) => {
      const wrapper = mountAlert()
      const opener = await openDetails(wrapper)
      if (method === 'Close') {
        document
          .querySelector<HTMLButtonElement>(
            '[data-id="hilos-maintenance-watchdog-close"]',
          )!
          .click()
      } else {
        document
          .querySelector('[role="dialog"]')!
          .dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
          )
      }
      await flushPromises()
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      expect(document.activeElement).toBe(opener)
      expect(
        wrapper.get('[data-id="hilos-maintenance-watchdog-alert"]').text(),
      ).toContain('Nodes did not quiesce in time')
    },
  )

  it('updates open details from a changed verdict and facts, closes on clear and stays closed on the next alarm', async () => {
    const wrapper = mountAlert()
    await openDetails(wrapper)
    const changed = alarm('silent')
    await wrapper.setProps({
      alert: { ...changed, facts: [{ label: 'Phase', value: 'active' }] },
    })
    const full = document.querySelector(
      '[data-id="hilos-maintenance-watchdog-full"]',
    )!
    expect(full.textContent).toContain('No movement behind the freeze')
    expect(full.textContent).toContain('active')
    expect(full.textContent).not.toContain('node-3')
    expect(full.textContent).not.toContain('Nodes did not quiesce in time')
    await wrapper.setProps({
      alert: { ...changed, facts: [{ label: 'Phase', value: 'deactivating' }] },
    })
    expect(full.querySelector('dd')?.textContent).toBe('deactivating')

    await wrapper.setProps({ alert: null })
    await flushPromises()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(document.body.textContent).not.toContain(
      'No movement behind the freeze',
    )
    await wrapper.setProps({ alert: alarm('initiator-lost') })
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(
      wrapper.get('[data-id="hilos-maintenance-watchdog-alert"]').text(),
    ).toContain('The initiator is gone')
  })
})
