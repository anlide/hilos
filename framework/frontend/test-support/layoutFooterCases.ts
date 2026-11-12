// One footer scenario text, registered by the three native Layout test entries.
import type * as Vitest from 'vitest'
import {
  layoutChromeFeed,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'
import {
  footerBuildIsVisible,
  installFooterStylesheet,
} from './layoutFooterFixtures.js'

type LayoutFooterRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect' | 'vi'
>

/** Register build snapshots, source replacement, native disposal and footer data. */
export function registerLayoutFooterCases(
  sdk: string,
  runner: LayoutFooterRunner,
  mount: MountLayoutChrome,
): void {
  const { describe, it, expect, vi } = runner

  function get(root: ParentNode, id: string): HTMLElement {
    const element = root.querySelector<HTMLElement>(`[data-id="${id}"]`)
    expect(element, id).not.toBeNull()
    return element!
  }

  describe(`HilosLayout footer on ${sdk}`, () => {
    it('F01–F03 reads build snapshots through drop and source replacement, then releases native resources', async () => {
      const source = layoutChromeFeed(undefined, 'A')
      let view: LayoutChromeView | undefined
      try {
        view = await mount({ connection: source.connection, nav: true })
        const root = get(view.root, 'app-root')
        expect(get(root, 'footer-build').textContent?.trim()).toBe('build A')
        await view.flush(() => source.handshake('B'))
        expect(get(root, 'footer-build').textContent?.trim()).toBe('build B')
        await view.flush(() => source.state('disconnected'))
        expect(get(root, 'footer-build').textContent?.trim()).toBe('build B')

        const replacement = layoutChromeFeed(undefined, 'C')
        await view.update({ connection: replacement.connection })
        expect(get(view.root, 'app-root')).toBe(root)
        expect(get(root, 'footer-build').textContent?.trim()).toBe('build C')
        expect(source.listenerCount('handshake')).toBe(0)
        await view.flush(() => source.handshake('old'))
        expect(get(root, 'footer-build').textContent?.trim()).toBe('build C')
        await view.flush(() => replacement.handshake('D'))
        expect(get(root, 'footer-build').textContent?.trim()).toBe('build D')

        const remove = vi.spyOn(window, 'removeEventListener')
        const disconnect = vi.spyOn(MutationObserver.prototype, 'disconnect')
        try {
          const mounted = view
          view = undefined
          await mounted.unmount()
          expect(replacement.listenerCount('handshake')).toBe(0)
          // Any-call evidence, without claiming ownership of every native handler.
          expect(remove.mock.calls.some(([name]) => name === 'resize')).toBe(
            true,
          )
          expect(disconnect).toHaveBeenCalled()
        } finally {
          remove.mockRestore()
          disconnect.mockRestore()
        }
      } finally {
        await view?.unmount()
      }
    })

    it('F04 hides the existing build line until welcome and keeps the four footer links in order', async () => {
      const removeStylesheet = installFooterStylesheet()
      const source = layoutChromeFeed()
      let view: LayoutChromeView | undefined
      try {
        view = await mount({ connection: source.connection })
        const footer = get(view.root, 'app-footer')
        const line = get(footer, 'footer-build')
        expect(line.textContent?.trim()).toBe('build')
        expect(footerBuildIsVisible(line)).toBe(false)
        expect(
          Array.from(footer.querySelectorAll('a')).map((link) =>
            link.getAttribute('href'),
          ),
        ).toEqual(['/about', '/terms', '/privacy', '/license'])

        await view.flush(() => source.handshake('A'))
        expect(get(footer, 'footer-build')).toBe(line)
        expect(line.textContent?.trim()).toBe('build A')
        expect(footerBuildIsVisible(line)).toBe(true)
      } finally {
        try {
          await view?.unmount()
        } finally {
          removeStylesheet()
        }
      }
    })
  })
}
