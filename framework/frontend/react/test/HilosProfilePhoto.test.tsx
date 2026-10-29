import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionLifecycle,
  createHilosProfilePhotoFlow,
  createSignal,
  HILOS_PROFILE_PHOTO_COPY as COPY,
  hilosUploads,
  PROFILE_PHOTO_KIND,
  PROFILE_PHOTO_REMOVE_ACTION,
  PROFILE_PHOTO_SET_ACTION,
  ScopeManager,
  SIGNAL_MODERATION_CHECK,
  uploadFile,
  type ActionLifecycleSource,
  type HilosConnection,
  type HilosProfilePhotoFlow,
  type HilosProfilePhotoStep,
  type HilosUpload,
  type WritableSignal,
} from '@hilos/core'
import { afterEach, expect, it, vi } from 'vitest'
import { HilosProfilePhoto } from '../src/profile/HilosProfilePhoto.js'

// Keep the real photo flow and modal; control only the upload transport boundary.
vi.mock('../../core/src/uploads/hilosUploads.js', async () => {
  const { createSignal } = await import('../../core/src/state/signal.js')
  return {
    hilosUploads: createSignal<readonly HilosUpload[]>([]),
    uploadFile: vi.fn(() => 'upload-1'),
    cancelUpload: vi.fn(),
  }
})

const uploads = hilosUploads as WritableSignal<readonly HilosUpload[]>
const releases: (() => void)[] = []

afterEach(() => {
  cleanup()
  while (releases.length) releases.pop()?.()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  vi.clearAllTimers()
  vi.useRealTimers()
  uploads.set([])
  vi.mocked(uploadFile).mockClear()
})

/** A flow with writable signals for events arriving before React renders. */
function photoFlow(at: HilosProfilePhotoStep = 'pick') {
  const step = createSignal<HilosProfilePhotoStep>(at)
  return {
    step,
    photo: createSignal<string | null>('/photo.webp'),
    name: createSignal('Ada'),
    initials: createSignal('A'),
    preview: createSignal(null),
    crop: createSignal({ zoom: 1, centerX: 0, centerY: 0 }),
    zoom: createSignal(1),
    busy: createSignal(false),
    checking: createSignal(false),
    refusal: createSignal<string | null>(null),
    voice: createSignal(''),
    open: () => step.set('pick'),
    pick: vi.fn(async () => {}),
    setZoom: vi.fn(),
    move: vi.fn(),
    save: vi.fn(async () => {}),
    remove: vi.fn(async () => {}),
    close: () => step.set('closed'),
    dispose: vi.fn(),
  } satisfies HilosProfilePhotoFlow
}

it('offers the picker, current-photo actions and an accessible crop canvas', () => {
  const flow = photoFlow()
  const { step, remove, save } = flow
  render(<HilosProfilePhoto flow={flow} />)
  const byId = (id: string) =>
    document.querySelector<HTMLElement>(`[data-id="${id}"]`)

  expect(byId('profile-photo-input')?.getAttribute('accept')).toBe(
    'image/jpeg,image/png,image/webp',
  )
  expect(byId('profile-photo-drop')?.textContent).toContain('up to 40 MB')
  act(() => step.set('current'))
  fireEvent.click(byId('profile-photo-remove')!)
  expect(remove).toHaveBeenCalledOnce()
  act(() => step.set('crop'))
  expect(byId('profile-photo-preview')?.getAttribute('aria-label')).toContain(
    'arrow keys',
  )
  fireEvent.click(byId('profile-photo-save')!)
  expect(save).toHaveBeenCalledOnce()

  expect(byId('profile-photo-checking')).toBeNull()
  const checkingIdle = byId('profile-photo-checking-idle')
  expect(checkingIdle).not.toBeNull()
  expect(checkingIdle?.getAttribute('aria-hidden')).toBe('true')
  expect(checkingIdle?.classList.contains('invisible')).toBe(true)

  expect(byId('profile-photo-upload-another')).toBeNull()
  const uploadAnotherIdle = byId(
    'profile-photo-upload-another-idle',
  ) as HTMLButtonElement | null
  expect(uploadAnotherIdle).not.toBeNull()
  expect(uploadAnotherIdle?.getAttribute('aria-hidden')).toBe('true')
  expect(uploadAnotherIdle?.disabled).toBe(true)
  expect(uploadAnotherIdle?.getAttribute('tabindex')).toBe('-1')
  expect(uploadAnotherIdle?.classList.contains('invisible')).toBe(true)

  act(() => flow.checking.set(true))
  expect(byId('profile-photo-checking')?.textContent).toContain('Checking')
  expect(byId('profile-photo-checking-idle')).toBeNull()
  expect(byId('profile-photo-upload-another')).toBeNull()
  expect(byId('profile-photo-upload-another-idle')).not.toBeNull()

  act(() => {
    flow.checking.set(false)
    flow.refusal.set('Photo was rejected')
  })
  expect(byId('profile-photo-checking')).toBeNull()
  expect(byId('profile-photo-checking-idle')).not.toBeNull()
  expect(byId('profile-photo-upload-another-idle')).toBeNull()
  const uploadAnother = byId(
    'profile-photo-upload-another',
  ) as HTMLButtonElement | null
  expect(uploadAnother).not.toBeNull()
  expect(uploadAnother?.disabled).toBe(false)

  act(() => flow.busy.set(true))
  expect(uploadAnother?.disabled).toBe(true)
  act(() => flow.busy.set(false))
  expect(uploadAnother?.disabled).toBe(false)

  const fileInput = byId('profile-photo-input') as HTMLInputElement
  const clickSpy = vi.spyOn(fileInput, 'click')
  fireEvent.click(uploadAnother!)
  expect(clickSpy).toHaveBeenCalledOnce()

  fireEvent.change(fileInput, { target: { files: [] } })
  expect(flow.pick).not.toHaveBeenCalled()
  expect(byId('profile-photo-preview')).not.toBeNull()

  const file = new File(['data'], 'new.png', { type: 'image/png' })
  fireEvent.change(fileInput, { target: { files: [file] } })
  expect(flow.pick).toHaveBeenCalledWith(file)
  expect(byId('profile-photo-preview')).not.toBeNull()
})

it('preserves refusal slot identity and live region across error states and step transitions', () => {
  const flow = photoFlow()
  const { step } = flow

  render(<HilosProfilePhoto flow={flow} />)
  const byId = (id: string) =>
    document.querySelector<HTMLElement>(`[data-id="${id}"]`)

  const slot = byId('profile-photo-error-slot')
  if (!slot) throw new Error('Missing profile-photo-error-slot')

  expect(slot.getAttribute('role')).toBe('alert')
  expect(slot.getAttribute('aria-live')).toBe('assertive')
  expect(slot.closest('[role="dialog"][aria-modal="true"]')).not.toBeNull()
  expect(byId('profile-photo-error')).toBeNull()
  const idle = byId('profile-photo-error-idle')
  expect(idle?.getAttribute('aria-hidden')).toBe('true')
  expect(slot.querySelector('[data-id="profile-photo-error"]')).toBeNull()
  expect(slot.textContent?.trim()).toBe('')

  act(() => flow.refusal.set('Photo was rejected'))

  const visibleError = byId('profile-photo-error')
  expect(visibleError).not.toBeNull()
  expect(visibleError?.textContent).toContain('Photo was rejected')
  expect(visibleError?.hasAttribute('role')).toBe(false)
  expect(visibleError?.hasAttribute('aria-live')).toBe(false)
  expect(slot.textContent).toContain('Photo was rejected')
  expect(byId('profile-photo-error-slot')).toBe(slot)
  const voice = byId('profile-photo-live-assertive')
  expect(voice?.getAttribute('role')).toBe('status')
  expect(voice?.getAttribute('aria-live')).toBe('polite')
  expect(voice?.textContent?.trim()).toBe('')

  act(() => flow.refusal.set(null))

  expect(byId('profile-photo-error')).toBeNull()
  const idleAfter = byId('profile-photo-error-idle')
  expect(idleAfter?.getAttribute('aria-hidden')).toBe('true')
  expect(slot.querySelector('[data-id="profile-photo-error"]')).toBeNull()
  expect(slot.textContent?.trim()).toBe('')
  expect(byId('profile-photo-error-slot')).toBe(slot)

  act(() => step.set('crop'))
  expect(byId('profile-photo-preview')).not.toBeNull()
  expect(byId('profile-photo-error-slot')).toBe(slot)

  act(() => flow.refusal.set('Photo needs a visible face'))

  const nextError = byId('profile-photo-error')
  expect(nextError?.textContent).toContain('Photo needs a visible face')
  expect(nextError?.hasAttribute('role')).toBe(false)
  expect(nextError?.hasAttribute('aria-live')).toBe(false)
  expect(slot.textContent).toContain('Photo needs a visible face')
  expect(slot.textContent).not.toContain('Photo was rejected')
  expect(byId('profile-photo-error-slot')).toBe(slot)
  expect(byId('profile-photo-live-assertive')?.textContent?.trim()).toBe('')

  act(() => flow.refusal.set(null))

  act(() => step.set('current'))
  expect(byId('profile-photo-remove')).not.toBeNull()
  expect(byId('profile-photo-error-slot')).toBe(slot)

  act(() => step.set('pick'))
  expect(byId('profile-photo-input')).not.toBeNull()
  expect(byId('profile-photo-error-slot')).toBe(slot)
})

function shown<T extends HTMLElement = HTMLElement>(id: string): T {
  const element = document.querySelector<T>(`[data-id="${id}"]`)
  if (!element) throw new Error(`Missing ${id}`)
  return element
}

function expectCloseHeld(flow: HilosProfilePhotoFlow): void {
  const root = shown('modal')
  expect(shown('profile-photo-cancel').matches(':disabled')).toBe(true)
  expect(shown('modal-close').matches(':disabled')).toBe(true)
  for (const close of [
    () => fireEvent.click(shown('profile-photo-cancel')),
    () => fireEvent.click(shown('modal-close')),
    () => fireEvent.keyDown(root, { key: 'Escape' }),
    () => fireEvent.click(root),
  ]) {
    close()
    expect(flow.step.get()).not.toBe('closed')
    expect(shown('modal')).toBe(root)
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
  }
}

function layOut(canvas: HTMLCanvasElement) {
  const capture = vi.fn()
  canvas.setPointerCapture = capture
  vi.spyOn(canvas, 'getBoundingClientRect').mockReturnValue(
    new DOMRect(0, 0, 128, 128),
  )
  return capture
}

/** Send pointer coordinates without depending on jsdom's PointerEvent support. */
function point(
  canvas: HTMLCanvasElement,
  type: 'pointerdown' | 'pointermove' | 'pointerup' | 'pointercancel',
  pointerId: number,
  clientX: number,
  clientY: number,
): void {
  const event = new MouseEvent(type, { bubbles: true, clientX, clientY })
  Object.defineProperty(event, 'pointerId', { value: pointerId })
  fireEvent(canvas, event)
}

const HELD = ['busy', 'checking'] as const

it.each(HELD)(
  'locks native controls and close paths for %s alone at every step',
  (held) => {
    const flow = photoFlow()
    render(<HilosProfilePhoto flow={flow} />)
    const input = shown('profile-photo-input')
    const room = shown('profile-photo-error-slot')
    const voice = shown('profile-photo-live-assertive')
    for (const step of ['pick', 'current', 'crop'] as const) {
      act(() => {
        flow.step.set(step)
        flow.refusal.set('Photo was rejected')
        flow.voice.set('Photo status')
        flow[held].set(true)
      })
      expect(flow[held === 'busy' ? 'checking' : 'busy'].get()).toBe(false)
      expect(shown('profile-photo-input')).toBe(input)
      expect(input.matches(':disabled')).toBe(true)
      const controls =
        step === 'pick'
          ? ['profile-photo-choose']
          : step === 'current'
            ? ['profile-photo-upload-another', 'profile-photo-remove']
            : ['profile-photo-zoom', 'profile-photo-upload-another']
      for (const id of controls)
        expect(shown(id).matches(':disabled')).toBe(true)
      expectCloseHeld(flow)
      expect(shown('profile-photo-error-slot')).toBe(room)
      expect(room.textContent).toContain('Photo was rejected')
      expect(room.closest('[aria-hidden="true"], [inert]')).toBeNull()
      expect(shown('profile-photo-live-assertive')).toBe(voice)
      expect(voice.textContent).toBe('Photo status')
      if (step === 'crop') {
        expect(shown('profile-photo-preview').tabIndex).toBe(-1)
        expect(
          shown('profile-photo-preview').getAttribute('aria-disabled'),
        ).toBe('true')
        expect(shown('profile-photo-save').matches(':disabled')).toBe(
          held === 'checking',
        )
        expect(shown('profile-photo-save').getAttribute('aria-busy')).toBe(
          held === 'busy' ? 'true' : null,
        )
        expect(
          document.querySelector('[data-id="profile-photo-checking"]') !== null,
        ).toBe(held === 'checking')
      }
      act(() => flow[held].set(false))
      for (const id of [
        'profile-photo-input',
        'profile-photo-cancel',
        'modal-close',
        ...controls,
      ]) {
        expect(shown(id).matches(':disabled')).toBe(false)
      }
      if (step === 'crop') {
        expect(shown('profile-photo-preview').tabIndex).toBe(0)
        expect(
          shown('profile-photo-preview').hasAttribute('aria-disabled'),
        ).toBe(false)
        act(() => flow.refusal.set(null))
        expect(
          shown('profile-photo-upload-another-idle').matches(':disabled'),
        ).toBe(true)
      }
    }
  },
)

it.each(HELD)(
  'guards file changes, drops and every chooser against live %s before render',
  (held) => {
    const flow = photoFlow()
    render(<HilosProfilePhoto flow={flow} />)
    const input = shown<HTMLInputElement>('profile-photo-input')
    const chooser = vi.spyOn(input, 'click')
    const file = new File(['picture'], 'another.png', { type: 'image/png' })
    for (const step of ['pick', 'current', 'crop'] as const) {
      act(() => {
        flow.step.set(step)
        flow.refusal.set('Photo was rejected')
      })
      const choose = shown(
        step === 'pick'
          ? 'profile-photo-choose'
          : 'profile-photo-upload-another',
      )
      act(() => {
        flow[held].set(true)
        // The DOM still belongs to the previous render. The handlers read core.
        expect(choose.matches(':disabled')).toBe(false)
        choose.click()
        fireEvent.change(input, { target: { files: [file] } })
        if (step === 'pick') {
          expect(
            fireEvent.drop(shown('profile-photo-drop'), {
              dataTransfer: { files: [file] },
            }),
          ).toBe(false)
        }
      })
      expect(chooser).not.toHaveBeenCalled()
      expect(flow.pick).not.toHaveBeenCalled()
      expect(input.value).toBe('')
      act(() => flow[held].set(false))
      expect(chooser).not.toHaveBeenCalled()
    }
    fireEvent.click(shown('profile-photo-upload-another'))
    expect(chooser).toHaveBeenCalledOnce()
    fireEvent.change(input, { target: { files: [file] } })
    expect(flow.pick).toHaveBeenCalledExactlyOnceWith(file)
    act(() => flow.step.set('pick'))
    fireEvent.drop(shown('profile-photo-drop'), {
      dataTransfer: { files: [file] },
    })
    expect(flow.pick).toHaveBeenCalledTimes(2)
  },
)

it.each(HELD)(
  'holds crop gestures with live %s, tracks a paused drag and cleans up held pointers',
  (held) => {
    const flow = photoFlow('crop')
    render(<HilosProfilePhoto flow={flow} />)
    const canvas = shown<HTMLCanvasElement>('profile-photo-preview')
    const capture = layOut(canvas)
    const range = shown<HTMLInputElement>('profile-photo-zoom')
    point(canvas, 'pointerdown', 1, 10, 10)
    act(() => {
      flow[held].set(true)
      expect(canvas.tabIndex).toBe(0)
      point(canvas, 'pointermove', 1, 100, 80)
      point(canvas, 'pointerdown', 2, 5, 5)
      point(canvas, 'pointermove', 2, 20, 20)
      expect(fireEvent.keyDown(canvas, { key: 'ArrowLeft' })).toBe(true)
      fireEvent.change(range, { target: { value: '2' } })
    })
    expect(flow.move).not.toHaveBeenCalled()
    expect(flow.setZoom).not.toHaveBeenCalled()
    expect(capture).toHaveBeenCalledExactlyOnceWith(1)
    act(() => flow[held].set(false))
    expect(flow.move).not.toHaveBeenCalled()
    point(canvas, 'pointermove', 1, 103, 84)
    expect(flow.move).toHaveBeenCalledExactlyOnceWith(3, 4, 128)
    for (const stop of ['pointerup', 'pointercancel'] as const) {
      act(() => flow[held].set(true))
      point(canvas, stop, 1, 103, 84)
      act(() => flow[held].set(false))
      point(canvas, 'pointermove', 1, 120, 100)
      expect(flow.move).toHaveBeenCalledTimes(1)
      point(canvas, 'pointerdown', 1, 10, 10)
    }
    expect(fireEvent.keyDown(canvas, { key: 'ArrowLeft' })).toBe(false)
    expect(flow.move).toHaveBeenLastCalledWith(-8, 0, 128)
    fireEvent.change(range, { target: { value: '3' } })
    expect(flow.setZoom).toHaveBeenCalledExactlyOnceWith(3)
  },
)

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((done) => {
    resolve = done
  })
  return { promise, resolve }
}

/** Real crop/flow/action lifecycle, with browser decoding and upload replies under test control. */
function realPhoto(initialPhoto: string | null = null) {
  vi.useFakeTimers()
  const decoded = deferred<ImageBitmap>()
  const bitmap = {
    width: 800,
    height: 600,
    close: vi.fn(),
  } as unknown as ImageBitmap
  const decode = vi
    .fn()
    .mockResolvedValue(bitmap)
    .mockImplementationOnce(() => decoded.promise)
  vi.stubGlobal('createImageBitmap', decode)
  const draw = vi.fn()
  vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
    fillRect: vi.fn(),
    drawImage: draw,
  } as unknown as CanvasRenderingContext2D)
  vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation((done) => {
    done(new Blob(['jpeg'], { type: 'image/jpeg' }))
  })
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const emit = (event: string, payload: unknown): void => {
    act(() => {
      for (const listener of listeners.get(event) ?? [])
        listener(payload as never)
    })
  }
  const sendAction = vi.fn<
    (action: string, data: unknown, requestId?: string) => boolean
  >(() => true)
  const connection = {
    state: 'connected',
    sendAction,
    on(event: string, listener: (payload: never) => void): () => void {
      const set = listeners.get(event) ?? new Set()
      set.add(listener)
      listeners.set(event, set)
      return () => {
        set.delete(listener)
      }
    },
  }
  const actions = new ActionLifecycle(
    connection as unknown as ActionLifecycleSource,
  )
  const photo = createSignal<string | null>(initialPhoto)
  const flow = createHilosProfilePhotoFlow(
    {
      connection: connection as unknown as HilosConnection,
      actions,
      scopes: new ScopeManager(),
    },
    photo,
  )
  releases.push(() => flow.dispose())
  flow.open()
  render(<HilosProfilePhoto flow={flow} />)
  return {
    flow,
    photo,
    bitmap,
    decode,
    draw,
    emit,
    sendAction,
    async decodePicked(): Promise<void> {
      await act(async () => decoded.resolve(bitmap))
    },
    async reply(
      action: string,
      outcome: 'success' | 'fail',
      reason = 'Photo was rejected',
    ): Promise<void> {
      const sent = sendAction.mock.calls
        .filter(([name]) => name === action)
        .at(-1)
      expect(sent).toBeDefined()
      await act(async () =>
        emit(outcome === 'success' ? 'actionSuccess' : 'actionError', {
          action,
          requestId: sent?.[2],
          reason,
        }),
      )
    },
    completeUpload(): void {
      act(() =>
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
        ]),
      )
    },
    checking(value: boolean): void {
      emit('projectSignal', {
        type: SIGNAL_MODERATION_CHECK,
        data: { kind: PROFILE_PHOTO_KIND, checking: value },
      })
    },
  }
}

function pickPicture(): void {
  fireEvent.change(shown('profile-photo-input'), {
    target: {
      files: [new File(['picture'], 'photo.png', { type: 'image/png' })],
    },
  })
}

it('holds the real file decode, then frees the crop with the same file input and refusal room', async () => {
  const server = realPhoto()
  const input = shown('profile-photo-input')
  const room = shown('profile-photo-error-slot')
  pickPicture()
  expect(server.flow.busy.get()).toBe(true)
  expect(server.flow.checking.get()).toBe(false)
  expect(input.matches(':disabled')).toBe(true)
  expect(shown('profile-photo-choose').matches(':disabled')).toBe(true)
  expectCloseHeld(server.flow)
  pickPicture()
  expect(server.decode).toHaveBeenCalledOnce()
  await server.decodePicked()
  expect(server.flow.step.get()).toBe('crop')
  expect(shown('profile-photo-input')).toBe(input)
  expect(shown('profile-photo-error-slot')).toBe(room)
  expect(input.matches(':disabled')).toBe(false)
  expect(shown('profile-photo-preview').tabIndex).toBe(0)
  expect(server.draw).toHaveBeenCalled()
  expect(shown('profile-photo-upload-another-idle').matches(':disabled')).toBe(
    true,
  )
})

it('holds real crop during checking alone and restores movement without discarding the picture', async () => {
  const server = realPhoto()
  pickPicture()
  await server.decodePicked()
  fireEvent.change(shown('profile-photo-zoom'), { target: { value: '2' } })
  const canvas = shown<HTMLCanvasElement>('profile-photo-preview')
  layOut(canvas)
  const crop = server.flow.crop.get()
  const preview = server.flow.preview.get()
  server.checking(true)
  expect(server.flow.busy.get()).toBe(false)
  expect(server.flow.checking.get()).toBe(true)
  expectCloseHeld(server.flow)
  for (const id of [
    'profile-photo-input',
    'profile-photo-zoom',
    'profile-photo-save',
  ])
    expect(shown(id).matches(':disabled')).toBe(true)
  expect(canvas.tabIndex).toBe(-1)
  expect(canvas.getAttribute('aria-disabled')).toBe('true')
  expect(shown('profile-photo-checking').textContent).toBe(COPY.checking)
  point(canvas, 'pointerdown', 1, 10, 10)
  point(canvas, 'pointermove', 1, 30, 30)
  fireEvent.keyDown(canvas, { key: 'ArrowLeft' })
  fireEvent.change(shown('profile-photo-zoom'), { target: { value: '3' } })
  expect(server.flow.crop.get()).toBe(crop)
  expect(server.flow.preview.get()).toBe(preview)
  expect(shown('profile-photo-preview')).toBe(canvas)
  server.checking(false)
  expect(shown('profile-photo-save').matches(':disabled')).toBe(false)
  expect(canvas.tabIndex).toBe(0)
  fireEvent.keyDown(canvas, { key: 'ArrowLeft' })
  expect(server.flow.crop.get()).not.toBe(crop)
  expect(server.flow.preview.get()?.bitmap).toBe(server.bitmap)
})

it('keeps a real save pending through upload and checking, preserves refusal/crop, and closes on retry echo', async () => {
  const server = realPhoto()
  pickPicture()
  await server.decodePicked()
  fireEvent.change(shown('profile-photo-zoom'), { target: { value: '2' } })
  const canvas = shown<HTMLCanvasElement>('profile-photo-preview')
  layOut(canvas)
  point(canvas, 'pointerdown', 1, 10, 10)
  const crop = server.flow.crop.get()
  const preview = server.flow.preview.get()
  const room = shown('profile-photo-error-slot')
  const voice = shown('profile-photo-live-assertive')
  const save = shown<HTMLButtonElement>('profile-photo-save')
  fireEvent.click(save)
  expect(server.flow.busy.get()).toBe(true)
  expect(server.flow.checking.get()).toBe(false)
  expectCloseHeld(server.flow)
  for (const elapsed of [0, 299, 1]) {
    act(() => vi.advanceTimersByTime(elapsed))
    expect(save.matches(':disabled')).toBe(false)
    expect(save.classList.contains('disabled')).toBe(elapsed === 1)
    expect(save.getAttribute('aria-busy')).toBe('true')
    expect(save.getAttribute('aria-disabled')).toBe('true')
    expect(
      save.querySelector('[data-id="loading-button-spinner"]') !== null,
    ).toBe(elapsed === 1)
  }
  fireEvent.click(save)
  await act(async () => {})
  expect(uploadFile).toHaveBeenCalledExactlyOnceWith(
    'hilos_profile_photo',
    expect.objectContaining({ name: 'photo.jpg' }),
  )
  point(canvas, 'pointermove', 1, 100, 80)
  fireEvent.keyDown(canvas, { key: 'ArrowLeft' })
  fireEvent.change(shown('profile-photo-zoom'), { target: { value: '3' } })
  pickPicture()
  expect(server.decode).toHaveBeenCalledOnce()
  expect(server.flow.crop.get()).toBe(crop)
  expect(server.flow.preview.get()).toBe(preview)
  server.completeUpload()
  expect(server.sendAction).toHaveBeenCalledExactlyOnceWith(
    PROFILE_PHOTO_SET_ACTION,
    { clientUploadId: 'upload-1' },
    expect.any(String),
  )
  await server.reply(PROFILE_PHOTO_SET_ACTION, 'success')
  server.checking(true)
  expectCloseHeld(server.flow)
  expect(server.flow.step.get()).toBe('crop')
  server.emit('actionError', {
    action: PROFILE_PHOTO_SET_ACTION,
    reason: 'This photo was not accepted.',
  })
  expect(server.flow.busy.get()).toBe(false)
  expect(server.flow.checking.get()).toBe(false)
  expect(shown('profile-photo-preview')).toBe(canvas)
  expect(server.flow.crop.get()).toBe(crop)
  expect(server.flow.preview.get()).toBe(preview)
  expect(shown('profile-photo-error-slot')).toBe(room)
  expect(room.textContent).toContain('This photo was not accepted.')
  expect(room.getAttribute('role')).toBe('alert')
  expect(shown('profile-photo-live-assertive')).toBe(voice)
  expect(voice.getAttribute('role')).toBe('status')
  expect(canvas.tabIndex).toBe(0)
  expect(canvas.hasAttribute('aria-disabled')).toBe(false)
  for (const id of [
    'profile-photo-input',
    'profile-photo-zoom',
    'profile-photo-upload-another',
    'profile-photo-save',
    'profile-photo-cancel',
    'modal-close',
  ])
    expect(shown(id).matches(':disabled')).toBe(false)
  const chooser = vi.spyOn(shown('profile-photo-input'), 'click')
  fireEvent.click(shown('profile-photo-upload-another'))
  expect(chooser).toHaveBeenCalledOnce()
  point(canvas, 'pointermove', 1, 103, 84)
  expect(server.flow.crop.get()).not.toBe(crop)
  expect(server.flow.zoom.get()).toBe(2)
  // A fresh upload gets its own completion; an old completion is not replayed.
  act(() => uploads.set([]))
  await act(async () => fireEvent.click(save))
  expect(uploadFile).toHaveBeenCalledTimes(2)
  expect(server.flow.refusal.get()).toBeNull()
  server.completeUpload()
  await server.reply(PROFILE_PHOTO_SET_ACTION, 'success')
  expect(server.flow.step.get()).toBe('crop')
  act(() => server.photo.set('/photo.webp'))
  expect(server.flow.step.get()).toBe('closed')
  expect(document.querySelector('[data-id="modal"]')).toBeNull()
  expect(voice.textContent).toBe(COPY.updated)
})

it('holds real removal until refusal or session echo and lets the owner force a pending close', async () => {
  const server = realPhoto('/photo.webp')
  const avatar = document.querySelector('[data-id="hilos-avatar"]')
  fireEvent.click(shown('profile-photo-remove'))
  expect(server.flow.busy.get()).toBe(true)
  expectCloseHeld(server.flow)
  expect(shown('profile-photo-upload-another').matches(':disabled')).toBe(true)
  expect(shown('profile-photo-remove').matches(':disabled')).toBe(true)
  await server.reply(
    PROFILE_PHOTO_REMOVE_ACTION,
    'fail',
    'Photo could not be removed.',
  )
  expect(server.flow.step.get()).toBe('current')
  expect(server.flow.busy.get()).toBe(false)
  expect(document.querySelector('[data-id="hilos-avatar"]')).toBe(avatar)
  expect(shown('profile-photo-error').textContent).toContain(
    'Photo could not be removed.',
  )
  expect(shown('profile-photo-remove').matches(':disabled')).toBe(false)
  fireEvent.click(shown('profile-photo-remove'))
  await server.reply(PROFILE_PHOTO_REMOVE_ACTION, 'success')
  expect(server.flow.step.get()).toBe('current')
  act(() => server.photo.set(null))
  expect(server.flow.step.get()).toBe('closed')
  expect(shown('profile-photo-live-assertive').textContent).toBe(COPY.removed)
  act(() => {
    server.photo.set('/another.webp')
    server.flow.open()
  })
  fireEvent.click(shown('profile-photo-remove'))
  expect(server.flow.busy.get()).toBe(true)
  act(() => server.flow.close())
  expect(server.flow.step.get()).toBe('closed')
  expect(document.querySelector('[data-id="modal"]')).toBeNull()
  await server.reply(PROFILE_PHOTO_REMOVE_ACTION, 'success')
})
