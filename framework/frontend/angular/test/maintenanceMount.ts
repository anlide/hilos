import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  releaseMaintenance,
  type MountMaintenance,
  type MaintenanceView,
} from '../../test-support/maintenanceFixtures.js'
import { HilosMaintenance } from '../src/HilosMaintenance.js'

/** Set required inputs before detection; release the fixture and module separately. */
export const mountMaintenance: MountMaintenance = async (input, retain) => {
  const host = document.createElement('div')
  const native: { fixture?: ComponentFixture<HilosMaintenance> } = {}
  let disposed = false
  async function flush(run?: () => void): Promise<void> {
    run?.()
    native.fixture!.detectChanges()
    await Promise.resolve()
    native.fixture!.detectChanges()
    await native.fixture!.whenStable()
    native.fixture!.detectChanges()
  }
  const view: MaintenanceView = {
    root: host,
    async update(patch) {
      await flush(() => {
        if (patch.status !== undefined)
          native.fixture!.componentRef.setInput('status', patch.status)
        if (patch.adminSurface !== undefined)
          native.fixture!.componentRef.setInput(
            'adminSurface',
            patch.adminSurface,
          )
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
        () => native.fixture?.destroy(),
        () => {
          TestBed.resetTestingModule()
        },
        () => host.remove(),
      ])
    },
  }
  retain(view)
  document.body.appendChild(host)
  native.fixture = TestBed.createComponent(HilosMaintenance)
  const destroy = native.fixture.destroy.bind(native.fixture)
  let fixtureDestroyed = false
  native.fixture.destroy = () => {
    if (fixtureDestroyed) return
    fixtureDestroyed = true
    destroy()
  }
  native.fixture.componentRef.setInput('status', input.status)
  native.fixture.componentRef.setInput('connection', input.connection)
  native.fixture.componentRef.setInput('adminSurface', input.adminSurface)
  host.appendChild(native.fixture.nativeElement as HTMLElement)
  await flush()
  return view
}
