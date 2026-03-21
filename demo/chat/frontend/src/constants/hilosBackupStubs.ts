/** Stub backup rows for Hilos backup table (demo only). */
export const hilosBackupStubRows = [
  {
    id: 'bkp-001',
    name: 'daily-2025-03-21',
    createdAt: '2025-03-21T02:00:00Z',
    sizeLabel: '128 MB',
    status: 'complete',
  },
  {
    id: 'bkp-002',
    name: 'manual-before-migration',
    createdAt: '2025-03-20T14:32:00Z',
    sizeLabel: '126 MB',
    status: 'complete',
  },
  {
    id: 'bkp-003',
    name: 'weekly-2025-03-16',
    createdAt: '2025-03-16T03:15:00Z',
    sizeLabel: '480 MB',
    status: 'complete',
  },
] as const
