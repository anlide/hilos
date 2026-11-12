import { act, cleanup, render } from '@testing-library/react'
import type {
  DashboardInput,
  DashboardView,
} from '../../test-support/dashboardFixtures.js'
import { HilosDashboardPage } from '../src/admin/dashboard/HilosDashboardPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** Native rendering creates the real Dashboard and its optional children. */
function DashboardHost({ router, top }: DashboardInput) {
  return (
    <HilosRouterContext.Provider value={router}>
      <HilosDashboardPage>
        {top === undefined ? undefined : (
          <div data-id={top.dataId}>{top.text}</div>
        )}
      </HilosDashboardPage>
    </HilosRouterContext.Provider>
  )
}

/** Keep React's signal notifications inside act and release RTL's root registry. */
export async function mountDashboard(
  input: DashboardInput,
): Promise<DashboardView> {
  const host = document.body.appendChild(document.createElement('div'))
  function destroy(): void {
    try {
      cleanup()
    } finally {
      host.remove()
    }
  }
  try {
    render(<DashboardHost {...input} />, { container: host })
    return {
      root: host,
      async flush(change) {
        act(change)
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
