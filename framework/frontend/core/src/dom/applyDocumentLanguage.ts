/**
 * Name the language the page is shown in on its root element, when a DOM
 * exists. The value is the code of the active language, never of a region.
 *
 * @param code The code of the tab's active language.
 */
export function applyDocumentLanguage(code: string): void {
  if (typeof globalThis.document === 'undefined') {
    return
  }

  globalThis.document.documentElement.setAttribute('lang', code)
}
