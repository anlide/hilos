import { mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosPages, createSignal } from '@hilos/core/base'
import type {
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core/base'
import { h, nextTick, type App } from 'vue'

import HilosAdminPage from './HilosAdminPage.vue'
import { hilosRouterKey } from './hilosRouterKey.js'

import { OWNER_IDENTITY } from '../../test-support/adminPageFixtures.js'
import { registerAdminPageLegacyCases } from '../../test-support/adminPageLegacyCases.js'
import { registerAdminPageHeadCases } from '../../test-support/adminPageHeadCases.js'
import { registerAdminPageContextCases } from '../../test-support/adminPageContextCases.js'
import { registerAdminPageDeletionCases } from '../../test-support/adminPageDeletionCases.js'
import { mountAdminPage } from '../test/adminPageMount.js'

registerAdminPageLegacyCases('Vue', { describe, it, expect }, mountAdminPage)
registerAdminPageHeadCases('Vue', { describe, it, expect }, mountAdminPage)
registerAdminPageContextCases('Vue', { describe, it, expect }, mountAdminPage)
registerAdminPageDeletionCases('Vue', { describe, it, expect }, mountAdminPage)

function router(identity: HilosPageIdentity | undefined): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: '',
      params: {},
      admin: false,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(identity),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) => `/hilos/${page}`,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/** Shells still owned by the current case, including failed detection paths. */
const mountedShells = new Set<VueWrapper>()
type ShellMountOptions = NonNullable<
  Parameters<typeof mount<typeof HilosAdminPage>>[1]
>

/** Keep native disposal and its attached host under the same case lifetime. */
function mountShell(options: ShellMountOptions) {
  const host = document.body.appendChild(document.createElement('div'))
  let app: App | undefined
  try {
    const wrapper = mount(HilosAdminPage, {
      ...options,
      attachTo: host,
      global: {
        ...options.global,
        plugins: [
          ...(options.global?.plugins ?? []),
          (mountedApp: App) => {
            app = mountedApp
          },
        ],
      },
    })
    const unmount = wrapper.unmount.bind(wrapper)
    mountedShells.add(wrapper)
    wrapper.unmount = () => {
      if (!mountedShells.delete(wrapper)) return
      try {
        unmount()
      } finally {
        host.remove()
      }
    }
    return wrapper
  } catch (error) {
    try {
      app?.unmount()
    } finally {
      host.remove()
    }
    throw error
  }
}

function unmountShells(): void {
  for (const wrapper of mountedShells) wrapper.unmount()
}

afterEach(unmountShells)

describe('HilosAdminPage with the shared head', () => {
  // AP-H8 is native Vue API: slots are invoked lazily by the product.
  // Shared DOM results of these transitions live in AP-H6/H7.
  it('AP-H8 invokes only the skeleton slot until the owner answers', async () => {
    const identity = createSignal<HilosPageIdentity | undefined>(undefined)
    const skeletonVisual = vi.fn(() => [h('span')])
    const visual = vi.fn(() => [h('span')])
    const wrapper = mountShell({
      props: { page: HilosPages.I18N, headKind: 'entity' },
      slots: {
        'head-skeleton-visual': skeletonVisual,
        'head-visual': visual,
      },
      global: {
        provide: {
          [hilosRouterKey as symbol]: {
            ...router(undefined),
            pageIdentity: identity,
          },
        },
      },
    })
    try {
      expect(skeletonVisual).toHaveBeenCalledOnce()
      expect(visual).not.toHaveBeenCalled()
      identity.set(OWNER_IDENTITY)
      await nextTick()
      expect(skeletonVisual).toHaveBeenCalledOnce()
      expect(visual).toHaveBeenCalledOnce()
    } finally {
      wrapper.unmount()
    }
  })
})
