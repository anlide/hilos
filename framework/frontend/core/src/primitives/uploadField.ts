// The headless upload field: the state the three upload tags (HilosUploadDrop,
// HilosUploadFiles, HilosUploadPicker) draw, over the framework uploads client
// (uploads.md, "The Browser Client"). The client owns the queue, the one-at-a-
// time pace and the resend after a dropped connection; this controller only
// reads its records back for one target — another surface of the page may
// upload too — and turns them into chips, a refusal, a busy flag, the ids of
// the files received, and the two sentences a screen reader hears. The page
// creates it and hands it to the views (multiframework-core.md, "Primitives").
//
// The voice lives here, the region in the view: the drop wrapper is the one
// surface that outlives the chips, so it renders `alert` and `status` in its
// permanent live regions (accessibility.md, "The room belongs to the block,
// the voice to the surface").

import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../state/signal.js'
import {
  cancelUpload,
  hilosUploads,
  uploadFile,
  type HilosUpload,
} from '../uploads/hilosUploads.js'
import {
  UPLOAD_PHASE_COMPLETE,
  UPLOAD_PHASE_FAILED,
  UPLOAD_PHASE_QUEUED,
  UPLOAD_PHASE_READY,
  UPLOAD_PHASE_UPLOADING,
  type HilosUploadPhase,
} from '../uploads/uploadProtocol.js'

/** The approved words of the upload field. */
export const HILOS_UPLOAD_FIELD_COPY = {
  overlay: 'Drop files to upload',
  attach: 'Attach files',
  waiting: 'Waiting',
  uploading: 'Uploading',
  failed: 'Failed',
  remove: 'Remove',
  uploaded: 'uploaded',
} as const

/** Pill of one file; a tone class always goes with it. */
export const HILOS_UPLOAD_FIELD_CHIP_CLASS =
  'badge rounded-pill d-inline-flex align-items-center gap-1 mw-100'

/** Tone of a chip that is waiting, uploading or received. */
export const HILOS_UPLOAD_FIELD_CHIP_CALM_CLASS =
  'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle'

/** Tone of a refused chip. */
export const HILOS_UPLOAD_FIELD_CHIP_FAILED_CLASS =
  'bg-danger-subtle text-danger-emphasis border border-danger-subtle'

/** Added to a chip whose cancel is waiting for the server. */
export const HILOS_UPLOAD_FIELD_CHIP_CANCELING_CLASS = 'opacity-50'

/** Overlay over the whole wrapped area while files are dragged over it. */
export const HILOS_UPLOAD_FIELD_OVERLAY_CLASS =
  'position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center bg-primary-subtle border border-2 border-primary rounded'

const PERCENT = 100

/** Phases of a record still on its way to the server. */
const IN_FLIGHT_PHASES: readonly HilosUploadPhase[] = [
  UPLOAD_PHASE_QUEUED,
  UPLOAD_PHASE_READY,
  UPLOAD_PHASE_UPLOADING,
]

/** One chip of the field, in selection order. */
export interface HilosUploadFieldFile {
  readonly clientUploadId: string
  readonly filename: string
  /** What the chip says: its icon, its tone and its hidden word. */
  readonly reading: 'waiting' | 'uploading' | 'received' | 'failed'
  /** Received share, 0–100. */
  readonly percent: number
  /** True while the cancel sent by × waits for the server. */
  readonly canceling: boolean
}

type HilosUploadFieldReading = HilosUploadFieldFile['reading']

/** The headless upload field the upload tags render. */
export interface HilosUploadField {
  /** Chips of this field's target, in selection order. */
  readonly files: ReadonlySignal<readonly HilosUploadFieldFile[]>
  /** The sentence of the last refused file, or null. */
  readonly refusal: ReadonlySignal<string | null>
  /** True while a file waits or uploads; the project holds its own send on it. */
  readonly busy: ReadonlySignal<boolean>
  /** Ids of the files received and not being removed, in selection order. */
  readonly ready: ReadonlySignal<readonly string[]>
  /** The one switch of the whole field: no overlay, no doors, no ×. */
  readonly disabled: ReadonlySignal<boolean>
  /** True while files are dragged over an enabled field. */
  readonly dragging: ReadonlySignal<boolean>
  /** Assertive sentence of the last refusal, empty when there is none. */
  readonly alert: ReadonlySignal<string>
  /** Polite sentence of the last file received, empty when there is none. */
  readonly status: ReadonlySignal<string>
  /** @param disabled Whether the field takes no files and removes none. */
  setDisabled(disabled: boolean): void
  /** Count a file drag entering the wrapped area; nested elements do not flicker. */
  dragEnter(): void
  /** Count a file drag leaving the wrapped area. */
  dragLeave(): void
  /** @param files Files dropped on the wrapped area. */
  drop(files: Iterable<File>): void
  /** @param files Files taken by any of the three doors: drop, paste or picker. */
  add(files: Iterable<File>): void
  /** @param clientUploadId Upload whose × was pressed. */
  remove(clientUploadId: string): void
  /** Stop listening to the uploads client; its records stay as they are. */
  dispose(): void
}

function readingOf(phase: HilosUploadPhase): HilosUploadFieldReading {
  switch (phase) {
    case UPLOAD_PHASE_UPLOADING:
      return 'uploading'
    case UPLOAD_PHASE_COMPLETE:
      return 'received'
    case UPLOAD_PHASE_FAILED:
      return 'failed'
    default:
      return 'waiting'
  }
}

function percentOf(upload: HilosUpload): number {
  if (upload.declaredSize <= 0) {
    return 0
  }

  return Math.min(
    PERCENT,
    Math.round((upload.receivedBytes / upload.declaredSize) * PERCENT),
  )
}

/**
 * Create the upload field over one target's uploads.
 *
 * @param options The project's upload target the field sends to and shows.
 */
export function createHilosUploadField(options: {
  target: string
}): HilosUploadField {
  const { target } = options
  const disabled = createSignal(false)
  const depth = createSignal(0)
  const alert = createSignal('')
  const status = createSignal('')

  // Only this field's target: another surface of the page may upload too.
  const records = computedSignal(() =>
    hilosUploads.get().filter((upload) => upload.target === target),
  )

  const files = computedSignal(() =>
    records.get().map((upload): HilosUploadFieldFile => ({
      clientUploadId: upload.clientUploadId,
      filename: upload.filename,
      reading: readingOf(upload.phase),
      percent: percentOf(upload),
      canceling: upload.canceling,
    })),
  )

  const refusal = computedSignal(() => {
    const failed = records
      .get()
      .filter((upload) => upload.phase === UPLOAD_PHASE_FAILED)

    return failed[failed.length - 1]?.errorMessage ?? null
  })

  const busy = computedSignal(() =>
    records.get().some((upload) => IN_FLIGHT_PHASES.includes(upload.phase)),
  )

  const ready = computedSignal(() =>
    records
      .get()
      .filter(
        (upload) => upload.phase === UPLOAD_PHASE_COMPLETE && !upload.canceling,
      )
      .map((upload) => upload.clientUploadId),
  )

  const dragging = computedSignal(() => depth.get() > 0 && !disabled.get())

  // The voice speaks of transitions, judged by the record's previous reading.
  // A sentence is taken back once its record no longer reads that way, so the
  // same file is heard again when it comes back to it — after a dropped
  // connection the client sends it anew from zero.
  let readings = new Map<string, HilosUploadFieldReading>()
  let alertId: string | null = null
  let statusId: string | null = null

  function hear(): void {
    const next = new Map<string, HilosUploadFieldReading>()
    let refused: HilosUpload | null = null
    let received: HilosUpload | null = null
    for (const upload of records.get()) {
      const reading = readingOf(upload.phase)
      next.set(upload.clientUploadId, reading)
      if (readings.get(upload.clientUploadId) === reading) {
        continue
      }
      if (reading === 'failed') {
        refused = upload
      } else if (reading === 'received') {
        received = upload
      }
    }
    readings = next

    if (alertId !== null && next.get(alertId) !== 'failed') {
      alertId = null
      alert.set('')
    }
    if (statusId !== null && next.get(statusId) !== 'received') {
      statusId = null
      status.set('')
    }
    if (refused !== null) {
      alertId = refused.clientUploadId
      alert.set(
        `${refused.filename}: ${refused.errorMessage ?? HILOS_UPLOAD_FIELD_COPY.failed}`,
      )
    }
    if (received !== null) {
      statusId = received.clientUploadId
      status.set(`${received.filename} ${HILOS_UPLOAD_FIELD_COPY.uploaded}`)
    }
  }

  // What the field finds already there was said before it existed.
  for (const upload of records.get()) {
    readings.set(upload.clientUploadId, readingOf(upload.phase))
  }
  const stop = subscribeSignal(hilosUploads, hear)

  function add(picked: Iterable<File>): void {
    if (disabled.get()) {
      return
    }
    // An empty file is dropped silently.
    const accepted = Array.from(picked).filter((file) => file.size > 0)
    if (accepted.length === 0) {
      return
    }
    // A new pick clears the previous refusal: its record goes, and its line with it.
    for (const upload of records.get()) {
      if (upload.phase === UPLOAD_PHASE_FAILED) {
        cancelUpload(upload.clientUploadId)
      }
    }
    for (const file of accepted) {
      uploadFile(target, file)
    }
  }

  return {
    files,
    refusal,
    busy,
    ready,
    disabled,
    dragging,
    alert,
    status,
    setDisabled(value) {
      disabled.set(value)
    },
    dragEnter() {
      depth.set(depth.get() + 1)
    },
    dragLeave() {
      depth.set(Math.max(0, depth.get() - 1))
    },
    drop(dropped) {
      depth.set(0)
      add(dropped)
    },
    add,
    remove(clientUploadId) {
      if (disabled.get()) {
        return
      }
      cancelUpload(clientUploadId)
    },
    dispose() {
      stop()
    },
  }
}
