import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionError,
  bindCodeSendProgress,
  bindImpersonation,
  CODE_SEND_PURPOSE_STEP_UP,
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_QUEUED,
  CODE_SEND_STATE_SENDING,
  CODE_SEND_STATE_SENT,
  createHilosDataExportFlow,
  createHilosDataExportStore,
  createSignal,
  HILOS_DATA_EXPORT_COPY,
  ingest,
  ScopeManager,
  SIGNAL_CODE_SEND_PROGRESS,
  type ActionLifecycle,
  type DataExportNode,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core/base'
import { HilosDataExport } from '../src/profile/HilosDataExport.js'
import { readerTimeZone } from './readerTimeZone.js'

const releases: Array<() => void> = []
afterEach(() => {
  cleanup()
  for (const release of releases.splice(0)) release()
  tellSend({ state: null })
})

const preparing = {
  state: 'preparing',
  requestedAt: Date.parse('2026-01-01T00:30:00Z'),
  finishedAt: null,
  expiresAt: null,
  sizeBytes: null,
} as const satisfies DataExportNode
const ready = {
  ...preparing,
  state: 'ready',
  finishedAt: preparing.requestedAt,
  expiresAt: preparing.requestedAt,
  sizeBytes: 456,
} as const satisfies DataExportNode

function deferredReply() {
  let resolve!: () => void
  let reject!: (error: ActionError) => void
  const done = new Promise<{ reply: object }>((yes, no) => {
    resolve = () => yes({ reply: {} })
    reject = no
  })
  return { done, resolve, reject }
}

/**
 * Mount the real store, flow and modal; only the connection and replies are controlled.
 * @param method The proof the server asks for, or null to skip it.
 * @param step What the confirmation's opening says beside its method: the
 *   address a sent code went to, and the send's own reply.
 */
function mountExport(
  method:
    | 'password'
    | 'second_factor'
    | 'email_code'
    | 'sms_code'
    | null = 'password',
  step: { destination?: string; send?: object } = {},
) {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const initial = createSignal<DataExportNode | null>(null)
  const connection = {
    on(event: string, listener: (signal: ProjectSignal) => void) {
      if (event === 'projectSignal') listeners.add(listener)
      return () => listeners.delete(listener)
    },
  } as unknown as HilosConnection
  const sent: Array<{ name: string; data: unknown }> = []
  const pending = new Map<string, ReturnType<typeof deferredReply>>()
  const actions = {
    dispatch(name: string, data: unknown) {
      sent.push({ name, data })
      return {
        requestId: name,
        loading: createSignal(false),
        done:
          pending.get(name)?.done ??
          Promise.resolve({
            reply:
              name === 'hilos_step_up_start'
                ? {
                    required: method !== null,
                    method,
                    purpose: 'export your data',
                    ...step,
                  }
                : {},
          }),
      }
    },
  } as unknown as ActionLifecycle
  const store = createHilosDataExportStore(connection, initial)
  const flow = createHilosDataExportFlow(actions, store)
  store.start()
  releases.push(() => {
    flow.dispose()
    store.dispose()
    expect(listeners.size).toBe(0)
  })
  const view = render(<HilosDataExport store={store} flow={flow} />)
  return {
    view,
    flow,
    store,
    sent,
    hold(name: string) {
      const reply = deferredReply()
      pending.set(name, reply)
      return reply
    },
    emit(node: DataExportNode | null) {
      act(() => {
        for (const listener of listeners)
          listener({
            type: 'hilos_data_export_state',
            data: { dataExport: node },
          } as ProjectSignal)
      })
    },
  }
}

function byId(id: string): HTMLElement {
  return document.querySelector(`[data-id="${id}"]`) as HTMLElement
}

function cancelButton(): HTMLButtonElement {
  return [...byId('modal').querySelectorAll('button')].find(
    (button) => button.textContent === 'Cancel',
  )!
}

/** Every user exit and both submit paths must leave a pending request alone. */
function probePending(): void {
  const modal = byId('modal')
  const confirm = byId('data-export-confirm')
  expect(cancelButton().matches(':disabled')).toBe(true)
  expect(byId('modal-close').matches(':disabled')).toBe(true)
  expect(confirm.getAttribute('aria-busy')).toBe('true')
  expect(confirm.closest('fieldset:disabled')).toBeNull()
  for (const gesture of [
    () => fireEvent.click(cancelButton()),
    () => fireEvent.click(byId('modal-close')),
    () => fireEvent.keyDown(modal, { key: 'Escape' }),
    () => fireEvent.click(modal),
    () => fireEvent.click(confirm),
    // jsdom does not synthesize implicit Enter submission; send its submit event.
    () => fireEvent.submit(byId('data-export-step-up')),
  ]) {
    gesture()
    expect(byId('modal')).toBe(modal)
  }
  expect(byId('modal-confirm')).toBeNull()
}

async function press(id: string): Promise<void> {
  await act(async () => {
    fireEvent.click(byId(id))
  })
}

/**
 * Tell the session's send line as the server frames it, through the binding the
 * application boots with; the binding is let go once the frame is in, and a
 * null state takes the line away.
 *
 * @param line The frame's fields beside its message, which is none.
 */
function tellSend(line: Record<string, unknown>): void {
  const sendListeners: ((signal: { type: string; data: unknown }) => void)[] =
    []
  const unbind = bindCodeSendProgress({
    on: (_event: string, listener: (signal: never) => void) => {
      sendListeners.push(
        listener as (signal: { type: string; data: unknown }) => void,
      )
      return () => undefined
    },
  } as unknown as HilosConnection)
  act(() => {
    for (const listener of sendListeners)
      listener({
        type: SIGNAL_CODE_SEND_PROGRESS,
        data: { message: null, ...line },
      })
  })
  unbind()
}

/**
 * One frame of the confirmation's send line.
 *
 * @param ticket The send the line follows.
 * @param state The step the send reached.
 * @param more The provider's detail, when the frame carries one.
 */
function confirmationLine(
  ticket: string,
  state: string,
  more: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    state,
    channel: 'email',
    purpose: CODE_SEND_PURPOSE_STEP_UP,
    ticket,
    ...more,
  }
}

/** What the window's calm region says now. */
function calmText(): string | null {
  return byId('data-export-live-polite').textContent
}

/**
 * The element itself, or else the first thing inside it, that matches the
 * selector: the root of a block is the likeliest place for a voice to return.
 *
 * @param element The block to look through, root included.
 * @param selector What a live voice looks like.
 */
function selfOrInside(element: Element, selector: string): Element | null {
  return element.matches(selector) ? element : element.querySelector(selector)
}

/** A confirmation that texted its code, whose resend gate opens in a minute. */
const SMS_STEP = {
  destination: '+15550001111',
  send: { sent: true, resendAt: Date.now() + 60_000, expiresAt: null },
}

describe('HilosDataExport', () => {
  it.each(['password', 'second_factor'] as const)(
    'holds the %s proof and order, retains refused drafts, and closes on successful retry',
    async (method) => {
      const w = mountExport(method)
      await press('data-export-prepare')
      const modal = byId('modal')
      const field = byId(
        method === 'password' ? 'step-up-password' : 'step-up-code',
      ) as HTMLInputElement
      expect(document.activeElement).toBe(field)
      const draft = method === 'password' ? 'my proof' : '123456'
      fireEvent.change(field, { target: { value: draft } })
      const body = field.closest('fieldset')!
      const room = byId('step-up-error-slot')
      const orderRoom = byId('data-export-order-error-slot')
      expect(field.matches(':disabled')).toBe(false)
      expect(cancelButton().matches(':disabled')).toBe(false)

      const proof = w.hold('hilos_step_up_confirm')
      await press('data-export-confirm')
      expect(w.flow.busy.get()).toBe(true)
      expect(field.matches(':disabled')).toBe(true)
      if (method === 'second_factor')
        expect(byId('step-up-backup-toggle').matches(':disabled')).toBe(true)
      probePending()
      expect(w.sent.map((request) => request.name)).toEqual([
        'hilos_step_up_start',
        'hilos_step_up_confirm',
      ])
      await act(async () => {
        proof.reject(
          new ActionError('hilos_step_up_confirm', 'fail', 'Proof refused'),
        )
      })
      expect(byId('modal')).toBe(modal)
      expect(field.closest('fieldset')).toBe(body)
      expect(byId(field.dataset.id!)).toBe(field)
      expect(field.value).toBe(draft)
      expect(field.matches(':disabled')).toBe(false)
      expect(cancelButton().matches(':disabled')).toBe(false)
      expect(byId('modal-close').matches(':disabled')).toBe(false)
      expect(byId('step-up-error-slot')).toBe(room)
      expect(byId('step-up-error').textContent).toContain('Proof refused')

      const retryProof = w.hold('hilos_step_up_confirm')
      const order = w.hold('hilos_data_export_order')
      await press('data-export-confirm')
      await act(async () => retryProof.resolve())
      expect(field.matches(':disabled')).toBe(true)
      probePending()
      expect(w.sent.map((request) => request.name)).toEqual([
        'hilos_step_up_start',
        'hilos_step_up_confirm',
        'hilos_step_up_confirm',
        'hilos_data_export_order',
      ])
      await act(async () => {
        order.reject(
          new ActionError(
            'hilos_data_export_order',
            'fail',
            'Only one copy at a time',
          ),
        )
      })
      expect(byId('modal')).toBe(modal)
      expect(byId(field.dataset.id!)).toBe(field)
      expect(field.value).toBe(draft)
      expect(field.matches(':disabled')).toBe(false)
      expect(byId('data-export-order-error-slot')).toBe(orderRoom)
      expect(byId('data-export-order-error').textContent).toContain(
        'Only one copy at a time',
      )
      expect(byId('data-export-confirm').getAttribute('aria-busy')).toBeNull()

      const finalProof = w.hold('hilos_step_up_confirm')
      const finalOrder = w.hold('hilos_data_export_order')
      await press('data-export-confirm')
      await act(async () => finalProof.resolve())
      await act(async () => finalOrder.resolve())
      expect(byId('modal')).toBeNull()
      expect(w.flow.busy.get()).toBe(false)
      expect(
        w.sent.filter((request) => request.name === 'hilos_step_up_confirm'),
      ).toHaveLength(3)
      expect(w.sent.at(-1)).toEqual({
        name: 'hilos_data_export_order',
        data: {},
      })
      w.emit(preparing)
      expect(byId('data-export-preparing')).not.toBeNull()
      expect(w.flow.busy.get()).toBe(false)
      expect(byId('modal')).toBeNull()
      w.emit(ready)
      await press('data-export-prepare-new')
      expect(byId(field.dataset.id!).matches(':disabled')).toBe(false)
      expect(cancelButton().matches(':disabled')).toBe(false)
    },
  )

  it.each(['cancel', 'header', 'Escape', 'backdrop'] as const)(
    'restores the %s exit after refusal without replaying a suppressed gesture',
    async (exit) => {
      const w = mountExport()
      await press('data-export-prepare')
      fireEvent.change(byId('step-up-password'), {
        target: { value: 'my proof' },
      })
      const proof = w.hold('hilos_step_up_confirm')
      await press('data-export-confirm')
      probePending()
      await act(async () =>
        proof.reject(
          new ActionError('hilos_step_up_confirm', 'fail', 'Try again'),
        ),
      )
      const modal = byId('modal')
      expect(modal).not.toBeNull()
      if (exit === 'cancel') fireEvent.click(cancelButton())
      else if (exit === 'header') fireEvent.click(byId('modal-close'))
      else if (exit === 'Escape') fireEvent.keyDown(modal, { key: 'Escape' })
      else fireEvent.click(modal)
      expect(byId('modal')).toBeNull()
      expect(w.sent).toHaveLength(2)
    },
  )

  it('honors an authoritative preparing frame while proof is busy and orders nothing afterward', async () => {
    const w = mountExport()
    await press('data-export-prepare')
    fireEvent.change(byId('step-up-password'), {
      target: { value: 'my proof' },
    })
    const proof = w.hold('hilos_step_up_confirm')
    await press('data-export-confirm')
    expect(w.flow.busy.get()).toBe(true)
    w.emit(preparing)
    expect(byId('modal')).toBeNull()
    expect(document.body.classList.contains('modal-open')).toBe(false)
    await act(async () => proof.resolve())
    expect(w.sent.map((request) => request.name)).toEqual([
      'hilos_step_up_start',
      'hilos_step_up_confirm',
    ])
    expect(w.flow.busy.get()).toBe(false)
  })

  it('orders without a modal when proof is skipped and renders the archive states and download', async () => {
    const w = mountExport(null)
    await press('data-export-prepare')
    expect(w.sent.map((request) => request.name)).toEqual([
      'hilos_step_up_start',
      'hilos_data_export_order',
    ])
    expect(byId('modal')).toBeNull()
    w.emit(preparing)
    expect(byId('data-export-prepare')).toBeNull()
    expect(byId('data-export-preparing').textContent).toContain(
      'Preparing your copy…',
    )
    w.emit(ready)
    expect(byId('data-export-download').getAttribute('href')).toBe(
      '/_hilos/data-export',
    )
    expect(byId('data-export-download').hasAttribute('download')).toBe(true)
    expect(byId('data-export-prepare-new').matches(':disabled')).toBe(false)
    w.emit({ ...ready, state: 'failed', sizeBytes: null })
    expect(byId('data-export-download')).toBeNull()
    expect(byId('data-export-retry')).not.toBeNull()
  })

  it('keeps the archive and controls but hides its download during impersonation', () => {
    const scopes = new ScopeManager()
    const unbind = bindImpersonation(scopes, {} as ActionLifecycle)
    releases.push(unbind)
    const w = mountExport()
    w.emit(ready)
    expect(byId('data-export-download')).not.toBeNull()
    act(() =>
      ingest(scopes.session, {
        entities: {
          currentUser: { id: 2, name: 'Bob' },
          impersonatedBy: { id: 1, name: 'Ada' },
        },
      }),
    )
    expect(byId('data-export-download')).toBeNull()
    expect(byId('data-export-ready')).not.toBeNull()
    expect(byId('data-export-prepare-new').matches(':disabled')).toBe(false)
    expect(w.sent).toEqual([])
  })

  it.each([preparing, ready])(
    'updates the $state text and reserved line in the reader zone without changing the store',
    (node) => {
      const reader = readerTimeZone()
      releases.push(reader.release)
      reader.choose('UTC')
      const w = mountExport()
      w.emit(node)
      const snapshot = w.store.state.get()
      const status = byId(`data-export-${node.state}`)
      const room = w.view.container.querySelector('p.invisible')!
      for (const timeZone of ['UTC', 'Pacific/Honolulu']) {
        act(() => reader.choose(timeZone))
        const expectedPreparing = HILOS_DATA_EXPORT_COPY.preparing.replace(
          '{time}',
          new Intl.DateTimeFormat(undefined, {
            year: 'numeric',
            month: 'numeric',
            day: 'numeric',
            hour: 'numeric',
            minute: 'numeric',
            second: 'numeric',
            timeZone,
          }).format(node.requestedAt),
        )
        expect(room.textContent).toBe(expectedPreparing)
        expect(status.textContent).toBe(
          node.state === 'preparing'
            ? expectedPreparing
            : `Your copy is ready · 456 B · available until ${new Intl.DateTimeFormat(undefined, { dateStyle: 'long', timeZone }).format(node.requestedAt)}`,
        )
        expect(byId(`data-export-${node.state}`)).toBe(status)
        expect(w.store.state.get()).toBe(snapshot)
        expect(w.sent).toEqual([])
      }
    },
  )

  it("says the confirmation's line to its number through a region that outlives every frame and keeps refusals apart", async () => {
    const w = mountExport('sms_code', SMS_STEP)
    await press('data-export-prepare')
    const calm = byId('data-export-live-polite')
    expect(calm.getAttribute('role')).toBe('status')
    expect(calm.getAttribute('aria-live')).toBe('polite')
    expect(calm.closest('[aria-modal="true"]')).not.toBeNull()
    expect(calmText()).toBe('')

    tellSend(confirmationLine('this', CODE_SEND_STATE_SENDING))
    expect(calmText()).toBe('Sending to +15550001111…')
    tellSend(confirmationLine('this', CODE_SEND_STATE_SENT))
    expect(byId('data-export-live-polite')).toBe(calm)
    expect(calmText()).toBe('Sent to +15550001111')
    tellSend(
      confirmationLine('this', CODE_SEND_STATE_FAILED, {
        detail: 'number unreachable',
      }),
    )
    expect(calmText()).toBe('Could not send: number unreachable')

    // The countdown and the line show on the row, which has no voice of its own.
    const row = byId('step-up-send')
    expect(row.textContent).toContain('Could not send: number unreachable')
    expect(row.textContent).toContain('Send again in')
    expect(calmText()).not.toContain('Send again in')
    expect(
      selfOrInside(
        byId('step-up'),
        '[role="status"], [role="alert"], [aria-live]',
      ),
    ).toBeNull()

    // A refusal interrupts through the window's own alert; the line stays calm.
    const proof = w.hold('hilos_step_up_confirm')
    fireEvent.change(byId('step-up-code'), { target: { value: '000000' } })
    await press('data-export-confirm')
    await act(async () =>
      proof.reject(
        new ActionError(
          'hilos_step_up_confirm',
          'fail',
          'That code is not right',
        ),
      ),
    )
    expect(
      byId('modal').querySelector('[role="alert"][aria-live="assertive"]')
        ?.textContent,
    ).toContain('That code is not right')
    expect(byId('data-export-live-polite')).toBe(calm)
    expect(calmText()).toBe('Could not send: number unreachable')
  })

  it.each([
    {
      method: 'email_code',
      destination: 'a@example.test',
      said: 'Sent to a@example.test',
    },
    { method: 'second_factor', destination: undefined, said: '' },
  ] as const)(
    'says the confirmation line of a $method step only when a code was sent',
    async ({ method, destination, said }) => {
      mountExport(method, { destination })
      await press('data-export-prepare')
      const calm = byId('data-export-live-polite')
      expect(calmText()).toBe('')

      tellSend(confirmationLine('this', CODE_SEND_STATE_SENT))
      expect(byId('data-export-live-polite')).toBe(calm)
      expect(calmText()).toBe(said)
    },
  )

  it('leaves the line of a window closed by its order behind and says the next attempt from a new region', async () => {
    const w = mountExport('sms_code', SMS_STEP)
    await press('data-export-prepare')
    const first = byId('data-export-live-polite')
    tellSend(confirmationLine('ordered', CODE_SEND_STATE_SENT))
    expect(calmText()).toBe('Sent to +15550001111')

    fireEvent.change(byId('step-up-code'), { target: { value: '123456' } })
    await press('data-export-confirm')
    expect(w.sent.map((request) => request.name)).toContain(
      'hilos_data_export_order',
    )
    expect(byId('modal')).toBeNull()

    await press('data-export-prepare')
    expect(byId('data-export-live-polite')).not.toBe(first)
    expect(calmText()).toBe('')
    tellSend(confirmationLine('again', CODE_SEND_STATE_QUEUED))
    expect(calmText()).toBe('Queued for sending…')
  })
})
