/**
 * Base types for Hilos framework frontend
 * These types can be extended in demo projects
 */

// DomainObject base class
export { DomainObject } from './DomainObject'

// Re-export websocket options
export type { WebSocketOptions } from './websocket'

// Re-export WebSocket message types
export * from './websocket-messages'

// Entities transport (full/updates/deleted envelope)
export type { EntitiesEnvelope } from './entities'
export { extractEntitiesEnvelope, hasEntities } from './entities'

// Explicit frontend state transport (full/updates/deleted envelope)
export type { FrontendChangesEnvelope } from './frontendState'
export { extractFrontendChangesEnvelope, hasFrontendChanges } from './frontendState'

// Table types
export type { TableRowMutationDTO, TableDataState, PendingChanges, ChangeMarkers, ApplyMutationsResult } from './table'

// Page catalog
export type { PageCatalogEntry, PageCatalogState } from './pageCatalog'
export { pathTemplateParamKeys, normalizePageCatalog } from './pageCatalog'

// Hilos logs overview
export type { HilosLogsOverviewSnapshot } from './hilosLogsOverview'
export { parseHilosLogsOverviewPayload } from './hilosLogsOverview'

// Guardian agent runs
export type { GuardianRunStatus, GuardianAgentStatusMap } from './guardianAgentRuns'
export {
  guardianRunStatuses,
  isGuardianRunStatus,
  parseGuardianAgentStatusesSnapshot,
  parseGuardianAgentStatusUpdate,
} from './guardianAgentRuns'
