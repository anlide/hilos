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
  expect(slot.textContent).toContain('Photo was rejected')
  expect(byId('profile-photo-error-slot')).toBe(slot)

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

  act(() => step.set('current'))
  expect(byId('profile-photo-remove')).not.toBeNull()
  expect(byId('profile-photo-error-slot')).toBe(slot)

  act(() => step.set('pick'))
  expect(byId('profile-photo-input')).not.toBeNull()
  expect(byId('profile-photo-error-slot')).toBe(slot)
})
