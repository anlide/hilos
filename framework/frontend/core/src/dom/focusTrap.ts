// A focus trap for a modal dialog: the subtle, accessibility-critical DOM that
// every framework's modal needs identically, so it is written once here. It is
// the first member of core/dom — browser-only helpers (plain DOM, no framework)
// that a thin view calls, kept out of the framework views to avoid triplicating
// fiddly a11y logic (multiframework-core.md). Pure DOM, no signals: it reads and
// moves focus through the element passed in and that element's ownerDocument, so
// it never touches a global and stays usable in any document.

const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

/** The tabbable elements inside `root`, in DOM order. */
export function focusableElements(root: HTMLElement): HTMLElement[] {
  return Array.from(root.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR))
}

/** Focus the initial element: a `[data-autofocus]`, else the first focusable, else `root`. */
export function focusInitial(root: HTMLElement): void {
  const autofocus = root.querySelector<HTMLElement>('[data-autofocus]')
  ;(autofocus ?? focusableElements(root)[0] ?? root).focus()
}

/**
 * A modal focus trap: remembers the element focused when the dialog opens, moves
 * focus into the dialog, cycles Tab within it, and returns focus on release.
 */
export class FocusTrap {
  private opener: HTMLElement | null = null

  /** Remember the currently-focused element and move focus into `root`. */
  activate(root: HTMLElement): void {
    const active = root.ownerDocument.activeElement
    this.opener = active instanceof HTMLElement ? active : null
    focusInitial(root)
  }

  /** Move focus to `root`'s initial element — e.g. when swapping sub-dialogs. */
  refocus(root: HTMLElement): void {
    focusInitial(root)
  }

  /** Return focus to the element focused before {@link activate}. */
  release(): void {
    this.opener?.focus()
    this.opener = null
  }

  /**
   * Keep Tab focus within `root`: wrap from the last element to the first and,
   * with Shift, from the first back to the last.
   *
   * @param root The dialog element whose focusables cycle.
   * @param event The Tab keydown event; its default is prevented on a wrap.
   */
  handleTab(root: HTMLElement, event: KeyboardEvent): void {
    const items = focusableElements(root)
    if (items.length === 0) {
      event.preventDefault()

      return
    }
    const first = items[0]
    const last = items[items.length - 1]
    const active = root.ownerDocument.activeElement
    if (event.shiftKey && active === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && active === last) {
      event.preventDefault()
      first.focus()
    }
  }
}
