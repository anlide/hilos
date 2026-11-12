// One semantic union of live deletion, shell and modal draft behavior.
import { HilosPages, lazyPage, resolveHilosPath } from '@hilos/core/base'
import type * as Vitest from 'vitest'

import {
  CARD_DELETED,
  CARD_IDENTITY,
  CARD_NOT_FOUND,
  deletionRouter,
  type DeletionOutlet,
  type HilosViewDeletionKit,
  type WithHilosViewDeletion,
} from './hilosViewDeletionFixtures.js'

type DeletionRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>
type DeletionControl = ReturnType<typeof deletionRouter>
type CardKind = 'eager' | 'lazy'

const find = (root: ParentNode, id: string) =>
  root.querySelector<HTMLElement>(`[data-id="${id}"]`)
const draftInput = () =>
  document.querySelector<HTMLInputElement>('[data-id="draft"]')
const pageErrors = () => document.querySelectorAll('[data-id="page-error"]')

async function run<T>(
  withKit: WithHilosViewDeletion<T>,
  steps: (
    kit: HilosViewDeletionKit<T>,
    control: DeletionControl,
  ) => Promise<void>,
) {
  const control = deletionRouter()
  try {
    await withKit((kit) => steps(kit, control))
  } finally {
    control.router.stop()
  }
}

/** Preconditions stay here as assertions, rather than becoming adapter outcomes. */
async function openCard<T>(
  expect: DeletionRunner['expect'],
  kit: HilosViewDeletionKit<T>,
  control: DeletionControl,
  preserve = true,
  kind: CardKind = 'eager',
  checkInitialMounts = (mounts: number) => expect(mounts).toBe(1),
) {
  const page = kit.card(preserve)
  control.pageIdentity.set(CARD_IDENTITY)
  const view = await kit.mount({
    router: control.router,
    pages: {
      main: kit.main,
      lazy:
        kind === 'eager'
          ? page.component
          : lazyPage(() => Promise.resolve(page.component)),
      [HilosPages.PUBLIC_STATUS]: kit.status,
    },
  })
  await view.flush(control.answer)
  const mounts = page.mounted.mock.calls.length
  checkInitialMounts(mounts)
  const opener = find(view.root, 'open-draft')
  expect(opener).not.toBeNull()
  await view.flush(() => {
    opener!.focus()
    opener!.click()
  })
  const draft = draftInput()
  expect(draft).not.toBeNull()
  await view.type(draft!, 'my draft')
  expect(draft!.value).toBe('my draft')
  expect(document.activeElement).toBe(draft)
  return { view, page, mounts, draft: draft! }
}

/** D01 is also the continuation of React's local StrictMode registration. */
export async function runHilosViewDeletionD01<T>(
  { expect }: Pick<DeletionRunner, 'expect'>,
  withKit: WithHilosViewDeletion<T>,
  kind: CardKind,
  checkInitialMounts?: (mounts: number) => void,
): Promise<void> {
  await run(withKit, async (kit, control) => {
    const { view, page, mounts, draft } = await openCard(
      expect,
      kit,
      control,
      true,
      kind,
      checkInitialMounts,
    )
    expect(find(view.root, 'hilos-admin-title')?.textContent?.trim()).toBe(
      'Poland',
    )
    await view.flush(() => control.pageError.set(CARD_DELETED))
    expect(page.mounted).toHaveBeenCalledTimes(mounts)
    expect(draftInput()).toBe(draft)
    expect(draft.value).toBe('my draft')
    expect(document.activeElement).toBe(draft)
    expect(pageErrors()).toHaveLength(1)
    const shell = find(view.root, 'hilos-admin-page')
    expect(shell?.contains(pageErrors()[0])).toBe(true)
    expect(
      shell
        ?.querySelector('[data-id="page-error-transition-message"]')
        ?.textContent?.trim(),
    ).toBe('It was deleted just now.')
    for (const gone of [
      'hilos-breadcrumb',
      'hilos-admin-title',
      'page-body',
      'open-draft',
    ]) {
      expect(find(view.root, gone)).toBeNull()
    }
    expect(document.querySelectorAll('h1')).toHaveLength(1)
    expect(
      find(view.root, 'hilos-page-state')?.getAttribute('data-state'),
    ).toBe('error')
  })
}

/** Register the same scenarios against each SDK's real View, AdminPage and Modal. */
export function registerHilosViewDeletionCases<T>(
  sdk: string,
  { describe, it, expect }: DeletionRunner,
  withKit: WithHilosViewDeletion<T>,
): void {
  const open = (
    kit: HilosViewDeletionKit<T>,
    control: DeletionControl,
    preserve = true,
  ) => openCard(expect, kit, control, preserve)
  const deleted = (view: DeletionOutlet, control: DeletionControl) =>
    view.flush(() => control.pageError.set(CARD_DELETED))

  describe(`HilosView live deletion on ${sdk}`, () => {
    it.each(['eager', 'lazy'] as const)(
      'D01 keeps the %s card and its draft while the shell draws one error',
      (kind) => runHilosViewDeletionD01({ expect }, withKit, kind),
    )

    it('D02 keeps the same shell and draft through a later plain 404', async () => {
      await run(withKit, async (kit, control) => {
        const { view, page, draft } = await open(kit, control)
        const shell = find(view.root, 'hilos-admin-page')
        await deleted(view, control)
        await view.flush(() => control.pageError.set({ ...CARD_NOT_FOUND }))
        expect(find(view.root, 'page-error-transition-message')).toBeNull()
        expect(pageErrors()).toHaveLength(1)
        expect(find(view.root, 'hilos-admin-page')).toBe(shell)
        expect(shell?.contains(pageErrors()[0])).toBe(true)
        expect(page.mounted).toHaveBeenCalledTimes(1)
        expect(draftInput()).toBe(draft)
        expect(draft.value).toBe('my draft')
      })
    })

    it.each([
      { httpCode: 401, errorCode: 'unauthorized' },
      { httpCode: 403, errorCode: 'forbidden' },
      { httpCode: 503, errorCode: 'unavailable' },
    ])(
      'D03 releases the held shell and modal on $httpCode',
      async ({ httpCode, errorCode }) => {
        await run(withKit, async (kit, control) => {
          const { view } = await open(kit, control)
          await deleted(view, control)
          await view.flush(() =>
            control.pageError.set({
              ...CARD_NOT_FOUND,
              httpCode,
              errorCode,
              title: 'Refused',
              message: 'Server refusal words',
            }),
          )
          expect(find(view.root, 'hilos-admin-page')).toBeNull()
          expect(find(document, 'modal')).toBeNull()
          expect(pageErrors()).toHaveLength(1)
          expect(pageErrors()[0].getAttribute('data-error-code')).toBe(
            String(httpCode),
          )
          expect(
            [...pageErrors()[0].querySelectorAll('p')].map((node) =>
              node.textContent?.trim(),
            ),
          ).toEqual(['Refused', 'Server refusal words'])
        })
      },
    )

    it('D04 releases the held shell and modal after home click and answer', async () => {
      await run(withKit, async (kit, control) => {
        const { view } = await open(kit, control)
        await deleted(view, control)
        await view.flush(() => find(view.root, 'page-error-home')!.click())
        await view.flush(control.answer)
        expect(find(view.root, 'main')).not.toBeNull()
        expect(find(view.root, 'hilos-admin-page')).toBeNull()
        expect(find(document, 'modal')).toBeNull()
        expect(pageErrors()).toHaveLength(0)
      })
    })

    it('D05 leaves the held card and modal for the public file reader', async () => {
      await run(withKit, async (kit, control) => {
        const { view } = await open(kit, control)
        await deleted(view, control)
        expect(find(view.root, 'hilos-admin-page')).not.toBeNull()
        expect(find(document, 'modal')).not.toBeNull()
        await view.flush(() =>
          control.router.navigate(resolveHilosPath(HilosPages.PUBLIC_STATUS)!),
        )
        expect(find(view.root, 'public-file-reader')).not.toBeNull()
        expect(find(view.root, 'hilos-admin-page')).toBeNull()
        expect(pageErrors()).toHaveLength(0)
        expect(find(document, 'modal')).toBeNull()
        expect(find(view.root, 'hilos-page-departure')!.childElementCount).toBe(
          0,
        )
      })
    })

    it('D06 replaces a card that did not opt in, including its modal', async () => {
      await run(withKit, async (kit, control) => {
        const { view, page } = await open(kit, control, false)
        await deleted(view, control)
        expect(find(view.root, 'hilos-admin-page')).toBeNull()
        expect(find(document, 'modal')).toBeNull()
        expect(pageErrors()).toHaveLength(1)
        expect(
          find(view.root, 'page-error-transition-message')?.textContent?.trim(),
        ).toBe('It was deleted just now.')
        expect(page.mounted).toHaveBeenCalledTimes(1)
      })
    })

    it('D07 never mounts an opted-in card refused before its first render', async () => {
      await run(withKit, async (kit, control) => {
        const page = kit.card(true)
        control.pageError.set(CARD_DELETED)
        control.pageLoading.set(false)
        const view = await kit.mount({
          router: control.router,
          pages: { lazy: page.component },
        })
        expect(page.mounted).not.toHaveBeenCalled()
        expect(find(view.root, 'hilos-admin-page')).toBeNull()
        expect(pageErrors()).toHaveLength(1)
      })
    })

    it('D08 keeps focus in the draft and returns it to enabled Back to home', async () => {
      await run(withKit, async (kit, control) => {
        const { view, draft } = await open(kit, control)
        await deleted(view, control)
        expect(document.activeElement).toBe(draft)
        await view.flush(() => find(document, 'modal-close')!.click())
        expect(find(document, 'modal')).toBeNull()
        const home = find(view.root, 'page-error-home')
        expect(home).not.toBeNull()
        expect(home!.matches(':disabled')).toBe(false)
        expect(document.activeElement).toBe(home)
      })
    })
  })
}
