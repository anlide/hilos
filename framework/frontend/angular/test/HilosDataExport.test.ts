import { TestBed } from '@angular/core/testing'
import {
  createSignal,
  bindImpersonation,
  ingest,
  ScopeManager,
  createHilosDataExportFlow,
  type ActionLifecycle,
  type DataExportNode,
  type HilosDataExportStore,
} from '@hilos/core'
import { afterEach, expect, it } from 'vitest'
import { HilosDataExport } from '../src/profile/HilosDataExport.js'

afterEach(() => {
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
