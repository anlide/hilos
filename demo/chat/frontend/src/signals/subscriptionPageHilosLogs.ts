import { SignalDefinition } from '@hilos/sdk/services/signals'
import {
  parseHilosLogsOverviewPayload,
  type HilosLogsOverviewSnapshot,
} from '@/types/hilosLogsOverview'

/**
 * `subscription_page_hilos_logs` — Hilos logs overview snapshot pushed on
 * subscribe and on any rotation-affecting event.
 */
export const subscriptionPageHilosLogs = new SignalDefinition<
  'subscription_page_hilos_logs',
  HilosLogsOverviewSnapshot
>('subscription_page_hilos_logs', parseHilosLogsOverviewPayload)
