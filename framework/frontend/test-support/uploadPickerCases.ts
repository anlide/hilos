// One text of the file chooser cases, registered by the three SDK test entries.
import type { HilosUploadField } from '@hilos/core/base'

import {
  uploadFieldDouble,
  withUploadView,
  type UploadTestRunner,
  type UploadView,
} from './uploadFieldTestSupport.js'

/** Mount the real picker with the page's field and an optional chooser filter. */
export type MountUploadPicker = (
  field: HilosUploadField,
  accept?: string,
) => Promise<UploadView>

/** Register the shared chooser behavior against an SDK's real component. */
export function registerUploadPickerCases(
  sdk: string,
  runner: UploadTestRunner,
  mount: MountUploadPicker,
): void {
  const { describe, it, expect, vi } = runner

  describe(`HilosUploadPicker on ${sdk}`, () => {
    it('draws a named button over a hidden multiple chooser', async () => {
      const { field } = uploadFieldDouble(runner)
      await withUploadView(
        (field) => mount(field, 'image/*,.pdf'),
        field,
        async (view) => {
          const button = view.root.querySelector<HTMLButtonElement>(
            '[data-id="upload-picker"]',
          )!
          expect(button.tagName).toBe('BUTTON')
          expect(button.type).toBe('button')
          expect(button.getAttribute('aria-label')).toBe('Attach files')
          expect(button.querySelector('i')?.getAttribute('aria-hidden')).toBe(
            'true',
          )
          const input = view.root.querySelector<HTMLInputElement>(
            '[data-id="upload-picker-input"]',
          )!
          expect(input.type).toBe('file')
          expect(input.multiple).toBe(true)
          expect(input.accept).toBe('image/*,.pdf')
          expect(input.classList.contains('d-none')).toBe(true)
        },
      )
    })

    it('opens the chooser and hands the chosen files to the field', async () => {
      const { field } = uploadFieldDouble(runner)
      await withUploadView(mount, field, async (view) => {
        const input = view.root.querySelector<HTMLInputElement>(
          '[data-id="upload-picker-input"]',
        )!
        const click = vi
          .spyOn(input, 'click')
          .mockImplementation(() => undefined)
        try {
          await view.update(() =>
            view.root
              .querySelector<HTMLButtonElement>('[data-id="upload-picker"]')!
              .click(),
          )
          expect(click).toHaveBeenCalledOnce()

          const chosen = [new File(['x'], 'notes.txt')]
          const emptied = vi.fn()
          Object.defineProperty(input, 'files', { value: chosen })
          Object.defineProperty(input, 'value', { get: () => '', set: emptied })
          await view.update(() =>
            input.dispatchEvent(new Event('change', { bubbles: true })),
          )
          expect(field.add).toHaveBeenCalledWith(chosen)
          expect(emptied).toHaveBeenCalledWith('')
        } finally {
          click.mockRestore()
        }
      })
    })

    it('is inert while the field is disabled', async () => {
      const { field, disabled } = uploadFieldDouble(runner)
      await withUploadView(mount, field, async (view) => {
        await view.update(() => disabled.set(true))
        await view.flush()
        const button = view.root.querySelector<HTMLButtonElement>(
          '[data-id="upload-picker"]',
        )!
        expect(button.disabled).toBe(true)
        const input = view.root.querySelector<HTMLInputElement>(
          '[data-id="upload-picker-input"]',
        )!
        const click = vi
          .spyOn(input, 'click')
          .mockImplementation(() => undefined)
        try {
          await view.update(() => button.click())
          expect(click).not.toHaveBeenCalled()
        } finally {
          click.mockRestore()
        }
      })
    })
  })
}
