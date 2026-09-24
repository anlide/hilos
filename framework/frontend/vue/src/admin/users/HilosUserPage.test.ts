// The rename modal on the shared row-edit helper (HIL-1050): a live rename
// reloads a pristine modal and says so, a typed one conflicts and answers Keep
// mine / Take theirs without Merge, a card whose row went locks save as
// "Deleted", and the modal asks before discarding a changed draft. The account
// merge cases below cover the separate live two-step modal.
import { flushPromises, mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  createSignal,
  entityCollection,
} from '@hilos/core'
import type {
  HilosConnection,
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
function userContext(
  accountMerge = false,
  options: {
    detailHasPassword?: boolean
    candidateHasPassword?: boolean
  } = {},
): {
  context: HilosUsersContext
  renameElsewhere: (name: string) => void
  removeRow: () => void
  removeCandidate: () => void
  answerMerge: (reason?: string) => void
  sent: Array<{ action: string; data: unknown; requestId?: string }>
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
    identities: { hasPassword: options.detailHasPassword ?? false },
  })
  scopes.session.data.set('currentUser', { type: 'user', id: 99 })
  const users = entityCollection<HilosUserProfile>(
    scopes,
    'user',
    (fields) => ({
      id: Number(fields.id),
      name: String(fields.name ?? ''),
      lastActivity: (fields.lastActivity as string | null) ?? null,
    }),
  )
  const listeners = new Map<
    string,
    Set<(payload: { data?: unknown } & Record<string, unknown>) => void>
  >()
  const sent: Array<{
    action: string
    data: unknown
    requestId?: string
  }> = []
  const emit = (
    event: string,
    payload: { data?: unknown } & Record<string, unknown>,
  ): void => {
    for (const listener of listeners.get(event) ?? []) {
      listener(payload)
    }
  }
  const candidateRows = () => [
    {
      rowKey: 2,
      slots: {
        users: { id: 2, name: 'Bob', lastActivity: null },
        merge: {
          identities: [
            {
              type: 'email',
              identifier: 'bob@example.test',
              provider: null,
              verified: true,
            },
          ],
          hasPassword: options.candidateHasPassword ?? false,
        },
      },
    },
    {
      rowKey: 99,
      slots: {
        users: { id: 99, name: 'Admin', lastActivity: null },
        merge: { identities: [], hasPassword: false },
      },
    },
  ]
  const serveCandidates = (): void => {
    emit('tableWindow', {
      data: {
        page: HilosPages.USER,
        tableKey: 'mergeCandidates',
        rows: candidateRows(),
        totalCount: 2,
        totalExact: true,
        firstAnchor: null,
        lastAnchor: null,
        offset: 0,
        limit: 10,
      },
    })
  }
  const connection = {
    sendAction(action: string, data: unknown, requestId?: string): boolean {
      sent.push({ action, data, requestId })

      return true
    },
    registerTableWindow(tableKey: string): void {
      if (tableKey === 'mergeCandidates') {
        serveCandidates()
      }
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(): boolean {
      serveCandidates()

      return true
    },
    sendTableRendered(): boolean {
      return true
    },
    on(
      event: string,
      listener: (payload: { data?: unknown } & Record<string, unknown>) => void,
    ): () => void {
      const eventListeners = listeners.get(event) ?? new Set()
      eventListeners.add(listener)
      listeners.set(event, eventListeners)

      return () => eventListeners.delete(listener)
    },
  } as unknown as HilosConnection

  return {
    context: {
      scopes,
      connection,
      actions: new ActionLifecycle(connection),
      users,
      accountMerge,
    },
    renameElsewhere(name: string): void {
      page.entities.upsert({ type: 'user', id: 1 }, { name })
    },
    removeRow(): void {
      page.tables.delete('userDetail', 1)
    },
    removeCandidate(): void {
      emit('tableViewportDelta', {
        data: {
          page: HilosPages.USER,
          tableKey: 'mergeCandidates',
          kind: 'row_removed',
          rowKey: '2',
          reason: 'deleted',
        },
      })
    },
    answerMerge(reason?: string): void {
      const requestId = sent.at(-1)?.requestId
      if (reason === undefined) {
        emit('actionSuccess', {
          kind: 'actionSuccess',
          action: 'hilos_user_merge',
          requestId,
          message: 'Merged.',
        })

        return
      }
      emit('actionError', {
        kind: 'actionError',
        action: 'hilos_user_merge',
        requestId,
        reason,
      })
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
  it('shows the merge zone only when enabled and locks Next without a candidate', async () => {
    const disabled = userContext()
    const disabledWrapper = mount(HilosUserPage, {
      props: { context: markRaw(disabled.context) },
      global: { provide: { [hilosRouterKey as symbol]: router() } },
    })
    mounted.push(disabledWrapper)
    await nextTick()
    expect(
      disabledWrapper.find('[data-id="hilos-user-merge-zone"]').exists(),
    ).toBe(false)

    const enabled = userContext(true)
    const enabledWrapper = mount(HilosUserPage, {
      props: { context: markRaw(enabled.context) },
      attachTo: document.body,
      global: { provide: { [hilosRouterKey as symbol]: router() } },
    })
    mounted.push(enabledWrapper)
    await nextTick()
    expect(
      enabledWrapper.find('[data-id="hilos-user-merge-zone"]').exists(),
    ).toBe(true)
    modalEl('hilos-user-merge-open')?.click()
    await flushPromises()
    await nextTick()
    expect(document.activeElement).toBe(modalEl('hilos-table-search'))
    expect(
      document.body.querySelectorAll('[data-id="hilos-user-merge-row-2"]'),
    ).toHaveLength(2)
    expect(
      modalEl('modal')
        ?.querySelector('.modal-dialog')
        ?.classList.contains('modal-lg'),
    ).toBe(true)
    expect(
      (modalEl('hilos-user-merge-next') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('drives password choice, tracked outcomes, and a candidate leaving live', async () => {
    const passwordWorld = userContext(true, {
      detailHasPassword: true,
      candidateHasPassword: true,
    })
    const passwordWrapper = mount(HilosUserPage, {
      props: { context: markRaw(passwordWorld.context) },
      attachTo: document.body,
      global: { provide: { [hilosRouterKey as symbol]: router() } },
    })
    mounted.push(passwordWrapper)
    await nextTick()
    modalEl('hilos-user-merge-open')?.click()
    await nextTick()

    expect(
      (modalEl('hilos-user-merge-row-99') as HTMLInputElement).disabled,
    ).toBe(true)
    modalEl('hilos-user-merge-row-2')?.click()
    await nextTick()
    expect(
      Array.from(
        document.body.querySelectorAll<HTMLInputElement>(
          '[data-id="hilos-user-merge-row-2"]',
        ),
      ).every((choice) => choice.checked),
    ).toBe(true)
    modalEl('hilos-user-merge-next')?.click()
    await nextTick()
    expect(modalEl('hilos-user-merge-fate-survivor')).not.toBeNull()
    expect(
      (modalEl('hilos-user-merge-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)

    modalEl('hilos-user-merge-fate-loser')?.click()
    await nextTick()
    modalEl('hilos-user-merge-confirm')?.click()
    expect(passwordWorld.sent.at(-1)).toMatchObject({
      action: 'hilos_user_merge',
      data: { survivorUserId: 1, loserUserId: 2, passwordFate: 'loser' },
    })
    passwordWorld.answerMerge('Merge refused')
    await nextTick()
    await nextTick()
    expect(modalEl('modal')).not.toBeNull()
    expect(document.body.textContent).toContain('Merge refused')

    passwordWorld.removeCandidate()
    await nextTick()
    expect(modalEl('hilos-user-merge-gone')?.textContent).toContain(
      'No longer available',
    )
    expect(
      (modalEl('hilos-user-merge-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)

    passwordWrapper.unmount()
    mounted.splice(mounted.indexOf(passwordWrapper), 1)
    const plainWorld = userContext(true, {
      detailHasPassword: true,
      candidateHasPassword: false,
    })
    const plainWrapper = mount(HilosUserPage, {
      props: { context: markRaw(plainWorld.context) },
      attachTo: document.body,
      global: { provide: { [hilosRouterKey as symbol]: router() } },
    })
    mounted.push(plainWrapper)
    await nextTick()
    modalEl('hilos-user-merge-open')?.click()
    await nextTick()
    modalEl('hilos-user-merge-row-2')?.click()
    await nextTick()
    modalEl('hilos-user-merge-next')?.click()
    await nextTick()
    expect(modalEl('hilos-user-merge-fate-survivor')).toBeNull()
    modalEl('hilos-user-merge-confirm')?.click()
    expect(plainWorld.sent.at(-1)).toMatchObject({
      action: 'hilos_user_merge',
      data: { survivorUserId: 1, loserUserId: 2 },
    })
    plainWorld.answerMerge()
    await flushPromises()
    await nextTick()
    expect(modalEl('modal')).toBeNull()
  })

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
