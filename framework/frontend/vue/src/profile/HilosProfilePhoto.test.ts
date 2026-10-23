// The profile photo window over a flow standing at a step: the picker, the
// current photo and the crop in one modal, the refusal kept in its standing
// room, and the window held - closing, crop, file and drop - while a request or
// the moderator's check of the photo is out (HIL-1763).
import {
  createSignal,
  type HilosProfilePhotoFlow,
  type HilosProfilePhotoStep,
} from '@hilos/core/base'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import HilosProfilePhoto from './HilosProfilePhoto.vue'

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

/** The two ways the window is held: a request in flight, and the photo's check. */
const HELD = ['busy', 'checking'] as const

/**
 * A photo flow standing at a step, its signals the test's to set and its
 * actions recorded.
 *
 * @param at The step the window stands at.
 */
function photoFlow(at: HilosProfilePhotoStep) {
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

function byId(id: string): HTMLElement | null {
  return document.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

/** The node behind a data-id the window has to be showing. */
function shown(id: string): HTMLElement {
  const found = byId(id)
  if (found === null) {
    throw new Error(`no ${id} in the window`)
  }

  return found
}

// The fieldset the modal stands its body in; a request in flight disables it.
function bodyLock(): HTMLFieldSetElement | null {
  return document.querySelector('[data-id="modal"] .modal-body > fieldset')
}

/**
 * Expect the window held: the body locked, Cancel and the × off, and the ×,
 * Cancel, Escape and the backdrop all leaving it open with no Discard step.
 *
 * @param flow The flow under the window.
 */
async function expectHeld(flow: ReturnType<typeof photoFlow>): Promise<void> {
  expect(bodyLock()?.disabled).toBe(true)
  expect((shown('profile-photo-cancel') as HTMLButtonElement).disabled).toBe(
    true,
  )
  expect((shown('modal-close') as HTMLButtonElement).disabled).toBe(true)
  shown('modal-close').click()
  shown('profile-photo-cancel').click()
  shown('modal').dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  shown('modal').click()
  await flushPromises()
  expect(flow.step.get()).not.toBe('closed')
  expect(byId('modal')).not.toBeNull()
  expect(byId('modal-confirm')).toBeNull()
}

/** Expect the window free again: the body, Cancel and the × all live. */
function expectFreed(): void {
  expect(bodyLock()?.disabled).toBe(false)
  expect((shown('profile-photo-cancel') as HTMLButtonElement).disabled).toBe(
    false,
  )
  expect((shown('modal-close') as HTMLButtonElement).disabled).toBe(false)
}

/**
 * Stand the crop canvas in for a laid-out one: 128 px square, the pointer
 * captured by a mock.
 *
 * @param canvas The window's crop canvas.
 */
function layOut(canvas: HTMLElement): ReturnType<typeof vi.fn> {
  const capture = vi.fn()
  canvas.setPointerCapture = capture
  vi.spyOn(canvas, 'getBoundingClientRect').mockReturnValue(
    new DOMRect(0, 0, 128, 128),
  )

  return capture
}

/**
 * Send a pointer event to the canvas.
 *
 * @param canvas The window's crop canvas.
 * @param type The pointer event's type.
 * @param pointerId The pointer it comes from.
 * @param clientX Where the pointer stands across.
 * @param clientY Where the pointer stands down.
 */
function point(
  canvas: HTMLElement,
  type: 'pointerdown' | 'pointermove' | 'pointerup',
  pointerId: number,
  clientX: number,
  clientY: number,
): void {
  canvas.dispatchEvent(
    new PointerEvent(type, { pointerId, clientX, clientY, bubbles: true }),
  )
}

/**
 * Press the left arrow on the canvas.
 *
 * @param canvas The window's crop canvas.
 * @returns The key event, to read whether the canvas took it.
 */
function pressLeft(canvas: HTMLElement): KeyboardEvent {
  const key = new KeyboardEvent('keydown', {
    key: 'ArrowLeft',
    bubbles: true,
    cancelable: true,
  })
  canvas.dispatchEvent(key)

  return key
}

/**
 * A file dropped on the drop zone.
 *
 * @param file The file the drop carries.
 */
function dropOf(file: File): Event {
  const drop = new Event('drop', { bubbles: true, cancelable: true })
  Object.defineProperty(drop, 'dataTransfer', { value: { files: [file] } })

  return drop
}

it('shows the picker, then current-photo controls and the crop canvas in one modal', async () => {
  const flow = photoFlow('pick')
  mount(HilosProfilePhoto, { props: { flow }, attachTo: document.body })
  await nextTick()

  expect(byId('profile-photo-input')?.getAttribute('accept')).toBe(
    'image/jpeg,image/png,image/webp',
  )
  expect(byId('profile-photo-drop')?.textContent).toContain('up to 40 MB')
  flow.step.set('current')
  await nextTick()
  byId('profile-photo-remove')?.click()
  expect(flow.remove).toHaveBeenCalledOnce()
  flow.step.set('crop')
  await nextTick()
  expect(byId('profile-photo-preview')?.getAttribute('aria-label')).toContain(
    'arrow keys',
  )
  byId('profile-photo-save')?.click()
  expect(flow.save).toHaveBeenCalledOnce()

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

  flow.checking.set(true)
  await nextTick()
  expect(byId('profile-photo-checking')?.textContent).toContain('Checking')
  expect(byId('profile-photo-checking-idle')).toBeNull()
  expect(byId('profile-photo-upload-another')).toBeNull()
  expect(byId('profile-photo-upload-another-idle')).not.toBeNull()

  flow.checking.set(false)
  flow.refusal.set('Photo was rejected')
  await nextTick()
  expect(byId('profile-photo-checking')).toBeNull()
  expect(byId('profile-photo-checking-idle')).not.toBeNull()
  expect(byId('profile-photo-upload-another-idle')).toBeNull()
  const uploadAnother = byId(
    'profile-photo-upload-another',
  ) as HTMLButtonElement | null
  expect(uploadAnother).not.toBeNull()
  expect(uploadAnother?.disabled).toBe(false)

  flow.busy.set(true)
  await nextTick()
  expect(uploadAnother?.disabled).toBe(true)
  flow.busy.set(false)
  await nextTick()
  expect(uploadAnother?.disabled).toBe(false)

  const fileInput = byId('profile-photo-input') as HTMLInputElement
  const clickSpy = vi.spyOn(fileInput, 'click')
  uploadAnother?.click()
  expect(clickSpy).toHaveBeenCalledOnce()

  fileInput.dispatchEvent(new Event('change'))
  expect(flow.pick).not.toHaveBeenCalled()
  expect(byId('profile-photo-preview')).not.toBeNull()

  const file = new File(['data'], 'new.png', { type: 'image/png' })
  Object.defineProperty(fileInput, 'files', {
    value: [file],
    configurable: true,
  })
  fileInput.dispatchEvent(new Event('change'))
  expect(flow.pick).toHaveBeenCalledWith(file)
  expect(byId('profile-photo-preview')).not.toBeNull()
})

it('preserves refusal slot identity and live region across error states and step transitions', async () => {
  const flow = photoFlow('pick')
  mount(HilosProfilePhoto, { props: { flow }, attachTo: document.body })
  await nextTick()

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

  flow.refusal.set('Photo was rejected')
  await nextTick()

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

  flow.refusal.set(null)
  await nextTick()

  expect(byId('profile-photo-error')).toBeNull()
  const idleAfter = byId('profile-photo-error-idle')
  expect(idleAfter?.getAttribute('aria-hidden')).toBe('true')
  expect(slot.querySelector('[data-id="profile-photo-error"]')).toBeNull()
  expect(slot.textContent?.trim()).toBe('')
  expect(byId('profile-photo-error-slot')).toBe(slot)

  flow.step.set('crop')
  await nextTick()
  expect(byId('profile-photo-preview')).not.toBeNull()
  expect(byId('profile-photo-error-slot')).toBe(slot)

  flow.refusal.set('Photo needs a visible face')
  await nextTick()

  const nextError = byId('profile-photo-error')
  expect(nextError?.textContent).toContain('Photo needs a visible face')
  expect(nextError?.hasAttribute('role')).toBe(false)
  expect(nextError?.hasAttribute('aria-live')).toBe(false)
  expect(slot.textContent).toContain('Photo needs a visible face')
  expect(slot.textContent).not.toContain('Photo was rejected')
  expect(byId('profile-photo-error-slot')).toBe(slot)
  expect(byId('profile-photo-live-assertive')?.textContent?.trim()).toBe('')

  flow.refusal.set(null)
  await nextTick()

  flow.step.set('current')
  await nextTick()
  expect(byId('profile-photo-remove')).not.toBeNull()
  expect(byId('profile-photo-error-slot')).toBe(slot)

  flow.step.set('pick')
  await nextTick()
  expect(byId('profile-photo-input')).not.toBeNull()
  expect(byId('profile-photo-error-slot')).toBe(slot)
})

it.each(HELD)(
  'holds the window and locks the zoom while %s is on, and lets it close once that clears',
  async (held) => {
    const flow = photoFlow('crop')
    mount(HilosProfilePhoto, { props: { flow }, attachTo: document.body })
    await nextTick()
    const zoom = shown('profile-photo-zoom') as HTMLInputElement
    expect(bodyLock()?.contains(zoom)).toBe(true)
    expectFreed()

    flow[held].set(true)
    await nextTick()
    await expectHeld(flow)
    expect(zoom.disabled).toBe(true)

    flow[held].set(false)
    await nextTick()
    expectFreed()
    expect(zoom.disabled).toBe(false)
    shown('profile-photo-cancel').click()
    await flushPromises()
    expect(flow.step.get()).toBe('closed')
    expect(byId('modal')).toBeNull()
  },
)

it.each(HELD)(
  'keeps the crop still on the canvas while %s is on, and lets it move again once that clears',
  async (held) => {
    const flow = photoFlow('crop')
    mount(HilosProfilePhoto, { props: { flow }, attachTo: document.body })
    await nextTick()
    const canvas = shown('profile-photo-preview')
    const capture = layOut(canvas)
    expect(canvas.getAttribute('tabindex')).toBe('0')
    expect(canvas.hasAttribute('aria-disabled')).toBe(false)

    // A drag begun before the window is held moves nothing while it is, and
    // goes on from where the pointer stands, with no jump, once it frees.
    point(canvas, 'pointerdown', 1, 10, 10)
    flow[held].set(true)
    await nextTick()
    point(canvas, 'pointermove', 1, 30, 20)
    expect(flow.move).not.toHaveBeenCalled()
    flow[held].set(false)
    await nextTick()
    point(canvas, 'pointermove', 1, 33, 24)
    expect(flow.move).toHaveBeenCalledOnce()
    expect(flow.move).toHaveBeenLastCalledWith(3, 4, 128)

    flow[held].set(true)
    await nextTick()
    expect(canvas.getAttribute('tabindex')).toBe('-1')
    expect(canvas.getAttribute('aria-disabled')).toBe('true')
    point(canvas, 'pointermove', 1, 40, 30)
    point(canvas, 'pointerup', 1, 40, 30)
    point(canvas, 'pointerdown', 2, 50, 50)
    point(canvas, 'pointermove', 2, 60, 60)
    expect(pressLeft(canvas).defaultPrevented).toBe(false)
    expect(flow.move).toHaveBeenCalledOnce()
    expect(capture).toHaveBeenCalledOnce()
    expect(flow.setZoom).not.toHaveBeenCalled()

    flow[held].set(false)
    await nextTick()
    expect(canvas.getAttribute('tabindex')).toBe('0')
    expect(canvas.hasAttribute('aria-disabled')).toBe(false)
    point(canvas, 'pointermove', 1, 45, 35)
    point(canvas, 'pointermove', 2, 65, 65)
    expect(flow.move).toHaveBeenCalledOnce()

    point(canvas, 'pointerdown', 3, 10, 10)
    point(canvas, 'pointermove', 3, 12, 11)
    expect(flow.move).toHaveBeenLastCalledWith(2, 1, 128)
    point(canvas, 'pointerup', 3, 12, 11)
    expect(pressLeft(canvas).defaultPrevented).toBe(true)
    expect(flow.move).toHaveBeenLastCalledWith(-8, 0, 128)
    expect(flow.move).toHaveBeenCalledTimes(3)
  },
)

it.each(HELD)(
  'picks no file by choice or drop while %s is on, and picks again once that clears',
  async (held) => {
    const flow = photoFlow('pick')
    mount(HilosProfilePhoto, { props: { flow }, attachTo: document.body })
    await nextTick()
    const fileInput = shown('profile-photo-input') as HTMLInputElement
    const chooser = vi.spyOn(fileInput, 'click')
    const file = new File(['data'], 'new.png', { type: 'image/png' })
    Object.defineProperty(fileInput, 'files', {
      value: [file],
      configurable: true,
    })

    flow[held].set(true)
    await nextTick()
    await expectHeld(flow)
    expect(bodyLock()?.contains(shown('profile-photo-choose'))).toBe(true)
    shown('profile-photo-choose').click()
    shown('profile-photo-drop').dispatchEvent(dropOf(file))
    fileInput.dispatchEvent(new Event('change'))
    expect(chooser).not.toHaveBeenCalled()
    expect(flow.pick).not.toHaveBeenCalled()

    flow[held].set(false)
    await nextTick()
    expectFreed()
    shown('profile-photo-choose').click()
    expect(chooser).toHaveBeenCalledOnce()
    shown('profile-photo-drop').dispatchEvent(dropOf(file))
    expect(flow.pick).toHaveBeenCalledOnce()
    expect(flow.pick).toHaveBeenLastCalledWith(file)
    fileInput.dispatchEvent(new Event('change'))
    expect(flow.pick).toHaveBeenCalledTimes(2)
  },
)

it.each(HELD)(
  'keeps the crop and the refusal in place when what held the window as %s is refused',
  async (held) => {
    const flow = photoFlow('crop')
    flow.zoom.set(1.5)
    mount(HilosProfilePhoto, { props: { flow }, attachTo: document.body })
    await nextTick()
    const canvas = shown('profile-photo-preview')
    const zoom = shown('profile-photo-zoom') as HTMLInputElement
    const slot = shown('profile-photo-error-slot')
    flow[held].set(true)
    await nextTick()
    expect(slot.textContent?.trim()).toBe('')

    flow[held].set(false)
    flow.refusal.set('Photo needs a visible face')
    await nextTick()
    expect(flow.step.get()).toBe('crop')
    expect(byId('profile-photo-preview')).toBe(canvas)
    expect(canvas.getAttribute('tabindex')).toBe('0')
    expect(byId('profile-photo-zoom')).toBe(zoom)
    expect(zoom.value).toBe('1.5')
    expect(zoom.disabled).toBe(false)
    expectFreed()
    expect(byId('profile-photo-error-slot')).toBe(slot)
    expect(slot.textContent).toContain('Photo needs a visible face')
    expect(
      (shown('profile-photo-upload-another') as HTMLButtonElement).disabled,
    ).toBe(false)
    expect(flow.move).not.toHaveBeenCalled()
    expect(flow.setZoom).not.toHaveBeenCalled()
  },
)
