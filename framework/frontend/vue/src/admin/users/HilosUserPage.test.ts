// The rename modal on the shared row-edit helper (HIL-1050): a live rename
// reloads a pristine modal and says so, a typed one conflicts and answers Keep
// mine / Take theirs without Merge, a card whose row went locks save as
// "Deleted", and the modal asks before discarding a changed draft. The merge
// itself is the core helper's; this covers the view.
import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionLifecycle,
  HilosConnection,
  HilosPages,
  ScopeManager,
  createSignal,
  entityCollection,
} from '@hilos/core'
import type {
  HilosRouter,
  HilosUserProfile,
  HilosUsersContext,
  PageRouteMatch,
} from '@hilos/core'

import HilosUserPage from './HilosUserPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.USER,
      params: { userId: '1' },
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/**
 * The single-user detail arrives as the one row of the `userDetail` table, its
 * name through the `user` entity — so a rename elsewhere is an entity upsert and
 * a deleted user is the row leaving the table.
 */
function userContext(): {
  context: HilosUsersContext
  renameElsewhere: (name: string) => void
  removeRow: () => void
  sent: Array<{ action: string; data: unknown }>
} {
  const scopes = new ScopeManager()
  const page = scopes.openPage(HilosPages.USER)
  page.entities.upsert(
    { type: 'user', id: 1 },
    { id: 1, name: 'Alice', lastActivity: null },
  )
  page.tables.upsert('userDetail', 1, {
    users: { type: 'user', id: 1 },
    connections: { presence: 'online', onlineSessionCount: 1 },
  })
  const users = entityCollection<HilosUserProfile>(
    scopes,
    'user',
    (fields) => ({
      id: Number(fields.id),
      name: String(fields.name ?? ''),
      lastActivity: (fields.lastActivity as string | null) ?? null,
    }),
  )
  const connection = new HilosConnection({ url: 'ws://test/ws' })
  const sent: Array<{ action: string; data: unknown }> = []
  vi.spyOn(connection, 'sendAction').mockImplementation((action, data) => {
    sent.push({ action, data })

    return true
  })

  return {
    context: {
      scopes,
      connection,
      actions: new ActionLifecycle(connection),
      users,
    },
    renameElsewhere(name: string): void {
      page.entities.upsert({ type: 'user', id: 1 }, { name })
    },
    removeRow(): void {
      page.tables.delete('userDetail', 1)
    },
    sent,
  }
}

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
  document.body.classList.remove('modal-open')
})

function modalEl(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function nameInput(): HTMLInputElement {
  return modalEl('hilos-user-name-input') as HTMLInputElement
}

function saveButton(): HTMLButtonElement {
  return modalEl('hilos-user-save') as HTMLButtonElement
}

async function typeDraft(text: string): Promise<void> {
  const input = nameInput()
  input.value = text
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}

/** Mount the page and open the rename modal. */
async function openModal(context: HilosUsersContext): Promise<void> {
  const wrapper = mount(HilosUserPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
  mounted.push(wrapper)
  await nextTick()
  modalEl('hilos-user-edit')?.click()
  await nextTick()
}

describe('HilosUserPage rename modal', () => {
  it('opens on the committed name with save locked and the message line empty', async () => {
    const { context } = userContext()
    await openModal(context)

    expect(nameInput().value).toBe('Alice')
    expect(saveButton().disabled).toBe(true)
    expect(modalEl('hilos-user-edit-notice')).toBeNull()
    expect(modalEl('hilos-user-edit-notice-idle')).not.toBeNull()
  })

  it('reloads a pristine edit when the name changes elsewhere and says so', async () => {
    const { context, renameElsewhere } = userContext()
    await openModal(context)

    renameElsewhere('Alicia')
    await nextTick()
    await nextTick()

    expect(nameInput().value).toBe('Alicia')
    expect(modalEl('conflict-badge')).toBeNull()
    expect(modalEl('hilos-user-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton().disabled).toBe(true)

    // The person types over the taken name: the note about it goes out.
    await typeDraft('Alicia B')
    expect(modalEl('hilos-user-edit-notice')).toBeNull()
    expect(saveButton().disabled).toBe(false)
  })

  it('surfaces a conflict on a dirty edit, hides merge, and Keep mine sends mine', async () => {
    const { context, renameElsewhere, sent } = userContext()
    await openModal(context)
    await typeDraft('Mine')

    renameElsewhere('Theirs')
    await nextTick()
    await nextTick()

    expect(modalEl('conflict-badge')).not.toBeNull()
    expect(modalEl('hilos-user-edit-notice')?.textContent).toContain(
      'Changed elsewhere to "Theirs"',
    )
    expect(modalEl('conflict-merge')).toBeNull()
    expect(saveButton().disabled).toBe(true)
    expect(nameInput().value).toBe('Mine')

    modalEl('conflict-accept-mine')?.click()
    await nextTick()
    expect(modalEl('conflict-badge')).toBeNull()
    expect(modalEl('hilos-user-edit-notice')).toBeNull()
    expect(saveButton().disabled).toBe(false)

    saveButton().click()
    await nextTick()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('hilos_user_update')
    expect(sent[0]?.data).toEqual({ id: 1, name: 'Mine' })
  })

  it('Take theirs sets the name to the live value, says so, and locks save', async () => {
    const { context, renameElsewhere } = userContext()
    await openModal(context)
    await typeDraft('Mine')
    renameElsewhere('Theirs')
    await nextTick()
    await nextTick()

    modalEl('conflict-accept-theirs')?.click()
    await nextTick()

    expect(nameInput().value).toBe('Theirs')
    expect(modalEl('conflict-badge')).toBeNull()
    expect(modalEl('hilos-user-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton().disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes under the modal', async () => {
    const { context, removeRow } = userContext()
    await openModal(context)
    await typeDraft('Mine')
    removeRow()
    await nextTick()
    await nextTick()

    expect(modalEl('hilos-user-edit-notice')?.textContent).toContain(
      'Deleted elsewhere',
    )
    expect(saveButton().disabled).toBe(true)
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(nameInput().value).toBe('Mine')
    expect(modalEl('modal')).not.toBeNull()
  })

  it('asks before discarding a changed draft', async () => {
    const { context } = userContext()
    await openModal(context)
    await typeDraft('Mine')

    modalEl('modal-close')?.click()
    await nextTick()
    // The discard question is the modal's own confirm step: the dialog stays.
    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('modal-confirm-discard')).not.toBeNull()

    modalEl('modal-confirm-discard')?.click()
    await nextTick()
    expect(modalEl('modal')).toBeNull()
  })
})
