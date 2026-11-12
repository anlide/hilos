// One text of the file chips/refusal cases, run on all three real SDK views.
import type { HilosUploadFieldFile } from '@hilos/core/base'

import {
  uploadFieldDouble,
  withUploadView,
  type MountUpload,
  type UploadTestRunner,
} from './uploadFieldTestSupport.js'

/** A file row as the field presents it, independent of transport internals. */
function file(
  clientUploadId: string,
  reading: HilosUploadFieldFile['reading'],
  extra: Partial<HilosUploadFieldFile> = {},
): HilosUploadFieldFile {
  return {
    clientUploadId,
    filename: `${clientUploadId}.pdf`,
    reading,
    percent: 0,
    canceling: false,
    ...extra,
  }
}

/** Register the shared file chip behavior against an SDK's real component. */
export function registerUploadFilesCases(
  sdk: string,
  runner: UploadTestRunner,
  mount: MountUpload,
): void {
  const { describe, it, expect } = runner

  describe(`HilosUploadFiles on ${sdk}`, () => {
    it('draws nothing while there are no files', async () => {
      const { field } = uploadFieldDouble(runner)
      await withUploadView(mount, field, async (view) => {
        expect(view.root.querySelector('[data-id="upload"]')).toBeNull()
        expect(
          view.root.querySelector('[data-id="upload-error-slot"]'),
        ).toBeNull()
      })
    })

    it('holds the refusal line from the first file and shows the refusal in it', async () => {
      const { field, files, refusal } = uploadFieldDouble(runner)
      files.set([file('a', 'waiting')])
      await withUploadView(mount, field, async (view) => {
        expect(view.root.querySelector('[data-id="upload"]')).not.toBeNull()
        expect(
          view.root.querySelector('[data-id="upload-error-slot"]'),
        ).not.toBeNull()
        expect(
          view.root
            .querySelector('[data-id="upload-error-idle"]')
            ?.getAttribute('aria-hidden'),
        ).toBe('true')
        await view.update(() =>
          refusal.set('File is larger than the allowed size'),
        )
        await view.flush()
        expect(
          view.root.querySelector('[data-id="upload-error"]')?.textContent,
        ).toContain('File is larger than the allowed size')
        expect(
          view.root
            .querySelector('[data-id="upload-error-slot"]')
            ?.getAttribute('role'),
        ).toBeNull()
        // Drop owns the voice; the visible refusal must not announce it again.
        expect(
          view.root.querySelector(
            '[role="alert"], [role="status"], [aria-live]',
          ),
        ).toBeNull()
      })
    })

    it('draws every reading with its state word and upload progress', async () => {
      const { field, files } = uploadFieldDouble(runner)
      files.set([
        file('a', 'waiting'),
        file('b', 'uploading', { percent: 62 }),
        file('c', 'received'),
        file('d', 'failed'),
      ])
      await withUploadView(mount, field, async (view) => {
        const chips = Array.from(
          view.root.querySelectorAll('[data-id="upload-file"]'),
        )
        expect(chips.map((chip) => chip.getAttribute('data-state'))).toEqual([
          'waiting',
          'uploading',
          'received',
          'failed',
        ])
        const [waiting, uploading, received, failed] = chips
        expect(waiting.querySelector('i')?.getAttribute('aria-hidden')).toBe(
          'true',
        )
        expect(waiting.querySelector('.visually-hidden')?.textContent).toBe(
          'Waiting',
        )
        expect(uploading.querySelector('.visually-hidden')?.textContent).toBe(
          'Uploading',
        )
        expect(
          uploading.querySelector('[data-id="upload-file-percent"]')
            ?.textContent,
        ).toBe('62%')
        expect(
          uploading.querySelector('[aria-hidden="true"]')?.textContent,
        ).toBe('100%')
        expect(uploading.querySelector('i')).toBeNull()
        expect(received.querySelector('i')?.getAttribute('aria-hidden')).toBe(
          'true',
        )
        expect(received.querySelector('.visually-hidden')).toBeNull()
        expect(failed.querySelector('i')?.getAttribute('aria-hidden')).toBe(
          'true',
        )
        expect(failed.querySelector('.visually-hidden')?.textContent).toBe(
          'Failed',
        )
      })
    })

    it('names each chip and removes it by its own button', async () => {
      const { field, files } = uploadFieldDouble(runner)
      files.set([file('a', 'received'), file('b', 'waiting')])
      await withUploadView(mount, field, async (view) => {
        const names = Array.from(
          view.root.querySelectorAll('[data-id="upload-file-name"]'),
        )
        expect(names.map((name) => name.textContent)).toEqual([
          'a.pdf',
          'b.pdf',
        ])
        expect(names.map((name) => name.getAttribute('title'))).toEqual([
          'a.pdf',
          'b.pdf',
        ])
        const removes = Array.from(
          view.root.querySelectorAll<HTMLButtonElement>(
            '[data-id="upload-file-remove"]',
          ),
        )
        expect(removes[1].tagName).toBe('BUTTON')
        expect(removes[1].getAttribute('aria-label')).toBe('Remove b.pdf')
        expect(removes[1].type).toBe('button')
        await view.update(() => removes[1].click())
        expect(field.remove).toHaveBeenCalledWith('b')
      })
    })

    it('keeps a canceling chip until the reply and prevents repeated removal', async () => {
      const { field, files } = uploadFieldDouble(runner)
      files.set([file('a', 'uploading', { percent: 54 })])
      await withUploadView(mount, field, async (view) => {
        const chip = view.root.querySelector('[data-id="upload-file"]')!
        const remove = view.root.querySelector<HTMLButtonElement>(
          '[data-id="upload-file-remove"]',
        )!
        await view.update(() => remove.click())
        expect(field.remove).toHaveBeenCalledExactlyOnceWith('a')
        // The page's field is waiting for cancellation; no reply removed its row yet.
        await view.update(() =>
          files.set([file('a', 'uploading', { percent: 54, canceling: true })]),
        )
        await view.flush()
        expect(view.root.querySelector('[data-id="upload-file"]')).toBe(chip)
        expect(
          chip.querySelector('[data-id="upload-file-name"]')?.textContent,
        ).toBe('a.pdf')
        expect(chip.textContent).toContain('54%')
        expect(remove.disabled).toBe(true)
        await view.update(() => remove.click())
        expect(field.remove).toHaveBeenCalledExactlyOnceWith('a')
        // Only the field's reply takes the row away.
        await view.update(() => files.set([]))
        await view.flush()
        expect(view.root.querySelector('[data-id="upload-file"]')).toBeNull()
        expect(view.root.querySelector('[data-id="upload"]')).toBeNull()
      })
    })

    it('locks every remove button while the field is disabled', async () => {
      const { field, files, disabled } = uploadFieldDouble(runner)
      files.set([file('a', 'received'), file('b', 'received')])
      await withUploadView(mount, field, async (view) => {
        const removes = Array.from(
          view.root.querySelectorAll<HTMLButtonElement>(
            '[data-id="upload-file-remove"]',
          ),
        )
        expect(removes).toHaveLength(2)
        expect(removes.every((remove) => !remove.disabled)).toBe(true)
        await view.update(() => disabled.set(true))
        await view.flush()
        expect(removes.every((remove) => remove.disabled)).toBe(true)
        await view.update(() => removes.forEach((remove) => remove.click()))
        expect(field.remove).not.toHaveBeenCalled()
      })
    })
  })
}
