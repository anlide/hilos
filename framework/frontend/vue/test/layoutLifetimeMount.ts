import { flushPromises } from '@vue/test-utils'
import { createApp, h, nextTick } from 'vue'
import {
  layoutLifetimeFixture,
  type LayoutLifetimeEvent,
  type LayoutLifetimeOptions,
  type LayoutLifetimeView,
} from '../../test-support/layoutLifetimeFixtures.js'
import HilosLayout from '../src/HilosLayout.vue'
import { hilosRouterKey } from '../src/hilosRouterKey.js'
import { LayoutLifetimeProbe } from './layoutLifetimeProbe.js'

/** Let Layout invoke its real default slot only while the ordinary page exists. */
export async function mountLayoutLifetime(
  options: LayoutLifetimeOptions,
): Promise<LayoutLifetimeView> {
  const scope = layoutLifetimeFixture(options)
  const host = document.createElement('div')
  const app = createApp({
    render: () =>
      h(
        HilosLayout,
        { connection: scope.feed.connection },
        {
          brand: () => h('span', { 'data-id': 'lifetime-brand' }, 'Brand'),
          nav: () => h('span', { 'data-id': 'lifetime-nav' }, 'Navigation'),
          user: () => h('span', { 'data-id': 'lifetime-user' }, 'Person'),
          banner: () => h('span', { 'data-id': 'lifetime-banner' }, 'Notice'),
          default: () =>
            h(LayoutLifetimeProbe, {
              record: (event: LayoutLifetimeEvent) => scope.events.push(event),
            }),
        },
      ),
  })
  app.provide(hilosRouterKey, scope.router)
  let failure: { error: unknown } | undefined
  app.config.errorHandler = (error) => {
    failure = { error }
  }
  async function flush(run?: () => void): Promise<void> {
    run?.()
    await nextTick()
    await flushPromises()
    await nextTick()
    if (failure !== undefined) throw failure.error
  }
  let disposed = false
  async function unmount(): Promise<void> {
    if (disposed) return
    disposed = true
    try {
      app.unmount()
      if (failure !== undefined) throw failure.error
    } finally {
      host.remove()
      await scope.dispose()
    }
  }
  try {
    document.body.appendChild(host)
    app.mount(host)
    await flush()
    return {
      root: host,
      events: scope.events,
      feed: scope.feed,
      flush,
      unmount,
    }
  } catch (error) {
    await unmount()
    throw error
  }
}
