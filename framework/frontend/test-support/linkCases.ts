// Direct href/current behavior shared by each SDK's real HilosLink.
import {
  createSignal,
  HilosPages,
  resolveHilosPath,
  type HilosRouter,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'

type LinkRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

/** The caller's optional decision, before translating it to native attrs. */
export interface LinkInput {
  to: string
  current: 'page' | null | undefined
}

/** One real anchor and its native update/destruction boundaries. */
export interface LinkView {
  readonly root: HTMLElement
  update(input: LinkInput): Promise<void>
  unmount(): Promise<void>
}

type MountLink = (input: LinkInput) => Promise<LinkView>

/** Stand at Billing so the component itself decides whether paths match. */
export function createLinkRouter(): HilosRouter {
  return {
    currentPath: createSignal(resolveHilosPath(HilosPages.BILLING)),
    navigate: () => {},
  } as unknown as HilosRouter
}

/** Register all six original caller-override and path-match combinations. */
export function registerLinkCases(
  sdk: string,
  runner: LinkRunner,
  mount: MountLink,
): void {
  const { describe, it, expect } = runner
  const billing = resolveHilosPath(HilosPages.BILLING)
  const dashboard = resolveHilosPath(HilosPages.DASHBOARD)
  describe(`HilosLink on ${sdk}`, () => {
    it.each([
      ['default, matching path', undefined, billing, '/hilos/billing', 'page'],
      ['default, different path', undefined, dashboard, '/hilos', null],
      ['cleared, matching path', null, billing, '/hilos/billing', null],
      ['cleared, different path', null, dashboard, '/hilos', null],
      ['page, matching path', 'page', billing, '/hilos/billing', 'page'],
      ['page, different path', 'page', dashboard, '/hilos', 'page'],
    ] as const)('%s', async (_name, current, to, href, expected) => {
      const view = await mount({ to: billing, current: 'page' })
      try {
        await view.update({ to, current })
        const anchor = view.root.querySelector('a')
        expect(anchor).not.toBeNull()
        expect(anchor?.getAttribute('href')).toBe(href)
        expect(anchor?.textContent?.trim()).toBe('Billing')
        expect(anchor?.getAttribute('aria-current')).toBe(expected)
      } finally {
        await view.unmount()
      }
    })
  })
}
