// A focus trap for a modal dialog: the subtle, accessibility-critical DOM that
// every framework's modal needs identically, so it is written once here. It is
// the first member of core/dom — browser-only helpers (plain DOM, no framework)
// that a thin view calls, kept out of the framework views to avoid triplicating
// fiddly a11y logic (multiframework-core.md). Pure DOM, no signals: it reads and
// moves focus through the element passed in and that element's ownerDocument, so
// it never touches a global and stays usable in any document.

import {
  HILOS_INTERACTION_BLOCKED_ATTR,
  HILOS_INTERACTION_REGION_ATTR,
} from '../connection/interactionBlock.js'

const FOCUSABLE_SELECTOR =
  'a[href], summary, button, input, select, textarea, [contenteditable]:not([contenteditable="false"]), [tabindex]:not([tabindex="-1"])'

const FOCUS_BOUNDARY_SELECTOR = `dialog, [role="dialog"], [role="alertdialog"], [${HILOS_INTERACTION_REGION_ATTR}]`

const FOCUS_RETURN_REQUEST = 'hilos-focus-return-request'

interface FocusReturnRequest {
  fallback?: () => HTMLElement | null
}

/**
 * Whether a return target still belongs to the visible, interactive document.
 * Shared within core/dom; not part of the public entrypoints.
 * @internal
 */
export function available(element: HTMLElement | null): element is HTMLElement {
  if (!element?.isConnected || element.matches(':disabled')) return false
  if (element.closest('[hidden], [inert]')) return false
  if (element.getAttribute('aria-disabled') === 'true') return false
  if (
    element
      .closest(`[${HILOS_INTERACTION_REGION_ATTR}]`)
      ?.hasAttribute(HILOS_INTERACTION_BLOCKED_ATTR)
  )
    return false
  for (
    let node: HTMLElement | null = element;
    node;
    node = node.parentElement
  ) {
    const style = node.ownerDocument.defaultView?.getComputedStyle(node)
    if (
      style?.display === 'none' ||
      style?.visibility === 'hidden' ||
      style?.visibility === 'collapse'
    )
      return false
  }
  return true
}

/**
 * Offer a mount-local fallback for modal openers inside `root`. Every owner
 * around the opener answers a bubbling request on activation, and the answer
 * stays nearest first: the nearest owner's target wins while it is available,
 * and an outer owner is read only when every nearer one gives none — it was
 * disposed, its root left the document, or its target is null or unavailable.
 * Each owner may capture a replacement reader from the request's original
 * opener while it is still mounted. After nearer offers, that owner's available
 * replacement precedes its ordinary fallback. No document registry is involved.
 * Disposal invalidates callbacks already captured by an open trap as well as
 * removing the listener.
 *
 * @param root The connected surface that owns the opener.
 * @param fallback Read the surface's current visible fallback on release.
 * @param captureReplacement Optionally capture a mount-local address at activation.
 * @returns Release this mount's offer, including outstanding callbacks.
 */
export function registerFocusReturnFallback(
  root: HTMLElement,
  fallback: () => HTMLElement | null,
  captureReplacement?: (
    opener: HTMLElement,
  ) => (() => HTMLElement | null) | null,
): () => void {
  let active = true
  const answer = (event: Event) => {
    const request = (event as CustomEvent<FocusReturnRequest>).detail
    const nearer = request.fallback
    const replacement =
      event.target instanceof HTMLElement
        ? captureReplacement?.(event.target)
        : null
    request.fallback = () => {
      const target = nearer?.() ?? null
      if (available(target)) return target
      if (!active || !root.isConnected) return null
      const replacementTarget = replacement?.() ?? null
      if (available(replacementTarget)) return replacementTarget

      return fallback()
    }
  }
  root.addEventListener(FOCUS_RETURN_REQUEST, answer)
  return () => {
    active = false
    root.removeEventListener(FOCUS_RETURN_REQUEST, answer)
  }
}

/**
 * Where {@link focusInitial} puts keyboard focus when a dialog opens.
 * `'marked'` reads the first `[data-autofocus]` that is not `:disabled` — a
 * disabled `fieldset` around it disables it too. When that element is a radio
 * button, focus goes to the checked radio of that group under `root` that is
 * not `:disabled` either (matching native radio group behavior), falling back
 * to the marked radio; finding no such mark, it focuses `root`.
 * `'dialog'` focuses the root without looking.
 */
export type FocusPlacement = 'marked' | 'dialog'

/**
 * The tabbable elements inside `root`, in DOM order. A control that matches
 * `:disabled` is left out, including one disabled only by its `fieldset`.
 * Hidden, ARIA-disabled and connection-blocked controls are unavailable too.
 */
export function focusableElements(root: HTMLElement): HTMLElement[] {
  return Array.from(
    root.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR),
  ).filter(
    (element) =>
      available(element) && element.getAttribute('tabindex') !== '-1',
  )
}

/**
 * Find the checked radio button belonging to the same radio group as `mark`
 * within `root` that is not `:disabled`, by itself or by its `fieldset`.
 *
 * @param root The dialog element to search for sibling radios.
 * @param mark The autofocus-marked element.
 * @returns The checked, available radio of the same group, or null if none or not a radio.
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
    if (radio.name === name && radio.checked && available(radio)) {
      return radio
    }
  }

  return null
}

/**
 * Focus the initial element: the first `[data-autofocus]` that is not
 * `:disabled` under `'marked'`, else `root` — a mark inside a disabled
 * `fieldset` is skipped. When the marked element is a radio button, focus goes
 * to the checked radio of that group under `root` that is not `:disabled`
 * either (matching native radio group behavior), falling back to the marked
 * radio. `'dialog'` focuses `root` without looking.
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
  const autofocus = Array.from(
    root.querySelectorAll<HTMLElement>('[data-autofocus]'),
  ).find(
    (mark) =>
      available(mark) &&
      (!root.hasAttribute(HILOS_INTERACTION_REGION_ATTR) ||
        mark.closest(`[${HILOS_INTERACTION_REGION_ATTR}]`) === root),
  )
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
  private root: HTMLElement | null = null
  private fallback: (() => HTMLElement | null) | undefined
  private wasBusy = false
  private busyTarget: HTMLElement | null = null
  private lastFocused: HTMLElement | null = null
  private stopFocusTracking: (() => void) | undefined

  /**
   * Remember the currently-focused element and move focus into `root`
   * according to `placement`.
   */
  activate(root: HTMLElement, placement: FocusPlacement = 'marked'): void {
    const active = root.ownerDocument.activeElement
    this.opener = active instanceof HTMLElement ? active : null
    this.root = root
    this.trackFocus(root)
    const request: FocusReturnRequest = {}
    this.opener?.dispatchEvent(
      new CustomEvent(FOCUS_RETURN_REQUEST, {
        bubbles: true,
        detail: request,
      }),
    )
    this.fallback = request.fallback
    focusInitial(root, placement)
  }

  /**
   * Move focus to `root`'s initial element according to `placement` — e.g.
   * when swapping sub-dialogs.
   */
  refocus(root: HTMLElement, placement: FocusPlacement = 'marked'): void {
    if (root !== this.root) {
      this.root = root
      this.trackFocus(root)
    }
    focusInitial(root, placement)
  }

  /**
   * Reconcile busy after the open main dialog's body has applied disabled.
   * Park only a natively disabled focused control, then return to that exact
   * node from the dialog root. A surviving unavailable target forbids fallback;
   * a missing target permits initial focus for the current step from root/body.
   * Repeated busy or idle renders do not move focus or replace the capture.
   *
   * @param root The current connected main dialog, without a confirm step.
   * @param body The current mounted body belonging to that dialog.
   * @param busy Whether the dialog's request is in flight.
   * @param placement Initial focus policy when no captured field survives.
   */
  setBusy(
    root: HTMLElement,
    body: HTMLElement,
    busy: boolean,
    placement: FocusPlacement = 'marked',
  ): void {
    if (
      root !== this.root ||
      !root.isConnected ||
      root.matches('[role="alertdialog"]') ||
      !this.owns(body) ||
      busy === this.wasBusy
    )
      return

    this.wasBusy = busy
    const document = root.ownerDocument
    const active = document.activeElement
    if (busy) {
      const target = this.lastFocused
      if (
        target &&
        body.contains(target) &&
        this.owns(target) &&
        target.matches(':disabled') &&
        (active === target || active === document.body)
      ) {
        this.busyTarget = target
        root.focus({ preventScroll: true })
      }

      return
    }

    const target = this.busyTarget
    this.busyTarget = null
    if (target && body.contains(target)) {
      if (active === root && this.owns(target) && available(target)) {
        target.focus({ preventScroll: true })
      }

      return
    }
    if (!active || active === root || active === document.body) {
      focusInitial(root, placement)
    }
  }

  /**
   * Return to the available opener, or, when it disappeared, the current
   * fallback of the nearest mount around it that still gives an available one.
   * An explicit focus choice outside the dialog is preserved.
   * Every handle is released even when there is no longer a return target.
   */
  release(): void {
    const { opener, root, fallback } = this
    this.clearFocusTracking()
    this.opener = null
    this.root = null
    this.fallback = undefined
    if (!root) return
    const active = root.ownerDocument.activeElement
    if (active && active !== root.ownerDocument.body && !root.contains(active))
      return
    const target = available(opener) ? opener : (fallback?.() ?? null)
    if (available(target)) target.focus({ preventScroll: true })
  }

  /**
   * Keep Tab focus within `root`: wrap from the last element to the first and,
   * with Shift, from the first back to the last. From the root itself, Tab takes
   * the first element and Shift+Tab the last. With nothing in `root` left to take
   * focus — no control, or every one `:disabled` — Tab is swallowed and focus
   * stays where it is.
   *
   * @param root The dialog element whose focusables cycle.
   * @param event The Tab keydown event; its default is prevented on a move and
   *   when nothing in `root` takes focus.
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
    if (active === root) {
      event.preventDefault()
      const target = event.shiftKey ? last : first
      target.focus()
    } else if (event.shiftKey && active === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && active === last) {
      event.preventDefault()
      first.focus()
    }
  }

  private owns(element: Element): boolean {
    const root = this.root

    return (
      !!root &&
      root.contains(element) &&
      element.closest(FOCUS_BOUNDARY_SELECTOR) ===
        root.closest(FOCUS_BOUNDARY_SELECTOR)
    )
  }

  private trackFocus(root: HTMLElement): void {
    this.clearFocusTracking()
    const document = root.ownerDocument
    const view = document.defaultView
    const remember = (target: EventTarget | null) => {
      // Native disabled focus fixup may land on body without a focus event,
      // or send one there. Blur/focusout ordering cannot invalidate the snapshot.
      if (target === document.body && this.lastFocused?.matches(':disabled'))
        return
      this.lastFocused =
        view && target instanceof view.HTMLElement && this.owns(target)
          ? target
          : null
    }
    const onFocus = (event: FocusEvent) => remember(event.target)
    document.addEventListener('focusin', onFocus, true)
    this.stopFocusTracking = () => {
      document.removeEventListener('focusin', onFocus, true)
    }
    remember(document.activeElement)
  }

  private clearFocusTracking(): void {
    this.stopFocusTracking?.()
    this.stopFocusTracking = undefined
    this.wasBusy = false
    this.busyTarget = null
    this.lastFocused = null
  }
}
