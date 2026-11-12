import type { HilosLegalReconsentPerson } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  RECONSENT_NOW,
  reconsentContent,
  reconsentPreview,
} from '../core/test/legal/reconsentFixture.js'
import {
  withLegalReconsentView,
  type LegalReconsentInput,
  type LegalReconsentView,
  type MountLegalReconsent,
} from './legalReconsentPreviewCases.js'

type Runner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

const PERSON: HilosLegalReconsentPerson = {
  name: 'Bob',
  impersonated: false,
}

/** Require the real component element before checking its text or native type. */
function get(root: ParentNode, id: string): HTMLElement {
  const element = root.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (!element) throw new Error(`Missing legal re-consent element: ${id}`)
  return element
}

/** Register presentation proofs against each SDK's actual legal re-consent view. */
export function registerLegalReconsentPresentationCases(
  sdk: string,
  runner: Runner,
  mount: MountLegalReconsent,
): void {
  const { describe, it, expect } = runner

  describe(`HilosLegalReconsent presentation on ${sdk}`, () => {
    it('draws documents, deadlines, changes, and the person', async () => {
      await withLegalReconsentView(
        runner,
        mount,
        {
          variant: 'window',
          content: reconsentContent(),
          view: { kind: 'changes' },
          person: PERSON,
          now: RECONSENT_NOW,
        },
        ({ root }) => {
          const documents = Array.from(
            root.querySelectorAll<HTMLElement>(
              '[data-id="legal-reconsent-document"]',
            ),
          )
          expect(
            documents.map((document) => document.getAttribute('data-document')),
          ).toEqual(['terms', 'privacy'])

          const badges = root.querySelectorAll<HTMLElement>(
            '[data-id="legal-reconsent-badge"]',
          )
          expect(badges[0]!.textContent).toContain('9 days left')
          expect(badges[0]!.textContent).toContain('until 10 November 2026')
          expect(badges[1]!.textContent).toContain(
            'deadline passed 27 September 2026',
          )

          expect(documents[0]!.textContent).toContain(
            'You accepted the revision of 17 September 2026. Since then 2 clauses changed',
          )
          const termsChanges = Array.from(
            documents[0]!.querySelectorAll<HTMLElement>(
              '[data-id="legal-reconsent-change"]',
            ),
          )
          expect(termsChanges).toHaveLength(2)
          expect(termsChanges[0]!.textContent).toContain('Wording changed')
          expect(termsChanges[1]!.textContent).toContain('Data may be wiped')
          expect(termsChanges[1]!.textContent).toContain(
            'Before: No promise of uptime',
          )
          expect(
            Array.from(
              root.querySelectorAll<HTMLElement>(
                '[data-id="legal-reconsent-change-kind"]',
              ),
            ).map((change) => change.textContent?.trim() ?? ''),
          ).toEqual(['Changed', 'Changed', 'Added'])
          expect(get(root, 'legal-reconsent-person').textContent).toContain(
            'Bob',
          )
          expect(
            root.querySelector('[data-id="legal-reconsent-exits"]'),
          ).toBeNull()
        },
      )
    })

    it('shows frozen acceptance and scheduled account exits', async () => {
      await withLegalReconsentView(
        runner,
        mount,
        {
          variant: 'frozen',
          content: reconsentContent(),
          view: { kind: 'changes' },
          person: PERSON,
          deletionScheduled: true,
          now: RECONSENT_NOW,
        },
        ({ root }) => {
          expect(get(root, 'legal-reconsent-heading').textContent?.trim()).toBe(
            'The terms were not accepted',
          )
          expect(
            root.querySelector('[data-id="legal-reconsent-later"]'),
          ).toBeNull()
          expect(
            root.querySelector('[data-id="legal-reconsent-refuse"]'),
          ).toBeNull()
          expect(
            root.querySelector('[data-id="legal-reconsent-accept"]'),
          ).not.toBeNull()
          expect(
            root.querySelectorAll<HTMLElement>(
              '[data-id="legal-reconsent-badge"]',
            )[1]!.textContent,
          ).toContain('account frozen · deadline passed')

          expect(get(root, 'legal-reconsent-data-link')).toBeInstanceOf(
            HTMLAnchorElement,
          )
          expect(get(root, 'legal-reconsent-keep-account')).toBeInstanceOf(
            HTMLButtonElement,
          )
          expect(get(root, 'legal-reconsent-sign-out')).toBeInstanceOf(
            HTMLButtonElement,
          )
        },
      )
    })

    it('omits Keep my account when deletion is not scheduled', async () => {
      await withLegalReconsentView(
        runner,
        mount,
        {
          variant: 'frozen',
          content: reconsentContent(),
          view: { kind: 'changes' },
          person: PERSON,
        },
        ({ root }) => {
          expect(
            root.querySelector('[data-id="legal-reconsent-keep-account"]'),
          ).toBeNull()
        },
      )
    })

    it('limits a takeover to its person and removes account actions', async () => {
      await withLegalReconsentView(
        runner,
        mount,
        {
          variant: 'frozen',
          content: reconsentContent(),
          view: { kind: 'changes' },
          person: { name: 'Bob', impersonated: true },
          deletionScheduled: true,
        },
        ({ root }) => {
          expect(
            root.querySelector('[data-id="legal-reconsent-accept"]'),
          ).toBeNull()
          expect(
            root.querySelector('[data-id="legal-reconsent-keep-account"]'),
          ).toBeNull()
          expect(
            root.querySelector('[data-id="legal-reconsent-not-you"]'),
          ).toBeNull()
          expect(
            get(root, 'legal-reconsent-impersonated').textContent?.trim(),
          ).toBe('Only Bob can accept the terms.')
        },
      )
    })

    it('shows an address only for an eligible person with loaded content', async () => {
      const mounts: {
        input: LegalReconsentInput
        check: (view: LegalReconsentView) => void
      }[] = [
        {
          input: {
            variant: 'window',
            content: reconsentContent('freeze', 'bob@example.com'),
            view: { kind: 'changes' },
            person: PERSON,
          },
          check: ({ root }) => {
            expect(
              get(root, 'legal-reconsent-address').textContent?.trim(),
            ).toBe('bob@example.com')
          },
        },
        {
          input: {
            variant: 'window',
            content: null,
            loading: true,
            view: { kind: 'changes' },
            person: PERSON,
          },
          check: ({ root }) => {
            expect(
              root.querySelector('[data-id="legal-reconsent-address"]'),
            ).toBeNull()
          },
        },
        {
          input: {
            variant: 'window',
            content: reconsentContent('freeze', null),
            view: { kind: 'changes' },
            person: PERSON,
          },
          check: ({ root }) => {
            expect(
              root.querySelector('[data-id="legal-reconsent-address"]'),
            ).toBeNull()
          },
        },
        {
          input: {
            variant: 'frozen',
            content: reconsentContent('freeze', null),
            view: { kind: 'changes' },
            person: { name: 'Bob', impersonated: true },
          },
          check: ({ root }) => {
            expect(
              root.querySelector('[data-id="legal-reconsent-address"]'),
            ).toBeNull()
          },
        },
        {
          input: {
            variant: 'preview',
            content: reconsentPreview('window'),
            view: { kind: 'changes' },
            person: null,
          },
          check: ({ root }) => {
            expect(
              root.querySelector('[data-id="legal-reconsent-person"]'),
            ).toBeNull()
          },
        },
      ]

      for (const { input, check } of mounts) {
        await withLegalReconsentView(runner, mount, input, check)
      }
    })
  })
}
