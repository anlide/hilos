// Background scroll lock for open modals: records each modal instance that
// owns Bootstrap's `modal-open` class and keeps the class until the last owner
// of that document releases it. Unlocking without a matching lock is a no-op,
// including where no browser document exists. A core/dom helper (browser-only,
// plain DOM, no framework) keeps this ownership rule out of the three views
// (multiframework-core.md). The document is passed in and remembered when the
// lock is taken, never read from a global.

/** The identity of one component instance holding a scroll lock. */
export type ScrollLockOwner = object

const SCROLL_LOCK_CLASS = 'modal-open'
const lockOwners = new Map<ScrollLockOwner, Document>()

/**
 * Lock background scroll for one owner while its modal is open.
 *
 * @param doc The document whose body is locked.
 * @param owner The component instance taking the lock.
 */
export function lockBodyScroll(doc: Document, owner: ScrollLockOwner): void {
  lockOwners.set(owner, doc)
  doc.body.classList.add(SCROLL_LOCK_CLASS)
}

/**
 * Release the background scroll lock held by one owner.
 *
 * An owner that took no lock touches no document, which makes cleanup safe in
 * both closed nested modals and a server render.
 *
 * @param owner The component instance releasing its lock.
 */
export function unlockBodyScroll(owner: ScrollLockOwner): void {
  const doc = lockOwners.get(owner)
  if (doc === undefined) {
    return
  }
  lockOwners.delete(owner)
  if (![...lockOwners.values()].includes(doc)) {
    doc.body.classList.remove(SCROLL_LOCK_CLASS)
  }
}
