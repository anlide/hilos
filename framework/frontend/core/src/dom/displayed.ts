// Whether an element is on display: the question a keyboard walk over a list asks of
// each entry before it lands focus there. A core/dom helper (browser-only, plain DOM,
// no framework) the views call so the rule is not triplicated (multiframework-core.md).
// It reads the element's computed style through its own ownerDocument, so it never
// touches a global and stays usable in any document.

/**
 * Whether the element is on display — its computed `display` is anything but `none`.
 *
 * Only the element itself is asked: an entry that a width hides carries the hiding
 * class on itself (`d-md-none`), which is the one case the walk has to skip. Its
 * layout boxes are not asked — a document that lays nothing out reports none for any
 * element, and every entry would drop out of the walk there — and the width is not
 * asked either: the class already answers for it, and the SDK keeps no second reading
 * of the breakpoint (table-subscription.md).
 *
 * @param element The element to ask about.
 * @returns Whether it is on display; true in a document with no window to compute in.
 */
export function isDisplayed(element: Element): boolean {
  const view = element.ownerDocument.defaultView

  return view === null || view.getComputedStyle(element).display !== 'none'
}
