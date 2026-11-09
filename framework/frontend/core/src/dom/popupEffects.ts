// Shared popup effects (browser-only, plain DOM, no framework). Views bind them
// around the DOM-free picker so dismissal, scrolling and focus follow one rule
// across frameworks (multiframework-core.md).

/**
 * Dismiss a popup when a pointer press or focus moves outside its root.
 *
 * @param root The field and popup's shared root in their owning document.
 * @param onDismiss The callback for an interaction outside that root.
 * @returns A function that removes both document listeners.
 */
export function bindPopupDismiss(
  root: HTMLElement,
  onDismiss: () => void,
): () => void {
  const document = root.ownerDocument
  const NodeType = document.defaultView?.Node ?? Node
  const onOutside = (event: Event): void => {
    if (event.target instanceof NodeType && root.contains(event.target)) return
    onDismiss()
  }

  document.addEventListener('pointerdown', onOutside, true)
  document.addEventListener('focusin', onOutside)

  return () => {
    document.removeEventListener('pointerdown', onOutside, true)
    document.removeEventListener('focusin', onOutside)
  }
}

/**
 * Reveal a picker panel and focus its active day or first time part.
 * Focus the dialog itself when its time controls are disabled.
 *
 * @param panel The mounted calendar or time panel to reveal.
 */
export function revealPickerPanel(panel: HTMLElement): void {
  panel.scrollIntoView({ block: 'nearest' })
  const target =
    panel.querySelector<HTMLElement>('[role="gridcell"][tabindex="0"]') ??
    panel.querySelector<HTMLInputElement>('input[role="spinbutton"]')
  const available = target?.matches(':disabled') ? panel : target
  available?.focus({ preventScroll: true })
}
