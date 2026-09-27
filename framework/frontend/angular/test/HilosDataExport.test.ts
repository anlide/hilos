import { TestBed } from '@angular/core/testing'
import {
  createSignal,
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
