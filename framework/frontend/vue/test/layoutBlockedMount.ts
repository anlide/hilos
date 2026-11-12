import { flushPromises } from '@vue/test-utils'
import { createApp, defineComponent, h, markRaw, nextTick, ref } from 'vue'
import type {
  LayoutBlockedInput,
  LayoutBlockedView,
} from '../../test-support/layoutBlockedLiveCases.js'
import HilosLayout from '../src/HilosLayout.vue'
import HilosModal from '../src/HilosModal.vue'
import HilosView from '../src/HilosView.vue'
import { hilosRouterKey } from '../src/hilosRouterKey.js'

/** Stable eager caller content; the SDK owns the dialog and its focus trap. */
const PrivatePage = markRaw(
  defineComponent(() => {
    const open = ref(false)
    return () =>
      h('div', { 'data-id': 'private-content' }, [
        h('h1', 'Profile'),
        h(
          'button',
          {
            type: 'button',
            'data-id': 'open-draft',
            onClick: () => {
              open.value = true
            },
          },
          'Edit',
        ),
        h(
          HilosModal,
          {
            modelValue: open.value,
            title: 'Edit phone',
            confirmOnClose: true,
            'onUpdate:modelValue': (value: boolean) => {
              open.value = value
            },
          },
          () =>
            h('input', {
              'data-id': 'draft',
              'data-autofocus': '',
              'aria-label': 'Phone number',
            }),
        ),
      ])
  }),
)

const AuthSurface = markRaw(
  defineComponent(
    () => () => h('form', { 'data-id': 'test-sign-in' }, h('h1', 'Sign in')),
  ),
)

/** Retain the native app before mounting so every partial setup can be released. */
export async function mountLayoutBlocked(
  input: LayoutBlockedInput,
): Promise<LayoutBlockedView> {
  const app = createApp({
    render: () =>
      h(
        HilosLayout,
        { connection: input.connection },
        {
          default: () =>
            h(HilosView, {
              pages: { main: PrivatePage },
              authSurface: AuthSurface,
            }),
        },
      ),
  })
  app.provide(hilosRouterKey, input.router)
  let failure: { error: unknown } | undefined
  app.config.errorHandler = (error) => {
    failure = { error }
  }
  const host = document.body.appendChild(document.createElement('div'))
  let destroyed = false
  async function unmount(): Promise<void> {
    if (destroyed) return
    destroyed = true
    try {
      app.unmount()
      await nextTick()
      await flushPromises()
      if (failure !== undefined) throw failure.error
    } finally {
      host.remove()
    }
  }
  async function flush(run?: () => void): Promise<void> {
    run?.()
    await nextTick()
    await flushPromises()
    await nextTick()
    if (failure !== undefined) throw failure.error
  }
  try {
    app.mount(host)
    await flush()
    return { root: host, flush, unmount }
  } catch (error) {
    try {
      await unmount()
    } catch (releaseError) {
      throw new AggregateError([error, releaseError], undefined, {
        cause: releaseError,
      })
    }
    throw error
  }
}
