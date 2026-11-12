import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render } from '@testing-library/react'
import type { ReactNode } from 'react'
import { HilosPages, createSignal } from '@hilos/core/base'
import type {
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core/base'

import { HilosAdminPage } from '../src/HilosAdminPage.js'
import type { HilosAdminPageProps } from '../src/HilosAdminPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

import {
  OWNER_IDENTITY,
  type AdminPageView,
} from '../../test-support/adminPageFixtures.js'
import { registerAdminPageLegacyCases } from '../../test-support/adminPageLegacyCases.js'
import { registerAdminPageHeadCases } from '../../test-support/adminPageHeadCases.js'
import { registerAdminPageContextCases } from '../../test-support/adminPageContextCases.js'
import { registerAdminPageDeletionCases } from '../../test-support/adminPageDeletionCases.js'
import {
  ADMIN_PAGE_DELETED,
  adminPageDeletionInput,
  adminPageRetention,
  adminPageTrapFrom,
} from '../../test-support/adminPageDeletionFixtures.js'
import { mountAdminPage } from './adminPageMount.js'

registerAdminPageLegacyCases('React', { describe, it, expect }, mountAdminPage)
registerAdminPageHeadCases('React', { describe, it, expect }, mountAdminPage)
registerAdminPageContextCases('React', { describe, it, expect }, mountAdminPage)
registerAdminPageDeletionCases(
  'React',
  { describe, it, expect },
  mountAdminPage,
)

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

describe('HilosAdminPage with the shared head', () => {
  afterEach(cleanup)

  /**
   * Mount the shell over a router that answers with the given identity.
   *
   * @param identity What the page answered with, or undefined before the answer.
   * @param props The page key and the shell's other inputs.
   * @param children The page's own content.
   */
  function renderShell(
    identity: HilosPageIdentity | undefined,
    props: HilosAdminPageProps,
    children?: ReactNode,
  ) {
    return render(
      <HilosRouterContext.Provider value={router(identity)}>
        <HilosAdminPage {...props}>{children}</HilosAdminPage>
      </HilosRouterContext.Provider>,
    )
  }

  // AP-H4 is native ReactNode API: null suppresses a default, whereas
  // omitted keeps it. Vue slots and Angular templates have no null input.
  it('AP-H4 keeps an omitted head input as the default and lets an explicit null turn it off', () => {
    const omitted = renderShell(OWNER_IDENTITY, { page: HilosPages.I18N })
    const block = () =>
      omitted.container.querySelector('[data-id="hilos-page-head-block"]')
    expect(block()?.querySelector('[aria-hidden="true"] i')).not.toBeNull()
    expect(block()?.textContent).toContain(
      'Languages, countries, and translation screens.',
    )
    omitted.unmount()

    const silenced = renderShell(OWNER_IDENTITY, {
      page: HilosPages.I18N,
      headVisual: null,
      headDescription: null,
    })
    const silencedBlock = silenced.container.querySelector(
      '[data-id="hilos-page-head-block"]',
    )
    expect(silencedBlock?.querySelector('[aria-hidden="true"] i')).toBeNull()
    expect(silencedBlock?.textContent).not.toContain(
      'Languages, countries, and translation screens.',
    )
  })
})

// AP-D8 stays local: StrictMode replays React effects, unlike the other SDKs.
// Ordinary retention and focus outcomes are registered in shared AP-D5/D7.
describe('HilosAdminPage deletion in React StrictMode', () => {
  it('AP-D8 holds one live registration and a working focus offer through effect replay', async () => {
    const strict = adminPageRetention()
    const { input, pageError } = adminPageDeletionInput(
      undefined,
      true,
      strict.retain,
    )
    let view: AdminPageView | undefined = await mountAdminPage(input, true)
    let dialog: ReturnType<typeof adminPageTrapFrom> | undefined
    try {
      expect(strict.shown.length).toBeGreaterThan(0)
      expect(strict.shown.every(Boolean)).toBe(true)
      expect(strict.live.size).toBe(1)
      const opener = view.root.querySelector<HTMLElement>('[data-id="opener"]')!
      dialog = adminPageTrapFrom(opener)

      await view.flush(() => pageError.set(ADMIN_PAGE_DELETED))
      dialog.close()

      const home = view.root.querySelector('[data-id="page-error-home"]')
      expect(home).not.toBeNull()
      expect(document.activeElement).toBe(home)
      const mounted = view
      view = undefined
      await mounted.unmount()
      expect(strict.live.size).toBe(0)
    } finally {
      dialog?.dispose()
      await view?.unmount()
    }
  })
})
