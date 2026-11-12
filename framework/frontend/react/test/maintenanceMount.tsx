import { act, fireEvent } from '@testing-library/react'
import { createRoot, type Root } from 'react-dom/client'
import {
  releaseMaintenance,
  type MountMaintenance,
  type MaintenanceView,
} from '../../test-support/maintenanceFixtures.js'
import { HilosMaintenance } from '../src/HilosMaintenance.js'

/** Own one native React root; every render and release awaits its own act. */
export const mountMaintenance: MountMaintenance = async (input, retain) => {
  const host = document.createElement('div')
  const native: { root?: Root } = {}
  let current = input
  let disposed = false
  async function flush(run?: () => void): Promise<void> {
    await act(async () => {
      run?.()
      await Promise.resolve()
    })
  }
  const view: MaintenanceView = {
    root: host,
    async update(patch) {
      await flush(() => {
        current = { ...current, ...patch }
        native.root!.render(<HilosMaintenance {...current} />)
      })
    },
    flush,
    async input(control, value) {
      await flush(() => {
        fireEvent.change(control, { target: { value } })
      })
    },
    async submit(form) {
      await flush(() => {
        fireEvent.submit(form)
      })
    },
    async unmount() {
      if (disposed) return
      disposed = true
      await releaseMaintenance([
        () => flush(() => native.root?.unmount()),
        () => host.remove(),
      ])
    },
  }
  retain(view)
  document.body.appendChild(host)
  native.root = createRoot(host)
  await flush(() => native.root!.render(<HilosMaintenance {...current} />))
  return view
}
