import {
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  type ThemeSettings,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  FROZEN,
  layoutChromeConnection,
  type LayoutChromeInput,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'
import { layoutThemeSession } from './layoutThemeFixtures.js'
import { withThemeMenuState } from './themeMenuFixtures.js'

type LayoutThemeRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'> & {
  releaseBindings?: (release: () => void) => Promise<void>
}

export type LayoutThemeCheckpoint =
  'T01-mounted' | 'T02-maintenance' | 'T04-off'

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** One Layout integration contract, with optional native host observations. */
export function registerLayoutThemeCases(
  sdk: string,
  runner: LayoutThemeRunner,
  mount: MountLayoutChrome,
  observe?: (checkpoint: LayoutThemeCheckpoint, root: HTMLElement) => void,
): void {
  const { describe, it, expect } = runner

  async function withThemedLayout(
    greeting: Record<string, unknown>,
    steps: (view: LayoutChromeView) => void | Promise<void>,
    connection: LayoutChromeInput['connection'] = layoutChromeConnection(),
    settings: ThemeSettings = {
      switchingEnabled: true,
      defaultTheme: 'system',
    },
  ): Promise<void> {
    await withThemeMenuState({ pick: null, settings }, async () => {
      const session = layoutThemeSession()
      let view: LayoutChromeView | undefined
      try {
        session.handshake(greeting)
        view = await mount({
          connection,
          user: { dataId: 'user-slot', text: 'Ada' },
        })
        await steps(view)
      } finally {
        try {
          await view?.unmount()
        } finally {
          // Release the newer session before the browser envelope restores state.
          // Its stale binding releases cannot reset a newer session binding.
          if (runner.releaseBindings)
            await runner.releaseBindings(session.release)
          else session.release()
        }
      }
    })
  }

  describe(`HilosLayout theme integration on ${sdk}`, () => {
    it.each([
      ['T01a', 'guest', null],
      ['T01b', 'signed-in person', { id: 7, name: 'Ada' }],
    ] as const)(
      '%s draws the theme icon first in the user group for a %s',
      async (_id, _label, user) => {
        await withThemedLayout({ entities: { currentUser: user } }, (view) => {
          const content = part(view.root, 'user-slot')
          const theme = part(view.root, 'nav-theme')
          expect(content).not.toBeNull()
          expect(theme).not.toBeNull()
          expect(
            content!.parentElement?.firstElementChild?.contains(theme),
          ).toBe(true)
          expect(
            theme!.compareDocumentPosition(content!) &
              Node.DOCUMENT_POSITION_FOLLOWING,
          ).not.toBe(0)
          expect(theme!.getAttribute('aria-label')).toBe('Theme: System')
          observe?.('T01-mounted', view.root)
        })
      },
    )

    it('T02 draws no theme icon under maintenance', async () => {
      await withThemedLayout(
        { entities: { currentUser: null } },
        (view) => {
          expect(part(view.root, 'maintenance')).not.toBeNull()
          expect(part(view.root, 'nav-theme')).toBeNull()
          expect(part(view.root, 'conn-state')).not.toBeNull()
          observe?.('T02-maintenance', view.root)
        },
        layoutChromeConnection(FROZEN),
      )
    })

    it('T03 draws no theme icon during the held first frame', async () => {
      const connection = layoutChromeConnection()
      Object.defineProperty(connection, 'firstFrameHeld', { value: true })
      await withThemedLayout(
        { entities: { currentUser: null } },
        (view) => {
          expect(part(view.root, 'nav-theme')).toBeNull()
          expect(
            part(view.root, 'hilos-boot-state')?.getAttribute('data-state'),
          ).toBe('held')
        },
        connection,
      )
    })

    it('T04 draws no theme icon when switching is off', async () => {
      await withThemedLayout(
        { entities: { currentUser: null } },
        (view) => {
          expect(part(view.root, 'nav-brand')).not.toBeNull()
          expect(part(view.root, 'nav-theme')).toBeNull()
          observe?.('T04-off', view.root)
        },
        layoutChromeConnection(),
        { switchingEnabled: false, defaultTheme: 'light' },
      )
    })

    it('T05 keeps the icon in a takeover but closes it to writes', async () => {
      await withThemedLayout(
        {
          entities: {
            currentUser: { id: 2, name: 'Bob' },
            impersonatedBy: { id: 1, name: 'Ada' },
          },
        },
        (view) => {
          const theme = view.root.querySelector<HTMLButtonElement>(
            '[data-id="nav-theme"]',
          )
          expect(theme).toBeInstanceOf(HTMLButtonElement)
          expect(theme!.disabled).toBe(true)
          expect(theme!.getAttribute('aria-describedby')).toBe(
            HILOS_IMPERSONATION_STRIP_TEXT_ID,
          )
        },
      )
    })
  })
}
