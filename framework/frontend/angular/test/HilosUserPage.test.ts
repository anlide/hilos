// The Angular peer of vue/src/admin/users/HilosUserPage.test.ts and
// react/test/HilosUserPage.test.tsx (HIL-1050), under the same case names: the
// rename modal on the shared row-edit helper.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
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
import { describe, expect, it, vi } from 'vitest'

import { HilosUserPage } from '../src/admin/users/HilosUserPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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

/** Mount the page and open the rename modal. */
function openModal(
  context: HilosUsersContext,
): ComponentFixture<HilosUserPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosUserPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()
  el(fixture, 'hilos-user-edit')?.click()
  fixture.detectChanges()

  return fixture
}

function el(
  fixture: ComponentFixture<unknown>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

function nameInput(fixture: ComponentFixture<unknown>): HTMLInputElement {
  return el(fixture, 'hilos-user-name-input') as HTMLInputElement
}

function saveButton(fixture: ComponentFixture<unknown>): HTMLButtonElement {
  return el(fixture, 'hilos-user-save') as HTMLButtonElement
}

function typeDraft(fixture: ComponentFixture<unknown>, text: string): void {
  const input = nameInput(fixture)
  input.value = text
  input.dispatchEvent(new Event('input', { bubbles: true }))
  fixture.detectChanges()
}

describe('HilosUserPage rename modal', () => {
  it('opens on the committed name with save locked and the message line empty', () => {
    const { context } = userContext()
    const fixture = openModal(context)

    expect(nameInput(fixture).value).toBe('Alice')
    expect(saveButton(fixture).disabled).toBe(true)
    expect(el(fixture, 'hilos-user-edit-notice')).toBeNull()
    expect(el(fixture, 'hilos-user-edit-notice-idle')).not.toBeNull()
  })

  it('reloads a pristine edit when the name changes elsewhere and says so', () => {
    const { context, renameElsewhere } = userContext()
    const fixture = openModal(context)

    renameElsewhere('Alicia')
    fixture.detectChanges()

    expect(nameInput(fixture).value).toBe('Alicia')
    expect(el(fixture, 'conflict-badge')).toBeNull()
    expect(el(fixture, 'hilos-user-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton(fixture).disabled).toBe(true)

    // The person types over the taken name: the note about it goes out.
    typeDraft(fixture, 'Alicia B')
    expect(el(fixture, 'hilos-user-edit-notice')).toBeNull()
    expect(saveButton(fixture).disabled).toBe(false)
  })

  it('surfaces a conflict on a dirty edit, hides merge, and Keep mine sends mine', () => {
    const { context, renameElsewhere, sent } = userContext()
    const fixture = openModal(context)
    typeDraft(fixture, 'Mine')

    renameElsewhere('Theirs')
    fixture.detectChanges()

    expect(el(fixture, 'conflict-badge')).not.toBeNull()
    expect(el(fixture, 'hilos-user-edit-notice')?.textContent).toContain(
      'Changed elsewhere to "Theirs"',
    )
    expect(el(fixture, 'conflict-merge')).toBeNull()
    expect(saveButton(fixture).disabled).toBe(true)
    expect(nameInput(fixture).value).toBe('Mine')

    el(fixture, 'conflict-accept-mine')?.click()
    fixture.detectChanges()
    expect(el(fixture, 'conflict-badge')).toBeNull()
    expect(el(fixture, 'hilos-user-edit-notice')).toBeNull()
    expect(saveButton(fixture).disabled).toBe(false)

    saveButton(fixture).click()
    fixture.detectChanges()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('hilos_user_update')
    expect(sent[0]?.data).toEqual({ id: 1, name: 'Mine' })
  })

  it('Take theirs sets the name to the live value, says so, and locks save', () => {
    const { context, renameElsewhere } = userContext()
    const fixture = openModal(context)
    typeDraft(fixture, 'Mine')
    renameElsewhere('Theirs')
    fixture.detectChanges()

    el(fixture, 'conflict-accept-theirs')?.click()
    fixture.detectChanges()

    expect(nameInput(fixture).value).toBe('Theirs')
    expect(el(fixture, 'conflict-badge')).toBeNull()
    expect(el(fixture, 'hilos-user-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton(fixture).disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes under the modal', () => {
    const { context, removeRow } = userContext()
    const fixture = openModal(context)
    typeDraft(fixture, 'Mine')
    removeRow()
    fixture.detectChanges()

    expect(el(fixture, 'hilos-user-edit-notice')?.textContent).toContain(
      'Deleted elsewhere',
    )
    expect(saveButton(fixture).disabled).toBe(true)
    expect(saveButton(fixture).textContent?.trim()).toBe('Deleted')
    expect(nameInput(fixture).value).toBe('Mine')
    expect(el(fixture, 'modal')).not.toBeNull()
  })

  it('asks before discarding a changed draft', () => {
    const { context } = userContext()
    const fixture = openModal(context)
    typeDraft(fixture, 'Mine')

    el(fixture, 'modal-close')?.click()
    fixture.detectChanges()
    // The discard question is the modal's own confirm step: the dialog stays.
    expect(el(fixture, 'modal')).not.toBeNull()
    expect(el(fixture, 'modal-confirm-discard')).not.toBeNull()

    el(fixture, 'modal-confirm-discard')?.click()
    fixture.detectChanges()
    expect(el(fixture, 'modal')).toBeNull()
  })
})
