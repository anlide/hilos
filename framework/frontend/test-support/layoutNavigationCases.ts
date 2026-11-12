// One shell navigation scenario text, exercised through the three native Layouts.
import type * as Vitest from 'vitest'
import { getByRole } from '@testing-library/dom'
import { focusableElements, PROTECTED_MODE_INACTIVE } from '@hilos/core/base'
import {
  FROZEN,
  layoutChromeConnection,
  layoutChromeFeed,
  layoutChromeRouter,
  type LayoutChromeInput,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'

type NavigationRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect' | 'vi'>

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** Register skip-link and disclosure semantics, with controlled toggle visibility. */
export function registerLayoutNavigationCases(
  sdk: string,
  runner: NavigationRunner,
  mount: MountLayoutChrome,
): void {
  const { describe, it, expect, vi } = runner
  function get(view: LayoutChromeView, id: string): HTMLElement {
    const element = part(view.root, id)
    expect(element, id).not.toBeNull()
    return element!
  }
  function closed(view: LayoutChromeView): void {
    expect(get(view, 'nav-toggle').getAttribute('aria-expanded')).toBe('false')
    // The collapse flag is state, not evidence of CSS geometry.
    expect(get(view, 'nav-panel').classList.contains('show')).toBe(false)
  }
  async function withNavigation(
    run: (view: LayoutChromeView, width: { wide: boolean }) => Promise<void>,
    input: Partial<LayoutChromeInput> = {},
  ): Promise<void> {
    const width = { wide: false }
    const computedStyle = window.getComputedStyle.bind(window)
    const visibility = vi
      .spyOn(window, 'getComputedStyle')
      .mockImplementation((element, pseudo) => {
        if (element.getAttribute('data-id') === 'nav-toggle')
          return {
            display: width.wide ? 'none' : 'block',
          } as CSSStyleDeclaration
        return computedStyle(element, pseudo)
      })
    let view: LayoutChromeView | undefined
    try {
      view = await mount({
        connection: layoutChromeConnection(),
        nav: true,
        ...input,
      })
      await run(view, width)
    } finally {
      try {
        await view?.unmount()
      } finally {
        visibility.mockRestore()
      }
    }
  }

  describe(`HilosLayout skip link on ${sdk}`, () => {
    it('K01 offers the first keyboard stop as a named link to the existing main', async () => {
      const view = await mount({
        connection: layoutChromeConnection(),
        nav: true,
      })
      try {
        const skip = getByRole(view.root, 'link', {
          name: 'Skip to main content',
        })
        expect(skip.tagName).toBe('A')
        expect(focusableElements(view.root)[0]).toBe(skip)
        expect(skip.getAttribute('href')).toBe('#hilos-main-content')
        const main = getByRole(view.root, 'main')
        expect(main.id).toBe('hilos-main-content')
        expect(main.tabIndex).toBe(-1)
      } finally {
        await view.unmount()
      }
    })
  })

  describe(`HilosLayout navigation disclosure on ${sdk}`, () => {
    it('N01 relates one named native button to one existing panel', async () => {
      await withNavigation(async (view) => {
        const toggle = get(view, 'nav-toggle')
        const panel = get(view, 'nav-panel')
        expect(toggle.tagName).toBe('BUTTON')
        expect(toggle.getAttribute('type')).toBe('button')
        expect(toggle.getAttribute('aria-label')).toBe('Navigation')
        expect(panel.id).not.toBe('')
        expect(toggle.getAttribute('aria-controls')).toBe(panel.id)
        expect(document.querySelectorAll(`[id="${panel.id}"]`)).toHaveLength(1)
        expect(
          view.root.querySelectorAll('[data-id="nav-panel"]'),
        ).toHaveLength(1)
        expect(
          view.root.querySelectorAll('[data-id="project-nav-first"]'),
        ).toHaveLength(1)
        expect(get(view, 'project-nav-hidden').hidden).toBe(true)
        expect(get(view, 'project-nav-hidden').getAttribute('href')).toBe(
          '/hidden',
        )
        expect(get(view, 'project-nav-first').getAttribute('href')).toBe(
          '/first',
        )
        expect(get(view, 'project-nav-second').getAttribute('href')).toBe(
          '/second',
        )
        expect(panel.getAttribute('role')).toBeNull()
        expect(panel.getAttribute('aria-modal')).toBeNull()
        expect(toggle.hasAttribute('data-bs-toggle')).toBe(false)
        closed(view)
      })
    })

    it('N02 follows synthetic Enter/Space with an explicit click, then returns focus on Escape', async () => {
      await withNavigation(async (view) => {
        const toggle = get(view, 'nav-toggle')
        for (const key of ['Enter', ' ']) {
          // DOM emulators do not generate native activation from keydown.
          await view.flush(() => {
            toggle.dispatchEvent(
              new KeyboardEvent('keydown', { key, bubbles: true }),
            )
            toggle.click()
          })
          expect(toggle.getAttribute('aria-expanded')).toBe('true')
          expect(get(view, 'nav-panel').classList.contains('show')).toBe(true)
          const first = get(view, 'project-nav-first')
          expect(document.activeElement).toBe(first)
          await view.flush(() =>
            first.dispatchEvent(
              new KeyboardEvent('keydown', {
                key: 'Escape',
                bubbles: true,
                cancelable: true,
              }),
            ),
          )
          closed(view)
          expect(document.activeElement).toBe(toggle)
        }
      })
    })

    it('N03 closes on a link click and preserves that link’s focus', async () => {
      await withNavigation(async (view) => {
        await view.flush(() => get(view, 'nav-toggle').click())
        const link = get(view, 'project-nav-second')
        link.focus()
        expect(document.activeElement).toBe(link)
        // Bubble after Layout's handler; cancel only emulator navigation outside.
        const cancelNavigation = (event: Event): void => event.preventDefault()
        view.root.addEventListener('click', cancelNavigation)
        try {
          await view.flush(() => link.click())
          closed(view)
          expect(document.activeElement).toBe(link)
        } finally {
          try {
            await view.unmount()
          } finally {
            view.root.removeEventListener('click', cancelNavigation)
          }
        }
      })
    })

    it('N04 reads the provided route input and closes without taking main focus', async () => {
      const { router, route } = layoutChromeRouter()
      await withNavigation(
        async (view) => {
          await view.flush(() => get(view, 'nav-toggle').click())
          const main = view.root.querySelector('main')!
          expect(main).not.toBeNull()
          main.focus()
          expect(document.activeElement).toBe(main)
          await view.flush(() =>
            route.set({ page: 'next', params: {}, admin: false }),
          )
          closed(view)
          expect(document.activeElement).toBe(main)
        },
        { router },
      )
    })

    it('N05 has no public disclosure markers for initially empty navigation', async () => {
      await withNavigation(
        async (view) => {
          expect(part(view.root, 'nav-toggle')).toBeNull()
          expect(part(view.root, 'nav-panel')).toBeNull()
        },
        { nav: false },
      )
    })

    it('N06 observes projected content disappearing and returns with a closed disclosure', async () => {
      await withNavigation(async (view) => {
        await view.flush(() => get(view, 'nav-toggle').click())
        expect(get(view, 'nav-toggle').getAttribute('aria-expanded')).toBe(
          'true',
        )
        await view.update({ nav: false })
        expect(part(view.root, 'nav-toggle')).toBeNull()
        expect(part(view.root, 'nav-panel')).toBeNull()
        await view.update({ nav: true })
        closed(view)
      })
    })

    it('N07 gates chrome during maintenance and returns with a closed disclosure', async () => {
      const feed = layoutChromeFeed()
      await withNavigation(
        async (view) => {
          await view.flush(() => get(view, 'nav-toggle').click())
          expect(get(view, 'nav-toggle').getAttribute('aria-expanded')).toBe(
            'true',
          )
          await view.flush(() => feed.protectedMode(FROZEN))
          // Guest admin/logout may already be absent: this checks the chrome gate.
          for (const id of [
            'nav-toggle',
            'nav-panel',
            'nav-brand',
            'nav-admin',
            'nav-logout',
          ])
            expect(part(view.root, id), id).toBeNull()
          expect(part(view.root, 'conn-state')).not.toBeNull()
          await view.flush(() => feed.protectedMode(PROTECTED_MODE_INACTIVE))
          closed(view)
        },
        { connection: feed.connection },
      )
    })

    it('N08 follows controlled toggle visibility through resize and preserves exact focus outcomes', async () => {
      await withNavigation(async (view, width) => {
        const toggle = get(view, 'nav-toggle')
        await view.flush(() => toggle.click())
        width.wide = true
        await view.flush(() => window.dispatchEvent(new Event('resize')))
        closed(view)
        expect(document.activeElement).toBe(get(view, 'project-nav-first'))
        width.wide = false
        await view.flush(() => window.dispatchEvent(new Event('resize')))
        closed(view)
        expect(document.activeElement).toBe(toggle)
        toggle.focus()
        width.wide = true
        await view.flush(() => window.dispatchEvent(new Event('resize')))
        closed(view)
        expect(document.activeElement).toBe(get(view, 'project-nav-first'))
      })
    })

    it('N09 handles an injected synthetic hold defensively, outside the production event timeline', async () => {
      const feed = layoutChromeFeed()
      await withNavigation(
        async (view) => {
          expect(part(view.root, 'app-root')).not.toBeNull()
          // A real connection holds its initial snapshot and only emits release.
          await view.flush(() => feed.injectFirstFrameHold(true))
          expect(part(view.root, 'app-root')).toBeNull()
          expect(
            part(view.root, 'hilos-boot-state')?.getAttribute('data-state'),
          ).toBe('held')
        },
        { connection: feed.connection },
      )
    })
  })
}
