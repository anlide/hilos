// Clipboard writes for a modal's Copy button. A core/dom helper (browser-only,
// plain DOM, no framework) the views call so the effect is not triplicated
// (multiframework-core.md). Never throws: over plain http `navigator.clipboard`
// is absent or `writeText` rejects, and the caller only needs to know whether
// the text reached the buffer.

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
