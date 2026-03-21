/** Stub daemon metrics and lists (demo only). */
export const hilosDaemonStub = {
  startedAt: '2025-03-22T08:15:42Z',
  buildVersion: '1.0.0-stub+build.42',
  nonExclusiveWorkerCount: 3,
  nonExclusiveAgentCount: 12,
  exclusiveWorkerCount: 1,
  exclusiveAgentCount: 2,
  lastBackupAt: '2025-03-22T02:00:00Z',
  cronEntryCount: 8,
  websocketConnectionCount: 17,
  httpServers: [
    { serverId: 'main', label: 'Main API', requestsLastHour: 1240 },
    { serverId: 'admin', label: 'Admin UI', requestsLastHour: 88 },
    { serverId: 'ws', label: 'WebSocket upgrade', requestsLastHour: 320 },
  ],
  clusterStateNote: 'Cluster coordination is not implemented in this build.',
} as const

export const hilosDaemonWorkersStub = [
  { workerId: 'w-1', exclusive: false, agentCount: 4, label: 'General pool' },
  { workerId: 'w-2', exclusive: false, agentCount: 5, label: 'General pool' },
  { workerId: 'w-3', exclusive: false, agentCount: 3, label: 'General pool' },
  { workerId: 'w-mono-1', exclusive: true, agentCount: 2, label: 'Dedicated' },
] as const

export const hilosDaemonAgentsStub = [
  { agentId: 'agent-1', workerId: 'w-1', name: 'ChatAgent' },
  { agentId: 'agent-2', workerId: 'w-1', name: 'ModeratorAgent' },
  { agentId: 'agent-3', workerId: 'w-2', name: 'HilosIndexAgent' },
  { agentId: 'agent-4', workerId: 'w-mono-1', name: 'HeavyJobAgent' },
] as const

export const hilosDaemonCronStub = [
  { id: 'cron-1', schedule: '0 * * * *', handler: 'cleanup_sessions' },
  { id: 'cron-2', schedule: '15 2 * * *', handler: 'backup_database' },
] as const

export const hilosDaemonWebsocketsStub = [
  { connectionId: 'conn-a1', userId: 1, remoteAddr: '192.168.0.10' },
  { connectionId: 'conn-b2', userId: 2, remoteAddr: '192.168.0.11' },
] as const
