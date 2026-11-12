import {
  createSignal,
  HILOS_PAGE_ROUTES,
  HilosPages,
  type HilosLegalReconsentView,
  type HilosRouter,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  RECONSENT_NOW,
  reconsentContent,
} from '../core/test/legal/reconsentFixture.js'
import {
  withLegalReconsentView,
  type MountLegalReconsent,
} from './legalReconsentPreviewCases.js'

type Runner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

/** Require the real control before dispatching or comparing its identity. */
function get(root: ParentNode, id: string): HTMLElement {
  const node = root.querySelector<HTMLElement>(
    `[data-id="legal-reconsent-${id}"]`,
  )
  if (!node) throw new Error(`Missing legal re-consent control: ${id}`)
  return node
}

/** Record real HilosLink navigation without letting the test document leave. */
function recordingRouter(navigated: string[], currentPath = '/'): HilosRouter {
  return {
    currentPath: createSignal(currentPath),
    navigate: (path: string) => navigated.push(path),
  } as unknown as HilosRouter
}

/** A fresh cancelable event for each primary, modified and auxiliary input. */
function linkGestures(): MouseEvent[] {
  return [
    {},
    { ctrlKey: true },
    { metaKey: true },
    { shiftKey: true },
    { button: 1 },
  ].map(
    (init) =>
      new MouseEvent(init.button === 1 ? 'auxclick' : 'click', {
        ...init,
        bubbles: true,
        cancelable: true,
      }),
  )
}

/** Keep the explicit cancelable primary input used by the controlled history. */
function primaryClick(): MouseEvent {
  return new MouseEvent('click', {
    bubbles: true,
    cancelable: true,
    button: 0,
  })
}

/** Shared link, synthetic-exit and failed-read phases of the window lock. */
export function registerLegalReconsentWindowExitCases(
  runner: Runner,
  mount: MountLegalReconsent,
): void {
  const { it, expect } = runner

  it.each([
    { view: { kind: 'text', document: 'terms' } },
    { view: { kind: 'compare', document: 'terms' } },
    { view: { kind: 'refuse' } },
  ] as const)(
    'guards synthetic Back/Close outputs in the $view.kind step',
    async ({ view }) => {
      const shown: HilosLegalReconsentView[] = []
      let later = 0
      await withLegalReconsentView(
        runner,
        mount,
        {
          variant: 'window',
          content: reconsentContent(),
          view,
          onShow: (value) => shown.push(value),
          onLater: () => later++,
        },
        async ({ root, update, interact }) => {
          const back = get(root, 'back')
          const close = view.kind === 'refuse' ? get(root, 'close') : null
          await update({ busy: true })
          // Raw dispatch reaches handlers even inside a disabled fieldset.
          await interact(() => {
            back.dispatchEvent(new MouseEvent('click', { bubbles: true }))
            close?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
          })
          expect(shown).toEqual([])
          expect(later).toBe(0)

          await update({ busy: false })
          await interact(() => {
            back.dispatchEvent(new MouseEvent('click', { bubbles: true }))
          })
          expect(shown).toEqual([{ kind: 'changes' }])
          if (view.kind === 'refuse') {
            await interact(() => {
              close!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
            })
            expect(later).toBe(1)
          }
        },
      )
    },
  )

  it.each([true, false])(
    'holds both refusal links before callbacks and navigation (router=%s)',
    async (routed) => {
      const navigated: string[] = []
      let later = 0
      await withLegalReconsentView(
        runner,
        mount,
        {
          variant: 'window',
          content: reconsentContent(),
          view: { kind: 'refuse' },
          busy: false,
          onLater: () => later++,
        },
        async ({ root, update, interact }) => {
          const links = [...root.querySelectorAll('a')]
          expect(links).toHaveLength(2)
          await update({ busy: true })
          for (const link of links) {
            expect(link.getAttribute('aria-disabled')).toBe('true')
            expect(link.tabIndex).toBe(-1)
            for (const event of linkGestures()) {
              await interact(() => {
                expect(link.dispatchEvent(event)).toBe(false)
              })
              expect(event.defaultPrevented).toBe(true)
            }
          }
          expect(later).toBe(0)
          expect(navigated).toEqual([])

          await update({ busy: false })
          for (const link of links) {
            expect(link.getAttribute('aria-disabled')).toBeNull()
            expect(link.hasAttribute('tabindex')).toBe(false)
          }
          expect(later).toBe(0)
          expect(navigated).toEqual([])
          // The no-router source deliberately leaves released anchors unpressed.
          if (routed) {
            for (const link of links) {
              await interact(() => {
                link.dispatchEvent(primaryClick())
              })
            }
            expect(later).toBe(2)
            expect(navigated).toEqual([
              HILOS_PAGE_ROUTES[HilosPages.PROFILE_DATA],
              HILOS_PAGE_ROUTES[HilosPages.PROFILE],
            ])
          }
        },
        routed ? { router: recordingRouter(navigated) } : undefined,
      )
    },
  )

  it('retains the controlled changes/text/refusal and coupled exit history', async () => {
    const navigated: string[] = []
    const shown: HilosLegalReconsentView[] = []
    let later = 0
    await withLegalReconsentView(
      runner,
      mount,
      {
        variant: 'window',
        content: reconsentContent(),
        view: { kind: 'changes' },
        busy: true,
        person: { name: 'Bob', impersonated: false },
        now: RECONSENT_NOW,
        onShow: (view) => shown.push(view),
        onLater: () => later++,
      },
      async ({ root, update, interact }) => {
        for (const id of ['refuse', 'later'])
          await interact(() => get(root, id).click())
        await update({ view: { kind: 'text', document: 'terms' } })
        await update({ view: { kind: 'refuse' } })
        for (const id of ['back', 'close'])
          await interact(() => get(root, id).click())
        const links = [get(root, 'data-link'), get(root, 'delete-link')]
        for (const link of links) {
          expect(link.getAttribute('aria-disabled')).toBe('true')
          expect(link.getAttribute('tabindex')).toBe('-1')
          const event = primaryClick()
          await interact(() => {
            link.dispatchEvent(event)
          })
          expect(event.defaultPrevented).toBe(true)
        }
        expect(later).toBe(0)
        expect(shown).toEqual([])
        expect(navigated).toEqual([])

        await update({ busy: false })
        for (const link of links) {
          expect(link.getAttribute('aria-disabled')).toBeNull()
          expect(link.hasAttribute('tabindex')).toBe(false)
        }
        await interact(() => {
          links[0]!.dispatchEvent(primaryClick())
        })
        expect(navigated).toEqual([HILOS_PAGE_ROUTES[HilosPages.PROFILE_DATA]])
        expect(later).toBe(1)
        await interact(() => {
          get(root, 'close').dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
          )
        })
        expect(later).toBe(2)
        await interact(() => {
          get(root, 'back').dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
          )
        })
        expect(shown).toEqual([{ kind: 'changes' }])
      },
      { router: recordingRouter(navigated, '') },
    )
  })

  it('holds an already busy failed read and releases exactly one Retry', async () => {
    let retry = 0
    await withLegalReconsentView(
      runner,
      mount,
      {
        variant: 'window',
        content: null,
        view: { kind: 'changes' },
        error: 'Read failed.',
        busy: true,
        onRetry: () => retry++,
      },
      async ({ root, update, interact }) => {
        const node = get(root, 'retry')
        expect(node.matches(':disabled')).toBe(true)
        await interact(() => {
          node.dispatchEvent(primaryClick())
        })
        expect(retry).toBe(0)
        // Also dispatch raw input independently of native disabled .click().
        await interact(() => {
          node.dispatchEvent(new MouseEvent('click', { bubbles: true }))
        })
        expect(retry).toBe(0)
        await update({ busy: false })
        await interact(() => {
          node.dispatchEvent(primaryClick())
        })
        expect(retry).toBe(1)
        expect(get(root, 'accept').matches(':disabled')).toBe(true)
        expect(get(root, 'refuse').matches(':disabled')).toBe(true)
      },
    )
  })

  it('holds the settled failed read, its person and error, then releases its outputs', async () => {
    const shown: HilosLegalReconsentView[] = []
    let later = 0
    let retry = 0
    await withLegalReconsentView(
      runner,
      mount,
      {
        variant: 'window',
        content: null,
        view: { kind: 'changes' },
        person: { name: 'Bob', impersonated: false },
        error: 'The terms changed. Review them again.',
        onShow: (view) => shown.push(view),
        onLater: () => later++,
        onRetry: () => retry++,
      },
      async ({ root, update, interact }) => {
        const ids = ['retry', 'later']
        const nodes = ids.map((id) => get(root, id))
        const notYou = get(root, 'not-you')
        const body = root.querySelector('fieldset')!
        const text = body.textContent
        const error = get(root, 'error')
        await update({ busy: true })
        for (const node of [...nodes, notYou]) {
          expect(node.matches(':disabled')).toBe(true)
          await interact(() => node.click())
        }
        expect(shown).toEqual([])
        expect(later).toBe(0)
        expect(retry).toBe(0)
        expect(body.textContent).toBe(text)
        expect(get(root, 'error')).toBe(error)
        expect(error.textContent).toContain(
          'The terms changed. Review them again.',
        )
        await update({ busy: false })
        for (const [index, id] of ids.entries()) {
          expect(get(root, id)).toBe(nodes[index])
          expect(nodes[index]!.matches(':disabled')).toBe(false)
          await interact(() => nodes[index]!.click())
        }
        expect(shown.length + later + retry).toBe(2)
        expect(shown).toEqual([])
        expect(later).toBe(1)
        expect(retry).toBe(1)
        expect(get(root, 'refuse').matches(':disabled')).toBe(true)
        expect(get(root, 'accept').matches(':disabled')).toBe(true)
      },
    )
  })

  it.each(['data', 'delete'])(
    'holds the settled reminder %s link and restores its ordinary action',
    async (link) => {
      const navigated: string[] = []
      let later = 0
      await withLegalReconsentView(
        runner,
        mount,
        {
          variant: 'window',
          content: reconsentContent('remind'),
          view: { kind: 'refuse' },
          onLater: () => later++,
        },
        async ({ root, update, interact }) => {
          const anchor = get(root, `${link}-link`)
          const refusal = get(root, 'refuse-step')
          await update({ busy: true })
          for (const event of linkGestures()) {
            await interact(() => {
              expect(anchor.dispatchEvent(event)).toBe(false)
            })
            expect(event.defaultPrevented).toBe(true)
          }
          expect(navigated).toEqual([])
          expect(later).toBe(0)
          expect(anchor.getAttribute('aria-disabled')).toBe('true')
          expect(anchor.getAttribute('tabindex')).toBe('-1')
          expect(get(root, 'refuse-step')).toBe(refusal)
          expect(refusal.textContent).toContain(
            'Nothing changes: this reminder will keep coming back.',
          )
          await update({ busy: false })
          expect(get(root, `${link}-link`)).toBe(anchor)
          expect(anchor.hasAttribute('aria-disabled')).toBe(false)
          expect(anchor.hasAttribute('tabindex')).toBe(false)
          expect(navigated).toEqual([])
          expect(later).toBe(0)
          await interact(() => anchor.click())
          expect(navigated).toHaveLength(1)
          expect(later).toBe(1)
          expect(navigated).toEqual([
            HILOS_PAGE_ROUTES[
              link === 'data' ? HilosPages.PROFILE_DATA : HilosPages.PROFILE
            ],
          ])
        },
        { router: recordingRouter(navigated) },
      )
    },
  )
}
