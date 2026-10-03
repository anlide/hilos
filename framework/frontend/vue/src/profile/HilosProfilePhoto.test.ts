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
})
