import {
  HILOS_INTERACTION_BLOCKED_ATTR,
  HILOS_INTERACTION_REGION_ATTR,
  PROTECTED_MODE_INACTIVE,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import { BLOCKED_GREETING } from './layoutBlockedFixtures.js'
import { FROZEN, layoutChromeFeed } from './layoutChromeFixtures.js'
import { layoutReconsentHandshake } from './layoutReconsentFixtures.js'
import {
  isolated,
  type MountShell,
  type ShellSession,
} from './layoutConnectionShellCases.js'

function get<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  id: string,
): T {
  const element = root.querySelector<T>(`[data-id="${id}"]`)
  if (element === null)
    throw new Error(`Missing connection branch control: ${id}`)
  return element
}

/** Navigation, first-frame disposal and account/maintenance branches on real shells. */
export function registerLayoutConnectionBranchCases(
  sdk: string,
  runner: Pick<typeof Vitest, 'describe' | 'it' | 'expect'>,
  mount: MountShell,
  bindSession: () => ShellSession,
): void {
  const { describe, it, expect } = runner
  describe(`HilosLayout connection branches on ${sdk}`, () => {
    it('C06 keeps shell navigation available through disconnect and first-frame branch removal', async () => {
      await isolated(bindSession, mount, async (session, retain) => {
        session.handshake({
          entities: { currentUser: { id: 2, name: 'Bob', admin: true } },
        })
        const feed = layoutChromeFeed()
        const view = retain({ connection: feed.connection })
        await view.flush()
        await view.flush(() => feed.state('disconnected'))
        for (const id of ['nav-brand', 'nav-admin']) {
          expect(get(view.root, id).hasAttribute('aria-disabled')).toBe(false)
          expect(get(view.root, id).closest('fieldset')).toBeNull()
        }
        const toggle = get<HTMLButtonElement>(view.root, 'nav-toggle')
        expect(toggle.disabled).toBe(false)
        await view.flush(() => toggle.click())
        expect(toggle.getAttribute('aria-expanded')).toBe('true')
        expect(get(view.root, 'app-footer').closest('fieldset')).toBeNull()
        expect(
          get<HTMLFieldSetElement>(view.root, 'page-interaction-scope')
            .disabled,
        ).toBe(true)
        const main = view.root.querySelector('main')!
        await view.flush(() => feed.injectFirstFrameHold(true))
        expect(view.root.querySelector('main')).toBeNull()
        expect(main.hasAttribute(HILOS_INTERACTION_REGION_ATTR)).toBe(false)
        await view.flush(() => feed.injectFirstFrameHold(false))
        expect(
          get<HTMLFieldSetElement>(view.root, 'page-interaction-scope')
            .disabled,
        ).toBe(true)
      })
    })

    it.each(['blocked', 'frozen'] as const)(
      'C07 gates the %s account branch without replacing it on reconnect',
      async (branch) => {
        await isolated(bindSession, mount, async (session, retain) => {
          session.handshake(
            branch === 'blocked'
              ? BLOCKED_GREETING
              : layoutReconsentHandshake({ frozen: true }),
          )
          const feed = layoutChromeFeed()
          const view = retain({ connection: feed.connection })
          await view.flush()
          const content = get(
            view.root,
            branch === 'blocked' ? 'account-blocked' : 'legal-frozen-screen',
          )
          const fieldset = get<HTMLFieldSetElement>(
            view.root,
            'page-interaction-scope',
          )
          expect(fieldset.contains(content)).toBe(true)
          await view.flush(() => {
            feed.state('disconnected')
            expect(fieldset.disabled).toBe(true)
          })
          await view.flush(() => feed.state('connected'))
          expect(content.isConnected).toBe(true)
          expect(fieldset.disabled).toBe(false)
        })
      },
    )

    it('C08 releases the maintenance pass branch and rebinds while disconnected', async () => {
      await isolated(bindSession, mount, async (_session, retain) => {
        const feed = layoutChromeFeed()
        const view = retain({ connection: feed.connection })
        await view.flush()
        await view.flush(() => feed.state('disconnected'))
        const main = view.root.querySelector('main')!
        expect(main.hasAttribute(HILOS_INTERACTION_BLOCKED_ATTR)).toBe(true)
        await view.flush(() =>
          feed.protectedMode({
            ...FROZEN,
            acceptsPass: true,
            passIssued: true,
          }),
        )
        expect(main.hasAttribute(HILOS_INTERACTION_REGION_ATTR)).toBe(false)
        expect(
          view.root.querySelector('[data-id="page-interaction-scope"]'),
        ).toBeNull()
        const input = get<HTMLInputElement>(view.root, 'maintenance-pass')
        expect(input.matches(':disabled')).toBe(false)
        const inputEvent = new Event('beforeinput', {
          bubbles: true,
          cancelable: true,
        })
        input.dispatchEvent(inputEvent)
        expect(inputEvent.defaultPrevented).toBe(false)
        await view.flush(() => feed.protectedMode(PROTECTED_MODE_INACTIVE))
        expect(
          get<HTMLFieldSetElement>(view.root, 'page-interaction-scope')
            .disabled,
        ).toBe(true)
      })
    })
  })
}
