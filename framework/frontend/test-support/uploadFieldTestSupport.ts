// Test-only upload fixtures and lifecycle boundary. The three scenario modules
// own the steps and assertions; each SDK entry mounts its real component.
import {
  createSignal,
  type HilosUploadField,
  type HilosUploadFieldFile,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'

/** The local Vitest that registers and observes one SDK's upload cases. */
export type UploadTestRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect' | 'vi'
>

/** One real upload component, with lifecycle operations owned by its SDK. */
export interface UploadView {
  /** The component's own host, including every node it renders. */
  readonly root: ParentNode
  /** Apply a field change or DOM event through the framework's lifecycle. */
  update(change: () => void): Promise<void>
  /** Finish rendering the last change. */
  flush(): Promise<void>
  /** Destroy the component and remove its host. */
  unmount(): Promise<void>
}

/** Mount one SDK's real upload component with the page-owned field. */
export type MountUpload = (field: HilosUploadField) => Promise<UploadView>

/**
 * Create isolated signals and spies for the field the mounting page owns.
 *
 * @param runner The registering SDK's Vitest.
 * @returns The field and its writable signals.
 */
export function uploadFieldDouble(runner: UploadTestRunner) {
  const files = createSignal<readonly HilosUploadFieldFile[]>([])
  const refusal = createSignal<string | null>(null)
  const disabled = createSignal(false)
  const dragging = createSignal(false)
  const alert = createSignal('')
  const status = createSignal('')
  const field: HilosUploadField = {
    files,
    refusal,
    disabled,
    dragging,
    alert,
    status,
    busy: createSignal(false),
    ready: createSignal<readonly string[]>([]),
    setDisabled: runner.vi.fn(),
    dragEnter: runner.vi.fn(),
    dragLeave: runner.vi.fn(),
    drop: runner.vi.fn(),
    add: runner.vi.fn(),
    remove: runner.vi.fn(),
    dispose: runner.vi.fn(),
  }
  return { field, files, refusal, disabled, dragging, alert, status }
}

/**
 * Run a scenario and release its component even if a step or assertion fails.
 *
 * @param mount The SDK's component mount.
 * @param field The field the case drives.
 * @param steps The scenario's steps and assertions.
 */
export async function withUploadView(
  mount: MountUpload,
  field: HilosUploadField,
  steps: (view: UploadView) => Promise<void>,
): Promise<void> {
  const view = await mount(field)
  try {
    await view.flush()
    await steps(view)
  } finally {
    await view.unmount()
  }
}
