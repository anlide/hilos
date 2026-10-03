import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  createSignal,
  type HilosProfilePhotoFlow,
  type HilosProfilePhotoStep,
} from '@hilos/core'
import { afterEach, expect, it, vi } from 'vitest'
import { HilosProfilePhoto } from '../src/profile/HilosProfilePhoto.js'

afterEach(cleanup)

it('offers the picker, current-photo actions and an accessible crop canvas', () => {
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
})
