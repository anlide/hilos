/** Stub rows for Hilos MCP and Skills dashboard table (demo only). */
export type McpSkillsStubRow = {
  mcpId: string
  mcpName: string
  skills: string[]
}

export const hilosMcpSkillsDashboardStub: McpSkillsStubRow[] = [
  { mcpId: '1', mcpName: 'filesystem', skills: ['read_file', 'list_dir'] },
  { mcpId: '2', mcpName: 'git', skills: ['status', 'diff'] },
  { mcpId: '3', mcpName: 'web_fetch', skills: ['fetch_url'] },
]
