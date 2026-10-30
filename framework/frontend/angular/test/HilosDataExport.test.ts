import { By } from '@angular/platform-browser'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionError,
  createConnectionInteractionState,
  createSignal,
  bindImpersonation,
  dataExportStatusText,
  ingest,
  ScopeManager,
  createHilosDataExportFlow,
  type ActionLifecycle,
  type HilosConnection,
  type DataExportNode,
  type HilosDataExportStore,
} from '@hilos/core'
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
})

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
    expect(form.previousElementSibling?.textContent).toContain(
      'Another code could not be sent',
    )
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
