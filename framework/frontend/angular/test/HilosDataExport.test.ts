import { By } from '@angular/platform-browser'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionError,
  bindCodeSendProgress,
  CODE_SEND_PURPOSE_STEP_UP,
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_QUEUED,
  CODE_SEND_STATE_SENDING,
  CODE_SEND_STATE_SENT,
  createConnectionInteractionState,
  createSignal,
  bindImpersonation,
  dataExportStatusText,
  ingest,
  ScopeManager,
  SIGNAL_CODE_SEND_PROGRESS,
  createHilosDataExportFlow,
  type ActionLifecycle,
  type HilosConnection,
  type DataExportNode,
  type HilosDataExportStore,
} from '@hilos/core/base'
import { type ModalController } from '@hilos/core/base'
import { afterEach, expect, it, vi } from 'vitest'
import { HilosDataExport } from '../src/profile/HilosDataExport.js'
import { HilosModal } from '../src/HilosModal.js'
import { HILOS_CONNECTION_INTERACTION } from '../src/hilosConnectionInteraction.js'
import {
  bindTestTimeZone,
  type TimeZoneTestBinding,
} from './timeZoneTestHarness.js'

let timeZoneBinding: TimeZoneTestBinding | null = null

function held<T>(): {
  promise: Promise<T>
  reject: (reason: unknown) => void
  resolve: (value: T) => void
} {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((accept, refuse) => {
    resolve = accept
    reject = refuse
  })

  return { promise, reject, resolve }
}

async function flush(fixture: ComponentFixture<unknown>): Promise<void> {
  for (let tick = 0; tick < 10; tick += 1) await Promise.resolve()
  fixture.detectChanges()
}

async function settle(fixture: ComponentFixture<unknown>): Promise<void> {
  await fixture.whenStable()
  fixture.detectChanges()
}

function attemptClose(
  fixture: ComponentFixture<unknown>,
  cancel: HTMLButtonElement,
): void {
  const node = (id: string) =>
    document.querySelector<HTMLElement>(`[data-id="${id}"]`)!
  node('modal-close').click()
  cancel.click()
  node('modal').dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  node('modal').click()
  const modal = fixture.debugElement.query(By.directive(HilosModal))
    .componentInstance as unknown as { modal: ModalController }
  modal.modal.requestClose()
  fixture.detectChanges()
}

afterEach(() => {
  timeZoneBinding?.release()
  timeZoneBinding = null
  vi.restoreAllMocks()
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
  tellSend({ state: null })
})

/**
 * Tell the session's send line as the server frames it, through the binding the
 * application boots with; the binding is let go once the frame is in, and a
 * null state takes the line away.
 *
 * @param line The frame's fields beside its message, which is none.
 * @param fixture The window to draw once the frame is in, when there is one.
 */
function tellSend(
  line: Record<string, unknown>,
  fixture?: ComponentFixture<unknown>,
): void {
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
  for (const listener of sendListeners)
    listener({
      type: SIGNAL_CODE_SEND_PROGRESS,
      data: { message: null, ...line },
    })
  unbind()
  fixture?.detectChanges()
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

/** What the window's calm region says now, past the template's own spacing. */
function calmText(): string | undefined {
  return document
    .querySelector('[data-id="data-export-live-polite"]')!
    .textContent?.trim()
}

/**
 * The block's element-host, or else its root, or else the first thing inside
 * either, that matches the selector: the host and the root of a block are the
 * likeliest places for a voice to return.
 *
 * @param block The root of a step-up or send block, drawn inside its host.
 * @param selector What a live voice looks like.
 */
function selfOrInside(block: Element, selector: string): Element | null {
  const host = block.closest('hilos-step-up-step, hilos-send-progress') ?? block
  return host.matches(selector) ? host : host.querySelector(selector)
}

/** A confirmation that texted its code, whose resend gate opens in a minute. */
const SMS_STEP = {
  destination: '+15550001111',
  send: { sent: true, resendAt: Date.now() + 60_000, expiresAt: null },
}

/**
 * Mount the real flow and window over replies the test controls.
 *
 * @param method The proof the server asks for.
 * @param step What the confirmation's opening says beside its method: the
 *   address a sent code went to, and the send's own reply.
 */
function mountExport(
  method: 'password' | 'second_factor' | 'email_code' | 'sms_code',
  step: { destination?: string; send?: object } = {},
) {
  const state = createSignal<DataExportNode | null>(null)
  const store: HilosDataExportStore = { state, start() {}, dispose() {} }
  const sent: string[] = []
  const answers: Record<string, unknown> = {
    hilos_step_up_start: {
      required: true,
      method,
      purpose: 'export your data',
      ...step,
    },
  }
  const actions = {
    dispatch(action: string) {
      sent.push(action)
      const answer = answers[action]
      return {
        done:
          answer instanceof Promise
            ? answer.then((reply) => ({ reply }))
            : Promise.resolve({ reply: answer ?? [] }),
      }
    },
  } as unknown as ActionLifecycle
  const flow = createHilosDataExportFlow(actions, store)
  const fixture = TestBed.createComponent(HilosDataExport)
  fixture.componentRef.setInput('store', store)
  fixture.componentRef.setInput('flow', flow)
  fixture.detectChanges()
  const node = (id: string) =>
    document.querySelector<HTMLElement>(`[data-id="${id}"]`)!
  const type = (value: string): void => {
    const code = node('step-up-code') as HTMLInputElement
    code.value = value
    code.dispatchEvent(new Event('input', { bubbles: true }))
  }

  return { answers, fixture, flow, node, sent, type }
}

it('projects the confirmation controls into the modal footer', async () => {
  const state = createSignal<DataExportNode | null>(null)
  const store: HilosDataExportStore = { state, start() {}, dispose() {} }
  const actions = {
    dispatch() {
      return {
        done: Promise.resolve({
          reply: {
            required: true,
            method: 'password',
            purpose: 'export your data',
          },
        }),
      }
    },
  } as unknown as ActionLifecycle
  const flow = createHilosDataExportFlow(actions, store)
  const fixture = TestBed.createComponent(HilosDataExport)
  fixture.componentRef.setInput('store', store)
  fixture.componentRef.setInput('flow', flow)
  fixture.detectChanges()
  await flow.prepare()
  fixture.detectChanges()
  await fixture.whenStable()
  fixture.detectChanges()
  expect(document.querySelector('[data-id="step-up-password"]')).not.toBeNull()
  const confirm = document.querySelector<HTMLButtonElement>(
    '[data-id="data-export-confirm"]',
  )
  expect(confirm).not.toBeNull()
  expect(confirm?.getAttribute('form')).toBe('hilos-data-export-proof')
  flow.dispose()
  fixture.destroy()
})

it('locks its proof while confirmation is pending, keeps a refusal retryable, and unlocks before archive preparation', async () => {
  const state = createSignal<DataExportNode | null>(null)
  const store: HilosDataExportStore = { state, start() {}, dispose() {} }
  const response = held<unknown>()
  const answers: Record<string, unknown> = {
    hilos_step_up_start: {
      required: true,
      method: 'password',
      purpose: 'export your data',
    },
    hilos_step_up_confirm: response.promise,
  }
  const actions = {
    dispatch(action: string) {
      const answer = answers[action]
      return {
        done:
          answer instanceof Promise
            ? answer.then((reply) => ({ reply }))
            : Promise.resolve({ reply: answer ?? [] }),
      }
    },
  } as unknown as ActionLifecycle
  const flow = createHilosDataExportFlow(actions, store)
  const fixture = TestBed.createComponent(HilosDataExport)
  fixture.componentRef.setInput('store', store)
  fixture.componentRef.setInput('flow', flow)
  fixture.detectChanges()
  const node = (id: string) =>
    document.querySelector<HTMLElement>(`[data-id="${id}"]`)!

  await flow.prepare()
  fixture.detectChanges()
  const form = node('data-export-step-up') as HTMLFormElement
  const password = node('step-up-password') as HTMLInputElement
  password.value = 'secret'
  password.dispatchEvent(new Event('input', { bubbles: true }))
  fixture.detectChanges()
  const cancel = form
    .closest('[role="dialog"]')!
    .querySelector<HTMLButtonElement>('.modal-footer .btn-outline-secondary')!

  node('data-export-confirm').click()
  await flush(fixture)

  expect(flow.busy.get()).toBe(true)
  expect(password.matches(':disabled')).toBe(true)
  expect(password.value).toBe('secret')
  expect(node('data-export-step-up')).toBe(form)
  expect(cancel.matches(':disabled')).toBe(true)
  expect(node('modal-close').matches(':disabled')).toBe(true)
  attemptClose(fixture, cancel)
  expect(node('modal-confirm')).toBeNull()
  expect(node('modal')).not.toBeNull()

  response.reject(
    new ActionError(
      'hilos_step_up_confirm',
      'fail',
      'The password was not right',
    ),
  )
  await flush(fixture)
  await fixture.whenStable()
  fixture.detectChanges()

  expect(flow.busy.get()).toBe(false)
  expect(password.matches(':disabled')).toBe(false)
  expect(password.value).toBe('secret')
  expect(node('data-export-step-up')).toBe(form)
  expect(cancel.matches(':disabled')).toBe(false)
  expect(node('modal')).not.toBeNull()
  expect(node('modal-confirm')).toBeNull()

  answers.hilos_step_up_confirm = {}
  node('data-export-confirm').click()
  await fixture.whenStable()
  fixture.detectChanges()
  expect(flow.open.get()).toBe(false)
  expect(node('modal')).toBeNull()
  state.set({
    state: 'preparing',
    requestedAt: 1,
    finishedAt: null,
    expiresAt: null,
    sizeBytes: null,
  })
  fixture.detectChanges()
  expect(flow.busy.get()).toBe(false)
  expect(node('modal')).toBeNull()

  flow.dispose()
  fixture.destroy()
})

it('renders an untitled profile copy and hides download during impersonation', () => {
  const scopes = new ScopeManager()
  const actions = {} as ActionLifecycle
  const unbind = bindImpersonation(scopes, actions)
  const state = createSignal<DataExportNode | null>({
    state: 'ready',
    requestedAt: 1000,
    finishedAt: 2000,
    expiresAt: 3000,
    sizeBytes: 456,
  })
  const store: HilosDataExportStore = { state, start() {}, dispose() {} }
  const flow = createHilosDataExportFlow(actions, store)
  const fixture = TestBed.createComponent(HilosDataExport)
  fixture.componentRef.setInput('store', store)
  fixture.componentRef.setInput('flow', flow)
  fixture.componentRef.setInput('titled', false)
  fixture.componentRef.setInput('lead', 'The contents of your copy.')
  fixture.detectChanges()
  const root = fixture.nativeElement as HTMLElement
  expect(root.querySelector('h3')).toBeNull()
  expect(
    root.querySelector('[data-id="data-export"]')?.classList.contains('border'),
  ).toBe(false)
  expect(root.textContent).toContain('The contents of your copy.')
  expect(root.querySelector('[data-id="data-export-download"]')).not.toBeNull()
  ingest(scopes.session, {
    entities: {
      currentUser: { id: 2, name: 'Bob' },
      impersonatedBy: { id: 1, name: 'Ada' },
    },
  })
  fixture.detectChanges()
  expect(root.querySelector('[data-id="data-export-download"]')).toBeNull()
  expect(root.querySelector('[data-id="data-export-ready"]')).not.toBeNull()
  unbind()
  flow.dispose()
  fixture.destroy()
})

it('reformats the status and preparing reserve from the live reader zone', () => {
  const node: DataExportNode = {
    state: 'ready',
    requestedAt: Date.parse('2026-10-09T23:30:00.000Z'),
    finishedAt: Date.parse('2026-10-09T23:31:00.000Z'),
    expiresAt: Date.parse('2026-10-09T23:30:00.000Z'),
    sizeBytes: 456,
  }
  const state = createSignal<DataExportNode | null>(node)
  const store: HilosDataExportStore = { state, start() {}, dispose() {} }
  const actions = {} as ActionLifecycle
  const flow = createHilosDataExportFlow(actions, store)
  const fixture = TestBed.createComponent(HilosDataExport)
  fixture.componentRef.setInput('store', store)
  fixture.componentRef.setInput('flow', flow)
  fixture.detectChanges()
  timeZoneBinding = bindTestTimeZone('America/New_York')

  const root = fixture.nativeElement as HTMLElement
  const status = root.querySelector(
    '[data-id="data-export-ready"]',
  ) as HTMLElement
  const reserve = root.querySelector('p.invisible') as HTMLElement
  const receiveAndRead = (timeZone: string | null): [string, string] => {
    timeZoneBinding?.receive(timeZone)
    fixture.detectChanges()
    const actual: [string, string] = [
      status.textContent?.trim() ?? '',
      reserve.textContent?.trim() ?? '',
    ]
    const expected = [
      dataExportStatusText(node),
      dataExportStatusText({
        state: 'preparing',
        requestedAt: node.requestedAt,
        finishedAt: null,
        expiresAt: null,
        sizeBytes: null,
      }),
    ]
    expect(actual).toEqual(expected)
    return actual
  }

  const utc = receiveAndRead('UTC')
  const kyiv = receiveAndRead('Europe/Kyiv')
  const automatic = receiveAndRead(null)
  expect(utc[0]).not.toBe(kyiv[0])
  expect(kyiv[0]).not.toBe(automatic[0])
  expect(utc[1]).not.toBe(kyiv[1])
  expect(kyiv[1]).not.toBe(automatic[1])
  expect(status).toBe(root.querySelector('[data-id="data-export-ready"]'))
  expect(reserve).toBe(root.querySelector('p.invisible'))
  expect(state.get()).toBe(node)
  expect(timeZoneBinding.listenerCount()).toBe(2)

  fixture.destroy()
  const destroyedStatus = status.textContent
  const destroyedReserve = reserve.textContent
  timeZoneBinding.rebind()
  expect(timeZoneBinding.listenerCount()).toBe(2)
  timeZoneBinding.receive('UTC')
  expect(status.textContent).toBe(destroyedStatus)
  expect(reserve.textContent).toBe(destroyedReserve)

  const rebound = TestBed.createComponent(HilosDataExport)
  rebound.componentRef.setInput('store', store)
  rebound.componentRef.setInput('flow', flow)
  rebound.detectChanges()
  timeZoneBinding.receive('Europe/Kyiv')
  rebound.detectChanges()
  expect(
    (rebound.nativeElement as HTMLElement)
      .querySelector('[data-id="data-export-ready"]')
      ?.textContent?.trim(),
  ).toBe(kyiv[0])
  rebound.destroy()
  flow.dispose()
})

it.each(['email_code', 'sms_code'] as const)(
  'holds the window during a real %s step-up resend and preserves the independent connection lock',
  async (method) => {
    const connection = {
      state: 'connected',
      on: () => () => undefined,
    } as unknown as HilosConnection
    const interaction = createConnectionInteractionState(connection)
    TestBed.configureTestingModule({
      providers: [
        {
          provide: HILOS_CONNECTION_INTERACTION,
          useValue: createSignal(interaction),
        },
      ],
    })
    const state = createSignal<DataExportNode | null>(null)
    const store: HilosDataExportStore = { state, start() {}, dispose() {} }
    const dispatched: string[] = []
    const answers: Record<string, unknown> = {}
    const actions = {
      dispatch(action: string) {
        dispatched.push(action)
        const answer = answers[action]
        return {
          done:
            answer instanceof Promise
              ? answer.then((reply) => ({ reply }))
              : Promise.resolve({ reply: answer ?? [] }),
        }
      },
    } as unknown as ActionLifecycle
    const flow = createHilosDataExportFlow(actions, store)
    const fixture = TestBed.createComponent(HilosDataExport)
    fixture.componentRef.setInput('store', store)
    fixture.componentRef.setInput('flow', flow)
    fixture.detectChanges()
    const node = (id: string) =>
      document.querySelector<HTMLElement>(`[data-id="${id}"]`)!
    const opening = {
      required: true,
      method,
      purpose: 'export your data',
      destination: method === 'email_code' ? 'a@example.test' : '+12025550123',
      send: {
        sent: true,
        resendAt: 1_000_000_000_000,
        expiresAt: 1_900_000_600_000,
      },
    }
    answers.hilos_step_up_start = opening
    await flow.prepare()
    await settle(fixture)

    const form = node('data-export-step-up') as HTMLFormElement
    const code = node('step-up-code') as HTMLInputElement
    const again = node('step-up-send-again') as HTMLButtonElement
    const sendAgain = vi.spyOn(flow.stepUp, 'sendAgain')
    const cancel = form
      .closest('[role="dialog"]')!
      .querySelector<HTMLButtonElement>('.modal-footer .btn-outline-secondary')!
    const text = node('step-up-text').textContent
    expect(text).toContain(opening.destination)
    code.value = '123456'
    code.dispatchEvent(new Event('input', { bubbles: true }))
    await settle(fixture)
    expect(flow.stepUp.code.get()).toBe('123456')

    const response = held<unknown>()
    answers.hilos_step_up_start = response.promise
    again.click()
    await flush(fixture)

    expect(flow.busy.get()).toBe(false)
    expect(flow.stepUp.busy.get()).toBe(true)
    expect(code.matches(':disabled')).toBe(true)
    expect(code.value).toBe('')
    expect(flow.stepUp.code.get()).toBe('')
    expect(node('step-up-code')).toBe(code)
    expect(node('data-export-step-up')).toBe(form)
    expect(node('step-up-text').textContent).toBe(text)
    expect(code.closest('[inert], [aria-hidden="true"]')).toBeNull()
    expect(form.querySelector(`label[for="${code.id}"]`)?.textContent).toBe(
      'Code',
    )
    expect(cancel.matches(':disabled')).toBe(true)
    expect(node('modal-close').matches(':disabled')).toBe(true)
    expect(node('data-export-confirm').getAttribute('aria-busy')).not.toBe(
      'true',
    )
    again.click()
    attemptClose(fixture, cancel)
    expect(
      dispatched.filter((action) => action === 'hilos_step_up_start').length,
    ).toBe(2)
    expect(node('modal-confirm')).toBeNull()
    expect(node('data-export-step-up')).toBe(form)

    response.reject(
      new ActionError(
        'hilos_step_up_start',
        'fail',
        'Another code could not be sent',
      ),
    )
    await sendAgain.mock.results[0]!.value
    await settle(fixture)

    expect(flow.busy.get()).toBe(false)
    expect(flow.stepUp.busy.get()).toBe(false)
    expect(code.matches(':disabled')).toBe(false)
    expect(cancel.matches(':disabled')).toBe(false)
    expect(node('modal-close').matches(':disabled')).toBe(false)
    expect(again.matches(':disabled')).toBe(false)
    expect(node('data-export-step-up')).toBe(form)
    expect(node('step-up-code')).toBe(code)
    expect(code.value).toBe('')
    expect(node('step-up-error').textContent).toContain(
      'Another code could not be sent',
    )
    expect(
      node('modal').querySelector('[role="alert"][aria-live="assertive"]')
        ?.textContent,
    ).toContain('Another code could not be sent')
    expect(node('modal-confirm')).toBeNull()

    code.value = '654321'
    code.dispatchEvent(new Event('input', { bubbles: true }))
    await settle(fixture)
    expect(code.value).toBe('654321')
    expect(flow.stepUp.code.get()).toBe('654321')
    const retry = held<unknown>()
    answers.hilos_step_up_start = retry.promise
    again.click()
    await flush(fixture)
    expect(flow.busy.get()).toBe(false)
    expect(flow.stepUp.busy.get()).toBe(true)
    expect(code.matches(':disabled')).toBe(true)
    interaction.setConnection({
      ...connection,
      state: 'disconnected',
    } as HilosConnection)
    fixture.detectChanges()
    retry.resolve(opening)
    await sendAgain.mock.results[1]!.value
    await settle(fixture)

    expect(flow.busy.get()).toBe(false)
    expect(flow.stepUp.busy.get()).toBe(false)
    expect(code.matches(':disabled')).toBe(true)
    expect(node('data-export-step-up')).toBe(form)
    expect(code.value).toBe('')
    expect(node('step-up-error')).toBeNull()
    const calls = dispatched.slice()
    attemptClose(fixture, cancel)
    node('data-export-confirm').click()
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await flush(fixture)
    expect(dispatched).toEqual(calls)
    expect(node('data-export-step-up')).toBe(form)
    expect(node('modal-confirm')).toBeNull()

    interaction.setConnection(connection)
    await settle(fixture)
    expect(code.matches(':disabled')).toBe(false)
    expect(cancel.matches(':disabled')).toBe(false)
    expect(again.matches(':disabled')).toBe(false)
    expect(node('step-up-code')).toBe(code)
    expect(node('data-export-step-up')).toBe(form)
    expect(dispatched).toEqual(calls)

    const passed = held<unknown>()
    answers.hilos_step_up_start = passed.promise
    again.click()
    await flush(fixture)
    expect(flow.busy.get()).toBe(false)
    expect(flow.stepUp.busy.get()).toBe(true)
    expect(code.matches(':disabled')).toBe(true)
    passed.resolve({ required: false, purpose: opening.purpose })
    await sendAgain.mock.results[2]!.value
    await settle(fixture)
    expect(flow.stepUp.busy.get()).toBe(false)
    expect(flow.open.get()).toBe(false)
    expect(node('modal')).toBeNull()
    expect(dispatched).toEqual([
      'hilos_step_up_start',
      'hilos_step_up_start',
      'hilos_step_up_start',
      'hilos_step_up_start',
    ])
    state.set({
      state: 'preparing',
      requestedAt: 1,
      finishedAt: null,
      expiresAt: null,
      sizeBytes: null,
    })
    fixture.detectChanges()
    expect(flow.busy.get()).toBe(false)
    expect(node('modal')).toBeNull()
    fixture.destroy()
    flow.dispose()
    interaction.dispose()
  },
)

it("says the confirmation's line to its number through a region that outlives every frame and keeps refusals apart", async () => {
  const w = mountExport('sms_code', SMS_STEP)
  await w.flow.prepare()
  await settle(w.fixture)
  const calm = w.node('data-export-live-polite')
  expect(calm.getAttribute('role')).toBe('status')
  expect(calm.getAttribute('aria-live')).toBe('polite')
  expect(calm.closest('[aria-modal="true"]')).not.toBeNull()
  expect(calmText()).toBe('')

  tellSend(confirmationLine('this', CODE_SEND_STATE_SENDING), w.fixture)
  expect(calmText()).toBe('Sending to +15550001111…')
  tellSend(confirmationLine('this', CODE_SEND_STATE_SENT), w.fixture)
  expect(w.node('data-export-live-polite')).toBe(calm)
  expect(calmText()).toBe('Sent to +15550001111')
  tellSend(
    confirmationLine('this', CODE_SEND_STATE_FAILED, {
      detail: 'number unreachable',
    }),
    w.fixture,
  )
  expect(calmText()).toBe('Could not send: number unreachable')

  // The countdown and the line show on the row, which has no voice of its own.
  const row = w.node('step-up-send')
  expect(row.textContent).toContain('Could not send: number unreachable')
  expect(row.textContent).toContain('Send again in')
  expect(calmText()).not.toContain('Send again in')
  expect(
    selfOrInside(
      w.node('step-up'),
      '[role="status"], [role="alert"], [aria-live]',
    ),
  ).toBeNull()

  // A refusal interrupts through the window's own alert; the line stays calm.
  const proof = held<unknown>()
  w.answers.hilos_step_up_confirm = proof.promise
  w.type('000000')
  w.node('data-export-confirm').click()
  await flush(w.fixture)
  proof.reject(
    new ActionError('hilos_step_up_confirm', 'fail', 'That code is not right'),
  )
  await flush(w.fixture)
  await settle(w.fixture)
  expect(
    w
      .node('modal')
      .querySelector('[role="alert"][aria-live="assertive"]')
      ?.textContent?.trim(),
  ).toBe('That code is not right')
  expect(w.node('data-export-live-polite')).toBe(calm)
  expect(calmText()).toBe('Could not send: number unreachable')

  w.flow.dispose()
  w.fixture.destroy()
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
    const w = mountExport(method, { destination })
    await w.flow.prepare()
    await settle(w.fixture)
    const calm = w.node('data-export-live-polite')
    expect(calmText()).toBe('')

    tellSend(confirmationLine('this', CODE_SEND_STATE_SENT), w.fixture)
    expect(w.node('data-export-live-polite')).toBe(calm)
    expect(calmText()).toBe(said)

    w.flow.dispose()
    w.fixture.destroy()
  },
)

it('leaves the line of a window closed by its order behind and says the next attempt in a new dialog', async () => {
  const w = mountExport('sms_code', SMS_STEP)
  await w.flow.prepare()
  await settle(w.fixture)
  const first = w.node('modal')
  tellSend(confirmationLine('ordered', CODE_SEND_STATE_SENT), w.fixture)
  expect(calmText()).toBe('Sent to +15550001111')

  w.type('123456')
  w.node('data-export-confirm').click()
  await settle(w.fixture)
  expect(w.sent).toContain('hilos_data_export_order')
  expect(w.flow.open.get()).toBe(false)
  expect(w.node('modal')).toBeNull()

  // The window opens as a new dialog; the region it projects stands in it
  // with nothing to say.
  w.node('data-export-prepare').click()
  await settle(w.fixture)
  expect(w.node('modal')).not.toBeNull()
  expect(w.node('modal')).not.toBe(first)
  expect(w.node('data-export-live-polite').closest('[aria-modal="true"]')).toBe(
    w.node('modal'),
  )
  expect(calmText()).toBe('')
  tellSend(confirmationLine('again', CODE_SEND_STATE_QUEUED), w.fixture)
  expect(calmText()).toBe('Queued for sending…')

  w.flow.dispose()
  w.fixture.destroy()
})
