import {
  HILOS_CONNECTION_STATUS_TEXT_ID,
  RT_STALENESS_FRESH,
  rtStalenessCompactLabel,
  rtStalenessLabel,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  FROZEN,
  layoutChromeFeed,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'

type LayoutCompactStalenessRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect'
>

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** Exercise one live stale-data timeline through each real shell's lifecycle. */
export function registerLayoutCompactStalenessCases(
  sdk: string,
  runner: LayoutCompactStalenessRunner,
  mount: MountLayoutChrome,
): void {
  const { describe, it, expect } = runner

  function get(root: ParentNode, id: string): HTMLElement {
    const element = part(root, id)
    expect(element, id).not.toBeNull()
    return element!
  }

  describe(`HilosLayout compact staleness on ${sdk}`, () => {
    it('RS-stale keeps one compact sentence through maintenance and live freshness changes', async () => {
      const feed = layoutChromeFeed()
      let view: LayoutChromeView | undefined
      const errors: unknown[] = []

      try {
        view = await mount({ connection: feed.connection })
        const root = view.root
        expect(part(root, 'conn-stale-line')).toBeNull()

        const stale = { stale: true, since: undefined }
        await view.flush(() => feed.rtStaleness(stale))
        const line = get(root, 'conn-stale-line')
        expect(line.textContent?.trim()).toBe(
          'Some data may be out of date since an unknown time',
        )
        expect(line.getAttribute('aria-hidden')).toBe('true')
        expect(line.hasAttribute('aria-live')).toBe(false)
        expect(line.hasAttribute('role')).toBe(false)
        expect(get(root, 'app-banner').firstElementChild).toBe(line)

        const status = get(root, 'conn-state')
        const label = `connected - ${rtStalenessLabel(stale)}`
        expect(status.getAttribute('title')).toBe(label)
        expect(
          status
            .querySelector(`#${HILOS_CONNECTION_STATUS_TEXT_ID}`)
            ?.textContent?.trim(),
        ).toBe(label)
        expect(status.querySelector('i')?.getAttribute('aria-hidden')).toBe(
          'true',
        )
        expect(
          get(root, 'app-banner').querySelectorAll('[aria-live]'),
        ).toHaveLength(0)

        const dated = { stale: true, since: Date.UTC(2026, 9, 21, 10) }
        await view.flush(() => feed.rtStaleness(dated))
        expect(part(root, 'conn-stale-line')).toBe(line)
        expect(line.textContent?.trim()).toBe(rtStalenessCompactLabel(dated))
        expect(get(root, 'conn-state').getAttribute('title')).toContain(
          'may be out of date',
        )

        await view.flush(() => feed.protectedMode(FROZEN))
        expect(part(root, 'conn-stale-line')).toBe(line)
        expect(part(root, 'app-footer')).toBeNull()

        await view.flush(() => feed.state('reconnecting'))
        expect(part(root, 'conn-stale-line')).toBeNull()
        await view.flush(() => feed.state('connected'))
        expect(part(root, 'conn-stale-line')).not.toBeNull()
        await view.flush(() => feed.rtStaleness(RT_STALENESS_FRESH))
        expect(part(root, 'conn-stale-line')).toBeNull()
      } catch (error) {
        errors.push(error)
      } finally {
        try {
          await view?.unmount()
        } catch (error) {
          errors.push(error)
        }
        for (const event of [
          'state',
          'protectedMode',
          'rtStaleness',
          'reconnectDragging',
          'firstFrameHold',
          'handshake',
        ] as const) {
          try {
            expect(feed.listenerCount(event), event).toBe(0)
          } catch (error) {
            errors.push(error)
          }
        }
      }

      if (errors.length === 1) throw errors[0]
      if (errors.length > 1)
        throw new AggregateError(errors, 'Compact staleness cleanup failed')
    })
  })
}
