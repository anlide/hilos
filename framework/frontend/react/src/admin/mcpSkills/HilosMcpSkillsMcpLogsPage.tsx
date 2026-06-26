// HilosMcpSkillsMcpLogsPage — the Mcp Skills Mcp Logs admin page (HilosPages.MCP_SKILLS_MCP_LOGS). A framework default: a
// thin binding of the page key to the shared admin shell HilosAdminPage, which
// resolves the heading, lead, breadcrumb, and any sub-section cards from the
// @hilos/core admin tree. Implement the page by replacing the shell's default
// body through its children. Bootstrap classes only (styling-rules.md).
import { HilosPages } from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'

/** The Mcp Skills Mcp Logs admin page: the framework default shell for its key. */
export function HilosMcpSkillsMcpLogsPage() {
  return <HilosAdminPage page={HilosPages.MCP_SKILLS_MCP_LOGS} />
}
