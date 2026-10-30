import { By } from '@angular/platform-browser'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionError,
  createHilosProfilePhotoFlow,
  createSignal,
  ScopeManager,
  type ActionLifecycle,
  type HilosConnection,
  type HilosProfilePhotoFlow,
  type HilosProfilePhotoStep,
  type ModalController,
} from '@hilos/core'
import { afterEach, expect, it, vi } from 'vitest'
import { HilosModal } from '../src/HilosModal.js'
import { HilosProfilePhoto } from '../src/profile/HilosProfilePhoto.js'

afterEach(() => {
  vi.restoreAllMocks()
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

it('offers the picker, current-photo actions and an accessible crop canvas', async () => {
  const step = createSignal<HilosProfilePhotoStep>('pick')
  const remove = vi.fn(async () => {})
  const save = vi.fn(async () => {})
  const flow = {
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
    save,
    remove,
    close: () => step.set('closed'),
    dispose: vi.fn(),
  } satisfies HilosProfilePhotoFlow
  const fixture = TestBed.createComponent(HilosProfilePhoto)
  fixture.componentRef.setInput('flow', flow)
  fixture.detectChanges()
  await fixture.whenStable()
  const byId = (id: string) =>
    document.querySelector<HTMLElement>(`[data-id="${id}"]`)

  expect(byId('profile-photo-input')?.getAttribute('accept')).toBe(
    'image/jpeg,image/png,image/webp',
  )
  expect(byId('profile-photo-drop')?.textContent).toContain('up to 40 MB')
  step.set('current')
  fixture.detectChanges()
  await fixture.whenStable()
  byId('profile-photo-remove')?.click()
  expect(remove).toHaveBeenCalledOnce()
  step.set('crop')
  fixture.detectChanges()
  await fixture.whenStable()
  expect(byId('profile-photo-preview')?.getAttribute('aria-label')).toContain(
    'arrow keys',
  )
  byId('profile-photo-save')?.click()
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

  flow.checking.set(true)
  fixture.detectChanges()
  await fixture.whenStable()
  expect(byId('profile-photo-checking')?.textContent).toContain('Checking')
  expect(byId('profile-photo-checking-idle')).toBeNull()
  expect(byId('profile-photo-upload-another')).toBeNull()
  expect(byId('profile-photo-upload-another-idle')).not.toBeNull()

  flow.checking.set(false)
  flow.refusal.set('Photo was rejected')
  fixture.detectChanges()
  await fixture.whenStable()
  expect(byId('profile-photo-checking')).toBeNull()
  expect(byId('profile-photo-checking-idle')).not.toBeNull()
  expect(byId('profile-photo-upload-another-idle')).toBeNull()
  const uploadAnother = byId(
    'profile-photo-upload-another',
  ) as HTMLButtonElement | null
  expect(uploadAnother).not.toBeNull()
  expect(uploadAnother?.disabled).toBe(false)

  flow.busy.set(true)
  fixture.detectChanges()
  await fixture.whenStable()
  expect(uploadAnother?.disabled).toBe(true)
  flow.busy.set(false)
  fixture.detectChanges()
  await fixture.whenStable()
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

  fixture.destroy()
})

it('preserves refusal slot identity and live region across error states and step transitions', async () => {
  const step = createSignal<HilosProfilePhotoStep>('pick')
  const flow = {
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

  const fixture = TestBed.createComponent(HilosProfilePhoto)
  fixture.componentRef.setInput('flow', flow)
  fixture.detectChanges()
  await fixture.whenStable()
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

  flow.refusal.set('Photo was rejected')
  fixture.detectChanges()
  await fixture.whenStable()

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
  fixture.detectChanges()
  await fixture.whenStable()

  expect(byId('profile-photo-error')).toBeNull()
  const idleAfter = byId('profile-photo-error-idle')
  expect(idleAfter?.getAttribute('aria-hidden')).toBe('true')
  expect(slot.querySelector('[data-id="profile-photo-error"]')).toBeNull()
  expect(slot.textContent?.trim()).toBe('')
  expect(byId('profile-photo-error-slot')).toBe(slot)

  step.set('crop')
  fixture.detectChanges()
  await fixture.whenStable()
  expect(byId('profile-photo-preview')).not.toBeNull()
  expect(byId('profile-photo-error-slot')).toBe(slot)

  flow.refusal.set('Photo needs a visible face')
  fixture.detectChanges()
  await fixture.whenStable()

  const nextError = byId('profile-photo-error')
  expect(nextError?.textContent).toContain('Photo needs a visible face')
  expect(nextError?.hasAttribute('role')).toBe(false)
  expect(nextError?.hasAttribute('aria-live')).toBe(false)
  expect(slot.textContent).toContain('Photo needs a visible face')
  expect(slot.textContent).not.toContain('Photo was rejected')
  expect(byId('profile-photo-error-slot')).toBe(slot)
  expect(byId('profile-photo-live-assertive')?.textContent?.trim()).toBe('')

  flow.refusal.set(null)
  fixture.detectChanges()
  await fixture.whenStable()

  step.set('current')
  fixture.detectChanges()
  await fixture.whenStable()
  expect(byId('profile-photo-remove')).not.toBeNull()
  expect(byId('profile-photo-error-slot')).toBe(slot)

  step.set('pick')
  fixture.detectChanges()
  await fixture.whenStable()
  expect(byId('profile-photo-input')).not.toBeNull()
  expect(byId('profile-photo-error-slot')).toBe(slot)

  fixture.destroy()
})

async function settle(fixture: ComponentFixture<unknown>): Promise<void> {
  fixture.detectChanges()
  for (let tick = 0; tick < 10; tick += 1) await Promise.resolve()
  fixture.detectChanges()
  await fixture.whenStable()
}

function node(id: string): HTMLElement {
  return document.querySelector<HTMLElement>(`[data-id="${id}"]`)!
}

function attemptClose(fixture: ComponentFixture<unknown>): void {
  node('profile-photo-cancel').click()
  node('modal-close').click()
  node('modal').dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  node('modal').click()
  const modal = fixture.debugElement.query(By.directive(HilosModal))
    .componentInstance as unknown as { modal: ModalController }
  modal.modal.requestClose()
  fixture.detectChanges()
}

it.each(['busy', 'checking'] as const)(
  'guards the picker and canvas while %s, including a drag already in progress',
  async (lock) => {
    const step = createSignal<HilosProfilePhotoStep>('pick')
    const flow = {
      step,
      photo: createSignal<string | null>('/photo.webp'),
      name: createSignal('Ada'),
      initials: createSignal('A'),
      preview: createSignal(null),
      crop: createSignal({ zoom: 2, centerX: 80, centerY: 70 }),
      zoom: createSignal(2),
      busy: createSignal(false),
      checking: createSignal(false),
      refusal: createSignal<string | null>('Photo was rejected'),
      voice: createSignal(''),
      open: () => step.set('pick'),
      pick: vi.fn(async () => {}),
      setZoom: vi.fn(),
      move: vi.fn(),
      save: vi.fn(async () => {}),
      remove: vi.fn(async () => {}),
      close: vi.fn(() => step.set('closed')),
      dispose: vi.fn(),
    } satisfies HilosProfilePhotoFlow
    const fixture = TestBed.createComponent(HilosProfilePhoto)
    fixture.componentRef.setInput('flow', flow)
    await settle(fixture)

    const input = node('profile-photo-input') as HTMLInputElement
    const openPicker = vi.spyOn(input, 'click').mockImplementation(() => {})
    const file = new File(['picture'], 'photo.png', { type: 'image/png' })
    Object.defineProperty(input, 'files', { value: [file] })
    const dropZone = node('profile-photo-drop')
    flow[lock].set(true)
    await settle(fixture)

    expect(input.matches(':disabled')).toBe(true)
    expect(node('profile-photo-choose').matches(':disabled')).toBe(true)
    node('profile-photo-choose').dispatchEvent(new MouseEvent('click'))
    input.dispatchEvent(new Event('change'))
    const drop = new Event('drop', { cancelable: true })
    Object.defineProperty(drop, 'dataTransfer', { value: { files: [file] } })
    dropZone.dispatchEvent(drop)
    expect(drop.defaultPrevented).toBe(true)
    expect(openPicker).not.toHaveBeenCalled()
    expect(flow.pick).not.toHaveBeenCalled()
    attemptClose(fixture)
    expect(flow.close).not.toHaveBeenCalled()

    flow[lock].set(false)
    await settle(fixture)
    node('profile-photo-choose').click()
    input.dispatchEvent(new Event('change'))
    expect(openPicker).toHaveBeenCalledOnce()
    expect(flow.pick).toHaveBeenCalledWith(file)

    step.set('crop')
    await settle(fixture)
    const canvas = node('profile-photo-preview') as HTMLCanvasElement
    const range = node('profile-photo-zoom') as HTMLInputElement
    const errorSlot = node('profile-photo-error-slot')
    const capture = vi.fn()
    canvas.setPointerCapture = capture
    vi.spyOn(canvas, 'getBoundingClientRect').mockReturnValue(
      new DOMRect(0, 0, 128, 128),
    )
    const pointer = (type: string, x: number): void => {
      const event = new MouseEvent(type, { clientX: x, clientY: 10 })
      Object.defineProperty(event, 'pointerId', { value: 1 })
      canvas.dispatchEvent(event)
    }
    expect(canvas.tabIndex).toBe(0)
    expect(canvas.hasAttribute('aria-disabled')).toBe(false)
    pointer('pointerdown', 10)
    pointer('pointermove', 20)
    expect(flow.move).toHaveBeenCalledWith(10, 0, 128)
    flow.move.mockClear()
    capture.mockClear()
    const crop = flow.crop.get()
    flow[lock].set(true)
    await settle(fixture)

    expect(node('profile-photo-preview')).toBe(canvas)
    expect(canvas.tabIndex).toBe(-1)
    expect(canvas.getAttribute('aria-disabled')).toBe('true')
    expect(range.matches(':disabled')).toBe(true)
    expect(range.value).toBe('2')
    expect(node('profile-photo-cancel').matches(':disabled')).toBe(true)
    expect(node('modal-close').matches(':disabled')).toBe(true)
    expect(node('profile-photo-save').matches(':disabled')).toBe(
      lock === 'checking',
    )
    expect(node('profile-photo-upload-another').matches(':disabled')).toBe(true)
    pointer('pointermove', 30)
    pointer('pointerdown', 40)
    canvas.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'ArrowRight', cancelable: true }),
    )
    range.dispatchEvent(new Event('input'))
    node('profile-photo-upload-another').dispatchEvent(new MouseEvent('click'))
    expect(capture).not.toHaveBeenCalled()
    expect(flow.move).not.toHaveBeenCalled()
    expect(flow.setZoom).not.toHaveBeenCalled()
    expect(openPicker).toHaveBeenCalledOnce()
    expect(flow.crop.get()).toBe(crop)
    expect(node('profile-photo-error-slot')).toBe(errorSlot)
    expect(errorSlot.textContent).toContain('Photo was rejected')
    attemptClose(fixture)
    expect(flow.close).not.toHaveBeenCalled()
    expect(node('modal-confirm')).toBeNull()

    pointer('pointercancel', 40)
    flow[lock].set(false)
    await settle(fixture)
    pointer('pointermove', 50)
    expect(flow.move).not.toHaveBeenCalled()
    expect(node('profile-photo-preview')).toBe(canvas)
    expect(canvas.tabIndex).toBe(0)
    expect(canvas.hasAttribute('aria-disabled')).toBe(false)
    expect(range.matches(':disabled')).toBe(false)
    expect(node('profile-photo-cancel').matches(':disabled')).toBe(false)
    canvas.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft' }))
    range.value = '3'
    range.dispatchEvent(new Event('input'))
    pointer('pointerdown', 10)
    pointer('pointermove', 20)
    pointer('pointerup', 20)
    pointer('pointermove', 30)
    expect(flow.move).toHaveBeenCalledTimes(2)
    expect(flow.move).toHaveBeenCalledWith(-8, 0, 128)
    expect(flow.setZoom).toHaveBeenCalledWith(3)
    expect(capture).toHaveBeenCalledOnce()

    step.set('current')
    flow[lock].set(true)
    await settle(fixture)
    expect(node('profile-photo-upload-another').matches(':disabled')).toBe(true)
    expect(node('profile-photo-remove').matches(':disabled')).toBe(true)
    node('profile-photo-remove').click()
    expect(flow.remove).not.toHaveBeenCalled()
    flow[lock].set(false)
    await settle(fixture)
    node('profile-photo-cancel').click()
    expect(flow.close).toHaveBeenCalledOnce()
    fixture.destroy()
  },
)

it('holds a real removal request, unlocks on refusal and closes on the session echo', async () => {
  let resolve!: (value: object) => void
  let reject!: (error: unknown) => void
  let response = new Promise<object>((accept, refuse) => {
    resolve = accept
    reject = refuse
  })
  const dispatch = vi.fn(() => ({ done: response }))
  const photo = createSignal<string | null>('/photo.webp')
  const flow = createHilosProfilePhotoFlow(
    {
      connection: { on: () => () => {} } as unknown as HilosConnection,
      scopes: new ScopeManager(),
      actions: { dispatch } as unknown as ActionLifecycle,
    },
    photo,
  )
  flow.open()
  const fixture = TestBed.createComponent(HilosProfilePhoto)
  fixture.componentRef.setInput('flow', flow)
  await settle(fixture)
  const current = node('profile-photo-remove')
  node('profile-photo-remove').click()
  await settle(fixture)
  expect(flow.busy.get()).toBe(true)
  expect(current.matches(':disabled')).toBe(true)
  attemptClose(fixture)
  expect(flow.step.get()).toBe('current')
  expect(node('profile-photo-remove')).toBe(current)
  expect(dispatch).toHaveBeenCalledOnce()

  reject(new ActionError('hilos_profile_photo_remove', 'fail', 'Try again'))
  await settle(fixture)
  expect(flow.busy.get()).toBe(false)
  expect(node('profile-photo-remove')).toBe(current)
  expect(current.matches(':disabled')).toBe(false)
  expect(node('profile-photo-error').textContent).toContain('Try again')
  expect(node('modal')).not.toBeNull()
  expect(node('modal-confirm')).toBeNull()
  response = new Promise<object>((accept) => {
    resolve = accept
  })
  current.click()
  await settle(fixture)
  expect(dispatch).toHaveBeenCalledTimes(2)
  resolve({})
  await settle(fixture)
  expect(flow.busy.get()).toBe(true)
  photo.set(null)
  await settle(fixture)
  expect(node('modal')).toBeNull()
  flow.dispose()
  fixture.destroy()
})
