// Framework wire definitions shared by eager connections and lazy admin models.
import { z } from 'zod'
import { hideable } from '../state/hideableSchema.js'

/** Server→client state frame sent before the Maintenance page response and on change. */
export const MAINTENANCE_STATE_SIGNAL = 'subscription_page_hilos_maintenance'

/**
 * The whole state frame (PHP `HilosMaintenanceStateSignalData`).
 * `startedAt`, `activatedAt` and `progressAt` are ISO-8601 UTC moments ending in Z,
 * or null when the row has no such moment. A frame from before those keys existed
 * still parses: the new fields default to null, and `isInitiator` to false.
 */
export const maintenanceStateSchema = z.looseObject({
  phase: z.enum([
    'inactive',
    'activating',
    'active',
    'verifying',
    'deactivating',
  ]),
  operation: z.string().nullable(),
  manual: z.boolean(),
  startedAt: z.string().nullable(),
  enteredFromBrowser: z.boolean(),
  passCount: z.number().int(),
  circleNamed: z.number().int(),
  circleAdmitted: z.number().int(),
  initiatorAgentType: z.string().nullable().default(null),
  initiatorNodeId: z.string().nullable().default(null),
  activatedAt: z.string().nullable().default(null),
  progressAt: z.string().nullable().default(null),
  isInitiator: z.boolean().default(false),
  diagnosticsNodeId: z.string().nullable().default(null),
  stoppedAgentCount: z.number().int().nullable().default(null),
  preservedAgentTypes: z.array(z.string()).nullable().default(null),
  agentStartGateClosed: z.boolean().nullable().default(null),
  watchdogVerdict: z
    .enum(['initiator-lost', 'restored-from-disk', 'quiesce-overdue', 'silent'])
    .nullable()
    .default(null),
  watchdogPendingNodeIds: z.array(z.string()).default([]),
  watchdogQuiesceTimeoutSeconds: z.number().int().nullable().default(null),
  watchdogSilenceTimeoutSeconds: z.number().int().nullable().default(null),
  watchdogAlertIntervalSeconds: z.number().int().nullable().default(null),
  watchdogAlertEmails: hideable(z.array(z.string()).nullable()).default(null),
  watchdogManualRestartIsNormal: hideable(z.boolean().nullable()).default(null),
})

/** One state frame of the Maintenance section. */
export type HilosMaintenanceState = z.infer<typeof maintenanceStateSchema>

/** Schema registration for the section's own live frame. */
export const MAINTENANCE_STATE_SIGNAL_SCHEMAS = {
  [MAINTENANCE_STATE_SIGNAL]: maintenanceStateSchema,
}
