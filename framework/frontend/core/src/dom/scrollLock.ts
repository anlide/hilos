// Background scroll lock for open modals: records each modal instance that
// owns Bootstrap's `modal-open` class and holds the page still until the last
// owner of that document releases it. Unlocking without a matching lock is a
// no-op, including where no browser document exists. A core/dom helper
// (browser-only, plain DOM, no framework) keeps this ownership rule out of the
// three views (multiframework-core.md). The document is passed in and
// remembered when the lock is taken, never read from a global.
//
// Bootstrap 5.3 gives `modal-open` no rule: its modal script writes the
// overflow, and the SDK does not load that script. The lock therefore puts the
// stock `overflow-hidden` utility on html and body. WebKit still scrolls such a
// page under a finger (https://bugs.webkit.org/show_bug.cgi?id=240859,
// https://bugs.webkit.org/show_bug.cgi?id=240860), so a document listener also
// cancels a vertical drag that no scroll container inside the body can take.
//
// What one document's lock added lives from its first owner to its last: the
// classes it found missing and its touch listeners. bindModalOpen publishes
// whether that document is locked; the rest of its browser state stays here
// rather than in shared application state.

/** The identity of one component instance holding a scroll lock. */
export type ScrollLockOwner = object

const SCROLL_LOCK_CLASS = 'modal-open'
const OVERFLOW_CLASS = 'overflow-hidden'
const SCROLLPORT_OVERFLOW = ['auto', 'scroll']
// `scrollHeight` and `clientHeight` are whole numbers while `scrollTop` is
// fractional at a device pixel ratio other than 1 (999.67 of 1000), so a
// container that has reached an edge can still read a fraction of a pixel short
// of it. Less than this much left counts as the edge, or the drag would be left
// to a container that cannot move and the page would scroll under the finger.
const SCROLLPORT_EDGE_PX = 1
const lockOwners = new Map<ScrollLockOwner, Document>()
const documentReleases = new WeakMap<Document, () => void>()
const modalOpenSubscribers = new WeakMap<
  Document,
  Set<(open: boolean) => void>
>()

/**
 * Report whether a document is locked by an open modal and follow changes.
 *
 * @param doc The document whose modal state is followed without accessing DOM.
 * @param onChange The callback for the initial state and each lock change.
 * @returns A function that removes the callback.
 */
export function bindModalOpen(
  doc: Document,
  onChange: (open: boolean) => void,
): () => void {
  let subscribers = modalOpenSubscribers.get(doc)
  if (subscribers === undefined) {
    subscribers = new Set()
    modalOpenSubscribers.set(doc, subscribers)
  }
  subscribers.add(onChange)
  onChange(documentReleases.has(doc))

  return () => subscribers.delete(onChange)
}

/**
 * Lock background scroll for one owner while its modal is open.
 *
 * @param doc The document whose page is locked.
 * @param owner The component instance taking the lock.
 */
export function lockBodyScroll(doc: Document, owner: ScrollLockOwner): void {
  lockOwners.set(owner, doc)
  if (!documentReleases.has(doc)) {
    documentReleases.set(doc, holdDocument(doc))
    for (const onChange of modalOpenSubscribers.get(doc) ?? []) onChange(true)
  }
  for (const [element, name] of lockClasses(doc)) {
    element.classList.add(name)
  }
}

/**
 * Release the background scroll lock held by one owner.
 *
 * An owner that took no lock touches no document, which makes cleanup safe in
 * both closed nested modals and a server render. The last owner of a document
 * removes only the classes its lock added and leaves the scroll position
 * where it was.
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
    documentReleases.get(doc)?.()
    documentReleases.delete(doc)
    for (const onChange of modalOpenSubscribers.get(doc) ?? []) onChange(false)
  }
}

/**
 * The classes a lock holds, each beside the element that carries it.
 *
 * @param doc The locked document.
 * @returns Pairs of element and class name.
 */
function lockClasses(doc: Document): Array<[Element, string]> {
  return [
    [doc.documentElement, OVERFLOW_CLASS],
    [doc.body, OVERFLOW_CLASS],
    [doc.body, SCROLL_LOCK_CLASS],
  ]
}

/**
 * Take a document for its first owner: note which lock classes it lacks and
 * guard its touch drags.
 *
 * @param doc The document its first owner locks.
 * @returns Remove the classes the lock added and detach the guard.
 */
function holdDocument(doc: Document): () => void {
  const added = lockClasses(doc).filter(
    ([element, name]) => !element.classList.contains(name),
  )
  const detach = guardTouch(doc)

  return () => {
    for (const [element, name] of added) {
      element.classList.remove(name)
    }
    detach()
  }
}

/**
 * Cancel a one-finger vertical drag that no scroll container inside the body
 * can take. A horizontal drag and a gesture of several fingers stay native,
 * and a drag whose start was not seen is not judged.
 *
 * A page zoomed in by a pinch is not judged at all: a one-finger drag there
 * pans the magnified view, and cancelling it would keep a low-vision reader from
 * reaching the modal's footer buttons without zooming out (WCAG 2.1 AA). The
 * accepted cost is that on WebKit, where the root's `overflow-hidden` does not
 * hold the page, the background may shift while the view is magnified.
 *
 * @param doc The locked document.
 * @returns Detach both listeners.
 */
function guardTouch(doc: Document): () => void {
  let last: { x: number; y: number } | null = null

  function start(event: TouchEvent): void {
    const touch = event.touches.length === 1 ? event.touches[0] : undefined
    last = touch ? { x: touch.clientX, y: touch.clientY } : null
  }

  function move(event: TouchEvent): void {
    const touch = event.touches.length === 1 ? event.touches[0] : undefined
    if (!touch || !last) {
      last = null
      return
    }
    const dx = touch.clientX - last.x
    const dy = touch.clientY - last.y
    last = { x: touch.clientX, y: touch.clientY }
    const view = doc.defaultView
    if (!event.cancelable || !view || Math.abs(dy) <= Math.abs(dx)) {
      return
    }
    // Without a visual viewport, the gesture is judged as on an unzoomed page.
    if ((view.visualViewport?.scale ?? 1) > 1) {
      return
    }
    // A finger moving up scrolls the content down: its scrollTop grows.
    if (!scrollportTakes(event, dy < 0, doc, view)) {
      event.preventDefault()
    }
  }

  doc.addEventListener('touchstart', start, { capture: true, passive: true })
  doc.addEventListener('touchmove', move, { capture: true, passive: false })

  return () => {
    doc.removeEventListener('touchstart', start, true)
    doc.removeEventListener('touchmove', move, true)
  }
}

/**
 * Whether a scroll container between the drag's target and the body can still
 * move the way the drag asks. The body and the root are the locked page, never
 * a container; a container already at that edge leaves the drag to the next
 * one out, as the browser's own scroll chaining does.
 *
 * @param event The vertical touch move.
 * @param down Whether the content must scroll down.
 * @param doc The locked document.
 * @param view The document's own window, whose realm reads the elements.
 * @returns True when the native drag may proceed.
 */
function scrollportTakes(
  event: TouchEvent,
  down: boolean,
  doc: Document,
  view: NonNullable<Document['defaultView']>,
): boolean {
  for (const target of event.composedPath()) {
    if (target === doc.body || target === doc.documentElement) {
      return false
    }
    if (
      !(target instanceof view.Element) ||
      !SCROLLPORT_OVERFLOW.includes(view.getComputedStyle(target).overflowY)
    ) {
      continue
    }
    const room = target.scrollHeight - target.clientHeight
    if (
      room > 0 &&
      (down
        ? room - target.scrollTop >= SCROLLPORT_EDGE_PX
        : target.scrollTop >= SCROLLPORT_EDGE_PX)
    ) {
      return true
    }
  }

  return false
}
