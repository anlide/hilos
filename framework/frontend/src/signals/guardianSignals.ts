import { SignalDefinition } from '../services/signals'
import {
  parseGuardianAgentStatusUpdate,
  parseGuardianAgentStatusesSnapshot,
  type GuardianAgentStatusMap,
  type GuardianRunStatus,
} from '../types/guardianAgentRuns'

/**
 * Signal: `guardian_agent_status_update` — status change for a single agent.
 */
export interface GuardianAgentStatusUpdatePayload {
  agentId: string
  status: GuardianRunStatus
}

export const guardianAgentStatusUpdate = new SignalDefinition<
  'guardian_agent_status_update',
  GuardianAgentStatusUpdatePayload
>('guardian_agent_status_update', parseGuardianAgentStatusUpdate)

/**
 * Signal: `subscription_page_hilos_guardian` — full snapshot of all agent statuses.
 *
 * Payload is {@link GuardianAgentStatusMap} mapping agentId -> status. Same
 * shape is sent on `subscription_page_hilos_guardian_agent` (see below).
 */
export const subscriptionPageHilosGuardian = new SignalDefinition<
  'subscription_page_hilos_guardian',
  GuardianAgentStatusMap
>('subscription_page_hilos_guardian', parseGuardianAgentStatusesSnapshot)

export const subscriptionPageHilosGuardianAgent = new SignalDefinition<
  'subscription_page_hilos_guardian_agent',
  GuardianAgentStatusMap
>('subscription_page_hilos_guardian_agent', parseGuardianAgentStatusesSnapshot)
