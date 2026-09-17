// The injection key carrying the id of the page heading. The admin shell mints the
// id on its h1 and provides it; a table that declares no title of its own injects
// it and takes its accessible name from that heading, which already names it — the
// one table on a framework admin page (mockups/components/table section 7, "two
// names over one table"). It lives in the view layer rather than in the core, an id
// of an element being nothing the core has a reader for.
import type { InjectionKey } from 'vue'

/** Provide/inject key for the id the page heading carries. */
export const hilosPageHeadingIdKey: InjectionKey<string> =
  Symbol('hilosPageHeadingId')
