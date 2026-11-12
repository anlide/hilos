// One text of the drop/paste cases, registered by the three SDK test entries.
import { HILOS_UPLOAD_FIELD_COPY } from '@hilos/core/base'

import {
  uploadFieldDouble,
  withUploadView,
  type MountUpload,
  type UploadTestRunner,
} from './uploadFieldTestSupport.js'

/** A drag or paste event carrying what a browser would hand over. */
function transferEvent(
  type: string,
  key: 'dataTransfer' | 'clipboardData',
  transfer: { types: string[]; files: File[] },
): Event {
  const event = new Event(type, { bubbles: true, cancelable: true })
  Object.defineProperty(event, key, { value: transfer })
  return event
}

/** Register the shared drop/paste behavior against an SDK's real component. */
export function registerUploadDropCases(
  sdk: string,
  runner: UploadTestRunner,
  mount: MountUpload,
): void {
  const { describe, it, expect } = runner

  describe(`HilosUploadDrop on ${sdk}`, () => {
    it('wraps the content with two live regions that are always there', async () => {
      const { field } = uploadFieldDouble(runner)
      await withUploadView(mount, field, async (view) => {
        const root = view.root.querySelector('[data-id="upload"]')!
        expect(
          root.contains(view.root.querySelector('[data-id="content"]')),
        ).toBe(true)
        const alert = root.querySelector('[data-id="upload-alert"]')!
        expect(alert.getAttribute('role')).toBe('alert')
        expect(alert.getAttribute('aria-live')).toBe('assertive')
        expect(alert.classList.contains('visually-hidden')).toBe(true)
        const status = root.querySelector('[data-id="upload-status"]')!
        expect(status.getAttribute('role')).toBe('status')
        expect(status.getAttribute('aria-live')).toBe('polite')
        expect(status.classList.contains('visually-hidden')).toBe(true)
        expect(
          root.querySelectorAll('[role="alert"], [role="status"], [aria-live]'),
        ).toHaveLength(2)
        expect(root.querySelector('[data-id="upload-overlay"]')).toBeNull()
      })
    })

    it('draws the overlay only while the field says files are dragged over', async () => {
      const { field, dragging } = uploadFieldDouble(runner)
      await withUploadView(mount, field, async (view) => {
        await view.update(() => dragging.set(true))
        await view.flush()
        const overlay = view.root.querySelector('[data-id="upload-overlay"]')!
        expect(overlay.textContent?.trim()).toBe(
          HILOS_UPLOAD_FIELD_COPY.overlay,
        )
        expect(overlay.querySelector('i')?.getAttribute('aria-hidden')).toBe(
          'true',
        )
        await view.update(() => dragging.set(false))
        await view.flush()
        expect(view.root.querySelector('[data-id="upload-overlay"]')).toBeNull()
      })
    })

    it('speaks what the field says in its regions', async () => {
      const { field, alert, status } = uploadFieldDouble(runner)
      await withUploadView(mount, field, async (view) => {
        await view.update(() => {
          alert.set('site-backup.zip: File is larger than the allowed size')
          status.set('notes.txt uploaded')
        })
        await view.flush()
        expect(
          view.root
            .querySelector('[data-id="upload-alert"]')
            ?.textContent?.trim(),
        ).toBe('site-backup.zip: File is larger than the allowed size')
        expect(
          view.root
            .querySelector('[data-id="upload-status"]')
            ?.textContent?.trim(),
        ).toBe('notes.txt uploaded')
      })
    })

    it('takes a file drag into the field and keeps the browser from opening it', async () => {
      const { field } = uploadFieldDouble(runner)
      await withUploadView(mount, field, async (view) => {
        const photo = new File(['x'], 'site-photo.jpg', { type: 'image/jpeg' })
        const content = view.root.querySelector('[data-id="content"]')!
        const transfer = { types: ['Files'], files: [photo] }
        const events = ['dragenter', 'dragover', 'dragleave', 'drop'].map(
          (type) => transferEvent(type, 'dataTransfer', transfer),
        )
        await view.update(() =>
          events.forEach((event) => content.dispatchEvent(event)),
        )
        expect(field.dragEnter).toHaveBeenCalledOnce()
        expect(field.dragLeave).toHaveBeenCalledOnce()
        expect(field.drop).toHaveBeenCalledWith([photo])
        expect(events.every((event) => event.defaultPrevented)).toBe(true)
      })
    })

    it('leaves a drag without files alone', async () => {
      const { field } = uploadFieldDouble(runner)
      await withUploadView(mount, field, async (view) => {
        const content = view.root.querySelector('[data-id="content"]')!
        const events = ['dragenter', 'dragover', 'dragleave', 'drop'].map(
          (type) =>
            transferEvent(type, 'dataTransfer', {
              types: ['text/plain'],
              files: [],
            }),
        )
        await view.update(() =>
          events.forEach((event) => content.dispatchEvent(event)),
        )
        expect(field.dragEnter).not.toHaveBeenCalled()
        expect(field.dragLeave).not.toHaveBeenCalled()
        expect(field.drop).not.toHaveBeenCalled()
        expect(events.some((event) => event.defaultPrevented)).toBe(false)
      })
    })

    it('takes pasted files and lets a paste of text through', async () => {
      const { field } = uploadFieldDouble(runner)
      await withUploadView(mount, field, async (view) => {
        const content = view.root.querySelector('[data-id="content"]')!
        const text = transferEvent('paste', 'clipboardData', {
          types: ['text/plain'],
          files: [],
        })
        await view.update(() => content.dispatchEvent(text))
        expect(text.defaultPrevented).toBe(false)
        expect(field.add).not.toHaveBeenCalled()
        const photo = new File(['x'], 'site-photo.jpg', { type: 'image/jpeg' })
        const files = transferEvent('paste', 'clipboardData', {
          types: ['Files'],
          files: [photo],
        })
        await view.update(() => content.dispatchEvent(files))
        expect(files.defaultPrevented).toBe(true)
        expect(field.add).toHaveBeenCalledWith([photo])
      })
    })

    it('leaves the field to the page that created it', async () => {
      const { field } = uploadFieldDouble(runner)
      await withUploadView(mount, field, async (view) => {
        expect(view.root.querySelector('[data-id="content"]')).not.toBeNull()
      })
      expect(field.dispose).not.toHaveBeenCalled()
    })
  })
}
