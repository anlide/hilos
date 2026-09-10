// Deliberately broken sample: this file leaves values in the browser and declares
// none of them, so BROWSER-VALUE-DECLARED must report it — once, at the first
// write, however many writes come after.
//
// The writes are spelled four ways on purpose: the bare global, a store held in a
// local variable, the `globalThis` spelling, and a cookie. Three of the four would
// slip past a rule that read receivers rather than the write itself.
//
// This file sits outside every scanned root, so only the fixture test reads it.

/** Remembers which draft this tab was editing. */
export function rememberDraft(draftKey: string): void {
  sessionStorage.setItem('project.draft', draftKey)
}

/** Remembers the theme, through a store held in a variable. */
export function rememberTheme(theme: string): void {
  const storage = globalThis.localStorage
  storage.setItem('project.theme', theme)
}

/** Remembers the language, through the `globalThis` spelling. */
export function rememberLanguage(language: string): void {
  globalThis.localStorage.setItem('project.language', language)
}

/** Leaves a cookie behind. */
export function rememberConsent(): void {
  document.cookie = 'project.consent=1; Path=/'
}
