/**
 * Read an element's text while excluding its aria-hidden descendants.
 *
 * @param element The source element, which remains unchanged.
 * @param options Whether to trim the text; defaults to true.
 * @returns Text in DOM order, with whitespace otherwise preserved.
 */
export function readTextWithoutAriaHidden(
  element: Element,
  options?: { trim?: boolean },
): string {
  const clone = element.cloneNode(true) as Element
  for (const hidden of clone.querySelectorAll('[aria-hidden="true"]')) {
    hidden.remove()
  }
  const text = clone.textContent ?? ''
  return options?.trim === false ? text : text.trim()
}
