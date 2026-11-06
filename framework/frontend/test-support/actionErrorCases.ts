// The one text of HilosActionError's steps and expectations, run on the real
// Vue, React and Angular components (docs/agents/frontend/testing-strategy.md,
// "Shared behavior across view frameworks"). Each SDK's own test entry —
// vue/src/HilosActionError.test.ts, react/test/HilosActionError.test.tsx,
// angular/test/HilosActionError.test.ts — registers these cases with its local
// Vitest and a mount of its own component; nothing discovers this file by
// itself, which is why it is not named *.test.ts.
//
// The mount only connects the inputs to the component and performs that
// framework's lifecycle. It chooses no element and knows no expected result:
// every query, every step and every expectation is here, so a bug all three
// SDKs share fails the expectation instead of agreeing with itself.
//
// What the cases hold is behavior and accessibility: the live region, the
// hidden twin, the refusal and its absence, the details button's name, the
// failure's type, the panel opening and closing, and its heading. The row's
// Bootstrap styling is not a behavior and is not checked here (the assertion
// rules of testing-strategy.md); the twin is held as an element in the slot
// that the reader is told to skip — how much room it takes, a DOM test cannot
// prove.
//
// Test-only: no package, no barrel, no runtime import. Vitest itself comes from
// the entry that registers the cases, so this file imports only its types.
import { ActionError } from '@hilos/core/base'
import type * as Vitest from 'vitest'

/** The part of a local Vitest the cases are registered with. */
export type ActionErrorTestRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect'
>

/** What a case hands the component: the tracked action's outcome and the mount's props. */
export interface ActionErrorInput {
  /** The failure message of the tracked action, or null when it has not failed. */
  error: string | null
  /** The failure itself, or null when what was thrown was not an ActionError. */
  failure: ActionError | null
  /** Whether the same action is answering somewhere else right now. */
  suppressed: boolean
  /** What the mounting place says its action failed to do. */
  detailsTitle: string
}

/** One mounted component, driven through its own framework's lifecycle. */
export interface ActionErrorView {
  /** The document body: the row lives there, and so does a teleported or portaled panel. */
  readonly root: ParentNode
  /**
   * Change some inputs and leave the others as they are.
   *
   * @param patch The inputs to change; a field it does not carry keeps its value.
   */
  update(patch: Partial<ActionErrorInput>): Promise<void>
  /**
   * Click an element the case has found, through the framework's lifecycle.
   *
   * @param target The element to click.
   */
  click(target: HTMLElement): Promise<void>
  /** Let the framework finish the work the last step started. */
  flush(): Promise<void>
  /** Destroy the component, its effects, its panel and its host. */
  unmount(): Promise<void>
}

/** Mount the real component of one SDK with the given inputs. */
export type MountActionError = (
  input: ActionErrorInput,
) => Promise<ActionErrorView>

/** What the mounting place says its action failed to do, when the case is not about it. */
const DETAILS_TITLE = "Couldn't save"

/**
 * The inputs of a fresh mount: no refusal, not suppressed, the plain title.
 *
 * @param patch What this mount has otherwise.
 * @returns A new set of inputs.
 */
function inputs(patch: Partial<ActionErrorInput> = {}): ActionErrorInput {
  return {
    error: null,
    failure: null,
    suppressed: false,
    detailsTitle: DETAILS_TITLE,
    ...patch,
  }
}

/**
 * The element carrying a data-id, wherever under the root it is.
 *
 * @param root Where to look.
 * @param id The data-id to find.
 * @returns The element, or null when there is none.
 */
function byId(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

/**
 * Mount, run a case's steps, and destroy the mount even when a step failed.
 *
 * Once the steps have passed, the root must hold nothing of the mount any more
 * — no row, no panel, no host — and the page is not locked: the body no longer
 * carries `modal-open`, the scroll lock an open panel takes. The next mount, of
 * this case or the next, starts from an empty page that scrolls. Nothing here
 * removes that class by hand: the unmount alone has to release it, so a case
 * that ends with its panel open is the one that proves it.
 *
 * @param runner The local Vitest.
 * @param mount The SDK's mount.
 * @param input What the component is mounted with.
 * @param steps What the case does to it and expects of it.
 */
async function withView(
  runner: ActionErrorTestRunner,
  mount: MountActionError,
  input: ActionErrorInput,
  steps: (view: ActionErrorView) => Promise<void>,
): Promise<void> {
  const view = await mount(input)
  try {
    await view.flush()
    await steps(view)
  } finally {
    await view.unmount()
  }
  runner.expect(byId(view.root, 'hilos-action-error-slot')).toBeNull()
  runner.expect(view.root.querySelector('[role="dialog"]')).toBeNull()
  runner.expect(view.root.childElementCount).toBe(0)
  runner.expect(document.body.classList.contains('modal-open')).toBe(false)
}

/**
 * Press the details button of the refusal row and let the panel open.
 *
 * @param runner The local Vitest.
 * @param view The mounted component.
 */
async function openDetails(
  runner: ActionErrorTestRunner,
  view: ActionErrorView,
): Promise<void> {
  const button = byId(view.root, 'hilos-action-error-details')
  runner.expect(button).not.toBeNull()
  await view.click(button as HTMLElement)
  await view.flush()
}

/**
 * Register HilosActionError's cases under one SDK's name.
 *
 * @param sdk The SDK whose component is mounted, as the report names it.
 * @param runner The local Vitest of that SDK's test entry.
 * @param mount The mount of that SDK's real component.
 */
export function registerActionErrorCases(
  sdk: string,
  runner: ActionErrorTestRunner,
  mount: MountActionError,
): void {
  const { describe, it, expect } = runner

  describe(`HilosActionError on ${sdk}`, () => {
    it('draws the slot and no plate while nothing has failed', async () => {
      // The regression this case exists to hold: a plate drawn, red and empty,
      // before any action had failed — on Vue the message is a ref sitting on a
      // plain object, and a template expression does not unwrap it.
      await withView(runner, mount, inputs(), async (view) => {
        const slot = byId(view.root, 'hilos-action-error-slot')
        expect(slot).not.toBeNull()
        expect(slot?.getAttribute('role')).toBe('alert')
        expect(slot?.getAttribute('aria-live')).toBe('assertive')
        expect(byId(view.root, 'hilos-action-error')).toBeNull()
      })
    })

    it('holds the room with a twin the reader skips while nothing has failed', async () => {
      await withView(runner, mount, inputs(), async (view) => {
        const twin = view.root.querySelector(
          '[data-id="hilos-action-error-slot"] [data-id="hilos-action-error-idle"]',
        )
        expect(twin).not.toBeNull()
        // The room is all it is for: the reader is told to skip it.
        expect(twin?.getAttribute('aria-hidden')).toBe('true')
      })
    })

    it('draws a refusal that arrives, and says it only once', async () => {
      await withView(runner, mount, inputs(), async (view) => {
        await view.update({ error: 'Value must be an integer of 0 or more' })
        await view.flush()

        const plate = byId(view.root, 'hilos-action-error')
        expect(plate).not.toBeNull()
        expect(plate?.textContent).toContain(
          'Value must be an integer of 0 or more',
        )
        // The slot is the live region; a role on the row too and the reader
        // says it twice.
        expect(plate?.getAttribute('role')).toBeNull()
        const regions = view.root.querySelectorAll(
          '[role="alert"], [aria-live]',
        )
        expect(regions).toHaveLength(1)
        const region = regions.item(0)
        expect(region.getAttribute('data-id')).toBe('hilos-action-error-slot')
        expect(region.getAttribute('role')).toBe('alert')
        expect(region.getAttribute('aria-live')).toBe('assertive')
        expect(region.contains(plate)).toBe(true)
      })
    })

    it('keeps the room and drops the voice when suppressed', async () => {
      await withView(
        runner,
        mount,
        inputs({ error: 'Not applied', suppressed: true }),
        async (view) => {
          const slot = byId(view.root, 'hilos-action-error-slot')
          expect(slot).not.toBeNull()
          expect(byId(view.root, 'hilos-action-error-idle')).not.toBeNull()
          expect(byId(view.root, 'hilos-action-error')).toBeNull()
          // Nothing left to say and nothing to press: the region stays, empty.
          expect(slot?.textContent).not.toContain('Not applied')
          expect(byId(view.root, 'hilos-action-error-details')).toBeNull()
          expect(slot?.querySelector('button')).toBeNull()
        },
      )
    })

    it('offers the details button on every refusal, named for a reader', async () => {
      // Nothing was held back here — no type, no original text — and the button
      // is still the way to the whole of a truncated sentence.
      await withView(
        runner,
        mount,
        inputs({ error: 'Something went wrong' }),
        async (view) => {
          const button = byId(view.root, 'hilos-action-error-details')
          expect(button).not.toBeNull()
          expect(button?.getAttribute('aria-label')).toBe('Show error details')
          expect(byId(view.root, 'hilos-action-error-type')).toBeNull()
        },
      )

      // A mount of its own, after the first is gone: a type the plain refusal
      // left behind cannot pass for this one's.
      await withView(
        runner,
        mount,
        inputs({
          error: 'Something went wrong',
          failure: new ActionError(
            'settings::apply',
            'fail',
            'Something went wrong',
            'RuntimeException',
            'SQLSTATE[HY000]: lock wait timeout',
          ),
        }),
        async (view) => {
          const type = view.root.querySelector(
            '[data-id="hilos-action-error-details"] [data-id="hilos-action-error-type"]',
          )
          expect(type).not.toBeNull()
          expect(type?.textContent).toBe('RuntimeException')
        },
      )
    })

    it('opens the panel on the message and closes it when the message goes', async () => {
      await withView(
        runner,
        mount,
        inputs({ error: 'The connection dropped before it answered' }),
        async (view) => {
          await openDetails(runner, view)
          expect(
            byId(view.root, 'hilos-action-error-full')?.textContent,
          ).toContain('The connection dropped before it answered')

          // Nothing was thrown as an ActionError, so the failure is null — the
          // old guard watched that and would have closed the panel on the next
          // pass. The same message again is that pass: on React a new action
          // object and a render of its own.
          await view.update({
            error: 'The connection dropped before it answered',
          })
          await view.flush()
          expect(byId(view.root, 'hilos-action-error-full')).not.toBeNull()

          await view.update({ error: null })
          await view.flush()
          expect(byId(view.root, 'hilos-action-error-full')).toBeNull()
        },
      )
    })

    it('closes an open panel when the action turns suppressed', async () => {
      await withView(
        runner,
        mount,
        inputs({ error: 'Not applied' }),
        async (view) => {
          await openDetails(runner, view)
          expect(byId(view.root, 'hilos-action-error-full')).not.toBeNull()

          await view.update({ suppressed: true })
          await view.flush()
          expect(byId(view.root, 'hilos-action-error-full')).toBeNull()
          expect(byId(view.root, 'hilos-action-error-idle')).not.toBeNull()
        },
      )
    })

    it('treats an empty message as no refusal', async () => {
      await withView(runner, mount, inputs({ error: '' }), async (view) => {
        expect(byId(view.root, 'hilos-action-error-idle')).not.toBeNull()
        expect(byId(view.root, 'hilos-action-error')).toBeNull()
      })
    })

    it('heads the details panel with what the place says failed', async () => {
      await withView(
        runner,
        mount,
        inputs({
          error: 'Value must be an integer of 0 or more',
          detailsTitle: "Couldn't delete the backup",
        }),
        async (view) => {
          await openDetails(runner, view)

          const dialog = view.root.querySelector('[role="dialog"]')
          expect(dialog).not.toBeNull()
          expect(
            dialog?.querySelector('h1, h2, h3, h4, h5, h6, [role="heading"]')
              ?.textContent,
          ).toBe("Couldn't delete the backup")
          expect(dialog?.getAttribute('aria-label')).toBe(
            "Couldn't delete the backup",
          )
        },
      )
    })
  })
}
