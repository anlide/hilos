import { PROTECTED_MODE_INACTIVE } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import { FROZEN } from './layoutChromeFixtures.js'
import type {
  LayoutLifetimeCheckpoint,
  LayoutLifetimeOptions,
  LayoutLifetimeView,
} from './layoutLifetimeFixtures.js'
import { BLOCKED, reconsentHandshake } from './layoutLifetimeSessionFixtures.js'

type LayoutLifetimeRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

function draft(root: ParentNode): HTMLInputElement {
  const input = root.querySelector<HTMLInputElement>(
    '[data-id="lifetime-draft"]',
  )
  if (input === null) throw new Error('The ordinary page draft is missing')
  return input
}

async function enterDraft(
  view: LayoutLifetimeView,
  value: string,
): Promise<void> {
  await view.flush(() => {
    const input = draft(view.root)
    input.value = value
    input.dispatchEvent(new Event('input', { bubbles: true }))
  })
}

/** One exterior lifetime contract, with optional SDK-only API observations. */
export function registerLayoutLifetimeCases<View extends LayoutLifetimeView>(
  sdk: string,
  runner: LayoutLifetimeRunner,
  mount: (options: LayoutLifetimeOptions) => Promise<View>,
  observe?: (checkpoint: LayoutLifetimeCheckpoint, view: View) => void,
): void {
  const { describe, it, expect } = runner
  describe(`HilosLayout page projection lifetime on ${sdk}`, () => {
    for (const branch of ['maintenance', 'blocked', 'frozen'] as const) {
      it(`L01 destroys the page under ${branch} and restores a fresh draft`, async () => {
        const view = await mount({})
        try {
          const first = draft(view.root)
          const firstPage = first.parentElement
          observe?.('L01-created', view)
          await enterDraft(view, 'Unsaved draft')
          expect(first.value).toBe('Unsaved draft')
          observe?.('L01-edited', view)

          await view.flush(() => {
            if (branch === 'maintenance') view.feed.protectedMode(FROZEN)
            if (branch === 'blocked') view.feed.handshake(BLOCKED)
            if (branch === 'frozen')
              view.feed.handshake(reconsentHandshake({ frozen: true }))
          })
          expect(part(view.root, 'lifetime-draft')).toBeNull()
          expect(first.isConnected).toBe(false)
          expect(
            part(
              view.root,
              branch === 'maintenance'
                ? 'maintenance'
                : branch === 'blocked'
                  ? 'account-blocked'
                  : 'legal-frozen-screen',
            ),
          ).not.toBeNull()
          observe?.('L01-replaced', view)

          await view.flush(() => {
            if (branch === 'maintenance')
              view.feed.protectedMode(PROTECTED_MODE_INACTIVE)
            if (branch === 'blocked')
              view.feed.handshake({ data: { accountBlocked: null } })
            if (branch === 'frozen')
              view.feed.handshake(reconsentHandshake(null))
          })
          const restored = draft(view.root)
          expect(restored).not.toBe(first)
          expect(restored.parentElement).not.toBe(firstPage)
          expect(restored.value).toBe('')
          observe?.('L01-restored', view)
        } finally {
          await view.unmount()
        }
      })
    }

    it('L02 creates no intermediate page between the three replacements', async () => {
      const view = await mount({})
      try {
        const first = draft(view.root)
        expect(view.events).toHaveLength(1)
        expect(view.events[0]?.phase).toBe('start')
        const instance = view.events[0]!.instance
        observe?.('L02-created', view)
        await view.flush(() => view.feed.protectedMode(FROZEN))
        const stopped = [
          { phase: 'start', instance },
          { phase: 'stop', instance },
        ]
        expect(part(view.root, 'maintenance')).not.toBeNull()
        expect(part(view.root, 'lifetime-draft')).toBeNull()
        expect(view.events).toEqual(stopped)
        await view.flush(() => {
          view.feed.handshake(reconsentHandshake({ frozen: true }))
          view.feed.handshake(BLOCKED)
        })
        expect(part(view.root, 'maintenance')).not.toBeNull()
        expect(part(view.root, 'lifetime-draft')).toBeNull()
        expect(view.events).toEqual(stopped)
        await view.flush(() => view.feed.protectedMode(PROTECTED_MODE_INACTIVE))
        expect(part(view.root, 'account-blocked')).not.toBeNull()
        expect(part(view.root, 'lifetime-draft')).toBeNull()
        expect(view.events).toEqual(stopped)
        await view.flush(() =>
          view.feed.handshake({ data: { accountBlocked: null } }),
        )
        expect(part(view.root, 'legal-frozen-screen')).not.toBeNull()
        expect(part(view.root, 'lifetime-draft')).toBeNull()
        expect(view.events).toEqual(stopped)
        observe?.('L02-replaced', view)
        await view.flush(() => view.feed.handshake(reconsentHandshake(null)))
        expect(draft(view.root)).not.toBe(first)
        expect(draft(view.root).value).toBe('')
        expect(view.events).toHaveLength(3)
        expect(view.events.slice(0, 2)).toEqual(stopped)
        expect(view.events[2]?.phase).toBe('start')
        expect(view.events[2]?.instance).not.toBe(instance)
        observe?.('L02-restored', view)
      } finally {
        await view.unmount()
      }
    })

    it('L03 leaves the first page uncreated until the held frame is released', async () => {
      const view = await mount({ firstFrameHeld: true })
      try {
        expect(part(view.root, 'lifetime-draft')).toBeNull()
        expect(view.events).toEqual([])
        observe?.('L03-held', view)
        await view.flush(() => view.feed.releaseFirstFrame())
        expect(draft(view.root).value).toBe('')
        expect(view.events).toHaveLength(1)
        expect(view.events[0]?.phase).toBe('start')
        observe?.('L03-released', view)
      } finally {
        await view.unmount()
      }
    })

    it('L04 retains the page, draft and named regions across disconnection', async () => {
      const view = await mount({})
      try {
        const first = draft(view.root)
        const page = first.parentElement
        const slots = ['brand', 'nav', 'user', 'banner'].map((slot) =>
          part(view.root, `lifetime-${slot}`),
        )
        for (const slot of slots) expect(slot).not.toBeNull()
        await enterDraft(view, 'Keep this draft')
        expect(first.value).toBe('Keep this draft')
        const started = [...view.events]
        expect(started).toHaveLength(1)
        observe?.('L04-created', view)
        for (const state of ['disconnected', 'connected'] as const) {
          await view.flush(() => view.feed.state(state))
          expect(draft(view.root)).toBe(first)
          expect(draft(view.root).parentElement).toBe(page)
          expect(first.value).toBe('Keep this draft')
          expect(first.closest('fieldset')?.disabled).toBe(
            state !== 'connected',
          )
          for (const [index, slot] of [
            'brand',
            'nav',
            'user',
            'banner',
          ].entries())
            expect(part(view.root, `lifetime-${slot}`)).toBe(slots[index])
          expect(view.events).toEqual(started)
          observe?.(
            state === 'connected' ? 'L04-connected' : 'L04-disconnected',
            view,
          )
        }
      } finally {
        await view.unmount()
      }
    })
  })
}
