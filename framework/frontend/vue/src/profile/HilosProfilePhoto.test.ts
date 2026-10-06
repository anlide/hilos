import {
  createSignal,
  type HilosProfilePhotoFlow,
  type HilosProfilePhotoStep,
} from '@hilos/core'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import HilosProfilePhoto from './HilosProfilePhoto.vue'

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

it('shows the picker, then current-photo controls and the crop canvas in one modal', async () => {
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
  mount(HilosProfilePhoto, { props: { flow }, attachTo: document.body })
  await nextTick()
  const byId = (id: string) =>
    document.querySelector<HTMLElement>(`[data-id="${id}"]`)

  expect(byId('profile-photo-input')?.getAttribute('accept')).toBe(
    'image/jpeg,image/png,image/webp',
  )
  expect(byId('profile-photo-drop')?.textContent).toContain('up to 40 MB')
  step.set('current')
  await nextTick()
  byId('profile-photo-remove')?.click()
  expect(remove).toHaveBeenCalledOnce()
  step.set('crop')
  await nextTick()
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
