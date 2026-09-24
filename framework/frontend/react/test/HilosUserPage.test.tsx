import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionLifecycle,
  HilosConnection,
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

import { HilosUserPage } from '../src/admin/users/HilosUserPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: '',
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

// The single-user detail arrives as the one row of the `userDetail` table; when
// `seed` is false the table is empty and the page shows its loading state. The
// name rides the `user` entity, so a rename elsewhere is an entity upsert and a
// deleted user is the row leaving the table.
function userContext(
  seed: boolean,
  accountMerge = false,
): HilosUsersContext & {
  renameElsewhere: (name: string) => void
  removeRow: () => void
  sent: Array<{ action: string; data: unknown }>
} {
  const scopes = new ScopeManager()
  const page = scopes.openPage('hilos_user')
  if (seed) {
    page.entities.upsert(
      { type: 'user', id: 1 },
      { id: 1, name: 'Alice', lastActivity: '2026-06-19 10:00' },
    )
    page.tables.upsert('userDetail', 1, {
      users: { type: 'user', id: 1 },
      connections: { presence: 'online', onlineSessionCount: 2 },
    })
  }
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
    scopes,
    connection,
    actions: new ActionLifecycle(connection),
    users,
    accountMerge,
    renameElsewhere(name: string): void {
      page.entities.upsert({ type: 'user', id: 1 }, { name })
    },
    removeRow(): void {
      page.tables.delete('userDetail', 1)
    },
    sent,
  }
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function nameInput(): HTMLInputElement {
  return byId('hilos-user-name-input') as HTMLInputElement
}

function saveButton(): HTMLButtonElement {
  return byId('hilos-user-save') as HTMLButtonElement
}

/** Render the seeded page and open the rename modal. */
function openModal(context: HilosUsersContext): void {
  const { container } = renderPage(context)
  fireEvent.click(
    container.querySelector('[data-id="hilos-user-edit"]') as Element,
  )
}

function renderPage(context: HilosUsersContext) {
  return render(
    <HilosRouterContext.Provider value={router()}>
      <HilosUserPage context={context} />
    </HilosRouterContext.Provider>,
  )
}

describe('HilosUserPage', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
  })

  it('renders the loading state until the detail row lands', () => {
    const { container } = renderPage(userContext(false))
    expect(
      container.querySelector('[data-id="hilos-user-empty"]'),
    ).not.toBeNull()
    expect(container.querySelector('[data-id="hilos-user-detail"]')).toBeNull()
  })

  it('renders the user profile card from the detail row', () => {
    const { container } = renderPage(userContext(true))
    expect(
      container.querySelector('[data-id="hilos-user-name"]')?.textContent,
    ).toBe('Alice')
    expect(
      container.querySelector('[data-id="hilos-user-id"]')?.textContent,
    ).toBe('1')
    expect(
      container.querySelector('[data-id="hilos-user-sessions"]')?.textContent,
    ).toBe('2')
  })

  it('shows the merge zone only when enabled and locks Next without a candidate', () => {
    const disabled = renderPage(userContext(true))
    expect(
      disabled.container.querySelector('[data-id="hilos-user-merge-zone"]'),
    ).toBeNull()
    disabled.unmount()

    const enabled = renderPage(userContext(true, true))
    const open = enabled.container.querySelector(
      '[data-id="hilos-user-merge-open"]',
    ) as Element
    expect(open).not.toBeNull()
    fireEvent.click(open)
    expect((byId('hilos-user-merge-next') as HTMLButtonElement).disabled).toBe(
      true,
    )
  })

  it('opens the rename modal prefilled with the current name on Edit', () => {
    const { container } = renderPage(userContext(true))
    // Editing is modal, not inline: nothing is mounted until Edit, and the modal
    // portals to <body>, so the input is queried on the document, not container.
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-user-name-input"]'),
    ).toBeNull()
    fireEvent.click(
      container.querySelector('[data-id="hilos-user-edit"]') as Element,
    )
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    const input = document.querySelector(
      '[data-id="hilos-user-name-input"]',
    ) as HTMLInputElement
    expect(input).not.toBeNull()
    expect(input.value).toBe('Alice')
  })

  // The rename modal on the shared row-edit helper (HIL-1050), under the same
  // case names as vue/src/admin/users/HilosUserPage.test.ts.
  it('opens on the committed name with save locked and the message line empty', () => {
    openModal(userContext(true))

    expect(nameInput().value).toBe('Alice')
    expect(saveButton().disabled).toBe(true)
    expect(byId('hilos-user-edit-notice')).toBeNull()
    expect(byId('hilos-user-edit-notice-idle')).not.toBeNull()
  })

  it('reloads a pristine edit when the name changes elsewhere and says so', () => {
    const context = userContext(true)
    openModal(context)

    act(() => {
      context.renameElsewhere('Alicia')
    })

    expect(nameInput().value).toBe('Alicia')
    expect(byId('conflict-badge')).toBeNull()
    expect(byId('hilos-user-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton().disabled).toBe(true)

    // The person types over the taken name: the note about it goes out.
    fireEvent.change(nameInput(), { target: { value: 'Alicia B' } })
    expect(byId('hilos-user-edit-notice')).toBeNull()
    expect(saveButton().disabled).toBe(false)
  })

  it('surfaces a conflict on a dirty edit, hides merge, and Keep mine sends mine', () => {
    const context = userContext(true)
    openModal(context)
    fireEvent.change(nameInput(), { target: { value: 'Mine' } })

    act(() => {
      context.renameElsewhere('Theirs')
    })

    expect(byId('conflict-badge')).not.toBeNull()
    expect(byId('hilos-user-edit-notice')?.textContent).toContain(
      'Changed elsewhere to "Theirs"',
    )
    expect(byId('conflict-merge')).toBeNull()
    expect(saveButton().disabled).toBe(true)
    expect(nameInput().value).toBe('Mine')

    fireEvent.click(byId('conflict-accept-mine') as Element)
    expect(byId('conflict-badge')).toBeNull()
    expect(byId('hilos-user-edit-notice')).toBeNull()
    expect(saveButton().disabled).toBe(false)

    fireEvent.click(saveButton())
    expect(context.sent).toHaveLength(1)
    expect(context.sent[0]?.action).toBe('hilos_user_update')
    expect(context.sent[0]?.data).toEqual({ id: 1, name: 'Mine' })
  })

  it('Take theirs sets the name to the live value, says so, and locks save', () => {
    const context = userContext(true)
    openModal(context)
    fireEvent.change(nameInput(), { target: { value: 'Mine' } })
    act(() => {
      context.renameElsewhere('Theirs')
    })

    fireEvent.click(byId('conflict-accept-theirs') as Element)

    expect(nameInput().value).toBe('Theirs')
    expect(byId('conflict-badge')).toBeNull()
    expect(byId('hilos-user-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton().disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes under the modal', () => {
    const context = userContext(true)
    openModal(context)
    fireEvent.change(nameInput(), { target: { value: 'Mine' } })
    act(() => {
      context.removeRow()
    })

    expect(byId('hilos-user-edit-notice')?.textContent).toContain(
      'Deleted elsewhere',
    )
    expect(saveButton().disabled).toBe(true)
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(nameInput().value).toBe('Mine')
    expect(byId('modal')).not.toBeNull()
  })

  it('asks before discarding a changed draft', () => {
    openModal(userContext(true))
    fireEvent.change(nameInput(), { target: { value: 'Mine' } })

    fireEvent.click(byId('modal-close') as Element)
    // The discard question is the modal's own confirm step: the dialog stays.
    expect(byId('modal')).not.toBeNull()
    expect(byId('modal-confirm-discard')).not.toBeNull()

    fireEvent.click(byId('modal-confirm-discard') as Element)
    expect(byId('modal')).toBeNull()
  })
})
