// React context carrying the id of the page heading. The admin shell
// (HilosAdminPage) mints the id on its h1 and provides it; a table that declares
// no title of its own reads it and takes its accessible name from that heading,
// which already names it — the one table on a framework admin page
// (mockups/components/table section 7, "two names over one table"). It lives in
// the view layer rather than in the core, an id of an element being nothing the
// core has a reader for.
import { createContext } from 'react'

/**
 * Provides the id the page heading carries; undefined outside an admin page, where
 * there is no heading to take a name from.
 */
export const HilosPageHeadingIdContext = createContext<string | undefined>(
  undefined,
)
