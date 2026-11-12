import { flushPromises } from '@vue/test-utils'
import { createApp, h, nextTick, provide, ref, shallowRef } from 'vue'
import { vi } from 'vitest'
import type {
  LayoutConnectionInput,
  LayoutConnectionStandalone,
  LayoutConnectionView,
} from '../../test-support/layoutConnectionFixtures.js'
import HilosLayout from '../src/HilosLayout.vue'
import HilosModal from '../src/HilosModal.vue'
import LoadingButton from '../src/LoadingButton.vue'
import { hilosAdminViewModeKey } from '../src/hilosAdminViewMode.js'
import { useConnectionInteraction } from '../src/hilosConnectionInteraction.js'
import { hilosRouterKey } from '../src/hilosRouterKey.js'

/** Retain real Vue page nodes, providers and refs over connection replacements. */
export async function mountLayoutConnection(
  input: LayoutConnectionInput,
): Promise<LayoutConnectionView> {
  const current = shallowRef(input)
  const clicks = vi.fn()
  const consumers = {} as Record<
    'page' | 'shell' | 'modal',
    LayoutConnectionView['interaction']
  >
  const probe = (place: keyof typeof consumers) => ({
    setup() {
      consumers[place] = useConnectionInteraction()
      return () => null
    },
  })
  const PageProbe = probe('page')
  const ShellProbe = probe('shell')
  const ModalProbe = probe('modal')
  const ViewingAction = {
    setup() {
      provide(hilosAdminViewModeKey, ref(true))
      return () =>
        h(
          LoadingButton,
          { 'data-id': 'page-view', 'aria-describedby': 'validation' },
          () => 'View',
        )
    },
  }
  const app = createApp({
    render() {
      const input = current.value
      return h(
        HilosLayout,
        {
          connection: input.connection,
          allowDisconnectedInteraction: input.allow ?? false,
        },
        {
          nav: () => h('a', { href: '/next' }, 'Next'),
          user: () => h(ShellProbe),
          default: () => [
            h(PageProbe),
            h(ViewingAction),
            h('input', {
              'data-id': 'draft',
              'aria-describedby': input.description ?? 'validation',
              disabled: input.disabled ?? false,
            }),
            h('input', { type: 'file', 'data-id': 'file' }),
            h('select', { 'data-id': 'choice' }, [
              h('option', { value: 'a' }, 'A'),
              h('option', { value: 'b' }, 'B'),
            ]),
            h(
              LoadingButton,
              {
                opensWindow: true,
                onClick: clicks,
                'data-id': 'page-open',
              },
              () => 'Open',
            ),
            h(
              'a',
              { href: '/action', onClick: clicks, 'data-id': 'page-link' },
              'Action',
            ),
            h(
              HilosModal,
              {
                modelValue: input.modalOpen ?? false,
                title: 'Edit draft',
                confirmOnClose: true,
              },
              () =>
                h('div', { 'data-id': 'connection-modal-body' }, [
                  h(ModalProbe),
                  h('input', {
                    'data-id': 'modal-initial',
                    'data-autofocus': '',
                  }),
                  h('input', { 'data-id': 'modal-draft' }),
                ]),
            ),
          ],
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
      clicks,
      get interaction() {
        return consumers.page
      },
      consumers,
      async update(patch) {
        current.value = { ...current.value, ...patch }
        await flush()
      },
      flush,
      async unmount() {
        destroy()
      },
    }
  } catch (error) {
    try {
      destroy()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'Vue connection mount and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

/** The fallback is read inside a real Vue setup without a Layout provider. */
export async function mountStandaloneConnection(): Promise<LayoutConnectionStandalone> {
  let interaction: LayoutConnectionStandalone['interaction'] | undefined
  const Probe = {
    setup() {
      interaction = useConnectionInteraction()
      return () => null
    },
  }
  const app = createApp(Probe)
  const host = document.body.appendChild(document.createElement('div'))
  let destroyed = false
  async function unmount(): Promise<void> {
    if (destroyed) return
    destroyed = true
    try {
      app.unmount()
    } finally {
      host.remove()
    }
  }
  try {
    app.mount(host)
    await nextTick()
    return { interaction: interaction!, unmount }
  } catch (error) {
    try {
      await unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'Vue standalone cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}
