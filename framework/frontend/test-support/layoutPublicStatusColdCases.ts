import { HilosPages, PROTECTED_MODE_INACTIVE } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import { ADMITTED, FROZEN } from './layoutChromeFixtures.js'
import {
  withLayoutPublicStatus,
  type LayoutPublicStatusCase,
  type MountLayoutPublicStatus,
} from './layoutPublicStatusFixtures.js'

function get<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  id: string,
): T {
  const element = root.querySelector<T>(`[data-id="${id}"]`)
  if (!element) throw new Error(`Missing public Status element: ${id}`)
  return element
}

/** Read two strip days through native click and focus in the mounted shell. */
export async function readLayoutPublicStatusDay(
  expect: typeof Vitest.expect,
  { view, commit }: LayoutPublicStatusCase,
): Promise<void> {
  await commit()
  const days = get(
    view.root,
    'public-status-days-30',
  ).querySelectorAll<HTMLButtonElement>('button')
  const first = days[0]!
  expect(first.disabled).toBe(false)
  expect(first.closest('fieldset')?.disabled).toBe(false)
  expect(first.closest('[inert]')).toBeNull()
  expect(first.getAttribute('aria-disabled')).toBeNull()
  const event = new MouseEvent('click', { bubbles: true, cancelable: true })
  await commit(() => {
    first.focus()
    first.dispatchEvent(event)
  })
  expect(event.defaultPrevented).toBe(false)
  expect(get(view.root, 'public-status-day-detail').textContent?.trim()).toBe(
    first.getAttribute('aria-label'),
  )
  const last = days[days.length - 1]!
  await commit(() => last.focus())
  expect(document.activeElement).toBe(last)
  expect(get(view.root, 'public-status-day-detail').textContent?.trim()).toBe(
    last.getAttribute('aria-label'),
  )
  expect(view.root.querySelector('[data-id="conn-state"]')).not.toBeNull()
}

/** Cold file reading and the ordinary route's independent shell restrictions. */
export function registerLayoutPublicStatusColdCases(
  sdk: string,
  runner: Pick<typeof Vitest, 'describe' | 'it' | 'expect' | 'vi'>,
  mount: MountLayoutPublicStatus,
): void {
  const { describe, it, expect, vi } = runner

  async function routeTo(scope: LayoutPublicStatusCase, page: string) {
    await scope.commit(() =>
      scope.route.set({ page, params: {}, admin: page === HilosPages.STATUS }),
    )
  }

  describe(`HilosLayout public Status cold/freeze on ${sdk}`, () => {
    it('P01 paints cold files without releasing the first-frame hold or ordinary routes', async () => {
      await withLayoutPublicStatus(
        vi,
        mount,
        { state: 'disconnected', firstFrameHeld: true },
        async (scope) => {
          const { view, feed, requests } = scope
          await readLayoutPublicStatusDay(expect, scope)
          // Check one coherent read pass, not totals across route remounts.
          expect(requests.slice(0, 2).map((request) => request.input)).toEqual([
            '/status-data/status.json',
            '/status-data/heartbeat.json',
          ])
          const signal = requests[0]!.init?.signal
          for (const request of requests.slice(0, 2)) {
            expect(request.init?.cache).toBe('no-store')
            expect(request.init?.mode).toBe('same-origin')
            expect(request.init?.credentials).toBe('same-origin')
            expect(request.init?.redirect).toBe('error')
            expect(request.init?.signal).toBeInstanceOf(AbortSignal)
            expect(request.init?.signal).toBe(signal)
            expect(request.init?.signal?.aborted).toBe(false)
          }
          expect(
            get(view.root, 'hilos-boot-state').getAttribute('data-state'),
          ).toBe('ready')
          expect(feed.connection.firstFrameHeld).toBe(true)
          expect(get(view.root, 'nav-brand').tagName).toBe('SPAN')
          expect(view.root.querySelector('[data-id="app-footer"]')).toBeNull()
          for (const page of [
            HilosPages.ABOUT,
            HilosPages.TERMS,
            HilosPages.STATUS,
          ]) {
            await routeTo(scope, page)
            expect(view.root.querySelector('[data-id="app-root"]')).toBeNull()
            expect(
              get(view.root, 'hilos-boot-state').getAttribute('data-state'),
            ).toBe('held')
            await routeTo(scope, HilosPages.PUBLIC_STATUS)
            await readLayoutPublicStatusDay(expect, scope)
          }
        },
      )
    })

    it('P02 reads before handshake through a simultaneous first-frame hold and freeze', async () => {
      await withLayoutPublicStatus(
        vi,
        mount,
        {
          state: 'connecting',
          firstFrameHeld: true,
          protectedMode: { ...PROTECTED_MODE_INACTIVE, active: true },
          brand: 'Public test brand',
        },
        async (scope) => {
          const { view, feed } = scope
          expect(feed.connection.firstFrameHeld).toBe(true)
          expect(get(view.root, 'public-status-banner').textContent).toContain(
            'All systems operational',
          )
          expect(view.root.querySelector('[data-id="maintenance"]')).toBeNull()
          expect(
            get(view.root, 'page-interaction-scope').matches(':disabled'),
          ).toBe(false)
          expect(get(view.root, 'nav-brand').tagName).toBe('SPAN')
          expect(get(view.root, 'nav-brand').textContent).toContain(
            'Public test brand',
          )
          expect(
            view.root.querySelector('[data-id="protected-mode-banner"]'),
          ).toBeNull()
          expect(
            view.root.querySelector('[data-id="public-status-subscribe"]'),
          ).toBeNull()
          await readLayoutPublicStatusDay(expect, scope)
          await routeTo(scope, 'main')
          expect(view.root.querySelector('[data-id="app-root"]')).toBeNull()
          await scope.commit(() => feed.injectFirstFrameHold(false))
          expect(
            view.root.querySelector('[data-id="maintenance"]'),
          ).not.toBeNull()
          expect(
            view.root.querySelector('[data-id="public-status-banner"]'),
          ).toBeNull()
        },
      )
    })

    it('P03 keeps file controls usable through all freeze phases and ordinary round trips', async () => {
      await withLayoutPublicStatus(vi, mount, {}, async (scope) => {
        const { view, feed } = scope
        for (const status of [
          FROZEN,
          { ...FROZEN, acceptsPass: true },
          { ...FROZEN, acceptsPass: true, passIssued: true },
          ADMITTED,
        ]) {
          await scope.commit(() => {
            feed.protectedMode(status)
            feed.state('reconnecting')
          })
          await readLayoutPublicStatusDay(expect, scope)
          expect(view.root.querySelector('[data-id="maintenance"]')).toBeNull()
          expect(
            view.root.querySelector('[data-id="protected-mode-banner"]'),
          ).toBeNull()
          await routeTo(scope, HilosPages.STATUS)
          expect(
            view.root.querySelector('[data-id="maintenance"]') !== null,
          ).toBe(status.active)
          if (!status.active) {
            expect(
              get<HTMLFieldSetElement>(view.root, 'page-interaction-scope')
                .disabled,
            ).toBe(true)
          }
          await routeTo(scope, HilosPages.PUBLIC_STATUS)
          await readLayoutPublicStatusDay(expect, scope)
        }
      })
    })

    it('P04 retains the open reader DOM when maintenance starts and ends', async () => {
      await withLayoutPublicStatus(vi, mount, {}, async (scope) => {
        const { view, feed } = scope
        const banner = get(view.root, 'public-status-banner')
        const day = get(view.root, 'public-status-days-30').querySelector(
          'button',
        )!
        const fieldset = get(view.root, 'page-interaction-scope')
        for (const protectedMode of [FROZEN, PROTECTED_MODE_INACTIVE]) {
          await scope.commit(() => feed.protectedMode(protectedMode))
          expect(get(view.root, 'public-status-banner').textContent).toContain(
            'All systems operational',
          )
          expect(view.root.querySelector('[data-id="maintenance"]')).toBeNull()
          expect(get(view.root, 'public-status-banner')).toBe(banner)
          expect(
            get(view.root, 'public-status-days-30').querySelector('button'),
          ).toBe(day)
          expect(get(view.root, 'page-interaction-scope')).toBe(fieldset)
        }
      })
    })
  })
}
