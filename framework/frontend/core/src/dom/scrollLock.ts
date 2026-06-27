// Background scroll lock for an open modal: toggles Bootstrap's `modal-open`
// class on the document body. A core/dom helper (browser-only, plain DOM, no
// framework) the modal views call so the one-line lock is not triplicated
// (multiframework-core.md). The document is passed in, never read from a global.

const SCROLL_LOCK_CLASS = 'modal-open'

/**
 * Lock background scroll while a modal is open.
 *
 * @param doc The document whose body is locked.
 */
export function lockBodyScroll(doc: Document): void {
  doc.body.classList.add(SCROLL_LOCK_CLASS)
}

/**
 * Release the background scroll lock.
 *
 * @param doc The document whose body is unlocked.
 */
export function unlockBodyScroll(doc: Document): void {
  doc.body.classList.remove(SCROLL_LOCK_CLASS)
}
