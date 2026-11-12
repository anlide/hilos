import type { HilosLegalReconsentView } from '@hilos/core/base'
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

/** Require the actual component element before reading it or clicking it. */
function get(root: ParentNode, id: string): HTMLElement {
  const element = root.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (!element) throw new Error(`Missing legal re-consent element: ${id}`)
  return element
}

/** Exercise caller-controlled content and views through each SDK's native mount. */
export function registerLegalReconsentContentCases(
  sdk: string,
  runner: Runner,
  mount: MountLegalReconsent,
): void {
  const { describe, it, expect } = runner

  describe(`HilosLegalReconsent content on ${sdk}`, () => {
    it('asks for full text and draws text and comparison with a way back', async () => {
      const shown: HilosLegalReconsentView[] = []
      await withLegalReconsentView(
        runner,
        mount,
        {
          variant: 'window',
          content: reconsentContent(),
          view: { kind: 'changes' },
          now: RECONSENT_NOW,
          onShow: (view) => shown.push(view),
        },
        async ({ root, update, interact }) => {
          const screen = get(root, 'legal-reconsent')
          const fullText = root.querySelector<HTMLButtonElement>(
            '[data-id="legal-reconsent-full-text"][data-document="terms"]',
          )
          expect(fullText).toBeInstanceOf(HTMLButtonElement)
          await interact(() => fullText!.click())
          expect(shown[0]).toEqual({ kind: 'text', document: 'terms' })
          expect(shown).toHaveLength(1)
          expect(
            root.querySelector('[data-id="legal-revision-text"]'),
          ).toBeNull()

          await update({ view: { kind: 'text', document: 'terms' } })
          expect(get(root, 'legal-reconsent')).toBe(screen)
          expect(
            root.querySelector('[data-id="legal-revision-text"]'),
          ).not.toBeNull()
          expect(
            root.querySelector('[data-id="legal-reconsent-document"]'),
          ).toBeNull()

          await update({ view: { kind: 'compare', document: 'terms' } })
          expect(get(root, 'legal-reconsent')).toBe(screen)
          expect(root.querySelector('[data-id="legal-changes"]')).not.toBeNull()
          expect(
            root.querySelector('[data-id="legal-reconsent-document"]'),
          ).toBeNull()
          await interact(() => get(root, 'legal-reconsent-back').click())
          expect(shown.at(-1)).toEqual({ kind: 'changes' })
          expect(shown).toHaveLength(2)
        },
      )
    })

    it('words refusal by the current setting and closes it like Later', async () => {
      const shown: HilosLegalReconsentView[] = []
      const later: unknown[][] = []
      await withLegalReconsentView(
        runner,
        mount,
        {
          variant: 'window',
          content: reconsentContent('freeze'),
          view: { kind: 'changes' },
          now: RECONSENT_NOW,
          onShow: (view) => shown.push(view),
          onLater: (...args) => later.push(args),
        },
        async ({ root, update, interact }) => {
          const screen = get(root, 'legal-reconsent')
          await interact(() => get(root, 'legal-reconsent-refuse').click())
          expect(shown).toEqual([{ kind: 'refuse' }])
          expect(
            root.querySelector('[data-id="legal-reconsent-refuse-step"]'),
          ).toBeNull()
          await update({ view: { kind: 'refuse' } })
          expect(get(root, 'legal-reconsent')).toBe(screen)
          const step = get(root, 'legal-reconsent-refuse-step')
          expect(step.textContent).toContain(
            'you will not be able to use the product until you accept',
          )
          expect(get(step, 'legal-reconsent-data-link')).toBeInstanceOf(
            HTMLAnchorElement,
          )
          expect(get(step, 'legal-reconsent-delete-link')).toBeInstanceOf(
            HTMLAnchorElement,
          )
          expect(
            root.querySelector('[data-id="legal-reconsent-accept"]'),
          ).toBeNull()
          await interact(() => get(root, 'legal-reconsent-close').click())
          expect(later).toEqual([[]])

          await update({ content: reconsentContent('remind') })
          expect(get(root, 'legal-reconsent')).toBe(screen)
          expect(
            get(root, 'legal-reconsent-refuse-step').textContent,
          ).toContain('Nothing changes: this reminder will keep coming back.')
        },
      )
    })

    it('holds Accept during reading and reports Later and Accept after loading', async () => {
      const later: unknown[][] = []
      const accepted: unknown[][] = []
      await withLegalReconsentView(
        runner,
        mount,
        {
          variant: 'window',
          content: null,
          view: { kind: 'changes' },
          loading: true,
          onLater: (...args) => later.push(args),
          onAccept: (...args) => accepted.push(args),
        },
        async ({ root, update, interact }) => {
          const screen = get(root, 'legal-reconsent')
          expect(
            root.querySelector('[data-id="legal-reconsent-loading"]'),
          ).not.toBeNull()
          const accept = get(root, 'legal-reconsent-accept')
          expect(accept).toBeInstanceOf(HTMLButtonElement)
          expect(accept.hasAttribute('disabled')).toBe(true)

          await update({ loading: false, content: reconsentContent() })
          expect(get(root, 'legal-reconsent')).toBe(screen)
          await interact(() => get(root, 'legal-reconsent-later').click())
          await interact(() => get(root, 'legal-reconsent-accept').click())
          expect(later).toEqual([[]])
          expect(accepted).toEqual([[]])
        },
      )
    })

    it('holds Accept when no documents remain to accept', async () => {
      await withLegalReconsentView(
        runner,
        mount,
        {
          variant: 'frozen',
          content: { ...reconsentContent(), documents: [] },
          view: { kind: 'changes' },
        },
        ({ root }) => {
          const accept = get(root, 'legal-reconsent-accept')
          expect(accept).toBeInstanceOf(HTMLButtonElement)
          expect(accept.hasAttribute('disabled')).toBe(true)
        },
      )
    })

    it('keeps a failed read refusal and reports Retry', async () => {
      const retried: unknown[][] = []
      await withLegalReconsentView(
        runner,
        mount,
        {
          variant: 'window',
          content: null,
          view: { kind: 'changes' },
          error: 'The terms could not be loaded.',
          onRetry: (...args) => retried.push(args),
        },
        async ({ root, interact }) => {
          const accept = get(root, 'legal-reconsent-accept')
          expect(accept).toBeInstanceOf(HTMLButtonElement)
          expect(accept.hasAttribute('disabled')).toBe(true)
          expect(get(root, 'legal-reconsent-error').textContent).toContain(
            'The terms could not be loaded.',
          )
          await interact(() => get(root, 'legal-reconsent-retry').click())
          expect(retried).toEqual([[]])
        },
      )
    })
  })
}
