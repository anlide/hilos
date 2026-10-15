import type { Locator } from '@playwright/test'

// A drop from the desktop is the one door of the upload field Playwright has no
// call for: `setInputFiles` feeds the picker's input, and a mouse drag carries no
// files. What a drop is to the page is a DataTransfer holding them, so the
// helper builds one inside the page and fires the three events a drop consists
// of at the element, the way the browser does under the pointer.

/** One file the drop carries, in the shape `setInputFiles` takes. */
export interface DroppedFile {
  name: string
  mimeType: string
  buffer: Buffer
}

/**
 * Drop files on an element: dragenter, dragover and drop, all carrying them.
 * @param target The stable-id element the files are dropped on.
 * @param files The files the drop carries, in order.
 */
export async function dropFiles(
  target: Locator,
  files: readonly DroppedFile[],
): Promise<void> {
  await target.scrollIntoViewIfNeeded()
  await target.waitFor({ state: 'visible' })
  const carried = files.map((file) => ({
    name: file.name,
    mimeType: file.mimeType,
    base64: file.buffer.toString('base64'),
  }))
  const dataTransfer = await target.page().evaluateHandle((list) => {
    const transfer = new DataTransfer()
    for (const file of list) {
      const bytes = Uint8Array.from(atob(file.base64), (char) =>
        char.charCodeAt(0),
      )
      transfer.items.add(new File([bytes], file.name, { type: file.mimeType }))
    }
    return transfer
  }, carried)
  try {
    for (const type of ['dragenter', 'dragover', 'drop']) {
      await target.dispatchEvent(type, { dataTransfer })
    }
  } finally {
    await dataTransfer.dispose()
  }
}
