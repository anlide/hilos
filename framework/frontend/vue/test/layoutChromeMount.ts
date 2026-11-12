import { flushPromises } from '@vue/test-utils'
import { createApp, h, nextTick, shallowRef } from 'vue'
import type {
  LayoutChromeInput,
  LayoutChromeView,
} from '../../test-support/layoutChromeFixtures.js'
import HilosLayout from '../src/HilosLayout.vue'
import LoadingButton from '../src/LoadingButton.vue'
import { hilosRouterKey } from '../src/hilosRouterKey.js'

/** Render real shell slots, retaining the native scope even after a failed mount. */
export async function mountLayoutChrome(
  input: LayoutChromeInput,
  retain?: (view: LayoutChromeView) => void,
): Promise<LayoutChromeView> {
  const current = shallowRef(input)
  const app = createApp({
    render() {
      const input = current.value
      return h(
        HilosLayout,
        { connection: input.connection },
        {
          default: () =>
            input.pageAction
              ? h(LoadingButton, { 'data-id': 'page-action' }, () => 'Send')
              : h('p', { 'data-id': 'page-body' }, 'Page'),
          ...(input.projectUserControls
            ? {
                user: () => [
                  h('span', { 'data-id': 'user-bell' }, 'Bell'),
                  h('span', { 'data-id': 'user-avatar' }, 'Avatar'),
                  h(
                    'a',
                    { href: '/signin', 'data-id': 'user-signin' },
                    'Sign in',
                  ),
                ],
              }
            : input.user === undefined
              ? {}
              : {
                  user: () =>
                    h(
                      'span',
                      { 'data-id': input.user!.dataId },
                      input.user!.text,
                    ),
                }),
          nav: () =>
            input.nav
              ? [
                  h(
                    'a',
                    {
                      href: '/hidden',
                      hidden: true,
                      'data-id': 'project-nav-hidden',
                    },
                    'Hidden',
                  ),
                  h(
                    'a',
                    { href: '/first', 'data-id': 'project-nav-first' },
                    'First',
                  ),
                  h(
                    'a',
                    { href: '/second', 'data-id': 'project-nav-second' },
                    'Second',
                  ),
                ]
              : null,
          ...(input.banner === undefined
            ? {}
            : {
                banner: () =>
                  h(
                    'p',
                    { 'data-id': input.banner!.dataId },
                    input.banner!.text,
                  ),
              }),
        },
      )
    },
  })
  if (input.router !== undefined) app.provide(hilosRouterKey, input.router)
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
  const host = document.body.appendChild(document.createElement('div'))
  let destroyed = false
  function destroy(): void {
    if (destroyed) return
    destroyed = true
    const errors: unknown[] = []
    try {
      app.unmount()
      if (failure !== undefined) throw failure.error
    } catch (error) {
      errors.push(error)
    }
    try {
      host.remove()
    } catch (error) {
      errors.push(error)
    }
    if (errors.length === 1) throw errors[0]
    if (errors.length > 1)
      throw new AggregateError(errors, 'Layout unmount failed')
  }
  const view: LayoutChromeView = {
    root: host,
    async update(patch) {
      current.value = { ...current.value, ...patch }
      await flush()
    },
    flush,
    async unmount() {
      destroy()
    },
  }
  try {
    retain?.(view)
    app.mount(host)
    await flush()
    return view
  } catch (error) {
    try {
      destroy()
    } catch (cleanupError) {
      throw new AggregateError([error, cleanupError], 'Layout mount failed', {
        cause: cleanupError,
      })
    }
    throw error
  }
}
