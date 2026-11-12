// Shared behavior of each SDK's real decorative avatar.
import type { HilosAvatarMark } from '@hilos/core/base'
import type * as Vitest from 'vitest'

type AvatarTestRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

/** Inputs exercised by the shared avatar cases. */
export interface AvatarInput {
  name: string
  photo?: string | null
  icon?: string | null
  mark?: HilosAvatarMark | null
}

/** The real avatar and its framework's input and event delivery. */
export interface AvatarView {
  readonly root: HTMLElement
  update(patch: Partial<AvatarInput>): Promise<void>
  imageError(image: Element): Promise<void>
}

/** Own the component's whole lifetime, including failed mounts and updates. */
export type WithAvatar = (
  input: AvatarInput,
  steps: (view: AvatarView) => Promise<void>,
) => Promise<void>

/** Register the union of avatar behavior and accessibility checks. */
export function registerAvatarCases(
  sdk: string,
  runner: AvatarTestRunner,
  withAvatar: WithAvatar,
): void {
  const { describe, it, expect } = runner

  describe(`HilosAvatar on ${sdk}`, () => {
    it('draws Unicode initials without imposing a role, title or focus target', async () => {
      await withAvatar({ name: 'Мария Ковалёва' }, async ({ root }) => {
        const circle = root.querySelector('[data-id="hilos-avatar"]')!
        expect(circle.textContent?.trim()).toBe('МК')
        expect(circle.getAttribute('data-id')).toBe('hilos-avatar')
        expect(circle.getAttribute('aria-hidden')).toBe('true')
        expect(circle.hasAttribute('role')).toBe(false)
        expect(circle.hasAttribute('tabindex')).toBe(false)
        expect(circle.hasAttribute('title')).toBe(false)
      })
    })

    it.each(['', '🤖'])('draws the person icon for %j', async (name) => {
      await withAvatar({ name }, async ({ root }) => {
        expect(root.querySelector('i.bi-person')).not.toBeNull()
        expect(root.textContent?.trim()).toBe('')
      })
    })

    it('draws no mark by default', async () => {
      await withAvatar({ name: 'Ada' }, async ({ root }) => {
        expect(root.querySelector('[data-id="avatar-mark"]')).toBeNull()
      })
    })

    it.each([
      { tone: 'warning', icon: 'bi-trash' },
      { tone: 'danger', icon: 'bi-people-fill' },
      { tone: 'info', icon: 'bi-people-fill' },
    ] as const)(
      'draws the supplied decorative mark %j until cleared',
      async (mark) => {
        await withAvatar({ name: 'Ada', mark }, async (view) => {
          const circle = view.root.querySelector('[data-id="hilos-avatar"]')!
          const icon = circle.querySelector('[data-id="avatar-mark"]')!
          expect(icon.classList.contains(mark.icon)).toBe(true)
          expect(circle.textContent?.trim()).toBe('A')
          expect(circle.getAttribute('aria-hidden')).toBe('true')

          await view.update({ mark: null })
          expect(view.root.querySelector('[data-id="avatar-mark"]')).toBeNull()
        })
      },
    )

    it('updates the initials and fallback when the name changes', async () => {
      await withAvatar({ name: 'Alexander Baranov' }, async (view) => {
        expect(view.root.textContent?.trim()).toBe('AB')
        await view.update({ name: 'Мария Ковалёва' })
        expect(view.root.textContent?.trim()).toBe('МК')
        await view.update({ name: '🤖' })
        expect(view.root.textContent?.trim()).toBe('')
        expect(view.root.querySelector('i.bi-person')).not.toBeNull()
        await view.update({ name: 'Alexander' })
        expect(view.root.textContent?.trim()).toBe('A')
        expect(view.root.querySelector('i.bi-person')).toBeNull()
      })
    })

    it('shows a decorative photo, falls back on error, and tries the next photo', async () => {
      await withAvatar(
        { name: 'Ada Baranov', photo: '/a.webp' },
        async (view) => {
          const image = view.root.querySelector(
            '[data-id="hilos-avatar-photo"]',
          )!
          expect(image.getAttribute('alt')).toBe('')
          expect(image.getAttribute('src')).toBe('/a.webp')
          expect(view.root.textContent?.trim()).toBe('')

          await view.imageError(image)
          expect(view.root.querySelector('img')).toBeNull()
          expect(view.root.textContent?.trim()).toBe('AB')

          await view.update({ photo: '/b.webp' })
          expect(view.root.querySelector('img')?.getAttribute('src')).toBe(
            '/b.webp',
          )
        },
      )
    })

    it('draws the icon instead of the photo and the initials until cleared', async () => {
      await withAvatar(
        { name: 'Ada Baranov', photo: '/a.webp', icon: 'bi-sign-merge-left' },
        async (view) => {
          const circle = view.root.querySelector('[data-id="hilos-avatar"]')!
          const icon = circle.querySelector('[data-id="hilos-avatar-icon"]')!
          expect(icon.classList.contains('bi-sign-merge-left')).toBe(true)
          expect(circle.querySelector('img')).toBeNull()
          expect(circle.querySelector('i.bi-person')).toBeNull()
          expect(circle.textContent?.trim()).toBe('')
          expect(circle.getAttribute('aria-hidden')).toBe('true')

          await view.update({ icon: null })
          expect(
            view.root.querySelector('[data-id="hilos-avatar-icon"]'),
          ).toBeNull()
          expect(view.root.querySelector('img')?.getAttribute('src')).toBe(
            '/a.webp',
          )
        },
      )
    })
  })
}
