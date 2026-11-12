import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import type {
  DashboardInput,
  DashboardView,
} from '../../test-support/dashboardFixtures.js'
import { HilosDashboardPage } from '../src/admin/dashboard/HilosDashboardPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/** Ordinary fields bind caller content through Dashboard's native projection. */
@Component({
  imports: [HilosDashboardPage],
  template: `
    <hilos-dashboard-page>
      @if (top; as content) {
        <div [attr.data-id]="content.dataId">{{ content.text }}</div>
      }
    </hilos-dashboard-page>
  `,
})
class DashboardHost {
  top: DashboardInput['top']
}

/** Flush the provided signals through Angular, including guarded native teardown. */
export async function mountDashboard(
  input: DashboardInput,
): Promise<DashboardView> {
  const host = document.body.appendChild(document.createElement('div'))
  let fixture: ComponentFixture<DashboardHost> | undefined
  function destroy(): void {
    try {
      fixture?.destroy()
    } finally {
      try {
        TestBed.resetTestingModule()
      } finally {
        host.remove()
      }
    }
  }
  try {
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_ROUTER, useValue: input.router }],
    })
    fixture = TestBed.createComponent(DashboardHost)
    const nativeDestroy = fixture.destroy.bind(fixture)
    let destroyed = false
    fixture.destroy = () => {
      if (destroyed) return
      destroyed = true
      nativeDestroy()
    }
    fixture.componentInstance.top = input.top
    host.appendChild(fixture.nativeElement as HTMLElement)
    fixture.detectChanges()
    return {
      root: host,
      async flush(change) {
        change()
        fixture!.detectChanges()
      },
      async unmount() {
        destroy()
      },
    }
  } catch (error) {
    destroy()
    throw error
  }
}
