import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  ActionError,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
} from '../../src/connection/actionLifecycle.js'
import {
  type ConnectionState,
  type HilosConnection,
} from '../../src/connection/HilosConnection.js'
import { type ProjectSignal } from '../../src/protocol/parseSignal.js'
import { createSignal } from '../../src/state/signal.js'
import {
  bindUploads,
  cancelUpload,
  hilosUploads,
  uploadFile,
} from '../../src/uploads/hilosUploads.js'
import {
  SIGNAL_UPLOAD_STATE,
  UPLOAD_ACTION_CANCEL,
  UPLOAD_ACTION_INIT,
  UPLOAD_BUFFER_POLL_MS,
  UPLOAD_CHUNK_BYTES,
  UPLOAD_ERROR_INTERRUPTED,
  UPLOAD_ERROR_REFUSED,
  UPLOAD_ERROR_TIMEOUT,
  UPLOAD_ERROR_UNREADABLE,
  UPLOAD_PHASE_COMPLETE,
  UPLOAD_PHASE_FAILED,
  UPLOAD_PHASE_QUEUED,
  UPLOAD_PHASE_READY,
  UPLOAD_PHASE_UPLOADING,
} from '../../src/uploads/uploadProtocol.js'

interface PendingAction {
  readonly action: string
  readonly data: unknown
  readonly resolve: () => void
  readonly reject: (error: unknown) => void
}

class FakeActions {
  readonly pending: PendingAction[] = []

  dispatch(action: string, data: unknown): ActionHandle {
    let resolve!: () => void
    let reject!: (error: unknown) => void
    const done = new Promise<ActionResult>((resolvePromise, rejectPromise) => {
      resolve = () => resolvePromise({})
      reject = rejectPromise
    })
    this.pending.push({ action, data, resolve, reject })

    return {
      requestId: String(this.pending.length),
      loading: createSignal(false),
      done,
    }
  }
}

class FakeConnection {
  bufferedAmount = 0
  binaryAllowed = true
  readonly binaryFrames: ArrayBuffer[] = []
  private readonly stateListeners: Array<(state: ConnectionState) => void> = []
  private readonly projectListeners: Array<(signal: ProjectSignal) => void> = []

  on(
    event: string,
    listener: (payload: ConnectionState | ProjectSignal) => void,
  ): () => void {
    const listeners =
      event === 'state' ? this.stateListeners : this.projectListeners
    listeners.push(listener as never)

    return () => {
      const index = listeners.indexOf(listener as never)
      if (index >= 0) {
        listeners.splice(index, 1)
      }
    }
  }

  sendBinary(frame: ArrayBuffer): boolean {
    if (!this.binaryAllowed) {
      return false
    }
    this.binaryFrames.push(frame)

    return true
  }

  state(state: ConnectionState): void {
    for (const listener of [...this.stateListeners]) {
      listener(state)
    }
  }

  signal(type: string, data: unknown = {}): void {
    const signal = {
      kind: 'project',
      type,
      data,
      envelope: {},
    } as unknown as ProjectSignal
    for (const listener of [...this.projectListeners]) {
      listener(signal)
    }
  }
}

let release: (() => void) | null = null

afterEach(() => {
  release?.()
  release = null
  vi.useRealTimers()
})

function setup(userId: number | null = 1) {
  const connection = new FakeConnection()
  const actions = new FakeActions()
  const currentUserId = createSignal<number | null>(userId)
  release = bindUploads(
    connection as unknown as HilosConnection,
    actions as unknown as ActionLifecycle,
    currentUserId,
  )

  return { connection, actions, currentUserId }
}

function ready(connection: FakeConnection): void {
  connection.signal('page_response')
}

async function settle(): Promise<void> {
  await Promise.resolve()
  await Promise.resolve()
}

function file(name: string, size: number, type = 'text/plain'): File {
  return new File([new Uint8Array(size)], name, { type })
}

function frameBytes(frame: ArrayBuffer): Uint8Array {
  const bytes = new Uint8Array(frame)

  return bytes.slice(1 + (bytes[0] ?? 0))
}

describe('upload binding', () => {
  it('refuses project calls before bootHilos binds the client', () => {
    expect(() => uploadFile('message', file('a.txt', 1))).toThrow(
      'uploadFile() before bindUploads()',
    )
    expect(() => cancelUpload('missing')).toThrow(
      'cancelUpload() before bindUploads()',
    )
  })

  it('holds the queue until a page answer makes the connection ready', () => {
    const { connection, actions } = setup()
    const clientUploadId = uploadFile('message', file('a.txt', 3, 'text/plain'))

    expect(hilosUploads.get()).toMatchObject([
      { clientUploadId, phase: UPLOAD_PHASE_QUEUED },
    ])
    expect(actions.pending).toEqual([])

    ready(connection)

    expect(actions.pending).toHaveLength(1)
    expect(actions.pending[0]).toMatchObject({
      action: UPLOAD_ACTION_INIT,
      data: {
        target: 'message',
        clientUploadId,
        filename: 'a.txt',
        mimeType: 'text/plain',
        size: 3,
      },
    })
  })

  it('streams signed chunks and declares the next file after the last chunk', async () => {
    const { connection, actions } = setup()
    const firstId = uploadFile(
      'message',
      file('first.bin', UPLOAD_CHUNK_BYTES + 3),
    )
    const secondId = uploadFile('message', file('second.bin', 1))
    ready(connection)
    connection.signal(SIGNAL_UPLOAD_STATE, {
      clientUploadId: firstId,
      phase: UPLOAD_PHASE_READY,
      receivedBytes: 0,
      declaredSize: UPLOAD_CHUNK_BYTES + 3,
    })

    actions.pending[0]?.resolve()
    await vi.waitFor(() => expect(connection.binaryFrames).toHaveLength(2))

    expect(frameBytes(connection.binaryFrames[0] as ArrayBuffer)).toHaveLength(
      UPLOAD_CHUNK_BYTES,
    )
    expect(frameBytes(connection.binaryFrames[1] as ArrayBuffer)).toHaveLength(
      3,
    )
    expect(actions.pending[1]).toMatchObject({
      action: UPLOAD_ACTION_INIT,
      data: { clientUploadId: secondId },
    })
  })

  it('waits while the socket buffer is above its high-water mark', async () => {
    vi.useFakeTimers()
    const { connection, actions } = setup()
    connection.bufferedAmount = Number.MAX_SAFE_INTEGER
    uploadFile('message', file('a.bin', 1))
    ready(connection)
    actions.pending[0]?.resolve()
    await settle()

    expect(connection.binaryFrames).toEqual([])

    connection.bufferedAmount = 0
    await vi.advanceTimersByTimeAsync(UPLOAD_BUFFER_POLL_MS)
    await settle()

    expect(connection.binaryFrames).toHaveLength(1)
  })

  it('fails a refused declaration and advances the queue', async () => {
    const { connection, actions } = setup()
    uploadFile('message', file('first.bin', 1))
    uploadFile('message', file('second.bin', 1))
    ready(connection)

    actions.pending[0]?.reject(
      new ActionError(UPLOAD_ACTION_INIT, 'fail', 'Target refused the file.'),
    )
    await settle()

    expect(hilosUploads.get()[0]).toMatchObject({
      phase: UPLOAD_PHASE_FAILED,
      errorCode: UPLOAD_ERROR_REFUSED,
      errorMessage: 'Target refused the file.',
    })
    expect(actions.pending[1]?.action).toBe(UPLOAD_ACTION_INIT)
  })

  it('cancels a declaration that timed out', async () => {
    const { connection, actions } = setup()
    const clientUploadId = uploadFile('message', file('a.bin', 1))
    ready(connection)

    actions.pending[0]?.reject(
      new ActionError(UPLOAD_ACTION_INIT, 'timeout', 'The action timed out.'),
    )
    await settle()

    expect(hilosUploads.get()[0]).toMatchObject({
      phase: UPLOAD_PHASE_FAILED,
      errorCode: UPLOAD_ERROR_TIMEOUT,
    })
    expect(actions.pending[1]).toMatchObject({
      action: UPLOAD_ACTION_CANCEL,
      data: { clientUploadId },
    })
  })
})

describe('server upload state', () => {
  it('stops a stream when the server reports failure', async () => {
    let resolveSecond!: (bytes: ArrayBuffer) => void
    let sliceCall = 0
    const controlledFile = {
      name: 'a.bin',
      type: 'application/octet-stream',
      size: UPLOAD_CHUNK_BYTES + 1,
      slice: () => ({
        arrayBuffer: () => {
          sliceCall += 1

          return sliceCall === 1
            ? Promise.resolve(new Uint8Array(UPLOAD_CHUNK_BYTES).buffer)
            : new Promise<ArrayBuffer>((resolveBytes) => {
                resolveSecond = resolveBytes
              })
        },
      }),
    } as unknown as File
    const { connection, actions } = setup()
    const clientUploadId = uploadFile('message', controlledFile)
    ready(connection)
    actions.pending[0]?.resolve()
    await vi.waitFor(() => expect(connection.binaryFrames).toHaveLength(1))

    connection.signal(SIGNAL_UPLOAD_STATE, {
      clientUploadId,
      phase: UPLOAD_PHASE_FAILED,
      errorCode: 'write_error',
      errorMessage: 'Cannot write.',
    })
    resolveSecond(new Uint8Array([1]).buffer)
    await settle()

    expect(connection.binaryFrames).toHaveLength(1)
    expect(hilosUploads.get()[0]).toMatchObject({
      phase: UPLOAD_PHASE_FAILED,
      errorCode: 'write_error',
    })
  })

  it('removes complete and failed uploads when the server says they are gone', () => {
    const { connection, actions } = setup()
    const clientUploadId = uploadFile('message', file('a.bin', 0))
    ready(connection)
    connection.signal(SIGNAL_UPLOAD_STATE, {
      clientUploadId,
      phase: UPLOAD_PHASE_COMPLETE,
    })
    actions.pending[0]?.resolve()
    connection.signal(SIGNAL_UPLOAD_STATE, { clientUploadId })

    expect(hilosUploads.get()).toEqual([])
  })

  it('marks an unfinished upload interrupted when the server removes it', () => {
    const { connection } = setup()
    const clientUploadId = uploadFile('message', file('a.bin', 1))
    ready(connection)
    connection.signal(SIGNAL_UPLOAD_STATE, {
      clientUploadId,
      phase: UPLOAD_PHASE_UPLOADING,
      receivedBytes: 1,
    })

    connection.signal(SIGNAL_UPLOAD_STATE, { clientUploadId })

    expect(hilosUploads.get()[0]).toMatchObject({
      phase: UPLOAD_PHASE_FAILED,
      errorCode: UPLOAD_ERROR_INTERRUPTED,
      errorMessage: 'Upload interrupted',
    })
  })
})

describe('canceling uploads', () => {
  it('removes an unannounced queued upload without a frame', () => {
    const { actions } = setup()
    const clientUploadId = uploadFile('message', file('a.bin', 1))

    cancelUpload(clientUploadId)

    expect(hilosUploads.get()).toEqual([])
    expect(actions.pending).toEqual([])
  })

  it('marks an announced upload and removes it on cancel success', async () => {
    const { connection, actions } = setup()
    const clientUploadId = uploadFile('message', file('a.bin', 1))
    ready(connection)

    cancelUpload(clientUploadId)

    expect(hilosUploads.get()[0]?.canceling).toBe(true)
    expect(actions.pending[1]).toMatchObject({
      action: UPLOAD_ACTION_CANCEL,
      data: { clientUploadId },
    })
    actions.pending[1]?.resolve()
    await settle()
    expect(hilosUploads.get()).toEqual([])
  })

  it('also removes a canceling upload on the gone frame', () => {
    const { connection } = setup()
    const clientUploadId = uploadFile('message', file('a.bin', 1))
    ready(connection)
    cancelUpload(clientUploadId)

    connection.signal(SIGNAL_UPLOAD_STATE, { clientUploadId })

    expect(hilosUploads.get()).toEqual([])
  })

  it('clears canceling when cancellation is refused', async () => {
    const { connection, actions } = setup()
    const clientUploadId = uploadFile('message', file('a.bin', 1))
    ready(connection)
    cancelUpload(clientUploadId)

    actions.pending[1]?.reject(
      new ActionError(UPLOAD_ACTION_CANCEL, 'fail', 'Already complete.'),
    )
    await settle()

    expect(hilosUploads.get()[0]).toMatchObject({
      clientUploadId,
      canceling: false,
    })
  })
})

describe('connection and identity lifetime', () => {
  it('requeues live uploads on disconnect and redeclares the same id', () => {
    const { connection, actions } = setup()
    const clientUploadId = uploadFile('message', file('a.bin', 1))
    ready(connection)
    connection.signal(SIGNAL_UPLOAD_STATE, {
      clientUploadId,
      phase: UPLOAD_PHASE_READY,
      receivedBytes: 0,
    })

    connection.state('reconnecting')

    expect(hilosUploads.get()[0]).toMatchObject({
      clientUploadId,
      phase: UPLOAD_PHASE_QUEUED,
      receivedBytes: 0,
    })
    ready(connection)
    expect(actions.pending[1]).toMatchObject({
      action: UPLOAD_ACTION_INIT,
      data: { clientUploadId },
    })
  })

  it('keeps failed uploads and drops canceling uploads on disconnect', () => {
    const { connection } = setup()
    const failedId = uploadFile('message', file('failed.bin', 1))
    const cancelingId = uploadFile('message', file('canceling.bin', 1))
    ready(connection)
    connection.signal(SIGNAL_UPLOAD_STATE, {
      clientUploadId: failedId,
      phase: UPLOAD_PHASE_FAILED,
      errorCode: 'write_error',
      errorMessage: 'Cannot write.',
    })
    cancelUpload(cancelingId)

    connection.state('disconnected')

    expect(hilosUploads.get()).toMatchObject([
      { clientUploadId: failedId, phase: UPLOAD_PHASE_FAILED },
    ])
  })

  it('cancels announced uploads and clears the list when the user changes', () => {
    const { connection, actions, currentUserId } = setup()
    const clientUploadId = uploadFile('message', file('a.bin', 1))
    ready(connection)

    currentUserId.set(2)

    expect(hilosUploads.get()).toEqual([])
    expect(actions.pending[1]).toMatchObject({
      action: UPLOAD_ACTION_CANCEL,
      data: { clientUploadId },
    })
  })

  it('does not treat identity resolution before first readiness as a change', () => {
    const { connection, actions, currentUserId } = setup(null)
    uploadFile('message', file('a.bin', 1))

    currentUserId.set(1)
    ready(connection)

    expect(hilosUploads.get()).toHaveLength(1)
    expect(actions.pending[0]?.action).toBe(UPLOAD_ACTION_INIT)
  })
})

describe('file reads', () => {
  it('fails unreadable bytes and cancels the server upload', async () => {
    const unreadable = {
      name: 'gone.bin',
      type: 'application/octet-stream',
      size: 1,
      slice: () => ({
        arrayBuffer: () => Promise.reject(new Error('The file disappeared.')),
      }),
    } as unknown as File
    const { connection, actions } = setup()
    const clientUploadId = uploadFile('message', unreadable)
    ready(connection)

    actions.pending[0]?.resolve()
    await vi.waitFor(() =>
      expect(hilosUploads.get()[0]?.phase).toBe(UPLOAD_PHASE_FAILED),
    )

    expect(hilosUploads.get()[0]).toMatchObject({
      errorCode: UPLOAD_ERROR_UNREADABLE,
      errorMessage: 'The file could not be read',
    })
    expect(actions.pending[1]).toMatchObject({
      action: UPLOAD_ACTION_CANCEL,
      data: { clientUploadId },
    })
  })
})
