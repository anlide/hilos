import type {
  ConnectionInteractionState,
  HilosConnection,
  HilosRouter,
} from '@hilos/core/base'
import type { Mock } from 'vitest'

/** Raw inputs for the permanent page and its connection source lifetime. */
export interface LayoutConnectionInput {
  connection: HilosConnection
  allow?: boolean
  disabled?: boolean
  description?: string
  modalOpen?: boolean
  router?: HilosRouter
}

/** Native SDK context readers, without writable signals or derived policy flags. */
export type LayoutConnectionReader = Pick<
  ConnectionInteractionState,
  'unavailable' | 'blocked'
>

/** A real consumer mounted outside Layout through its native SDK lifecycle. */
export interface LayoutConnectionStandalone {
  readonly interaction: LayoutConnectionReader
  unmount(): Promise<void>
}

/** Native DOM, click observations and the real shell context consumers. */
export interface LayoutConnectionView {
  readonly root: HTMLElement
  readonly clicks: Mock
  readonly interaction: LayoutConnectionReader
  readonly consumers: Readonly<
    Record<'page' | 'shell' | 'modal', LayoutConnectionReader>
  >
  update(patch: Partial<Omit<LayoutConnectionInput, 'router'>>): Promise<void>
  flush(run?: () => void): Promise<void>
  unmount(): Promise<void>
}
