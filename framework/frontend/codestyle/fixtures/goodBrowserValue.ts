// Negative sample: this file writes to the browser and declares what it writes,
// so BROWSER-VALUE-DECLARED stays silent. One declaration is enough for the file —
// the rule is file-level, which is the granularity of "declared beside the write".
//
// The look-alikes are here too: reading a store, removing from one, and reading a
// cookie. None of the three leaves a value in the browser, and a file doing only
// those owes no declaration.
//
// The declaring call is spelled out locally rather than imported from the core:
// the rule reads the CALL, by name, and a fixture that proves so is a truer test
// than one that proves the import resolves. This file sits outside every scanned
// root, so only the fixture test reads it.

/** Stands in for `browserValue` from @hilos/core; only the call's name is read. */
function browserValue(value: {
  store: string
  key: string
  label: string
}): typeof value {
  return value
}

/** The key this file writes, declared beside the write. */
export const PROJECT_DRAFT_BROWSER_VALUE = browserValue({
  store: 'session',
  key: 'project.draft',
  label: 'The draft this tab was editing',
})

/** Remembers which draft this tab was editing. */
export function rememberDraft(draftKey: string): void {
  sessionStorage.setItem('project.draft', draftKey)
}

/** Reads it back; a read leaves nothing behind. */
export function readDraft(): string | null {
  return sessionStorage.getItem('project.draft')
}

/** Drops it; a removal leaves nothing behind either. */
export function forgetDraft(): void {
  sessionStorage.removeItem('project.draft')
}

/** Reads a cookie; only writing one is a write. */
export function readCookies(): string {
  return document.cookie
}
