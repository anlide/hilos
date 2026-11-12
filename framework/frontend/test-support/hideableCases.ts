// Shared value/projection behavior, exercised by each SDK's real HilosHideable.
import { HIDDEN_VALUE, type Hideable } from '@hilos/core/base'
import type * as Vitest from 'vitest'

/** The local Vitest that registers one SDK's cases. */
type HideableTestRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

/** Values exercised through text and framework-specific projection. */
export interface HideableInput {
  value: Hideable<string | null>
  renderValue?: (value: unknown) => string
}

/** One real component and its local destruction boundary. */
export interface HideableView {
  readonly root: HTMLElement
  unmount(): Promise<void>
}

/** Mount an SDK's real component with optional projected content. */
type MountHideable = (input: HideableInput) => Promise<HideableView>

/** Run a case and always destroy its component and host. */
async function withView(
  mount: MountHideable,
  input: HideableInput,
  steps: (view: HideableView) => void,
): Promise<void> {
  const view = await mount(input)
  try {
    steps(view)
  } finally {
    await view.unmount()
  }
}

/** Register the union of useful text, slot, render-prop and template checks. */
export function registerHideableCases(
  sdk: string,
  runner: HideableTestRunner,
  mount: MountHideable,
): void {
  const { describe, it, expect } = runner

  describe(`HilosHideable on ${sdk}`, () => {
    it('prints a value as text without projected content', async () => {
      await withView(mount, { value: 'Olena' }, ({ root }) => {
        expect(root.textContent).toBe('Olena')
        expect(root.querySelector('[data-id="hilos-hidden"]')).toBeNull()
      })
    })

    it('prints nothing for null without projected content', async () => {
      await withView(mount, { value: null }, ({ root }) => {
        expect(root.textContent).toBe('')
      })
    })

    it('hands an unhidden value to the projected content', async () => {
      await withView(
        mount,
        {
          value: 'olena@example.com',
          renderValue: (value) => String(value).toUpperCase(),
        },
        ({ root }) => {
          expect(root.querySelector('code[data-id="shown"]')?.textContent).toBe(
            'OLENA@EXAMPLE.COM',
          )
        },
      )
    })

    it('draws Hidden without invoking or stamping projected content', async () => {
      let called = false
      await withView(
        mount,
        {
          value: HIDDEN_VALUE,
          renderValue: () => {
            called = true
            return 'never'
          },
        },
        ({ root }) => {
          expect(
            root.querySelector('[data-id="hilos-hidden"]')?.textContent,
          ).toBe('Hidden')
          expect(called).toBe(false)
          expect(root.querySelector('code')).toBeNull()
        },
      )
    })
  })
}
