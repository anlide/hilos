import { createApp, defineComponent, h, markRaw, nextTick, ref } from 'vue'
import { HilosPages } from '@hilos/core/base'
import type {
  LayoutPublicStatusInput,
  LayoutPublicStatusView,
} from '../../test-support/layoutPublicStatusFixtures.js'
import HilosLayout from '../src/HilosLayout.vue'
import HilosModal from '../src/HilosModal.vue'
import HilosView from '../src/HilosView.vue'
import HilosStatusPage from '../src/public/HilosStatusPage.vue'
import { hilosRouterKey } from '../src/hilosRouterKey.js'

/** Stable registry and real routed reader; no session handshake is synthesized. */
export async function mountLayoutPublicStatus(
  input: LayoutPublicStatusInput,
): Promise<LayoutPublicStatusView> {
  const status = markRaw(
    defineComponent(
      () => () =>
        h(HilosStatusPage, {
          connection: input.connection,
          actions: input.actions,
        }),
    ),
  )
  const ordinary = markRaw(
    defineComponent(
      () => () => h('p', { 'data-id': 'ordinary-page' }, 'Ordinary'),
    ),
  )
  const auth = markRaw(
    defineComponent(() => () => h('form', { 'data-id': 'test-sign-in' })),
  )
  const pages = {
    [HilosPages.PUBLIC_STATUS]: status,
    [HilosPages.ABOUT]: ordinary,
    [HilosPages.TERMS]: ordinary,
    [HilosPages.STATUS]: ordinary,
    main: ordinary,
    ordinary,
  }
  const host = document.createElement('div')
  const allow = ref(input.allow ?? false)
  const modalOpen = ref(input.modalOpen ?? false)
  const update: LayoutPublicStatusView['update'] = (props) => {
    if (props.allow !== undefined) allow.value = props.allow
    if (props.modalOpen !== undefined) modalOpen.value = props.modalOpen
  }
  const app = createApp({
    render: () =>
      h(
        HilosLayout,
        {
          connection: input.connection,
          allowDisconnectedInteraction: allow.value,
        },
        {
          ...(input.brand === undefined
            ? {}
            : { brand: () => h('span', input.brand) }),
          user: () =>
            h(
              HilosModal,
              {
                modelValue: modalOpen.value,
                'aria-label': 'Protected action',
                initialFocus: 'dialog',
              },
              () =>
                h(
                  'button',
                  {
                    type: 'button',
                    'data-id': 'shell-dialog-action',
                    onClick: input.onSave,
                  },
                  'Save',
                ),
            ),
          default: () => h(HilosView, { pages, authSurface: auth }),
        },
      ),
  })
  app.provide(hilosRouterKey, input.router)
  const failures: unknown[] = []
  app.config.errorHandler = (error) => failures.push(error)
  function checkErrors(): void {
    if (failures.length)
      throw new AggregateError(failures.splice(0), 'Vue public Status failed')
  }
  async function flush(run?: () => void | Promise<void>): Promise<void> {
    await run?.()
    await nextTick()
    await Promise.resolve()
    await nextTick()
    checkErrors()
  }
  let mounted = false
  let disposed = false
  async function unmount(): Promise<void> {
    if (disposed) return
    disposed = true
    const errors: unknown[] = []
    try {
      if (mounted) app.unmount()
    } catch (error) {
      errors.push(error)
    }
    try {
      await nextTick()
      await Promise.resolve()
      checkErrors()
    } catch (error) {
      errors.push(error)
    } finally {
      host.remove()
    }
    if (errors.length)
      throw new AggregateError(errors, 'Vue public Status disposal failed')
  }
  const view = { root: host, update, flush, unmount }
  input.retain(view)
  document.body.appendChild(host)
  mounted = true
  app.mount(host)
  await flush()
  return view
}
