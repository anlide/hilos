import type {
  HilosLegalReconsentContent,
  HilosLegalReconsentPerson,
  HilosLegalReconsentPreview,
  HilosLegalReconsentVariant,
  HilosLegalReconsentView,
  HilosRouter,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  RECONSENT_NOW,
  reconsentPreview,
} from '../core/test/legal/reconsentFixture.js'

/** Actual props of the standalone screen used by these cases. */
export interface LegalReconsentInput {
  variant: HilosLegalReconsentVariant
  content: HilosLegalReconsentContent | HilosLegalReconsentPreview | null
  view: HilosLegalReconsentView
  person?: HilosLegalReconsentPerson | null
  loading?: boolean
  busy?: boolean
  error?: string | null
  deletionScheduled?: boolean
  now?: number
  onShow?: (view: HilosLegalReconsentView) => void
  onLater?: () => void
  onAccept?: () => void
  onRetry?: () => void
}

/** One real component and its own native host. */
export interface LegalReconsentView {
  readonly root: HTMLElement
  update(
    input: Partial<
      Pick<
        LegalReconsentInput,
        | 'variant'
        | 'content'
        | 'view'
        | 'person'
        | 'loading'
        | 'busy'
        | 'deletionScheduled'
      >
    >,
  ): Promise<void>
  interact(action: () => void): Promise<void>
  unmount(): Promise<void>
}

/** Native setup dependencies kept outside the component's props. */
export interface LegalReconsentMountOptions {
  router?: HilosRouter
}

/** Mount one SDK's component; a rejected setup releases its own partial resources. */
export type MountLegalReconsent = (
  input: LegalReconsentInput,
  options?: LegalReconsentMountOptions,
) => Promise<LegalReconsentView>

type Runner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

/** Attempt every native release without losing an earlier cleanup error. */
export async function releaseLegalReconsent(
  releases: (() => void | Promise<void>)[],
): Promise<void> {
  const errors: unknown[] = []
  for (const release of releases) {
    try {
      await release()
    } catch (error) {
      errors.push(error)
    }
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(errors, 'Legal re-consent cleanup failed')
}

/** Require the actual element before reading its native state or full text. */
function get(root: ParentNode, id: string): HTMLElement {
  const element = root.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (!element) throw new Error(`Missing legal re-consent element: ${id}`)
  return element
}

/** Keep the case failure and any independent unmount failure available together. */
export async function withLegalReconsentView(
  runner: Runner,
  mount: MountLegalReconsent,
  input: LegalReconsentInput,
  check: (view: LegalReconsentView) => void | Promise<void>,
  options?: LegalReconsentMountOptions,
): Promise<void> {
  const view = await mount(input, options)
  const errors: unknown[] = []
  try {
    await check(view)
  } catch (error) {
    errors.push(error)
  } finally {
    try {
      await view.unmount()
    } catch (error) {
      errors.push(error)
    }
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(errors, 'Legal re-consent case and cleanup failed')
  runner.expect(view.root.childElementCount).toBe(0)
  runner.expect(view.root.isConnected).toBe(false)
}

/** One text of preview behavior, registered with each SDK's native component. */
export function registerLegalReconsentPreviewCases(
  sdk: string,
  runner: Runner,
  mount: MountLegalReconsent,
): void {
  const { describe, it, expect } = runner

  describe(`HilosLegalReconsent preview on ${sdk}`, () => {
    it('previews through the previous holder with every button inactive', async () => {
      const plates: string[] = []
      for (const kind of ['window', 'lapsed', 'editorial'] as const) {
        await withLegalReconsentView(
          runner,
          mount,
          {
            variant: 'preview',
            content: reconsentPreview(kind),
            view: { kind: 'changes' },
            now: RECONSENT_NOW,
          },
          ({ root }) => {
            for (const id of [
              'legal-reconsent-refuse',
              'legal-reconsent-later',
              'legal-reconsent-accept',
            ]) {
              const button = get(root, id)
              expect(button).toBeInstanceOf(HTMLButtonElement)
              expect(button.hasAttribute('disabled')).toBe(true)
            }
            plates.push(get(root, 'legal-reconsent-badge').textContent!.trim())
          },
        )
      }
      expect(plates).toEqual([
        '9 days left · until 10 November 2026',
        'No window · in force since 27 September 2026',
        'Editorial · nobody is asked to accept it',
      ])
    })

    it('draws a line instead of the screen for a first revision', async () => {
      await withLegalReconsentView(
        runner,
        mount,
        {
          variant: 'preview',
          content: reconsentPreview('first'),
          view: { kind: 'changes' },
        },
        ({ root }) => {
          expect(
            get(root, 'legal-reconsent-preview-first').textContent!.trim(),
          ).toBe(
            'This is the first revision: there is nothing to compare it with, and nobody sees this screen.',
          )
          expect(
            root.querySelector('[data-id="legal-reconsent-accept"]'),
          ).toBeNull()
        },
      )
    })
  })
}
