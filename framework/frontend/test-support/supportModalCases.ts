// One text of the support dialog's behavior, run on each SDK's real component.
// Adapters supply only controlled inputs, event delivery and lifecycle cleanup.
import {
  HILOS_SUPPORT_REFUSAL,
  type HilosSupportTierKey,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'

type SupportModalTestRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

/** The real dialog, including a teleported or portaled body where applicable. */
export interface SupportModalView {
  readonly root: ParentNode
  update(open: boolean): Promise<void>
  click(target: HTMLElement): Promise<void>
}

/** Keep the entire mount, update and step lifetime inside the SDK's cleanup. */
export type WithSupportModal = (
  open: boolean,
  steps: (view: SupportModalView) => Promise<void>,
) => Promise<void>

/** Find a real control or message; a missing target must fail the case. */
function byId(root: ParentNode, id: string): HTMLElement {
  const element = root.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (element === null) {
    throw new Error(`Missing support dialog element: ${id}`)
  }

  return element
}

/** One of the dialog's real tier buttons. */
function tier(root: ParentNode, key: HilosSupportTierKey): HTMLElement {
  return byId(root, `hilos-about-tier-${key}`)
}

/** The visible refusal plates, excluding the hidden room holder. */
function refusals(root: ParentNode): NodeListOf<HTMLElement> {
  return root.querySelectorAll<HTMLElement>('[data-id="hilos-about-refusal"]')
}

/** Register the union of support dialog behavior, data and accessibility. */
export function registerSupportModalCases(
  sdk: string,
  runner: SupportModalTestRunner,
  withSupportModal: WithSupportModal,
): void {
  const { describe, it, expect } = runner

  describe(`HilosSupportModal on ${sdk}`, () => {
    it('opens on the drawn default tier with the primary answer live', async () => {
      await withSupportModal(true, async ({ root }) => {
        const dialog = root.querySelector('[role="dialog"]')
        expect(dialog).not.toBeNull()
        expect(dialog?.getAttribute('aria-label')).toBe('Support the project')
        expect(
          dialog?.querySelector('[role="group"]')?.getAttribute('aria-label'),
        ).toBe('Support tier')
        expect(
          root.querySelectorAll('[data-id^="hilos-about-tier-"]'),
        ).toHaveLength(3)

        expect(tier(root, 'medium').getAttribute('aria-pressed')).toBe('true')
        expect(tier(root, 'small').getAttribute('aria-pressed')).toBe('false')
        expect(tier(root, 'large').getAttribute('aria-pressed')).toBe('false')

        for (const [key, price, omittedNames] of [
          ['small', '€0.20 / month', ['Small', 'Coffee']],
          ['medium', '€2 / month', ['Medium', 'Beer']],
          ['large', '€20 / month', ['Large', 'Patron']],
        ] as const) {
          const button = tier(root, key)
          expect(button.tagName).toBe('BUTTON')
          expect(button.getAttribute('type')).toBe('button')
          expect(button.querySelector('span > span')?.textContent?.trim()).toBe(
            price,
          )
          for (const name of omittedNames) {
            expect(button.textContent).not.toContain(name)
          }
          expect(button.querySelector('i')?.getAttribute('aria-hidden')).toBe(
            'true',
          )
        }
        expect(tier(root, 'small').textContent).toContain(
          'Thank you — these coins add up to a server',
        )

        // The announcing region exists before it has anything to say.
        const region = byId(root, 'hilos-about-live-assertive')
        expect(region.getAttribute('role')).toBe('alert')
        expect(region.getAttribute('aria-live')).toBe('assertive')
        expect(region.textContent?.trim()).toBe('')
        const subscribe = byId(root, 'hilos-about-subscribe')
        expect(subscribe.tagName).toBe('BUTTON')
        expect(subscribe.textContent?.trim()).toBe('Subscribe')
        expect(subscribe.hasAttribute('disabled')).toBe(false)
        expect(byId(root, 'hilos-about-cancel').textContent?.trim()).toBe(
          'Cancel',
        )
        expect(refusals(root)).toHaveLength(0)
      })
    })

    it('answers a subscribe attempt with the refusal, in the core’s own words', async () => {
      await withSupportModal(true, async (view) => {
        const region = byId(view.root, 'hilos-about-live-assertive')
        await view.click(byId(view.root, 'hilos-about-subscribe'))

        expect(refusals(view.root)).toHaveLength(1)
        expect(refusals(view.root)[0]?.textContent).toContain(
          HILOS_SUPPORT_REFUSAL,
        )
        // The standing region speaks; the visible plate must not repeat its role.
        expect(refusals(view.root)[0]?.getAttribute('role')).toBeNull()
        expect(byId(view.root, 'hilos-about-live-assertive')).toBe(region)
        expect(region.textContent).toContain(HILOS_SUPPORT_REFUSAL)
      })
    })

    it('re-states the same refusal on a second attempt rather than stacking one', async () => {
      await withSupportModal(true, async (view) => {
        for (let attempt = 0; attempt < 2; attempt++) {
          await view.click(byId(view.root, 'hilos-about-subscribe'))
          expect(refusals(view.root)).toHaveLength(1)
          expect(refusals(view.root)[0]?.textContent).toContain(
            HILOS_SUPPORT_REFUSAL,
          )
          expect(
            byId(view.root, 'hilos-about-live-assertive').textContent,
          ).toContain(HILOS_SUPPORT_REFUSAL)
        }
      })
    })

    it('clears the refusal when another tier is chosen', async () => {
      await withSupportModal(true, async (view) => {
        await view.click(byId(view.root, 'hilos-about-subscribe'))
        expect(refusals(view.root)).toHaveLength(1)
        await view.click(tier(view.root, 'large'))

        expect(refusals(view.root)).toHaveLength(0)
        expect(
          byId(view.root, 'hilos-about-live-assertive').textContent?.trim(),
        ).toBe('')
        expect(tier(view.root, 'large').getAttribute('aria-pressed')).toBe(
          'true',
        )
        expect(tier(view.root, 'medium').getAttribute('aria-pressed')).toBe(
          'false',
        )
      })
    })

    it('starts clean when it is opened again', async () => {
      await withSupportModal(true, async (view) => {
        await view.click(byId(view.root, 'hilos-about-subscribe'))
        await view.click(tier(view.root, 'small'))
        expect(tier(view.root, 'small').getAttribute('aria-pressed')).toBe(
          'true',
        )
        await view.click(byId(view.root, 'hilos-about-subscribe'))
        expect(refusals(view.root)).toHaveLength(1)

        await view.update(false)
        expect(view.root.querySelector('[role="dialog"]')).toBeNull()
        await view.update(true)

        expect(refusals(view.root)).toHaveLength(0)
        expect(tier(view.root, 'medium').getAttribute('aria-pressed')).toBe(
          'true',
        )
        expect(
          byId(view.root, 'hilos-about-live-assertive').textContent?.trim(),
        ).toBe('')
      })
    })

    it('keeps the refusal twin standing in both resting and refused states', async () => {
      await withSupportModal(true, async (view) => {
        const slot = byId(view.root, 'hilos-about-refusal-slot')
        const twin = byId(slot, 'hilos-about-refusal-idle')
        expect(
          slot.querySelectorAll('[data-id="hilos-about-refusal-idle"]'),
        ).toHaveLength(1)

        await view.click(byId(view.root, 'hilos-about-subscribe'))

        expect(
          slot.querySelectorAll('[data-id="hilos-about-refusal-idle"]'),
        ).toHaveLength(1)
        expect(byId(slot, 'hilos-about-refusal-idle')).toBe(twin)
      })
    })

    it('shows the explanation note at rest and hides it when refused', async () => {
      await withSupportModal(true, async (view) => {
        expect(
          view.root.querySelectorAll('[data-id="hilos-about-note"]'),
        ).toHaveLength(1)
        expect(byId(view.root, 'hilos-about-note').textContent).toContain(
          'A subscription unlocks nothing',
        )
        expect(refusals(view.root)).toHaveLength(0)

        await view.click(byId(view.root, 'hilos-about-subscribe'))

        expect(
          view.root.querySelectorAll('[data-id="hilos-about-note"]'),
        ).toHaveLength(0)
        expect(refusals(view.root)).toHaveLength(1)
      })
    })

    it('marks the idle twin aria-hidden without interaction while the visible plate carries no role', async () => {
      await withSupportModal(true, async (view) => {
        for (const refused of [false, true]) {
          if (refused) {
            await view.click(byId(view.root, 'hilos-about-subscribe'))
          }
          const twin = byId(view.root, 'hilos-about-refusal-idle')
          expect(twin.getAttribute('aria-hidden')).toBe('true')
          expect(twin.hasAttribute('tabindex')).toBe(false)
          expect(twin.hasAttribute('role')).toBe(false)
          expect(
            twin.querySelector(
              'a[href], button, input, select, textarea, [tabindex], [contenteditable="true"], [role="button"], [role="link"]',
            ),
          ).toBeNull()
        }

        const plate = byId(view.root, 'hilos-about-refusal')
        expect(plate.getAttribute('role')).toBeNull()
      })
    })
  })
}
