import { flushPromises } from '@vue/test-utils'
import { createApp, h, nextTick, shallowRef, type App } from 'vue'
import {
  releaseMaintenance,
  type MountMaintenance,
  type MaintenanceView,
} from '../../test-support/maintenanceFixtures.js'
import HilosMaintenance from '../src/HilosMaintenance.vue'

/** Keep a native app and stable child identity, including a partial setup. */
export const mountMaintenance: MountMaintenance = async (input, retain) => {
  const host = document.createElement('div')
  const current = shallowRef(input)
  const native: { app?: App } = {}
  let disposed = false
  const failures: unknown[] = []
  function checkErrors(): void {
    if (failures.length)
      throw new AggregateError(failures.splice(0), 'Vue Maintenance failed')
  }
  async function flush(run?: () => void): Promise<void> {
    run?.()
    await nextTick()
    await flushPromises()
    await nextTick()
    checkErrors()
  }
  const view: MaintenanceView = {
    root: host,
    async update(patch) {
      await flush(() => {
        current.value = { ...current.value, ...patch }
      })
    },
    flush,
    async input(control, value) {
      await flush(() => {
        control.value = value
        control.dispatchEvent(new Event('input', { bubbles: true }))
      })
    },
    async submit(form) {
      await flush(() => {
        form.dispatchEvent(
          new Event('submit', { bubbles: true, cancelable: true }),
        )
      })
    },
    async unmount() {
      if (disposed) return
      disposed = true
      await releaseMaintenance([
        () => native.app?.unmount(),
        () => nextTick(),
        checkErrors,
        () => host.remove(),
      ])
    },
  }
  retain(view)
  document.body.appendChild(host)
  native.app = createApp({ render: () => h(HilosMaintenance, current.value) })
  native.app.config.errorHandler = (error) => failures.push(error)
  native.app.mount(host)
  await flush()
  return view
}
