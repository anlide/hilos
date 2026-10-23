import * as core from '@hilos/core/base'
import {
  bindTimeZoneState,
  ActionError,
  createSignal,
  bindImpersonation,
  ingest,
  ScopeManager,
  createHilosDataExportFlow,
  HILOS_DATA_EXPORT_COPY,
  type DataExportNode,
  type HilosDataExportStore,
  type ActionLifecycle,
  type HilosConnection,
  type ProjectSignal,
  type ReadonlySignal,
} from '@hilos/core/base'
import { mount, enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import HilosDataExport from './HilosDataExport.vue'

enableAutoUnmount(afterEach)
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
  vi.restoreAllMocks()
})

/**
 * The export section over a fake server.
 *
 * @param required Whether the confirmation step asks for the password.
 * @param hold Actions whose reply stays in flight until the test settles or refuses it.
 */
function mountExport(required = false, hold: string[] = []) {
  const state = createSignal<DataExportNode | null>(null)
  const store: HilosDataExportStore = { state, start() {}, dispose() {} }
  const sent: string[] = []
  const held: Array<{ settle(): void; refuse(reason: string): void }> = []
  const actions = {
    dispatch(name: string) {
      sent.push(name)
      if (hold.includes(name))
        return {
          done: new Promise((resolve, reject) =>
            held.push({
              settle: () => resolve({ reply: {} }),
              refuse: (reason) => reject(new ActionError(name, 'fail', reason)),
            }),
          ),
        }
      return {
        done: Promise.resolve({
          reply:
            name === 'hilos_step_up_start'
              ? { required, purpose: 'export your data', method: 'password' }
              : {},
        }),
      }
    },
  } as unknown as ActionLifecycle
  const flow = createHilosDataExportFlow(actions, store)
  const wrapper = mount(HilosDataExport, {
    attachTo: document.body,
    props: { store, flow },
  })
  return { state, flow, sent, held, wrapper }
}

// The fieldset the modal stands its body in; a request in flight disables it.
function bodyLock(): HTMLFieldSetElement | null {
  return document.querySelector('[data-id="modal"] .modal-body > fieldset')
}

function byId(id: string): HTMLElement {
  const found = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (found === null) throw new Error(`No element ${id}`)

  return found
}

// Every way a person closes the window: the ×, Cancel, Escape and the backdrop.
function tryClosing(): void {
  const modal = byId('modal')
  byId('modal-close').click()
  cancelButton().click()
  modal.dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  modal.click()
}

function cancelButton(): HTMLButtonElement {
  const found = [...document.querySelectorAll('button')].find(
    (button) => button.textContent?.trim() === 'Cancel',
  )
  if (found === undefined) throw new Error('No Cancel button')

  return found
}

/** Type into the password field as a person does. */
function typePassword(value: string): void {
  const field = byId('step-up-password') as HTMLInputElement
  field.value = value
  field.dispatchEvent(new Event('input'))
}

describe('HilosDataExport', () => {
  it.each(['preparing', 'ready'] as const)(
    'updates the cached %s status and its reserved line when only the authoritative zone changes',
    async (state) => {
      const moment = Date.parse('2026-01-01T00:30:00.000Z')
      vi.spyOn(Date, 'now').mockReturnValue(moment)
      const resolved = new Intl.DateTimeFormat().resolvedOptions()
      vi.spyOn(
        Intl.DateTimeFormat.prototype,
        'resolvedOptions',
      ).mockReturnValue({
        ...resolved,
        timeZone: 'America/New_York',
      })
      const listeners = new Set<(signal: ProjectSignal) => void>()
      const connection = {
        projectTimeZone: 'UTC',
        on(event: string, listener: (signal: ProjectSignal) => void) {
          if (event === 'projectSignal') listeners.add(listener)
          return () => listeners.delete(listener)
        },
      }
      const receive = (timeZone: string | null) => {
        for (const listener of listeners) {
          listener({
            type: 'handshake_response',
            data: { entities: { currentUser: { id: 1, timeZone } } },
          } as ProjectSignal)
        }
      }
      const release = bindTimeZoneState(
        connection as unknown as HilosConnection,
      )
      receive('UTC')
      const subscribe = core.subscribeSignal
      const callbacks = vi.fn()
      const releases = vi.fn()
      const subscriptions = vi
        .spyOn(core, 'subscribeSignal')
        .mockImplementation(
          <T>(source: ReadonlySignal<T>, listener: (value: T) => void) => {
            const stop = subscribe(source, (value) => {
              callbacks(value)
              listener(value)
            })
            return () => {
              releases()
              stop()
            }
          },
        )
      const w = mountExport()
      const snapshot: DataExportNode =
        state === 'ready'
          ? {
              state,
              requestedAt: moment,
              finishedAt: moment,
              expiresAt: moment,
              sizeBytes: 456,
            }
          : {
              state,
              requestedAt: moment,
              finishedAt: null,
              expiresAt: null,
              sizeBytes: null,
            }
      w.state.set(snapshot)
      await flushPromises()
      const surface = w.wrapper.element
      const flow = w.wrapper.props('flow')
      const status = w.wrapper.get(`[data-id="data-export-${state}"]`)
      const room = w.wrapper.get('p.invisible[aria-hidden="true"]')
      const expectedPreparing = (timeZone: string) =>
        HILOS_DATA_EXPORT_COPY.preparing.replace(
          '{time}',
          new Intl.DateTimeFormat(undefined, {
            year: 'numeric',
            month: 'numeric',
            day: 'numeric',
            hour: 'numeric',
            minute: 'numeric',
            second: 'numeric',
            timeZone,
          }).format(moment),
        )
      const expectedStatus = (timeZone: string) =>
        state === 'preparing'
          ? expectedPreparing(timeZone)
          : `Your copy is ready · 456 B · available until ${new Intl.DateTimeFormat(
              undefined,
              { dateStyle: 'long', timeZone },
            ).format(moment)}`
      try {
        expect(status.text()).toBe(expectedStatus('UTC'))
        expect(room.text()).toBe(expectedPreparing('UTC'))
        callbacks.mockClear()
        for (const zone of ['Europe/Kyiv', null]) {
          receive(zone)
          await flushPromises()
          const effective = zone ?? 'America/New_York'
          expect(status.text()).toBe(expectedStatus(effective))
          expect(room.text()).toBe(expectedPreparing(effective))
          expect(w.wrapper.get('[role="status"]').text()).toBe(
            expectedStatus(effective),
          )
          expect(w.wrapper.element).toBe(surface)
          expect(w.wrapper.props('flow')).toBe(flow)
          expect(w.state.get()).toBe(snapshot)
          expect(w.sent).toEqual([])
        }
        expect(callbacks).toHaveBeenCalledTimes(2)
        w.wrapper.unmount()
        receive('UTC')
        expect(callbacks).toHaveBeenCalledTimes(2)
        expect(releases).toHaveBeenCalledTimes(subscriptions.mock.calls.length)
      } finally {
        w.flow.dispose()
        release()
        expect(listeners.size).toBe(0)
      }
    },
  )

  it('shows state-specific controls and the authenticated download link', async () => {
    const w = mountExport()
    expect(w.wrapper.find('[data-id="data-export-prepare"]').exists()).toBe(
      true,
    )
    w.state.set({
      state: 'preparing',
      requestedAt: 1000,
      finishedAt: null,
      expiresAt: null,
      sizeBytes: null,
    })
    await flushPromises()
    expect(w.wrapper.find('[data-id="data-export-prepare"]').exists()).toBe(
      false,
    )
    expect(w.wrapper.find('[data-id="data-export-preparing"]').exists()).toBe(
      true,
    )
    w.state.set({
      state: 'ready',
      requestedAt: 1000,
      finishedAt: 2000,
      expiresAt: 3000,
      sizeBytes: 456,
    })
    await flushPromises()
    expect(
      w.wrapper.get('[data-id="data-export-download"]').attributes('href'),
    ).toBe('/_hilos/data-export')
    expect(w.wrapper.get('[data-id="data-export-ready"]').text()).toContain(
      '456 B',
    )
    expect(w.wrapper.find('[data-id="data-export-prepare-new"]').exists()).toBe(
      true,
    )
    w.state.set({
      state: 'failed',
      requestedAt: 1000,
      finishedAt: 2000,
      expiresAt: 3000,
      sizeBytes: null,
    })
    await flushPromises()
    expect(w.wrapper.find('[data-id="data-export-retry"]').exists()).toBe(true)
    w.flow.dispose()
  })
  it('uses the existing proof modal and focuses its password field', async () => {
    const w = mountExport(true)
    await w.wrapper.get('[data-id="data-export-prepare"]').trigger('click')
    await flushPromises()
    const field = document.querySelector<HTMLInputElement>(
      '[data-id="step-up-password"]',
    )
    expect(field).not.toBeNull()
    expect(document.activeElement).toBe(field)
    expect(
      document.querySelector('[data-id="data-export-step-up"]'),
    ).not.toBeNull()
    w.flow.close()
    await flushPromises()
    w.flow.dispose()
  })

  it('holds the window and locks the password while the proof and the order are out, and frees it after a refusal', async () => {
    const w = mountExport(true, [
      'hilos_step_up_confirm',
      'hilos_data_export_order',
    ])
    await w.wrapper.get('[data-id="data-export-prepare"]').trigger('click')
    await flushPromises()
    typePassword('a proof')
    await flushPromises()
    const field = byId('step-up-password') as HTMLInputElement
    const body = bodyLock()
    const cancel = cancelButton()
    const close = byId('modal-close') as HTMLButtonElement
    expect(body?.contains(field)).toBe(true)
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)

    // The proof is held, refused with its sentence, then let through.
    byId('data-export-confirm').click()
    await flushPromises()
    expect(w.held).toHaveLength(1)
    expect(body?.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    expect(close.disabled).toBe(true)
    tryClosing()
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    w.held[0]!.refuse('That password is not right')
    await flushPromises()
    expect(byId('step-up-password')).toBe(field)
    expect(field.value).toBe('a proof')
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)
    expect(byId('step-up-error').textContent).toContain(
      'That password is not right',
    )

    // The order follows the proof: the window stays held until it answers.
    byId('data-export-confirm').click()
    await flushPromises()
    w.held[1]!.settle()
    await flushPromises()
    expect(w.held).toHaveLength(3)
    expect(body?.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    tryClosing()
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    w.held[2]!.refuse('Only one copy at a time')
    await flushPromises()
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)
    expect(byId('data-export-order-error').textContent).toContain(
      'Only one copy at a time',
    )

    // The same proof goes through and the window closes with the order.
    byId('data-export-confirm').click()
    await flushPromises()
    w.held[3]!.settle()
    await flushPromises()
    w.held[4]!.settle()
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    w.flow.dispose()
  })
})

it('omits the nested heading and border for a profile section', async () => {
  const w = mountExport()
  await w.wrapper.setProps({
    titled: false,
    lead: 'The contents of your copy.',
  })
  expect(w.wrapper.find('h3').exists()).toBe(false)
  expect(w.wrapper.classes()).not.toContain('border')
  expect(w.wrapper.text()).toContain('The contents of your copy.')
  expect(w.wrapper.text()).not.toContain(
    'Download a copy of what your account holds.',
  )
  w.flow.dispose()
})

it('hides download during impersonation while keeping the state and controls', async () => {
  const scopes = new ScopeManager()
  const unbind = bindImpersonation(scopes, {} as ActionLifecycle)
  const w = mountExport()
  w.state.set({
    state: 'ready',
    requestedAt: 1000,
    finishedAt: 2000,
    expiresAt: 3000,
    sizeBytes: 456,
  })
  await flushPromises()
  expect(w.wrapper.find('[data-id="data-export-download"]').exists()).toBe(true)
  ingest(scopes.session, {
    entities: {
      currentUser: { id: 2, name: 'Bob' },
      impersonatedBy: { id: 1, name: 'Ada' },
    },
  })
  await flushPromises()
  expect(w.wrapper.find('[data-id="data-export-download"]').exists()).toBe(
    false,
  )
  expect(w.wrapper.find('[data-id="data-export-ready"]').exists()).toBe(true)
  expect(w.wrapper.find('[data-id="data-export-prepare-new"]').exists()).toBe(
    true,
  )
  unbind()
  w.flow.dispose()
})
