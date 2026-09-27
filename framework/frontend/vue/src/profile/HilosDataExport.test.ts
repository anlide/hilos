import {
  createSignal,
  createHilosDataExportFlow,
  type DataExportNode,
  type HilosDataExportStore,
  type ActionLifecycle,
} from '@hilos/core'
import { mount, enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import HilosDataExport from './HilosDataExport.vue'

enableAutoUnmount(afterEach)
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

function mountExport(required = false) {
  const state = createSignal<DataExportNode | null>(null)
  const store: HilosDataExportStore = { state, start() {}, dispose() {} }
  const sent: string[] = []
  const actions = {
    dispatch(name: string) {
      sent.push(name)
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
  return { state, flow, sent, wrapper }
}

describe('HilosDataExport', () => {
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
})
