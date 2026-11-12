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
  reconsentPreview,
} from '../core/test/legal/reconsentFixture.js'
import {
  withLegalReconsentView,
  type LegalReconsentInput,
  type MountLegalReconsent,
} from './legalReconsentPreviewCases.js'

type Runner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

const PERSON = { name: 'Bob', impersonated: false }

/** Exercise frozen exits and preview prohibitions through real native mounts. */
export function registerLegalReconsentNonWindowBusyCases(
  sdk: string,
  runner: Runner,
  mount: MountLegalReconsent,
): void {
  const { describe, it, expect } = runner

  describe(`HilosLegalReconsent non-window busy on ${sdk}`, () => {
    it('keeps fresh frozen exits and document navigation available while busy', async () => {
      const navigated: string[] = []
      const shown: HilosLegalReconsentView[] = []
      const router = {
        currentPath: createSignal(''),
        navigate: (path: string) => navigated.push(path),
      } as unknown as HilosRouter
      await withLegalReconsentView(
        runner,
        mount,
        {
          variant: 'frozen',
          content: reconsentContent(),
          view: { kind: 'changes' },
          busy: true,
          person: PERSON,
          deletionScheduled: true,
          now: RECONSENT_NOW,
          onShow: (view) => shown.push(view),
        },
        async ({ root, interact }) => {
          const fieldset = root.querySelector('fieldset')!
          expect(fieldset).toBeInstanceOf(HTMLFieldSetElement)
          expect(fieldset.disabled).toBe(false)
          expect(
            root
              .querySelector('[data-id="legal-reconsent-accept"]')!
              .getAttribute('aria-busy'),
          ).toBe('true')
          for (const id of ['not-you', 'keep-account', 'sign-out']) {
            const button = root.querySelector<HTMLButtonElement>(
              `[data-id="legal-reconsent-${id}"]`,
            )!
            expect(button).toBeInstanceOf(HTMLButtonElement)
            expect(button.disabled).toBe(false)
            expect(button.matches(':disabled')).toBe(false)
          }
          const data = root.querySelector<HTMLAnchorElement>(
            '[data-id="legal-reconsent-data-link"]',
          )!
          expect(data).toBeInstanceOf(HTMLAnchorElement)
          expect(data.hasAttribute('aria-disabled')).toBe(false)
          expect(data.hasAttribute('tabindex')).toBe(false)
          await interact(() => {
            data.dispatchEvent(
              new MouseEvent('click', {
                bubbles: true,
                cancelable: true,
                button: 0,
              }),
            )
          })
          expect(navigated).toEqual([
            HILOS_PAGE_ROUTES[HilosPages.PROFILE_DATA],
          ])
          const fullText = root.querySelector<HTMLButtonElement>(
            '[data-id="legal-reconsent-full-text"][data-document="terms"]',
          )!
          expect(fullText).toBeInstanceOf(HTMLButtonElement)
          expect(fullText.matches(':disabled')).toBe(false)
          await interact(() => fullText.click())
          expect(shown).toEqual([{ kind: 'text', document: 'terms' }])
        },
        { router },
      )
    })

    it.each([
      {
        name: 'Vue source with omitted person and deletionScheduled',
        input: {
          variant: 'preview',
          content: reconsentPreview('window'),
          view: { kind: 'changes' },
          busy: true,
          now: RECONSENT_NOW,
        },
      },
      {
        name: 'Angular source with person and deletionScheduled and omitted now',
        input: {
          variant: 'preview',
          content: reconsentPreview('window'),
          view: { kind: 'changes' },
          person: PERSON,
          deletionScheduled: true,
          busy: true,
        },
      },
    ] satisfies { name: string; input: LegalReconsentInput }[])(
      'preserves fresh preview prohibitions after busy clears — $name',
      async ({ input }) => {
        const shown: HilosLegalReconsentView[] = []
        await withLegalReconsentView(
          runner,
          mount,
          { ...input, onShow: (view) => shown.push(view) },
          async ({ root, update, interact }) => {
            const fieldset = root.querySelector('fieldset')!
            expect(fieldset).toBeInstanceOf(HTMLFieldSetElement)
            expect(fieldset.disabled).toBe(false)
            for (const id of ['refuse', 'later']) {
              const button = root.querySelector<HTMLButtonElement>(
                `[data-id="legal-reconsent-${id}"]`,
              )!
              expect(button).toBeInstanceOf(HTMLButtonElement)
              expect(button.disabled).toBe(true)
            }
            for (const id of ['accept', 'refuse', 'later']) {
              expect(
                root
                  .querySelector(`[data-id="legal-reconsent-${id}"]`)!
                  .matches(':disabled'),
              ).toBe(true)
            }
            const fullText = root.querySelector<HTMLButtonElement>(
              '[data-id="legal-reconsent-full-text"][data-document="terms"]',
            )!
            expect(fullText).toBeInstanceOf(HTMLButtonElement)
            expect(fullText.matches(':disabled')).toBe(false)
            await interact(() => fullText.click())
            expect(shown).toEqual([{ kind: 'text', document: 'terms' }])

            await update({ busy: false })
            for (const id of ['accept', 'refuse', 'later']) {
              expect(
                root
                  .querySelector(`[data-id="legal-reconsent-${id}"]`)!
                  .matches(':disabled'),
              ).toBe(true)
            }
          },
        )
      },
    )

    it('updates frozen to preview on the same mount while busy remains set', async () => {
      const shown: HilosLegalReconsentView[] = []
      await withLegalReconsentView(
        runner,
        mount,
        {
          variant: 'frozen',
          content: reconsentContent(),
          view: { kind: 'changes' },
          person: PERSON,
          deletionScheduled: true,
          busy: true,
          onShow: (view) => shown.push(view),
        },
        async ({ root, update, interact }) => {
          const section = root.querySelector('[data-id="legal-reconsent"]')!
          expect(section).toBeInstanceOf(HTMLElement)
          for (const id of ['not-you', 'keep-account', 'sign-out']) {
            expect(
              root
                .querySelector(`[data-id="legal-reconsent-${id}"]`)!
                .matches(':disabled'),
            ).toBe(false)
          }
          const data = root.querySelector(
            '[data-id="legal-reconsent-data-link"]',
          )!
          expect(data.hasAttribute('aria-disabled')).toBe(false)
          expect(data.hasAttribute('tabindex')).toBe(false)
          const fullText = root.querySelector<HTMLButtonElement>(
            '[data-id="legal-reconsent-full-text"][data-document="terms"]',
          )!
          expect(fullText).toBeInstanceOf(HTMLButtonElement)
          await interact(() => fullText.click())
          expect(shown).toEqual([{ kind: 'text', document: 'terms' }])

          await update({
            variant: 'preview',
            content: reconsentPreview('window'),
            view: { kind: 'changes' },
            busy: true,
            person: null,
            deletionScheduled: false,
          })
          expect(root.querySelector('[data-id="legal-reconsent"]')).toBe(
            section,
          )
          for (const id of ['accept', 'refuse', 'later']) {
            expect(
              root
                .querySelector(`[data-id="legal-reconsent-${id}"]`)!
                .matches(':disabled'),
            ).toBe(true)
          }
          const compare = root.querySelector<HTMLButtonElement>(
            '[data-id="legal-reconsent-compare"][data-document="terms"]',
          )!
          expect(compare).toBeInstanceOf(HTMLButtonElement)
          await interact(() => compare.click())
          expect(shown.at(-1)).toEqual({ kind: 'compare', document: 'terms' })
          const fieldset = root.querySelector('fieldset')!
          expect(fieldset).toBeInstanceOf(HTMLFieldSetElement)
          expect(fieldset.disabled).toBe(false)
        },
      )
    })
  })
}
