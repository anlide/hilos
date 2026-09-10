// The declaring form for a value this frontend leaves in the visitor's browser,
// and nothing else — no list, no sweep, no import of its own. Kept alone in this
// file so the module that WRITES a value can declare it beside the write without
// pulling the collected list in behind it, which would be a cycle
// (browser/browserValues.ts imports every declaring module).
//
// Why a declaration at all: /privacy offers to erase everything this site keeps
// here, and a list of keys typed out somewhere else is wrong the first time
// anybody adds one, and wrong silently. Declaring beside the write is the only
// layout in which the person adding a key SEES the declaration they owe; the
// BROWSER-VALUE-DECLARED guard is what turns "sees" into "cannot forget", and it
// matches this call by name, the way WIRE-KEY-CASE matches its own declaring form.

/** What a key that the deployment names, rather than the framework, is built from. */
export interface HilosBrowserValueContext {
  /** The session cookie name the welcome frame named, or undefined before a welcome. */
  sessionCookieName: string | undefined
}

/** One value this frontend keeps in the browser, declared where it is written. */
export interface HilosBrowserValue {
  /** Which of the browser's three stores holds it. */
  store: 'session' | 'local' | 'cookie'
  /** The key, or how to build it when the deployment names it. */
  key: string | ((context: HilosBrowserValueContext) => string | undefined)
  /** One English line naming this value to a person, shown in the confirmation. */
  label: string
}

/**
 * Declare a value this module puts in the browser.
 *
 * Returns its argument unchanged: the call exists to BE the declaring form the
 * guard reads, not to do anything at runtime. A declaration written any other way
 * is invisible to the guard, which is the trade this shape makes on purpose.
 *
 * @param value Where the value lives, its key, and the line a person is shown.
 * @returns The same declaration, so it can be exported straight from the call.
 */
export function browserValue(value: HilosBrowserValue): HilosBrowserValue {
  return value
}
