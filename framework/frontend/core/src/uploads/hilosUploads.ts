import {
  ActionError,
  type ActionLifecycle,
} from '../connection/actionLifecycle.js'
import {
  isCurrentProjectSignal,
  isProjectSignalFromCurrentSocket,
  type HilosConnection,
} from '../connection/HilosConnection.js'
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
  SIGNAL_UPLOAD_CREDIT,
  SIGNAL_UPLOAD_CREDIT_REQUEST,
  uploadCreditSchema,
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
  cancelRequested: boolean
  streamVersion: number
  sentBytes: number
  allowedUpTo: number
  creditRequested: boolean
  readonly creditWaiters: Set<() => void>
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
let queueOwner: number | null = null
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

function removeUpload(entry: UploadEntry): void {
  const { clientUploadId } = entry.upload
  resetCredit(entry)
  entries.delete(clientUploadId)
  if (activeUploadId === clientUploadId) {
    activeUploadId = null
  }
}

function dispatchCancel(entry: UploadEntry, removeOnSuccess: boolean): void {
  const actions = boundActions
  if (actions === null) {
    return
  }
  entry.cancelRequested = true
  const { clientUploadId } = entry.upload
  if (removeOnSuccess) {
    replaceUpload(entry, { canceling: true })
  }
  void actions
    .dispatch(UPLOAD_ACTION_CANCEL, { clientUploadId })
    .done.then(() => {
      if (removeOnSuccess && entries.get(clientUploadId) === entry) {
        removeUpload(entry)
        publish()
        pumpQueue()
      }
    })
    .catch(() => {
      if (!removeOnSuccess || entries.get(clientUploadId) !== entry) {
        return
      }
      replaceUpload(entry, { canceling: false })
      if (activeUploadId === clientUploadId) {
        activeUploadId = null
      }
      pumpQueue()
    })
}

function requestCleanup(entry: UploadEntry): void {
  if (entry.announced && !entry.cancelRequested) {
    dispatchCancel(entry, false)
  }
}

function failUnreadable(entry: UploadEntry): void {
  entry.streamVersion += 1
  resetCredit(entry)
  replaceUpload(entry, {
    phase: UPLOAD_PHASE_FAILED,
    errorCode: UPLOAD_ERROR_UNREADABLE,
    errorMessage: UPLOAD_COPY.unreadable,
    canceling: false,
  })
  requestCleanup(entry)
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

  while (entry.sentBytes < entry.file.size) {
    while (entry.sentBytes >= entry.allowedUpTo) {
      await new Promise<void>((resolve) => entry.creditWaiters.add(resolve))
      if (!mayStream(entry, generation, streamVersion)) {
        return
      }
    }
    while (connection.bufferedAmount > UPLOAD_BUFFER_HIGH_WATER_BYTES) {
      await waitForBuffer()
      if (!mayStream(entry, generation, streamVersion)) {
        return
      }
    }
    if (!mayStream(entry, generation, streamVersion)) {
      return
    }

    const byteCount = Math.min(
      UPLOAD_CHUNK_BYTES,
      entry.file.size - entry.sentBytes,
      entry.allowedUpTo - entry.sentBytes,
    )
    let bytes: ArrayBuffer
    try {
      bytes = await entry.file
        .slice(entry.sentBytes, entry.sentBytes + byteCount)
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
    entry.sentBytes += byteCount
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
    requestCleanup(entry)
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
  entry.cancelRequested = false
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
      if (entry.file.size > 0) {
        entry.creditRequested = true
        boundConnection?.send(
          JSON.stringify({
            type: SIGNAL_UPLOAD_CREDIT_REQUEST,
            clientUploadId: entry.upload.clientUploadId,
            bytes: entry.file.size,
          }),
        )
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
    resetCredit(entry)
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

function onConnectionReady(): void {
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
      removeUpload(entry)
      publish()
    } else if (
      entry.upload.phase === UPLOAD_PHASE_READY ||
      entry.upload.phase === UPLOAD_PHASE_UPLOADING
    ) {
      entry.streamVersion += 1
      resetCredit(entry)
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
    resetCredit(entry)
    if (activeUploadId === data.clientUploadId) {
      activeUploadId = null
    }
    pumpQueue()
  }
}

function wakeCredit(entry: UploadEntry): void {
  for (const resolve of entry.creditWaiters) {
    resolve()
  }
  entry.creditWaiters.clear()
}

function resetCredit(entry: UploadEntry): void {
  entry.sentBytes = 0
  entry.allowedUpTo = 0
  entry.creditRequested = false
  wakeCredit(entry)
}

function onUploadCredit(signal: ProjectSignal): void {
  const data = uploadCreditSchema.parse(signal.data)
  const entry = entries.get(data.clientUploadId)
  if (entry === undefined || !entry.creditRequested || entry.upload.canceling) {
    return
  }
  entry.allowedUpTo = Math.max(entry.allowedUpTo, data.sendUpToBytes)
  wakeCredit(entry)
}

/**
 * Bind the one browser upload client to the application's connection and action lifecycle.
 *
 * A guest signing in keeps the queue and every declared upload: on publication they become
 * the files of the person signed in. Signing out, or one person replacing another, cancels
 * the declared uploads and clears the queue.
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
  queueOwner = currentUserId.get()
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
      if (
        isCurrentProjectSignal(signal) &&
        isProjectSignalFromCurrentSocket(signal)
      ) {
        onConnectionReady()
      }
    } else if (signal.type === SIGNAL_UPLOAD_STATE) {
      onUploadState(signal)
    } else if (signal.type === SIGNAL_UPLOAD_CREDIT) {
      onUploadCredit(signal)
    }
  })
  const stopUser = subscribeSignal(currentUserId, (nextUserId) => {
    if (nextUserId === queueOwner) {
      return
    }
    const previousOwner = queueOwner
    queueOwner = nextUserId
    if (previousOwner !== null) {
      discardUploads(entries.keys())
    }
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
    queueOwner = null
    activeUploadId = null
    connectionGeneration += 1
    for (const entry of entries.values()) {
      resetCredit(entry)
    }
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
    cancelRequested: false,
    streamVersion: 0,
    sentBytes: 0,
    allowedUpTo: 0,
    creditRequested: false,
    creditWaiters: new Set(),
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
    removeUpload(entry)
    publish()
    pumpQueue()

    return
  }

  entry.streamVersion += 1
  resetCredit(entry)
  dispatchCancel(entry, true)
}

/**
 * Drop an abandoned selection from the local queue before starting the next upload.
 * Request server cleanup once per declaration.
 *
 * @param clientUploadIds Uploads owned by the abandoned selection.
 */
export function discardUploads(clientUploadIds: Iterable<string>): void {
  const removed: UploadEntry[] = []
  for (const clientUploadId of clientUploadIds) {
    const entry = entries.get(clientUploadId)
    if (entry !== undefined) {
      removeUpload(entry)
      removed.push(entry)
    }
  }
  if (removed.length === 0) {
    return
  }
  for (const entry of removed) {
    requestCleanup(entry)
  }
  publish()
  pumpQueue()
}
