import {
  ActionError,
  type ActionLifecycle,
} from '../connection/actionLifecycle.js'
import { type HilosConnection } from '../connection/HilosConnection.js'
import {
  SIGNAL_TYPE_PAGE_RESPONSE,
  SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR,
} from '../protocol/constants.js'
import { type ProjectSignal } from '../protocol/parseSignal.js'
import {
  createSignal,
  type ReadonlySignal,
  subscribeSignal,
} from '../state/signal.js'
import {
  SIGNAL_UPLOAD_STATE,
  UPLOAD_ACTION_CANCEL,
  UPLOAD_ACTION_INIT,
  UPLOAD_BUFFER_HIGH_WATER_BYTES,
  UPLOAD_BUFFER_POLL_MS,
  UPLOAD_CHUNK_BYTES,
  UPLOAD_COPY,
  UPLOAD_ERROR_INTERRUPTED,
  UPLOAD_ERROR_REFUSED,
  UPLOAD_ERROR_TIMEOUT,
  UPLOAD_ERROR_UNREADABLE,
  UPLOAD_PHASE_COMPLETE,
  UPLOAD_PHASE_FAILED,
  UPLOAD_PHASE_QUEUED,
  UPLOAD_PHASE_READY,
  UPLOAD_PHASE_UPLOADING,
  type HilosUploadPhase,
  mintUploadId,
  signUploadChunk,
  uploadStateSchema,
} from './uploadProtocol.js'

/** One upload exposed to project views. */
export interface HilosUpload {
  readonly clientUploadId: string
  readonly target: string
  readonly filename: string
  readonly declaredSize: number
  readonly receivedBytes: number
  readonly phase: HilosUploadPhase
  readonly errorCode: string | null
  readonly errorMessage: string | null
  readonly canceling: boolean
}

interface UploadEntry {
  upload: HilosUpload
  readonly file: File
  announced: boolean
  streamVersion: number
}

const uploadList = createSignal<readonly HilosUpload[]>([])
const entries = new Map<string, UploadEntry>()

/** Uploads in their selection order. */
export const hilosUploads: ReadonlySignal<readonly HilosUpload[]> = uploadList

let boundConnection: HilosConnection | null = null
let boundActions: ActionLifecycle | null = null
let boundRelease: (() => void) | null = null
let connectionGeneration = 0
let connectionReady = false
let queueOwner: number | null | undefined
let activeUploadId: string | null = null

function publish(): void {
  uploadList.set(Array.from(entries.values(), (entry) => entry.upload))
}

function replaceUpload(
  entry: UploadEntry,
  changes: Partial<HilosUpload>,
): void {
  entry.upload = { ...entry.upload, ...changes }
  publish()
}

function removeUpload(clientUploadId: string): void {
  entries.delete(clientUploadId)
  if (activeUploadId === clientUploadId) {
    activeUploadId = null
  }
  publish()
}

function dispatchCancel(
  clientUploadId: string,
  removeOnSuccess: boolean,
): void {
  const actions = boundActions
  if (actions === null) {
    return
  }
  void actions
    .dispatch(UPLOAD_ACTION_CANCEL, { clientUploadId })
    .done.then(() => {
      if (removeOnSuccess && entries.has(clientUploadId)) {
        removeUpload(clientUploadId)
        pumpQueue()
      }
    })
    .catch(() => {
      if (!removeOnSuccess) {
        return
      }
      const entry = entries.get(clientUploadId)
      if (entry === undefined) {
        return
      }
      replaceUpload(entry, { canceling: false })
      if (activeUploadId === clientUploadId) {
        activeUploadId = null
      }
      pumpQueue()
    })
}

function failUnreadable(entry: UploadEntry): void {
  entry.streamVersion += 1
  replaceUpload(entry, {
    phase: UPLOAD_PHASE_FAILED,
    errorCode: UPLOAD_ERROR_UNREADABLE,
    errorMessage: UPLOAD_COPY.unreadable,
    canceling: false,
  })
  dispatchCancel(entry.upload.clientUploadId, false)
  activeUploadId = null
  pumpQueue()
}

function waitForBuffer(): Promise<void> {
  return new Promise((resolve) => {
    setTimeout(resolve, UPLOAD_BUFFER_POLL_MS)
  })
}

async function streamUpload(
  entry: UploadEntry,
  generation: number,
  streamVersion: number,
): Promise<void> {
  const connection = boundConnection
  if (connection === null) {
    return
  }

  for (let offset = 0; offset < entry.file.size; offset += UPLOAD_CHUNK_BYTES) {
    while (connection.bufferedAmount > UPLOAD_BUFFER_HIGH_WATER_BYTES) {
      await waitForBuffer()
      if (!mayStream(entry, generation, streamVersion)) {
        return
      }
    }
    if (!mayStream(entry, generation, streamVersion)) {
      return
    }

    let bytes: ArrayBuffer
    try {
      bytes = await entry.file
        .slice(offset, offset + UPLOAD_CHUNK_BYTES)
        .arrayBuffer()
    } catch {
      if (mayStream(entry, generation, streamVersion)) {
        failUnreadable(entry)
      }

      return
    }
    if (!mayStream(entry, generation, streamVersion)) {
      return
    }
    if (
      !connection.sendBinary(
        signUploadChunk(entry.upload.clientUploadId, bytes),
      )
    ) {
      return
    }
  }

  if (!mayStream(entry, generation, streamVersion)) {
    return
  }
  activeUploadId = null
  pumpQueue()
}

function mayStream(
  entry: UploadEntry,
  generation: number,
  streamVersion: number,
): boolean {
  return (
    entries.get(entry.upload.clientUploadId) === entry &&
    connectionGeneration === generation &&
    entry.streamVersion === streamVersion &&
    !entry.upload.canceling &&
    entry.upload.phase !== UPLOAD_PHASE_FAILED
  )
}

function onInitFailure(entry: UploadEntry, error: unknown): void {
  if (entries.get(entry.upload.clientUploadId) !== entry) {
    return
  }
  activeUploadId = null
  if (error instanceof ActionError && error.outcome === 'disconnected') {
    entry.announced = false
    pumpQueue()

    return
  }

  const timedOut = error instanceof ActionError && error.outcome === 'timeout'
  replaceUpload(entry, {
    phase: UPLOAD_PHASE_FAILED,
    errorCode: timedOut ? UPLOAD_ERROR_TIMEOUT : UPLOAD_ERROR_REFUSED,
    errorMessage: error instanceof Error ? error.message : String(error),
  })
  if (timedOut) {
    dispatchCancel(entry.upload.clientUploadId, false)
  }
  pumpQueue()
}

function pumpQueue(): void {
  if (
    !connectionReady ||
    boundConnection === null ||
    boundActions === null ||
    activeUploadId !== null
  ) {
    return
  }
  const entry = Array.from(entries.values()).find(
    (candidate) =>
      candidate.upload.phase === UPLOAD_PHASE_QUEUED &&
      !candidate.upload.canceling,
  )
  if (entry === undefined) {
    return
  }

  activeUploadId = entry.upload.clientUploadId
  entry.announced = true
  const generation = connectionGeneration
  const streamVersion = entry.streamVersion
  void boundActions
    .dispatch(UPLOAD_ACTION_INIT, {
      target: entry.upload.target,
      clientUploadId: entry.upload.clientUploadId,
      filename: entry.upload.filename,
      mimeType: entry.file.type,
      size: entry.file.size,
    })
    .done.then(() => {
      if (!mayStream(entry, generation, streamVersion)) {
        return
      }
      void streamUpload(entry, generation, streamVersion)
    })
    .catch((error: unknown) => onInitFailure(entry, error))
}

function onConnectionLost(): void {
  connectionReady = false
  connectionGeneration += 1
  activeUploadId = null
  for (const [clientUploadId, entry] of [...entries]) {
    entry.streamVersion += 1
    entry.announced = false
    if (entry.upload.canceling) {
      entries.delete(clientUploadId)
      continue
    }
    if (
      entry.upload.phase === UPLOAD_PHASE_READY ||
      entry.upload.phase === UPLOAD_PHASE_UPLOADING ||
      entry.upload.phase === UPLOAD_PHASE_COMPLETE
    ) {
      entry.upload = {
        ...entry.upload,
        phase: UPLOAD_PHASE_QUEUED,
        receivedBytes: 0,
        errorCode: null,
        errorMessage: null,
      }
    }
  }
  publish()
}

function onConnectionReady(currentUserId: ReadonlySignal<number | null>): void {
  if (queueOwner === undefined) {
    queueOwner = currentUserId.get()
  }
  connectionReady = true
  pumpQueue()
}

function onUploadState(signal: ProjectSignal): void {
  const data = uploadStateSchema.parse(signal.data)
  const entry = entries.get(data.clientUploadId)
  if (entry === undefined) {
    return
  }
  if (data.phase === null) {
    if (
      entry.upload.canceling ||
      entry.upload.phase === UPLOAD_PHASE_COMPLETE ||
      entry.upload.phase === UPLOAD_PHASE_FAILED
    ) {
      removeUpload(data.clientUploadId)
    } else if (
      entry.upload.phase === UPLOAD_PHASE_READY ||
      entry.upload.phase === UPLOAD_PHASE_UPLOADING
    ) {
      entry.streamVersion += 1
      replaceUpload(entry, {
        phase: UPLOAD_PHASE_FAILED,
        errorCode: UPLOAD_ERROR_INTERRUPTED,
        errorMessage: UPLOAD_COPY.interrupted,
      })
      if (activeUploadId === data.clientUploadId) {
        activeUploadId = null
      }
    }
    pumpQueue()

    return
  }

  replaceUpload(entry, {
    phase: data.phase as HilosUploadPhase,
    receivedBytes: data.receivedBytes ?? entry.upload.receivedBytes,
    errorCode: data.errorCode,
    errorMessage: data.errorMessage,
  })
  if (data.phase === UPLOAD_PHASE_FAILED) {
    entry.streamVersion += 1
    if (activeUploadId === data.clientUploadId) {
      activeUploadId = null
    }
    pumpQueue()
  }
}

/**
 * Bind the one browser upload client to the application's connection and action lifecycle.
 *
 * @param connection The application's Hilos connection.
 * @param actions The application's one tracked action lifecycle.
 * @param currentUserId Current session user, or null for a guest.
 * @returns Unbind: removes listeners, forgets the lifecycle, and clears uploads.
 */
export function bindUploads(
  connection: HilosConnection,
  actions: ActionLifecycle,
  currentUserId: ReadonlySignal<number | null>,
): () => void {
  boundConnection = connection
  boundActions = actions
  connectionReady = false
  queueOwner = undefined
  activeUploadId = null
  const stopState = connection.on('state', (state) => {
    if (state === 'reconnecting' || state === 'disconnected') {
      onConnectionLost()
    }
  })
  const stopSignals = connection.on('projectSignal', (signal) => {
    if (
      signal.type === SIGNAL_TYPE_PAGE_RESPONSE ||
      signal.type === SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR
    ) {
      onConnectionReady(currentUserId)
    } else if (signal.type === SIGNAL_UPLOAD_STATE) {
      onUploadState(signal)
    }
  })
  const stopUser = subscribeSignal(currentUserId, (nextUserId) => {
    if (queueOwner === undefined || nextUserId === queueOwner) {
      return
    }
    for (const entry of entries.values()) {
      if (entry.announced) {
        dispatchCancel(entry.upload.clientUploadId, false)
      }
    }
    entries.clear()
    activeUploadId = null
    queueOwner = nextUserId
    publish()
  })
  const release = (): void => {
    stopState()
    stopSignals()
    stopUser()
    if (boundRelease !== release) {
      return
    }
    boundRelease = null
    boundConnection = null
    boundActions = null
    connectionReady = false
    queueOwner = undefined
    activeUploadId = null
    connectionGeneration += 1
    entries.clear()
    publish()
  }
  boundRelease = release

  return release
}

/**
 * Add a file to the upload queue.
 *
 * @param target Project-defined upload target.
 * @param file Browser file to upload.
 * @returns Client-minted upload id.
 * @throws Error When called before {@link bindUploads}.
 */
export function uploadFile(target: string, file: File): string {
  if (boundConnection === null || boundActions === null) {
    throw new Error(
      'uploadFile() before bindUploads(): bootHilos binds the uploads client.',
    )
  }
  const clientUploadId = mintUploadId()
  entries.set(clientUploadId, {
    upload: {
      clientUploadId,
      target,
      filename: file.name,
      declaredSize: file.size,
      receivedBytes: 0,
      phase: UPLOAD_PHASE_QUEUED,
      errorCode: null,
      errorMessage: null,
      canceling: false,
    },
    file,
    announced: false,
    streamVersion: 0,
  })
  publish()
  pumpQueue()

  return clientUploadId
}

/**
 * Cancel or remove one upload.
 *
 * @param clientUploadId Upload to cancel.
 * @throws Error When called before {@link bindUploads}.
 */
export function cancelUpload(clientUploadId: string): void {
  if (boundConnection === null || boundActions === null) {
    throw new Error(
      'cancelUpload() before bindUploads(): bootHilos binds the uploads client.',
    )
  }
  const entry = entries.get(clientUploadId)
  if (entry === undefined) {
    return
  }
  if (entry.upload.phase === UPLOAD_PHASE_QUEUED && !entry.announced) {
    removeUpload(clientUploadId)
    pumpQueue()

    return
  }

  entry.streamVersion += 1
  replaceUpload(entry, { canceling: true })
  dispatchCancel(clientUploadId, true)
}
