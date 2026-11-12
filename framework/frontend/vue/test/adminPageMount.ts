import {
  createApp,
  defineComponent,
  h,
  inject,
  nextTick,
  shallowRef,
  type Slot,
} from 'vue'
import type {
  AdminPageInput,
  AdminPageTableRow,
  AdminPageView,
} from '../../test-support/adminPageFixtures.js'
import HilosAdminPage from '../src/HilosAdminPage.vue'
import HilosViewportTable from '../src/HilosViewportTable.vue'
import { useAdminViewMode, useLookOnly } from '../src/hilosAdminViewMode.js'
import { hilosPageErrorBoundaryKey } from '../src/hilosPageErrorBoundaryKey.js'
import { hilosPageHeadingIdKey } from '../src/hilosPageHeading.js'
import { hilosRouterKey } from '../src/hilosRouterKey.js'
import { AdminPageDeletionModalsProbe } from './adminPageDeletionModalsProbe.js'

/** Read the shell's injection from a real child setup context. */
const HeadingProbe = defineComponent(() => {
  const headingId = inject(hilosPageHeadingIdKey)
  return () => h('span', { 'data-id': 'heading-probe' }, headingId)
})

/** Read both the native mode provider and the control's look-only API. */
const ViewModeProbe = defineComponent(() => {
  const viewMode = useAdminViewMode()
  const lookOnly = useLookOnly()
  return () => [
    h('span', { 'data-id': 'view-mode-probe' }, String(viewMode.value)),
    h('span', { 'data-id': 'look-only-probe' }, String(lookOnly.locked.value)),
    h('span', { 'data-id': 'look-only-described' }, lookOnly.describedBy.value),
  ]
})

/** Translate controlled inputs to real Vue props and lazy native slots. */
export async function mountAdminPage(
  input: AdminPageInput,
): Promise<AdminPageView> {
  const current = shallowRef(input)
  const app = createApp({
    render() {
      const {
        page,
        childParams,
        body,
        contextProbe,
        table,
        headContent,
        headKind,
        headAction,
        headTitleClass,
        breadcrumbLabel,
        tabsLabel,
        deletion,
      } = current.value
      const slots: Record<string, Slot> = {}
      if (deletion !== undefined) {
        slots.default = () => [
          h('button', { type: 'button', 'data-id': 'opener' }, 'Rename'),
        ]
        slots.modals = () => [
          h(AdminPageDeletionModalsProbe, { mounted: deletion.modalsMounted }),
        ]
      } else if (body !== undefined)
        slots.default = () => [h('p', { 'data-id': body.dataId }, body.text)]
      else if (contextProbe !== undefined)
        slots.default = () => [
          h(contextProbe === 'heading' ? HeadingProbe : ViewModeProbe),
        ]
      else if (table !== undefined)
        slots.default = () => [
          h(
            HilosViewportTable,
            { controller: table },
            {
              'cell-name': ({ row }: { row: AdminPageTableRow }) => row.name,
            },
          ),
        ]
      for (const [part, name] of [
        [headContent?.visual, 'head-visual'],
        [headContent?.meta, 'head-meta'],
        [headContent?.badges, 'head-badges'],
        [headContent?.description, 'head-description'],
        [headContent?.skeletonVisual, 'head-skeleton-visual'],
      ] as const) {
        if (part !== undefined)
          slots[name] = () => [h('span', { 'data-id': part.dataId }, part.text)]
      }
      return h(
        HilosAdminPage,
        {
          page,
          childParams,
          headKind,
          headAction,
          headTitleClass,
          breadcrumbLabel,
          tabsLabel,
          preserveModalDraftsOnDelete: deletion?.preserve,
        },
        slots,
      )
    },
  })
  // Complete native mounting before forwarding a render/setup error, so even
  // a failed mount has an application whose scopes can be unmounted.
  let failure: { error: unknown } | undefined
  app.config.errorHandler = (error) => {
    failure = { error }
  }
  async function flush(): Promise<void> {
    await nextTick()
    if (failure !== undefined) throw failure.error
  }
  app.provide(hilosRouterKey, input.router)
  if (input.deletion?.retain !== undefined)
    app.provide(hilosPageErrorBoundaryKey, input.deletion.retain)
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
      async update(next) {
        current.value = next
        await flush()
      },
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
