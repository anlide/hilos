import { beforeEach, describe, expect, it, vi } from 'vitest'
import { type ActionLifecycle } from '../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import {
  createHilosProfilePhotoFlow,
  HILOS_PROFILE_PHOTO_COPY,
  PROFILE_PHOTO_REMOVE_ACTION,
  PROFILE_PHOTO_SET_ACTION,
  SIGNAL_PROFILE_PHOTO_CHECK,
} from '../../src/profile/profilePhoto.js'
import {
  openHilosPhoto,
  renderHilosPhotoSquare,
} from '../../src/profile/photoPick.js'
import { createSignal, type WritableSignal } from '../../src/state/signal.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import {
  hilosUploads,
  uploadFile,
  type HilosUpload,
} from '../../src/uploads/hilosUploads.js'

vi.mock('../../src/uploads/hilosUploads.js', async () => {
  const { createSignal } = await import('../../src/state/signal.js')
  return {
    hilosUploads: createSignal<readonly HilosUpload[]>([]),
    uploadFile: vi.fn(() => 'upload-1'),
    cancelUpload: vi.fn(),
  }
})

vi.mock('../../src/profile/photoPick.js', () => ({
  openHilosPhoto: vi.fn(),
  renderHilosPhotoSquare: vi.fn(),
}))

const uploads = hilosUploads as WritableSignal<readonly HilosUpload[]>

beforeEach(() => {
  uploads.set([])
  vi.mocked(uploadFile).mockClear()
  vi.mocked(openHilosPhoto).mockReset()
  vi.mocked(renderHilosPhotoSquare).mockReset()
  vi.mocked(openHilosPhoto).mockResolvedValue({
    width: 800,
    height: 600,
    close: vi.fn(),
  } as unknown as ImageBitmap)
  vi.mocked(renderHilosPhotoSquare).mockResolvedValue(
    new Blob(['jpeg'], { type: 'image/jpeg' }),
  )
})

function setup(initialPhoto: string | null = null) {
  const listeners = new Map<string, Set<(value: unknown) => void>>()
  const connection = {
    on(event: string, listener: (value: unknown) => void): () => void {
      const set = listeners.get(event) ?? new Set()
      set.add(listener)
      listeners.set(event, set)
      return () => set.delete(listener)
    },
  }
  const emit = (event: string, value: unknown): void => {
    for (const listener of listeners.get(event) ?? []) listener(value)
  }
  const dispatch = vi.fn((_action: string, _payload: unknown) => {
    void _action
    void _payload
    return { done: Promise.resolve({}) }
  })
  const photo = createSignal<string | null>(initialPhoto)
  const flow = createHilosProfilePhotoFlow(
    {
      connection: connection as unknown as HilosConnection,
      actions: { dispatch } as unknown as ActionLifecycle,
      scopes: new ScopeManager(),
    },
    photo,
  )
  return { flow, photo, emit, dispatch }
}

function completeUpload(): void {
  uploads.set([
    {
      clientUploadId: 'upload-1',
      target: 'hilos_profile_photo',
      filename: 'photo.jpg',
      declaredSize: 4,
      receivedBytes: 4,
      phase: 'complete',
      errorCode: null,
      errorMessage: null,
      canceling: false,
    },
  ])
}

describe('profile photo flow', () => {
  it('moves from pick to crop, then uploads and dispatches the set action', async () => {
    const { flow, photo, emit, dispatch } = setup()
    flow.open()
    expect(flow.step.get()).toBe('pick')
    await flow.pick(new File(['a'], 'portrait.png', { type: 'image/png' }))
    expect(flow.step.get()).toBe('crop')
    flow.setZoom(2)
    flow.move(20, -10, 128)
    expect(flow.zoom.get()).toBe(2)
    expect(flow.preview.get()?.square.side).toBe(300)

    await flow.save()
    expect(uploadFile).toHaveBeenCalledWith(
      'hilos_profile_photo',
      expect.objectContaining({ name: 'photo.jpg' }),
    )
    completeUpload()
    await vi.waitFor(() =>
      expect(dispatch).toHaveBeenCalledWith(PROFILE_PHOTO_SET_ACTION, {
        clientUploadId: 'upload-1',
      }),
    )
    emit('projectSignal', {
      type: SIGNAL_PROFILE_PHOTO_CHECK,
      data: { checking: true },
    })
    expect(flow.checking.get()).toBe(true)
    expect(flow.busy.get()).toBe(true)
    photo.set('/_hilos/file?id=1&variant=hilos_avatar')
    await vi.waitFor(() => expect(flow.step.get()).toBe('closed'))
    expect(flow.voice.get()).toBe(HILOS_PROFILE_PHOTO_COPY.updated)
    flow.dispose()
  })

  it('keeps the cropped picture and shows an asynchronous checker refusal', async () => {
    const { flow, emit, dispatch } = setup()
    flow.open()
    await flow.pick(new File(['a'], 'photo.png', { type: 'image/png' }))
    await flow.save()
    completeUpload()
    await vi.waitFor(() =>
      expect(dispatch).toHaveBeenCalledWith(PROFILE_PHOTO_SET_ACTION, {
        clientUploadId: 'upload-1',
      }),
    )
    emit('actionError', {
      action: PROFILE_PHOTO_SET_ACTION,
      reason: 'This photo was not accepted.',
    })
    await vi.waitFor(() =>
      expect(flow.refusal.get()).toBe('This photo was not accepted.'),
    )
    expect(flow.step.get()).toBe('crop')
    expect(flow.busy.get()).toBe(false)
    flow.dispose()
  })

  it('removes the live photo and waits for the session echo', async () => {
    const { flow, photo, dispatch } = setup('/_hilos/file?id=1')
    flow.open()
    expect(flow.step.get()).toBe('current')
    void flow.remove()
    expect(dispatch).toHaveBeenCalledWith(PROFILE_PHOTO_REMOVE_ACTION, {})
    photo.set(null)
    await vi.waitFor(() => expect(flow.step.get()).toBe('closed'))
    expect(flow.voice.get()).toBe(HILOS_PROFILE_PHOTO_COPY.removed)
    flow.dispose()
  })

  it('can close during checking and recover a refusal when reopened', async () => {
    const { flow, emit, dispatch } = setup()
    flow.open()
    await flow.pick(new File(['a'], 'photo.png', { type: 'image/png' }))
    await flow.save()
    completeUpload()
    await vi.waitFor(() =>
      expect(dispatch).toHaveBeenCalledWith(PROFILE_PHOTO_SET_ACTION, {
        clientUploadId: 'upload-1',
      }),
    )
    emit('projectSignal', {
      type: SIGNAL_PROFILE_PHOTO_CHECK,
      data: { checking: true },
    })
    flow.close()
    emit('actionError', {
      action: PROFILE_PHOTO_SET_ACTION,
      reason: 'Photos cannot be checked right now.',
    })
    flow.open()
    expect(flow.step.get()).toBe('crop')
    expect(flow.checking.get()).toBe(false)
    expect(flow.refusal.get()).toBe('Photos cannot be checked right now.')
    flow.dispose()
  })
})
