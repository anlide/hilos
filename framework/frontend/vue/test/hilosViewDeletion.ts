// Native Vue components and events; the shell and modal own their lifetimes.
import { createApp, defineComponent, h, markRaw, nextTick, ref } from 'vue'
import type { Component } from 'vue'
import { vi } from 'vitest'

import type { WithHilosViewDeletion } from '../../test-support/hilosViewDeletionFixtures.js'
import HilosAdminPage from '../src/HilosAdminPage.vue'
import HilosModal from '../src/HilosModal.vue'
import HilosView from '../src/HilosView.vue'
import { hilosRouterKey } from '../src/hilosRouterKey.js'

/**
 * A card on the admin shell with one dialog in #modals, opened from a button
 * in its content. The draft is the input's own value, so it lives exactly as
 * long as that input does.
 *
 * @param preserveModalDraftsOnDelete Whether the card opts in.
 */
export function shellPage(preserveModalDraftsOnDelete: boolean) {
  const mounted = vi.fn()
  const component = markRaw(
    defineComponent(() => {
      mounted()
      const open = ref(false)

      return () =>
        h(
          HilosAdminPage,
          { page: 'lazy', preserveModalDraftsOnDelete },
          {
            default: () => [
              h('p', { 'data-id': 'page-body' }, 'The card'),
              h(
                'button',
                {
                  type: 'button',
                  'data-id': 'open-draft',
                  onClick: () => {
                    open.value = true
                  },
                },
                'Rename',
              ),
            ],
            modals: () =>
              h(
                HilosModal,
                {
                  modelValue: open.value,
                  title: 'Rename',
                  'onUpdate:modelValue': (next: boolean) => {
                    open.value = next
                  },
                },
                () => h('input', { 'data-id': 'draft', 'data-autofocus': '' }),
              ),
          },
        )
    }),
  )

  return { component, mounted }
}

export const withHilosViewDeletion: WithHilosViewDeletion<Component> = async (
  steps,
) => {
  const previousFocus = document.activeElement
  const modalOpen = document.body.classList.contains('modal-open')
  const releases: (() => Promise<void>)[] = []
  try {
    await steps({
      main: markRaw({ render: () => h('div', { 'data-id': 'main' }, 'Known') }),
      status: markRaw({
        render: () =>
          h('div', { 'data-id': 'public-file-reader' }, 'Public file reader'),
      }),
      card: shellPage,
      async mount({ router, pages }) {
        const host = document.body.appendChild(document.createElement('div'))
        const app = createApp({ render: () => h(HilosView, { pages }) })
        app.provide(hilosRouterKey, router)
        let disposed = false
        const flush = async (change?: () => void) => {
          change?.()
          await Promise.resolve()
          await nextTick()
          await nextTick()
        }
        releases.push(async () => {
          if (disposed) return
          disposed = true
          try {
            app.unmount()
            await nextTick()
          } finally {
            host.remove()
          }
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
      document.body.classList.toggle('modal-open', modalOpen)
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected)
        previousFocus.focus()
    }
  }
}
