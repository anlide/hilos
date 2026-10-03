// A browser-only copy of the departing page. The three view layers use the
// same plain-DOM operation, keeping framework scheduling out of the snapshot.
// Interactive state and inner scroll positions need explicit copying because
// cloneNode copies markup, not the values a visitor currently sees.

// Angular renders its modal in the page slot; Vue and React move theirs to body.
const MODAL_LAYER_SELECTOR = '.hilos-modal-layer'

/**
 * Copy the visible elements of a page slot into its departure container.
 *
 * Remove data-id so e2e locators still see only the live page, id so an
 * aria-labelledby reference (including the auth heading) cannot resolve to
 * the copy, and name so unformed radio groups cannot join the live group.
 *
 * @param source The slot whose element children are currently on screen.
 * @param target The inert container that holds the departing page.
 */
export function copyPageInto(source: Element, target: Element): void {
  const clones: Element[] = []
  const scrolled: Array<{ clone: Element; top: number; left: number }> = []

  for (const original of source.children) {
    const clone = original.cloneNode(true) as Element
    const originalWalker = source.ownerDocument.createTreeWalker(
      original,
      NodeFilter.SHOW_ELEMENT,
    )
    const cloneWalker = source.ownerDocument.createTreeWalker(
      clone,
      NodeFilter.SHOW_ELEMENT,
    )

    // Walk the intact trees together before removing modal layers or attrs.
    let originalElement: Element | null = original
    let cloneElement: Element | null = clone
    while (originalElement !== null && cloneElement !== null) {
      if (
        originalElement instanceof HTMLInputElement &&
        cloneElement instanceof HTMLInputElement
      ) {
        // A file input rejects every nonempty value assignment. Its FileList
        // setter preserves the selected filename without interrupting navigation.
        if (originalElement.type === 'file') {
          cloneElement.files = originalElement.files
        } else {
          cloneElement.value = originalElement.value
        }
        cloneElement.checked = originalElement.checked
        cloneElement.indeterminate = originalElement.indeterminate
      } else if (
        originalElement instanceof HTMLTextAreaElement &&
        cloneElement instanceof HTMLTextAreaElement
      ) {
        cloneElement.value = originalElement.value
      } else if (
        originalElement instanceof HTMLOptionElement &&
        cloneElement instanceof HTMLOptionElement
      ) {
        cloneElement.selected = originalElement.selected
      } else if (
        originalElement instanceof HTMLCanvasElement &&
        cloneElement instanceof HTMLCanvasElement
      ) {
        cloneElement.getContext('2d')?.drawImage(originalElement, 0, 0)
      }

      if (originalElement.scrollTop !== 0 || originalElement.scrollLeft !== 0) {
        scrolled.push({
          clone: cloneElement,
          top: originalElement.scrollTop,
          left: originalElement.scrollLeft,
        })
      }

      originalElement = originalWalker.nextNode() as Element | null
      cloneElement = cloneWalker.nextNode() as Element | null
    }

    if (!clone.matches(MODAL_LAYER_SELECTOR)) {
      clone
        .querySelectorAll(MODAL_LAYER_SELECTOR)
        .forEach((layer) => layer.remove())
      for (const element of [clone, ...clone.querySelectorAll('*')]) {
        element.removeAttribute('id')
        element.removeAttribute('data-id')
        element.removeAttribute('name')
      }
      clones.push(clone)
    }
  }

  target.replaceChildren(...clones)
  for (const { clone, top, left } of scrolled) {
    if (clone.isConnected) {
      clone.scrollTop = top
      clone.scrollLeft = left
    }
  }
}
