// Handing a rendered text to the browser as a file. A core/dom helper (browser
// only, plain DOM, no framework) the views call so the effect is not triplicated
// (multiframework-core.md), and the neighbour of clipboard.ts: the /license page
// sends one rendering to both destinations, and each of them is one call here.
//
// Never throws, and reads the environment only when it is called: the public
// pages are rendered at build time by a server renderer where `document` does
// not exist at all, so a module-level or render-time probe would decide the
// static file's contents by the absence of a browser on the build machine.

/**
 * Offer `text` to the person as a downloaded file.
 *
 * @param fileName The name the file is offered under.
 * @param text The file's whole contents.
 * @param mimeType The type the blob is built with, e.g. `text/csv;charset=utf-8`.
 * @returns Whether the download was handed to the browser.
 */
export function downloadTextFile(
  fileName: string,
  text: string,
  mimeType: string,
): boolean {
  if (typeof document === 'undefined' || typeof URL === 'undefined') {
    return false
  }
  if (typeof URL.createObjectURL !== 'function') {
    return false
  }

  let url: string | undefined
  try {
    url = URL.createObjectURL(new Blob([text], { type: mimeType }))
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = fileName
    // Appended rather than clicked detached: Firefox ignores the click of an
    // anchor that is not in the document.
    document.body.appendChild(anchor)
    anchor.click()
    anchor.remove()

    return true
  } catch {
    return false
  } finally {
    // The object URL holds the blob alive until it is revoked, and the click has
    // already taken what it needed from it by the time this runs.
    if (url !== undefined) URL.revokeObjectURL(url)
  }
}
