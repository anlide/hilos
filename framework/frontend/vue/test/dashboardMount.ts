import { createApp, h, nextTick } from 'vue'
import type {
  DashboardInput,
  DashboardView,
} from '../../test-support/dashboardFixtures.js'
import HilosDashboardPage from '../src/admin/dashboard/HilosDashboardPage.vue'
import { hilosRouterKey } from '../src/hilosRouterKey.js'

/** Provide the actual router and render caller content through Vue's top slot. */
export async function mountDashboard(
  input: DashboardInput,
): Promise<DashboardView> {
  const app = createApp({
    render() {
      return h(
        HilosDashboardPage,
        {},
        input.top === undefined
          ? {}
          : {
              top: () => [
                h('div', { 'data-id': input.top!.dataId }, input.top!.text),
              ],
            },
      )
    },
  })
  let failure: { error: unknown } | undefined
  app.config.errorHandler = (error) => {
    failure = { error }
  }
  async function flush(): Promise<void> {
    await nextTick()
    if (failure !== undefined) throw failure.error
  }
  app.provide(hilosRouterKey, input.router)
  const host = document.body.appendChild(document.createElement('div'))
  function destroy(): void {
    try {
      app.unmount()
      if (failure !== undefined) throw failure.error
    } finally {
      host.remove()
    }
  }
  try {
    app.mount(host)
    await flush()
    return {
      root: host,
      async flush(change) {
        change()
        await flush()
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
