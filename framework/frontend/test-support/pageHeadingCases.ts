// Catalog-heading behavior shared by the three real SDK components.
import {
  createSignal,
  HilosPages,
  resolveHilosPath,
  type HilosPageIdentity,
  type HilosRouter,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import { readTextWithoutAriaHidden } from '../test/breadcrumb.js'

type HeadingRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

/** One heading mounted with a real router signal bridge. */
export interface PageHeadingView {
  readonly root: HTMLElement
  update(identity: HilosPageIdentity): Promise<void>
  unmount(): Promise<void>
}

type MountPageHeading = (
  identity?: HilosPageIdentity,
) => Promise<PageHeadingView>

/** The catalog answer, with an intentionally unmounted parent page. */
const IDENTITY: HilosPageIdentity = {
  label: 'Ways to sign in',
  lead: 'Every way into this account.',
  children: [],
  breadcrumb: [
    { page: HilosPages.PROFILE, label: 'Profile' },
    { page: HilosPages.PROFILE_SIGN_IN, label: 'Ways to sign in' },
  ],
}

const ROOT_IDENTITY: HilosPageIdentity = {
  ...IDENTITY,
  label: 'Profile',
  breadcrumb: [{ page: HilosPages.PROFILE, label: 'Profile' }],
}

/** Supply only the sign-in route, leaving Profile addressless. */
export function createPageHeadingRouter(identity?: HilosPageIdentity) {
  const pageIdentity = createSignal(identity)
  const router = {
    pageIdentity,
    currentRoute: createSignal({
      page: HilosPages.PROFILE_SIGN_IN,
      params: {},
      admin: false,
    }),
    currentPath: createSignal(resolveHilosPath(HilosPages.PROFILE_SIGN_IN)),
    resolvePath: (page: string) =>
      page === HilosPages.PROFILE_SIGN_IN
        ? resolveHilosPath(HilosPages.PROFILE_SIGN_IN)
        : undefined,
  } as unknown as HilosRouter
  return { router, pageIdentity }
}

/** Register the catalog wait, live answer and singleton-trail union. */
export function registerPageHeadingCases(
  sdk: string,
  runner: HeadingRunner,
  mount: MountPageHeading,
): void {
  const { describe, it, expect } = runner
  describe(`HilosPageHeading on ${sdk}`, () => {
    it('waits for identity, draws its answer and hides a later singleton trail', async () => {
      const view = await mount()
      try {
        const { root } = view
        expect(
          root.querySelector('[data-id="test-heading-skeleton"]'),
        ).not.toBeNull()
        expect(root.querySelector('h1')).toBeNull()
        expect(root.textContent).not.toContain('hilos_profile')

        await view.update(IDENTITY)
        expect(root.querySelector('h1')?.textContent?.trim()).toBe(
          'Ways to sign in',
        )
        expect(root.querySelector('p')?.textContent?.trim()).toBe(
          'Every way into this account.',
        )
        const trail = root.querySelector('nav[aria-label="breadcrumb"]')
        expect(trail).not.toBeNull()
        const parent = trail?.querySelector('li')
        expect(parent).not.toBeNull()
        expect(readTextWithoutAriaHidden(parent!)).toBe('Profile')
        expect(trail?.querySelector('a')).toBeNull()
        expect(
          root.querySelector('[data-id="hilos-breadcrumb-hilos_profile"]'),
        ).toBeNull()

        await view.update(ROOT_IDENTITY)
        expect(root.querySelector('[aria-label="breadcrumb"]')).toBeNull()
      } finally {
        await view.unmount()
      }
    })

    it('keeps a separately mounted profile root free of breadcrumbs', async () => {
      const view = await mount(ROOT_IDENTITY)
      try {
        expect(view.root.querySelector('[aria-label="breadcrumb"]')).toBeNull()
      } finally {
        await view.unmount()
      }
    })
  })
}
