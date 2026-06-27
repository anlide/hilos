// HilosLogsRotationsPage — the Logs Rotations admin page (HilosPages.LOGS_ROTATIONS). A framework default: a
// thin binding of the page key to the shared admin shell HilosAdminPage, which
// resolves the heading, lead, breadcrumb, and any sub-section cards from the
// @hilos/core admin tree. Implement the page by replacing the shell's default
// body through its children. Bootstrap classes only (styling-rules.md).
import { HilosPages } from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'

/** The Logs Rotations admin page: the framework default shell for its key. */
export function HilosLogsRotationsPage() {
  return <HilosAdminPage page={HilosPages.LOGS_ROTATIONS} />
}
