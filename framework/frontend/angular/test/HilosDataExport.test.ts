import { TestBed } from '@angular/core/testing'
import {
  createSignal,
  bindImpersonation,
  dataExportStatusText,
  ingest,
  ScopeManager,
  createHilosDataExportFlow,
  type ActionLifecycle,
  type DataExportNode,
  type HilosDataExportStore,
} from '@hilos/core'
import { afterEach, expect, it, vi } from 'vitest'
import { HilosDataExport } from '../src/profile/HilosDataExport.js'
import {
  bindTestTimeZone,
  type TimeZoneTestBinding,
} from './timeZoneTestHarness.js'

let timeZoneBinding: TimeZoneTestBinding | null = null

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
