import {
  createSignal,
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
import { registerLegalReconsentWindowExitCases } from './legalReconsentWindowExitCases.js'

type Runner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

const PERSON = { name: 'Bob', impersonated: false }

/** Require the real node before reading its identity, text or native state. */
function get(root: ParentNode, selector: string): HTMLElement {
  const element = root.querySelector<HTMLElement>(selector)
  if (!element) throw new Error(`Missing legal re-consent element: ${selector}`)
  return element
}

/** Select the terms control when a document has more than one row. */
function button(root: ParentNode, id: string): HTMLElement {
  const document =
    id === 'full-text' || id === 'compare' ? '[data-document="terms"]' : ''
  return get(root, `[data-id="legal-reconsent-${id}"]${document}`)
}

/** Exercise ordinary window controls through each SDK's real native mount. */
export function registerLegalReconsentWindowBusyCases(
  sdk: string,
  runner: Runner,
  mount: MountLegalReconsent,
): void {
  const { describe, it, expect } = runner

  describe(`HilosLegalReconsent window busy on ${sdk}`, () => {
    it('holds all eight ordinary buttons, content and outputs until busy clears', async () => {
      const shown: HilosLegalReconsentView[] = []
      let later = 0
      await withLegalReconsentView(
        runner,
        mount,
        {
          variant: 'window',
          content: reconsentContent(),
          view: { kind: 'changes' },
          person: PERSON,
          error: 'These terms have changed.',
          now: RECONSENT_NOW,
          onShow: (view) => shown.push(view),
          onLater: () => later++,
        },
        async ({ root, update, interact }) => {
          const fieldset = root.querySelector('fieldset')!
          const documentSection = root.querySelector(
            '[data-id="legal-reconsent-document"]',
          )!
          const error = root.querySelector('[data-id="legal-reconsent-error"]')!
          const buttons = [
            ...root.querySelectorAll<HTMLButtonElement>('button'),
          ].filter((button) => button.dataset.id !== 'legal-reconsent-accept')
          expect(fieldset).toBeInstanceOf(HTMLFieldSetElement)
          expect(documentSection).toBeInstanceOf(HTMLElement)
          expect(error).toBeInstanceOf(HTMLElement)
          expect(buttons).toHaveLength(8)
          expect(fieldset.disabled).toBe(false)

          await update({ busy: true })
          expect(root.querySelector('fieldset')).toBe(fieldset)
          expect(
            root.querySelector('[data-id="legal-reconsent-document"]'),
          ).toBe(documentSection)
          expect(root.querySelector('[data-id="legal-reconsent-error"]')).toBe(
            error,
          )
          expect(error.textContent).toContain('These terms have changed.')
          for (const button of buttons) {
            expect(button).toBeInstanceOf(HTMLButtonElement)
            expect(button.matches(':disabled')).toBe(true)
            await interact(() => button.click())
          }
          expect(document.querySelector('[data-id="modal"]')).toBeNull()
          expect(shown).toEqual([])
          expect(later).toBe(0)

          await update({ busy: false })
          expect(root.querySelector('fieldset')).toBe(fieldset)
          for (const button of buttons)
            expect(button.matches(':disabled')).toBe(false)
          for (const id of ['full-text', 'compare', 'refuse', 'later']) {
            const button = buttons.find(
              (button) =>
                button.dataset.id === `legal-reconsent-${id}` &&
                (id !== 'full-text' && id !== 'compare'
                  ? true
                  : button.dataset.document === 'terms'),
            )!
            await interact(() => button.click())
          }
          expect(shown).toEqual([
            { kind: 'text', document: 'terms' },
            { kind: 'compare', document: 'terms' },
            { kind: 'refuse' },
          ])
          expect(later).toBe(1)
        },
      )
    })

    it.each(['text', 'compare', 'refuse'] as const)(
      'retains the %s body and locks its native Back/Close controls',
      async (kind) => {
        await withLegalReconsentView(
          runner,
          mount,
          {
            variant: 'window',
            content: reconsentContent(),
            view: kind === 'refuse' ? { kind } : { kind, document: 'terms' },
          },
          async ({ root, update }) => {
            const body = get(root, 'fieldset')
            const text = body.textContent
            const back = button(root, 'back')
            const close = kind === 'refuse' ? button(root, 'close') : null

            await update({ busy: true })
            expect(root.querySelector('fieldset')).toBe(body)
            expect(body.textContent).toBe(text)
            expect(back.matches(':disabled')).toBe(true)
            if (kind === 'refuse')
              expect(close!.matches(':disabled')).toBe(true)

            await update({ busy: false })
          },
        )
      },
    )

    it.each([
      {
        kind: 'changes',
        view: { kind: 'changes' },
        buttons: ['full-text', 'compare', 'refuse', 'later'],
        shown: [
          { kind: 'text', document: 'terms' },
          { kind: 'compare', document: 'terms' },
          { kind: 'refuse' },
        ],
        total: 4,
      },
      {
        kind: 'text',
        view: { kind: 'text', document: 'terms' },
        buttons: ['back', 'refuse', 'later'],
        shown: [{ kind: 'changes' }, { kind: 'refuse' }],
        total: 3,
      },
      {
        kind: 'compare',
        view: { kind: 'compare', document: 'terms' },
        buttons: ['back', 'refuse', 'later'],
        shown: [{ kind: 'changes' }, { kind: 'refuse' }],
        total: 3,
      },
      {
        kind: 'refuse',
        view: { kind: 'refuse' },
        buttons: ['back', 'close'],
        shown: [{ kind: 'changes' }],
        total: 2,
      },
    ] as const)(
      'holds the settled $kind state and releases its ordinary outputs',
      async (row) => {
        const shown: HilosLegalReconsentView[] = []
        let later = 0
        let retry = 0
        await withLegalReconsentView(
          runner,
          mount,
          {
            variant: 'window',
            content: reconsentContent('remind'),
            view: row.view,
            error: 'The terms changed. Review them again.',
            person: PERSON,
            onShow: (view) => shown.push(view),
            onLater: () => later++,
            onRetry: () => retry++,
          },
          async ({ root, update, interact }) => {
            const nodes = row.buttons.map((id) => button(root, id))
            const notYou = button(root, 'not-you')
            const body = get(root, 'fieldset')
            const text = body.textContent
            const error = get(root, '[data-id="legal-reconsent-error"]')

            await update({ busy: true })
            for (const node of [...nodes, notYou]) {
              expect(node.matches(':disabled')).toBe(true)
              await interact(() => node.click())
            }
            expect(shown).toEqual([])
            expect(later).toBe(0)
            expect(retry).toBe(0)
            expect(body.textContent).toBe(text)
            expect(
              root.querySelector('[data-id="legal-reconsent-error"]'),
            ).toBe(error)
            expect(error.textContent).toContain(
              'The terms changed. Review them again.',
            )

            await update({ busy: false })
            for (const [index, id] of row.buttons.entries()) {
              expect(button(root, id)).toBe(nodes[index])
              expect(nodes[index]!.matches(':disabled')).toBe(false)
              await interact(() => nodes[index]!.click())
            }
            expect(shown.length + later + retry).toBe(row.total)
            expect(shown).toEqual(row.shown)
            expect(later).toBe(1)
            expect(retry).toBe(0)
          },
        )
      },
    )

    it('mounts busy and retains the fieldset through caller-controlled views', async () => {
      const navigated: string[] = []
      const shown: HilosLegalReconsentView[] = []
      let later = 0
      const router = {
        currentPath: createSignal(''),
        navigate: (path: string) => navigated.push(path),
      } as unknown as HilosRouter
      await withLegalReconsentView(
        runner,
        mount,
        {
          variant: 'window',
          content: reconsentContent(),
          view: { kind: 'changes' },
          busy: true,
          person: PERSON,
          now: RECONSENT_NOW,
          onShow: (view) => shown.push(view),
          onLater: () => later++,
        },
        async ({ root, update, interact }) => {
          const fieldset = root.querySelector('fieldset')!
          const accept = root.querySelector<HTMLButtonElement>(
            '[data-id="legal-reconsent-accept"]',
          )!
          expect(fieldset).toBeInstanceOf(HTMLFieldSetElement)
          expect(accept).toBeInstanceOf(HTMLButtonElement)
          expect(fieldset.disabled).toBe(true)
          expect(fieldset.contains(accept)).toBe(false)
          expect(accept.getAttribute('aria-busy')).toBe('true')
          expect(accept.disabled).toBe(false)
          expect(accept.matches(':disabled')).toBe(false)
          for (const selector of [
            '[data-id="legal-reconsent-not-you"]',
            '[data-id="legal-reconsent-full-text"][data-document="terms"]',
            '[data-id="legal-reconsent-compare"][data-document="terms"]',
          ]) {
            const button = root.querySelector<HTMLButtonElement>(selector)!
            expect(button).toBeInstanceOf(HTMLButtonElement)
            expect(button.matches(':disabled')).toBe(true)
          }
          for (const id of ['refuse', 'later']) {
            const button = root.querySelector<HTMLButtonElement>(
              `[data-id="legal-reconsent-${id}"]`,
            )!
            expect(button).toBeInstanceOf(HTMLButtonElement)
            expect(fieldset.contains(button)).toBe(false)
            expect(button.disabled).toBe(true)
            await interact(() => button.click())
          }

          await update({ view: { kind: 'text', document: 'terms' } })
          const back = root.querySelector<HTMLButtonElement>(
            '[data-id="legal-reconsent-back"]',
          )!
          expect(back).toBeInstanceOf(HTMLButtonElement)
          expect(back.matches(':disabled')).toBe(true)

          await update({ view: { kind: 'refuse' } })
          for (const id of ['back', 'close']) {
            const button = root.querySelector<HTMLButtonElement>(
              `[data-id="legal-reconsent-${id}"]`,
            )!
            expect(button).toBeInstanceOf(HTMLButtonElement)
            expect(button.disabled).toBe(true)
            await interact(() => button.click())
          }
          expect(shown).toEqual([])
          expect(later).toBe(0)
          expect(root.querySelector('fieldset')).toBe(fieldset)

          await update({ busy: false })
          expect(fieldset.disabled).toBe(false)
        },
        { router },
      )
    })

    registerLegalReconsentWindowExitCases(runner, mount)
  })
}
