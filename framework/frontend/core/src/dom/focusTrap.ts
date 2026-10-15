// A focus trap for a modal dialog: the subtle, accessibility-critical DOM that
// every framework's modal needs identically, so it is written once here. It is
// the first member of core/dom — browser-only helpers (plain DOM, no framework)
// that a thin view calls, kept out of the framework views to avoid triplicating
// fiddly a11y logic (multiframework-core.md). Pure DOM, no signals: it reads and
// moves focus through the element passed in and that element's ownerDocument, so
// it never touches a global and stays usable in any document.

const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

/**
 * Where {@link focusInitial} puts keyboard focus when a dialog opens.
 * `'marked'` reads the first `[data-autofocus]`. When that element is a radio
 * button, focus goes to the checked and enabled radio of that group under
 * `root` (matching native radio group behavior), falling back to the marked
 * radio; finding no mark, it focuses `root`.
 * `'dialog'` focuses the root without looking.
 */
export type FocusPlacement = 'marked' | 'dialog'

/** The tabbable elements inside `root`, in DOM order. */
export function focusableElements(root: HTMLElement): HTMLElement[] {
  return Array.from(root.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR))
}

/**
 * Find the checked and enabled radio button belonging to the same radio group
 * as `mark` within `root`.
 *
 * @param root The dialog element to search for sibling radios.
 * @param mark The autofocus-marked element.
 * @returns The checked, enabled radio of the same group, or null if none or not a radio.
 */
function checkedRadioOf(
  root: HTMLElement,
  mark: HTMLElement,
): HTMLInputElement | null {
  if (!mark.matches('input[type="radio"]')) {
    return null
  }
  const name = (mark as HTMLInputElement).name
  if (!name) {
    return null
  }
  for (const radio of root.querySelectorAll<HTMLInputElement>(
    'input[type="radio"]',
  )) {
    if (radio.name === name && radio.checked && !radio.disabled) {
      return radio
    }
  }

  return null
}

/**
 * Focus the initial element: a `[data-autofocus]` under `'marked'`, else
 * `root`. When the marked element is a radio button, focus goes to the
 * checked and enabled radio of that group under `root` (matching native radio
 * group behavior), falling back to the marked radio. `'dialog'` focuses `root`
 * without looking.
 *
 * @param root The dialog element whose initial element is focused.
 * @param placement Whether to look for a marked element or focus `root`.
 */
export function focusInitial(
  root: HTMLElement,
  placement: FocusPlacement = 'marked',
): void {
  if (placement === 'dialog') {
    root.focus()

    return
  }
  const autofocus = root.querySelector<HTMLElement>('[data-autofocus]')
  if (!autofocus) {
    root.focus()

    return
  }
  const target = checkedRadioOf(root, autofocus) ?? autofocus
  target.focus()
}

/**
 * A modal focus trap: remembers the element focused when the dialog opens, moves
 * focus into the dialog, cycles Tab within it, and returns focus on release.
 */
export class FocusTrap {
  private opener: HTMLElement | null = null

  /**
   * Remember the currently-focused element and move focus into `root`
   * according to `placement`.
   */
  activate(root: HTMLElement, placement: FocusPlacement = 'marked'): void {
    const active = root.ownerDocument.activeElement
    this.opener = active instanceof HTMLElement ? active : null
    focusInitial(root, placement)
  }

  /**
   * Move focus to `root`'s initial element according to `placement` — e.g.
   * when swapping sub-dialogs.
   */
  refocus(root: HTMLElement, placement: FocusPlacement = 'marked'): void {
    focusInitial(root, placement)
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
