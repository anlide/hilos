import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import MainSkeleton from './MainSkeleton.vue'

// The real refusal row, modal and skeleton bars stay real. Only the session
// id is a double, the same signal Main.vue reads; no socket is started.
const inputs = await vi.hoisted(async () => {
  const { createSignal } =
    await vi.importActual<typeof import('@hilos/core/base')>('@hilos/core/base')
  return {
    userId: createSignal<number | null>(null),
  }
})

vi.mock('../../bootstrap/session.js', () => ({
  currentUserId: inputs.userId,
}))

enableAutoUnmount(afterEach)
afterEach(() => {
  document.body.innerHTML = ''
})

const FOCUSABLE = 'button, input, select, textarea, a, [tabindex]'

function mountSkeleton(): void {
  mount(MainSkeleton, { attachTo: document.body })
}

function byId(id: string): HTMLElement {
  const element = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (!element) throw new Error(`Missing ${id}`)
  return element
}

function stubHolding(text: string): HTMLElement {
  for (const hidden of document.querySelectorAll('.invisible')) {
    if (hidden.textContent?.trim() !== text) continue
    const stub = hidden.parentElement
    if (stub?.classList.contains('btn')) return stub
  }
  throw new Error(`Missing stub ${text}`)
}

describe('MainSkeleton composer', () => {
  it.each([
    [null, 'Send', 'Sign in to send'],
    [1, 'Sign in to send', 'Send'],
  ] as const)(
    'reserves a silent composer for user id %s',
    (userId, absent, present) => {
      inputs.userId.set(userId)
      mountSkeleton()

      const composer = byId('main-skeleton-composer')
      const root = composer.closest('[aria-hidden="true"]')
      expect(root?.getAttribute('aria-hidden')).toBe('true')

      const idle = byId('main-skeleton-message-error-idle')
      expect(idle.classList.contains('invisible')).toBe(true)
      expect(idle.getAttribute('aria-hidden')).toBe('true')
      expect(idle.querySelector('button')).toBeNull()
      expect(
        document.querySelector('[data-id="main-skeleton-message-error"]'),
      ).toBeNull()
      expect(document.querySelector('[role]')).toBeNull()
      expect(document.querySelector('[aria-live]')).toBeNull()
      expect(document.querySelector(FOCUSABLE)).toBeNull()
      expect(document.querySelector('[style]')).toBeNull()

      const bottom = composer.parentElement
      expect(bottom?.classList.contains('mt-3')).toBe(true)
      expect(bottom?.classList.contains('flex-shrink-0')).toBe(true)
      expect(bottom?.classList.contains('placeholder-glow')).toBe(false)
      expect(composer.contains(idle)).toBe(false)
      expect(idle.parentElement?.parentElement).toBe(bottom)

      const paperclip = composer.querySelector('.bi-paperclip')
      expect(paperclip?.getAttribute('aria-hidden')).toBe('true')
      expect(paperclip?.closest('.invisible')).not.toBeNull()
      const clip = paperclip?.closest('.btn')
      expect(clip?.tagName).toBe('SPAN')
      expect(clip?.classList.contains('col-1')).toBe(false)
      expect(clip?.classList.contains('flex-shrink-0')).toBe(true)

      const stub = stubHolding(present)
      expect(stub.tagName).toBe('SPAN')
      expect(stub.classList.contains('col-1')).toBe(false)
      expect(stub.classList.contains('btn-primary')).toBe(true)
      expect(stub.classList.contains('disabled')).toBe(true)
      expect(stub.classList.contains('placeholder')).toBe(true)
      expect(stub.classList.contains('flex-shrink-0')).toBe(true)
      const hidden = [...composer.querySelectorAll('.invisible')].map((node) =>
        node.textContent?.trim(),
      )
      expect(hidden).toContain(present)
      expect(hidden).not.toContain(absent)
      expect(composer.textContent).not.toContain('Sign in to send a message')
      expect(document.querySelector('.col-1')).toBeNull()

      const field = composer.querySelector('.placeholder.flex-grow-1.rounded')
      expect(field?.tagName).toBe('SPAN')
      expect(field?.classList.contains('btn')).toBe(false)
    },
  )
})
