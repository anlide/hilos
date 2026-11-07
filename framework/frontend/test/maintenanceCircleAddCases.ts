import type { ActionResult } from '@hilos/core/base'
import { HILOS_MAINTENANCE_CIRCLE_COPY } from '@hilos/core/admin/maintenance'
import type { expect, it } from 'vitest'

/** A recorded dispatch whose server answer stays under the scenario's control. */
export interface MaintenanceCircleAddDispatch {
  readonly action: string
  readonly payload: Record<string, unknown>
  settle(result: ActionResult): void
}

/** Only mounting, DOM events and framework lifecycle belong to the adapter. */
export interface MaintenanceCircleAddView {
  /** Includes the page and its teleported dialogs. */
  readonly container: HTMLElement
  readonly dispatched: readonly MaintenanceCircleAddDispatch[]
  openAndType(identifier: string): Promise<void>
  typeIdentifier(identifier: string): Promise<void>
  addField(): HTMLInputElement | null
  addConfirm(): HTMLButtonElement
  /** Run a DOM event or server answer and flush the framework's render. */
  change(change: () => void): Promise<void>
  unmount(): Promise<void>
}

/** Each SDK supplies its own Vitest instance and real Circle page mount. */
export interface MaintenanceCircleAddRunner {
  readonly it: typeof it
  readonly expect: typeof expect
}

/**
 * The native Add form contract, shared by the real Vue, React and Angular pages.
 *
 * @param runner The local Vitest runner.
 * @param mount Mount an empty Circle page with a controlled action lifecycle.
 */
export function registerMaintenanceCircleAddCases(
  runner: MaintenanceCircleAddRunner,
  mount: () => Promise<MaintenanceCircleAddView>,
): void {
  const { it, expect } = runner

  it.each(['', '   '])(
    'keeps Add unavailable and cancels submit for an empty identifier (%j)',
    async (identifier) => {
      const view = await mount()
      try {
        await view.openAndType(identifier)
        const field = view.addField()!
        expect(field).not.toBeNull()
        expect(field.value).toBe(identifier)
        expect(field.placeholder).toBe(
          HILOS_MAINTENANCE_CIRCLE_COPY.addPlaceholder,
        )
        expect(view.container.textContent).toContain(
          HILOS_MAINTENANCE_CIRCLE_COPY.addLead,
        )
        expect(view.addConfirm().disabled).toBe(true)
        const form = field.form!
        expect(form).not.toBeNull()
        const submit = new Event('submit', { bubbles: true, cancelable: true })
        await view.change(() => {
          form.dispatchEvent(submit)
        })
        expect(submit.defaultPrevented).toBe(true)
        expect(view.dispatched).toHaveLength(0)
        expect(view.addField()).toBe(field)
        await view.typeIdentifier('ann@example.test')
        expect(view.addConfirm().disabled).toBe(false)
        expect(view.dispatched).toHaveLength(0)
      } finally {
        await view.unmount()
      }
    },
  )

  it('sends only once for two synchronous submits before rendering', async () => {
    const view = await mount()
    try {
      await view.openAndType('  +7 900 000-00-00  ')
      const field = view.addField()!
      expect(field).not.toBeNull()
      const form = field.form!
      expect(form).not.toBeNull()
      const first = new Event('submit', { bubbles: true, cancelable: true })
      const second = new Event('submit', { bubbles: true, cancelable: true })
      await view.change(() => {
        form.dispatchEvent(first)
        form.dispatchEvent(second)
      })

      expect(first.defaultPrevented).toBe(true)
      expect(second.defaultPrevented).toBe(true)
      expect(view.dispatched).toHaveLength(1)
      expect(view.dispatched[0]).toMatchObject({
        action: 'maintenance_circle_add',
        payload: { identifier: '  +7 900 000-00-00  ' },
      })
      expect(view.addField()).toBe(field)
      expect(field.value).toBe('  +7 900 000-00-00  ')
      expect(view.addConfirm().getAttribute('aria-busy')).toBe('true')

      await view.change(() => {
        view.dispatched[0].settle({})
      })
      expect(view.addField()).toBeNull()
    } finally {
      await view.unmount()
    }
  })

  for (const entry of ['click', 'submit'] as const) {
    it(`sends the original identifier once through ${entry} and closes on the server answer`, async () => {
      const view = await mount()
      try {
        await view.openAndType('  +7 900 000-00-00  ')
        const field = view.addField()!
        expect(field).not.toBeNull()
        expect(field.type).toBe('text')
        expect(field.labels?.[0]?.textContent).toContain(
          HILOS_MAINTENANCE_CIRCLE_COPY.addField,
        )
        const form = field.form!
        expect(form).not.toBeNull()
        expect(form.id).toBe('hilos-maintenance-circle-add-form')
        const confirm = view.addConfirm()
        expect(confirm.type).toBe('submit')
        expect(confirm.form).toBe(form)
        expect(form.contains(confirm)).toBe(false)
        expect(confirm.disabled).toBe(false)

        const submits: Event[] = []
        form.addEventListener('submit', (event) => submits.push(event))
        await view.change(() => {
          if (entry === 'click') confirm.click()
          // A submit is the DOM result of implicit Enter submission. A keydown
          // alone does not perform that browser default in a component test.
          else
            form.dispatchEvent(
              new Event('submit', { bubbles: true, cancelable: true }),
            )
        })

        expect(submits).toHaveLength(1)
        expect(submits[0].defaultPrevented).toBe(true)
        expect(view.dispatched).toMatchObject([
          {
            action: 'maintenance_circle_add',
            payload: { identifier: '  +7 900 000-00-00  ' },
          },
        ])
        expect(view.dispatched).toHaveLength(1)
        expect(view.addField()).toBe(field)
        expect(field.value).toBe('  +7 900 000-00-00  ')
        expect(confirm.getAttribute('aria-busy')).toBe('true')
        expect(confirm.getAttribute('aria-disabled')).toBe('true')
        expect(
          view.container.querySelectorAll('[data-id^="hilos-table-row-"]'),
        ).toHaveLength(0)

        await view.change(() => confirm.click())
        expect(submits).toHaveLength(1)
        expect(view.dispatched).toHaveLength(1)

        const repeated = new Event('submit', {
          bubbles: true,
          cancelable: true,
        })
        await view.change(() => {
          form.dispatchEvent(repeated)
        })
        expect(repeated.defaultPrevented).toBe(true)
        expect(view.dispatched).toHaveLength(1)
        expect(view.addField()).toBe(field)

        await view.change(() => {
          view.dispatched[0].settle({})
        })
        expect(view.addField()).toBeNull()
        expect(
          view.container.querySelectorAll('[data-id^="hilos-table-row-"]'),
        ).toHaveLength(0)
      } finally {
        await view.unmount()
      }
    })
  }
}
