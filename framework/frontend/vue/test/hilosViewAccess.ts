// Native Vue state and teleport; every app is owned before its first mount.
import {
  createApp,
  defineComponent,
  h,
  markRaw,
  nextTick,
  ref,
  type Component,
} from 'vue'

import type { WithHilosViewAccess } from '../../test-support/hilosViewAccessFixtures.js'
import HilosModal from '../src/HilosModal.vue'
import HilosView from '../src/HilosView.vue'
import { hilosRouterKey } from '../src/hilosRouterKey.js'
import { shellPage } from './hilosViewDeletion.js'

const DirtyPage = markRaw(
  defineComponent(() => {
    const open = ref(false)
    return () =>
      h('div', { 'data-id': 'private-page' }, [
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
            title: 'Edit',
            confirmOnClose: true,
            'onUpdate:modelValue': (next: boolean) => {
              open.value = next
            },
          },
          () => h('input', { 'data-id': 'draft', 'data-autofocus': '' }),
        ),
      ])
  }),
)
const AuthSurface = markRaw(
  defineComponent(
    () => () =>
      h('form', { 'data-id': 'auth-surface' }, [
        h('input', { 'data-id': 'auth-identifier', 'data-autofocus': '' }),
      ]),
  ),
)

export const withHilosViewAccess: WithHilosViewAccess<Component> = async (
  steps,
  afterUnmount,
) => {
  const previousFocus = document.activeElement
  const modalOpen = document.body.classList.contains('modal-open')
  const releases: (() => Promise<void>)[] = []
  const hosts: HTMLElement[] = []
  try {
    await steps({
      dirty: DirtyPage,
      auth: AuthSurface,
      known: markRaw({
        render: () => h('div', { 'data-id': 'known-page' }, 'Known'),
      }),
      status: markRaw({
        render: () =>
          h('div', { 'data-id': 'public-file-reader' }, 'Public file reader'),
      }),
      card: shellPage,
      async mount({ router, ...input }) {
        const host = document.body.appendChild(document.createElement('div'))
        hosts.push(host)
        const app = createApp({ render: () => h(HilosView, input) })
        app.provide(hilosRouterKey, router)
        const flush = async (change?: () => void) => {
          change?.()
          await Promise.resolve()
          await nextTick()
          await nextTick()
        }
        releases.push(async () => {
          app.unmount()
          await nextTick()
        })
        const errors: unknown[] = []
        app.config.errorHandler = (error) => errors.push(error)
        app.mount(host)
        app.config.errorHandler = undefined
        if (errors.length) throw errors[0]
        await flush()
        return {
          root: host,
          flush,
          async type(input, value) {
            await flush(() => {
              input.value = value
              input.dispatchEvent(new Event('input', { bubbles: true }))
            })
          },
        }
      },
    })
  } finally {
    try {
      for (const release of releases.reverse()) await release()
    } finally {
      afterUnmount()
      for (const host of hosts) host.remove()
      document.body.classList.toggle('modal-open', modalOpen)
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected)
        previousFocus.focus()
    }
  }
}
