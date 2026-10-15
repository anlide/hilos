import { beforeEach, describe, expect, it, vi } from 'vitest'

import {
  createHilosUploadField,
  HILOS_UPLOAD_FIELD_COPY,
} from '../../src/primitives/uploadField.js'
import { type WritableSignal } from '../../src/state/signal.js'
import {
  cancelUpload,
  hilosUploads,
  uploadFile,
  type HilosUpload,
} from '../../src/uploads/hilosUploads.js'
import {
  UPLOAD_PHASE_COMPLETE,
  UPLOAD_PHASE_FAILED,
  UPLOAD_PHASE_QUEUED,
  UPLOAD_PHASE_READY,
  UPLOAD_PHASE_UPLOADING,
  type HilosUploadPhase,
} from '../../src/uploads/uploadProtocol.js'

vi.mock('../../src/uploads/hilosUploads.js', async () => {
  const { createSignal } = await import('../../src/state/signal.js')
  return {
    hilosUploads: createSignal<readonly HilosUpload[]>([]),
    uploadFile: vi.fn(() => 'minted'),
    cancelUpload: vi.fn(),
  }
})

const TARGET = 'chat_attachment'
const OTHER_TARGET = 'hilos_profile_photo'

const uploads = hilosUploads as WritableSignal<readonly HilosUpload[]>

function record(
  clientUploadId: string,
  phase: HilosUploadPhase,
  changes: Partial<HilosUpload> = {},
): HilosUpload {
  return {
    clientUploadId,
    target: TARGET,
    filename: `${clientUploadId}.txt`,
    declaredSize: 100,
    receivedBytes: 0,
    phase,
    errorCode: phase === UPLOAD_PHASE_FAILED ? 'too_large' : null,
    errorMessage:
      phase === UPLOAD_PHASE_FAILED
        ? 'File is larger than the allowed size'
        : null,
    canceling: false,
    ...changes,
  }
}

function file(name: string, body = 'bytes'): File {
  return new File([body], name)
}

beforeEach(() => {
  uploads.set([])
  vi.mocked(uploadFile).mockClear()
  vi.mocked(cancelUpload).mockClear()
})

it('carries the approved words', () => {
  expect(HILOS_UPLOAD_FIELD_COPY).toEqual({
    overlay: 'Drop files to upload',
    attach: 'Attach files',
    waiting: 'Waiting',
    uploading: 'Uploading',
    failed: 'Failed',
    remove: 'Remove',
    uploaded: 'uploaded',
  })
})

describe('createHilosUploadField files', () => {
  it('shows only its own target, in selection order, with each reading', () => {
    const field = createHilosUploadField({ target: TARGET })
    uploads.set([
      record('a', UPLOAD_PHASE_COMPLETE, { receivedBytes: 100 }),
      record('photo', UPLOAD_PHASE_UPLOADING, { target: OTHER_TARGET }),
      record('b', UPLOAD_PHASE_UPLOADING, { receivedBytes: 38 }),
      record('c', UPLOAD_PHASE_READY),
      record('d', UPLOAD_PHASE_QUEUED, { canceling: true }),
      record('e', UPLOAD_PHASE_FAILED),
    ])

    expect(field.files.get()).toEqual([
      {
        clientUploadId: 'a',
        filename: 'a.txt',
        reading: 'received',
        percent: 100,
        canceling: false,
      },
      {
        clientUploadId: 'b',
        filename: 'b.txt',
        reading: 'uploading',
        percent: 38,
        canceling: false,
      },
      {
        clientUploadId: 'c',
        filename: 'c.txt',
        reading: 'waiting',
        percent: 0,
        canceling: false,
      },
      {
        clientUploadId: 'd',
        filename: 'd.txt',
        reading: 'waiting',
        percent: 0,
        canceling: true,
      },
      {
        clientUploadId: 'e',
        filename: 'e.txt',
        reading: 'failed',
        percent: 0,
        canceling: false,
      },
    ])
    field.dispose()
  })

  it('rounds the received share, caps it at 100 and reads an empty size as 0', () => {
    const field = createHilosUploadField({ target: TARGET })
    uploads.set([
      record('a', UPLOAD_PHASE_UPLOADING, {
        declaredSize: 3,
        receivedBytes: 2,
      }),
      record('b', UPLOAD_PHASE_UPLOADING, {
        declaredSize: 10,
        receivedBytes: 12,
      }),
      record('c', UPLOAD_PHASE_UPLOADING, {
        declaredSize: 0,
        receivedBytes: 5,
      }),
    ])

    expect(field.files.get().map((chip) => chip.percent)).toEqual([67, 100, 0])
    field.dispose()
  })
})

describe('createHilosUploadField derived state', () => {
  it('names the sentence of the last refused file, or null', () => {
    const field = createHilosUploadField({ target: TARGET })
    expect(field.refusal.get()).toBeNull()

    uploads.set([
      record('a', UPLOAD_PHASE_FAILED, {
        errorMessage: 'This file type is not allowed',
      }),
      record('b', UPLOAD_PHASE_COMPLETE),
      record('c', UPLOAD_PHASE_FAILED),
      record('photo', UPLOAD_PHASE_FAILED, {
        target: OTHER_TARGET,
        errorMessage: 'Storage limit would be exceeded',
      }),
    ])
    expect(field.refusal.get()).toBe('File is larger than the allowed size')

    uploads.set([record('b', UPLOAD_PHASE_COMPLETE)])
    expect(field.refusal.get()).toBeNull()
    field.dispose()
  })

  it('is busy while a file of its own target waits or uploads', () => {
    const field = createHilosUploadField({ target: TARGET })
    expect(field.busy.get()).toBe(false)

    const inFlight: readonly HilosUploadPhase[] = [
      UPLOAD_PHASE_QUEUED,
      UPLOAD_PHASE_READY,
      UPLOAD_PHASE_UPLOADING,
    ]
    for (const phase of inFlight) {
      uploads.set([record('a', UPLOAD_PHASE_COMPLETE), record('b', phase)])
      expect(field.busy.get()).toBe(true)
    }

    uploads.set([
      record('a', UPLOAD_PHASE_COMPLETE),
      record('b', UPLOAD_PHASE_FAILED),
      record('photo', UPLOAD_PHASE_UPLOADING, { target: OTHER_TARGET }),
    ])
    expect(field.busy.get()).toBe(false)
    field.dispose()
  })

  it('holds ready the ids received and not being removed, in selection order', () => {
    const field = createHilosUploadField({ target: TARGET })
    uploads.set([
      record('a', UPLOAD_PHASE_COMPLETE),
      record('b', UPLOAD_PHASE_COMPLETE, { canceling: true }),
      record('c', UPLOAD_PHASE_UPLOADING),
      record('photo', UPLOAD_PHASE_COMPLETE, { target: OTHER_TARGET }),
      record('d', UPLOAD_PHASE_COMPLETE),
    ])

    expect(field.ready.get()).toEqual(['a', 'd'])
    field.dispose()
  })
})

describe('createHilosUploadField dragging', () => {
  it('counts nested enters and leaves, never below zero', () => {
    const field = createHilosUploadField({ target: TARGET })
    expect(field.dragging.get()).toBe(false)

    field.dragEnter()
    field.dragEnter()
    field.dragLeave()
    expect(field.dragging.get()).toBe(true)
    field.dragLeave()
    expect(field.dragging.get()).toBe(false)
    field.dragLeave()
    field.dragEnter()
    expect(field.dragging.get()).toBe(true)
    field.dispose()
  })

  it('shows no drag on a disabled field', () => {
    const field = createHilosUploadField({ target: TARGET })
    field.dragEnter()
    field.setDisabled(true)

    expect(field.disabled.get()).toBe(true)
    expect(field.dragging.get()).toBe(false)
    field.setDisabled(false)
    expect(field.dragging.get()).toBe(true)
    field.dispose()
  })

  it('resets the count on drop and takes the dropped files', () => {
    const field = createHilosUploadField({ target: TARGET })
    field.dragEnter()
    field.dragEnter()
    const dropped = file('notes.txt')

    field.drop([dropped])

    expect(field.dragging.get()).toBe(false)
    expect(uploadFile).toHaveBeenCalledWith(TARGET, dropped)
    field.dispose()
  })
})

describe('createHilosUploadField add and remove', () => {
  it('queues every non-empty file to its target in the order taken', () => {
    const field = createHilosUploadField({ target: TARGET })
    const first = file('first.pdf')
    const second = file('second.png')

    field.add([first, file('empty.txt', ''), second])

    expect(vi.mocked(uploadFile).mock.calls).toEqual([
      [TARGET, first],
      [TARGET, second],
    ])
    field.dispose()
  })

  it('first clears its own refusals, never another target or a received file', () => {
    const field = createHilosUploadField({ target: TARGET })
    uploads.set([
      record('a', UPLOAD_PHASE_COMPLETE),
      record('b', UPLOAD_PHASE_FAILED),
      record('photo', UPLOAD_PHASE_FAILED, { target: OTHER_TARGET }),
      record('c', UPLOAD_PHASE_FAILED),
    ])

    field.add([file('again.zip')])

    expect(vi.mocked(cancelUpload).mock.calls).toEqual([['b'], ['c']])
    expect(uploadFile).toHaveBeenCalledTimes(1)
    expect(vi.mocked(cancelUpload).mock.invocationCallOrder[1]).toBeLessThan(
      vi.mocked(uploadFile).mock.invocationCallOrder[0],
    )
    field.dispose()
  })

  it('does nothing when every file is empty, refusals included', () => {
    const field = createHilosUploadField({ target: TARGET })
    uploads.set([record('b', UPLOAD_PHASE_FAILED)])

    field.add([file('empty.txt', '')])
    field.add([])

    expect(cancelUpload).not.toHaveBeenCalled()
    expect(uploadFile).not.toHaveBeenCalled()
    field.dispose()
  })

  it('takes no file and removes none while disabled', () => {
    const field = createHilosUploadField({ target: TARGET })
    uploads.set([
      record('a', UPLOAD_PHASE_COMPLETE),
      record('b', UPLOAD_PHASE_FAILED),
    ])
    field.setDisabled(true)

    field.add([file('notes.txt')])
    field.drop([file('dropped.txt')])
    field.remove('a')

    expect(cancelUpload).not.toHaveBeenCalled()
    expect(uploadFile).not.toHaveBeenCalled()
    field.dispose()
  })

  it('cancels the upload of a pressed ×, whatever its phase', () => {
    const field = createHilosUploadField({ target: TARGET })
    uploads.set([
      record('a', UPLOAD_PHASE_COMPLETE),
      record('b', UPLOAD_PHASE_UPLOADING),
      record('c', UPLOAD_PHASE_QUEUED),
      record('d', UPLOAD_PHASE_FAILED),
    ])

    for (const id of ['a', 'b', 'c', 'd']) {
      field.remove(id)
    }

    expect(vi.mocked(cancelUpload).mock.calls).toEqual([
      ['a'],
      ['b'],
      ['c'],
      ['d'],
    ])
    field.dispose()
  })
})

describe('createHilosUploadField voice', () => {
  it('says a refusal when its own file turns failed', () => {
    const field = createHilosUploadField({ target: TARGET })
    expect(field.alert.get()).toBe('')

    uploads.set([record('a', UPLOAD_PHASE_UPLOADING)])
    expect(field.alert.get()).toBe('')
    uploads.set([
      record('a', UPLOAD_PHASE_FAILED, {
        filename: 'site-backup.zip',
      }),
    ])
    expect(field.alert.get()).toBe(
      'site-backup.zip: File is larger than the allowed size',
    )

    uploads.set([
      record('a', UPLOAD_PHASE_FAILED, { filename: 'site-backup.zip' }),
      record('photo', UPLOAD_PHASE_FAILED, { target: OTHER_TARGET }),
    ])
    expect(field.alert.get()).toBe(
      'site-backup.zip: File is larger than the allowed size',
    )
    field.dispose()
  })

  it('says a file received when it turns complete', () => {
    const field = createHilosUploadField({ target: TARGET })
    expect(field.status.get()).toBe('')

    uploads.set([
      record('a', UPLOAD_PHASE_UPLOADING, { filename: 'notes.txt' }),
    ])
    expect(field.status.get()).toBe('')
    uploads.set([record('a', UPLOAD_PHASE_COMPLETE, { filename: 'notes.txt' })])

    expect(field.status.get()).toBe('notes.txt uploaded')
    field.dispose()
  })

  it('stays silent about what was already there when it was created', () => {
    uploads.set([
      record('a', UPLOAD_PHASE_COMPLETE),
      record('b', UPLOAD_PHASE_FAILED),
    ])
    const field = createHilosUploadField({ target: TARGET })

    uploads.set([
      record('a', UPLOAD_PHASE_COMPLETE),
      record('b', UPLOAD_PHASE_FAILED),
      record('c', UPLOAD_PHASE_QUEUED),
    ])

    expect(field.alert.get()).toBe('')
    expect(field.status.get()).toBe('')
    field.dispose()
  })

  it('takes a sentence back once its file no longer reads that way', () => {
    const field = createHilosUploadField({ target: TARGET })
    uploads.set([record('a', UPLOAD_PHASE_UPLOADING)])
    uploads.set([record('a', UPLOAD_PHASE_FAILED)])
    expect(field.alert.get()).not.toBe('')

    // A new pick cancels the refused record, and the client removes it.
    uploads.set([record('b', UPLOAD_PHASE_QUEUED)])
    expect(field.alert.get()).toBe('')
    field.dispose()
  })

  it('says a file received again after a dropped connection sends it anew', () => {
    const field = createHilosUploadField({ target: TARGET })
    const done = record('a', UPLOAD_PHASE_COMPLETE, {
      filename: 'notes.txt',
      receivedBytes: 100,
    })
    uploads.set([
      record('a', UPLOAD_PHASE_UPLOADING, { filename: 'notes.txt' }),
    ])
    uploads.set([done])
    expect(field.status.get()).toBe('notes.txt uploaded')

    // The drop: the client puts the received file back to waiting from zero.
    uploads.set([record('a', UPLOAD_PHASE_QUEUED, { filename: 'notes.txt' })])
    expect(field.files.get()[0]?.reading).toBe('waiting')
    expect(field.status.get()).toBe('')

    uploads.set([
      record('a', UPLOAD_PHASE_UPLOADING, {
        filename: 'notes.txt',
        receivedBytes: 12,
      }),
    ])
    expect(field.files.get()[0]?.percent).toBe(12)
    uploads.set([done])
    expect(field.status.get()).toBe('notes.txt uploaded')
    field.dispose()
  })

  it('stops listening on dispose and leaves the client records alone', () => {
    const field = createHilosUploadField({ target: TARGET })
    uploads.set([record('a', UPLOAD_PHASE_UPLOADING)])

    field.dispose()
    uploads.set([record('a', UPLOAD_PHASE_COMPLETE)])

    expect(field.status.get()).toBe('')
    expect(cancelUpload).not.toHaveBeenCalled()
    expect(uploads.get()).toHaveLength(1)
  })
})
