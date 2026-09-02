// Clipboard writes for a modal's Copy button. A core/dom helper (browser-only,
// plain DOM, no framework) the views call so the effect is not triplicated
// (multiframework-core.md). Never throws: over plain http `navigator.clipboard`
// is absent or `writeText` rejects, and the caller only needs to know whether
// the text reached the buffer.

/**
 * Whether a clipboard is there to write to at all.
 *
 * Asked by a surface that would rather not draw its Copy button than draw one
 * that does nothing: over plain http, and anywhere else the API is absent, a
 * click on it would fail silently and the person would keep clicking.
 *
 * @returns Whether `navigator.clipboard` exists in this document.
 */
export function isClipboardAvailable(): boolean {
  return typeof navigator !== 'undefined' && navigator.clipboard !== undefined
}

/**
 * Copy text to the clipboard.
 *
 * @param text The text to copy.
 * @param clipboard Clipboard seam; tests inject a mock. Default `navigator.clipboard`.
 * @returns Whether the text reached the clipboard.
 */
export async function copyToClipboard(
  text: string,
  clipboard?: Clipboard,
): Promise<boolean> {
  const target =
    clipboard ??
    (typeof navigator === 'undefined' ? undefined : navigator.clipboard)

  if (target === undefined) {
    return false
  }

  try {
    await target.writeText(text)

    return true
  } catch {
    return false
  }
}
