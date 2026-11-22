import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  SIGNAL_TYPE_PAGE_RESPONSE,
  createSignal,
  type ActionHandle,
  type HilosConnectionEventMap,
} from '@hilos/core/base'

import { scopes } from '../../../src/bootstrap/session.js'
import { PAGE_ADMIN_CATEGORIES } from '../../../src/pages/keys.js'
import { CATEGORY_UPDATE } from '../../../src/views/AdminCategories/adminCategoriesActions.js'
import {
  createCategoriesTable,
  type CategoriesTable,
} from '../../../src/views/AdminCategories/adminCategoriesPage.js'
import {
  canSubmitCategoryEdit,
  createCategoryEdit,
  type CategoryEdit,
} from '../../../src/views/AdminCategories/categoryEdit.js'
import { type CategoryRow } from '../../../src/views/AdminCategories/types/tables/CategoryRow.js'

// EcommerceShopTableContext::categories and CategoriesTable::ROW_SLOT.
const CATEGORIES_TABLE = 'categories'
const CATEGORY_SLOT = 'category'

const openingRow: CategoryRow = {
  id: 2,
  name: 'Bouquets',
  description: 'Fresh seasonal arrangements',
  position: 1,
  picture: '/pictures/bouquets.webp',
  pictureFileId: 12,
  picturePreview: '/files/12/catalog_sm',
  productCount: 14,
  first: true,
}
const localPicture = { fileId: 23, url: '/files/23/catalog_sm' }
const livePicture = { fileId: 34, url: '/files/34/catalog_sm' }

const wire = vi.hoisted(() => {
  const listeners = new Map<string, Set<(signal: unknown) => void>>()
  return {
    listeners,
    connection: {
      on: vi.fn((name: string, listener: (signal: unknown) => void) => {
        const current = listeners.get(name) ?? new Set()
        current.add(listener)
        listeners.set(name, current)
        return () => current.delete(listener)
      }),
      registerTableWindow: vi.fn(),
      unregisterTableWindow: vi.fn(),
      sendTableViewport: vi.fn(),
      sendTableRendered: vi.fn(),
      sendTableRowFocus: vi.fn(),
    },
    actions: { dispatch: vi.fn() },
  }
})

vi.mock('../../../src/bootstrap/connection.js', () => ({
  connection: wire.connection,
  actions: wire.actions,
}))
vi.mock('../../../src/bootstrap/session.js', async () => {
  const { ScopeManager } = await import('@hilos/core/base')
  return { scopes: new ScopeManager() }
})

const handle: ActionHandle = {
  requestId: 'category-update-1',
  loading: createSignal(false),
  done: Promise.resolve({}),
}

function emit(name: string, signal: unknown): void {
  for (const listener of wire.listeners.get(name) ?? []) {
    listener(signal)
  }
}

function categoryFrame(changes: Partial<CategoryRow> = {}) {
  return {
    rowKey: openingRow.id,
    slots: { [CATEGORY_SLOT]: { ...openingRow, ...changes } },
  }
}

function delta(
  change: Pick<
    HilosConnectionEventMap['tableViewportDelta']['data'],
    'kind' | 'row' | 'reason'
  >,
): void {
  emit('tableViewportDelta', {
    data: {
      page: PAGE_ADMIN_CATEGORIES,
      tableKey: CATEGORIES_TABLE,
      rowKey: String(openingRow.id),
      ...change,
    },
  })
}

function changedElsewhere(changes: Partial<CategoryRow>): void {
  delta({ kind: 'row_updated', row: categoryFrame(changes) })
}

function pictureChangedElsewhere(): void {
  changedElsewhere({
    pictureFileId: livePicture.fileId,
    picturePreview: livePicture.url,
  })
}

describe('category edit', () => {
  let table: CategoriesTable
  let edit: CategoryEdit

  beforeEach(() => {
    vi.clearAllMocks()
    wire.actions.dispatch.mockReturnValue(handle)
    scopes.openPage(PAGE_ADMIN_CATEGORIES)
    table = createCategoriesTable(() => undefined)
    edit = createCategoryEdit(table.controller)
    table.start()
    edit.start()

    const anchor = { position: openingRow.position, id: openingRow.id }
    emit('projectSignal', {
      type: SIGNAL_TYPE_PAGE_RESPONSE,
      data: {
        page: PAGE_ADMIN_CATEGORIES,
        payload: {
          windows: {
            [CATEGORIES_TABLE]: {
              rows: [categoryFrame()],
              totalCount: 1,
              totalExact: true,
              firstAnchor: anchor,
              lastAnchor: anchor,
              limit: 25,
              sort: [{ field: 'position', direction: 'asc' }],
              filter: {},
            },
          },
        },
      },
    })
    expect(edit.open(String(openingRow.id))).toBe(true)
  })

  afterEach(() => {
    edit.dispose()
    table.dispose()
    scopes.dropPage()
    wire.listeners.clear()
  })

  it('focuses the row and opens with its saved preview and nothing to save', () => {
    expect(wire.connection.sendTableRowFocus).toHaveBeenCalledWith(
      PAGE_ADMIN_CATEGORIES,
      CATEGORIES_TABLE,
      '2',
    )
    expect(edit.form.get()).toEqual({
      name: 'Bouquets',
      description: 'Fresh seasonal arrangements',
      picture: { fileId: 12, url: '/files/12/catalog_sm' },
    })
    expect(edit.canSave.get()).toBe(false)
  })

  it('sends a text edit with null for the picture and the latest live values', async () => {
    edit.patchForm({ name: 'Dried flowers' })
    changedElsewhere({ description: 'Arrangements for spring' })
    const run = vi.fn(async () => true)

    await edit.save(run)

    expect(wire.actions.dispatch).toHaveBeenCalledExactlyOnceWith(
      CATEGORY_UPDATE,
      {
        id: 2,
        name: 'Dried flowers',
        description: 'Arrangements for spring',
        pictureFileId: null,
        expectedName: 'Bouquets',
        expectedDescription: 'Arrangements for spring',
        expectedPictureFileId: 12,
      },
    )
    expect(run).toHaveBeenCalledExactlyOnceWith(handle)
    expect(edit.opened.get()).toBe(false)
  })

  it('sends the new file id for a picture-only edit', async () => {
    edit.patchForm({ picture: localPicture })

    expect(edit.canSave.get()).toBe(true)
    await edit.save(async () => true)

    expect(wire.actions.dispatch).toHaveBeenCalledExactlyOnceWith(
      CATEGORY_UPDATE,
      {
        id: 2,
        name: 'Bouquets',
        description: 'Fresh seasonal arrangements',
        pictureFileId: 23,
        expectedName: 'Bouquets',
        expectedDescription: 'Fresh seasonal arrangements',
        expectedPictureFileId: 12,
      },
    )
  })

  it('takes the incoming preview into an untouched picture field', () => {
    edit.patchForm({ name: 'Dried flowers' })

    pictureChangedElsewhere()

    expect(edit.form.get()).toEqual({
      name: 'Dried flowers',
      description: 'Fresh seasonal arrangements',
      picture: { fileId: 34, url: '/files/34/catalog_sm' },
    })
    expect(edit.state.get().notice?.kind).toBe('updated')
    expect(edit.canSave.get()).toBe(true)
  })

  it('blocks conflicting replacements until Keep mine and then sends the draft against the live picture', async () => {
    edit.patchForm({ picture: localPicture })
    pictureChangedElsewhere()

    expect(edit.form.get().picture).toEqual(localPicture)
    expect(edit.state.get().fields.pictureFileId.conflict).toBe(true)
    expect(edit.noticeText.get()).toBe('Picture changed elsewhere.')
    expect(edit.canSave.get()).toBe(false)
    const run = vi.fn(async () => true)
    await edit.save(run)
    expect(run).not.toHaveBeenCalled()
    expect(wire.actions.dispatch).not.toHaveBeenCalled()

    edit.keepMine()

    expect(edit.canSave.get()).toBe(true)
    expect(wire.actions.dispatch).not.toHaveBeenCalled()
    await edit.save(run)

    expect(wire.actions.dispatch).toHaveBeenCalledExactlyOnceWith(
      CATEGORY_UPDATE,
      {
        id: 2,
        name: 'Bouquets',
        description: 'Fresh seasonal arrangements',
        pictureFileId: 23,
        expectedName: 'Bouquets',
        expectedDescription: 'Fresh seasonal arrangements',
        expectedPictureFileId: 34,
      },
    )
  })

  it('discards the replacement with Take theirs and saves an independent text edit', async () => {
    edit.patchForm({ picture: localPicture })
    pictureChangedElsewhere()

    edit.takeTheirs()

    expect(edit.form.get().picture).toEqual({
      fileId: 34,
      url: '/files/34/catalog_sm',
    })
    expect(edit.state.get().conflict).toBe(false)
    expect(edit.canSave.get()).toBe(false)
    expect(wire.actions.dispatch).not.toHaveBeenCalled()

    edit.patchForm({ description: 'Arrangements for spring' })
    await edit.save(async () => true)

    expect(wire.actions.dispatch).toHaveBeenCalledExactlyOnceWith(
      CATEGORY_UPDATE,
      {
        id: 2,
        name: 'Bouquets',
        description: 'Arrangements for spring',
        pictureFileId: null,
        expectedName: 'Bouquets',
        expectedDescription: 'Fresh seasonal arrangements',
        expectedPictureFileId: 34,
      },
    )
  })

  it.each([{ name: '' }, { description: '' }])(
    'blocks a picture replacement when a required text field is empty (%j)',
    async (changes) => {
      edit.patchForm({ picture: localPicture, ...changes })

      expect(edit.canSave.get()).toBe(false)
      await edit.save(async () => true)
      expect(wire.actions.dispatch).not.toHaveBeenCalled()
    },
  )

  it('keeps the draft after a refusal and waits for a choice before saving again', async () => {
    edit.patchForm({ name: 'Dried flowers' })
    await edit.save(async () => false)

    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get().name).toBe('Dried flowers')

    changedElsewhere({ name: 'Spring bouquets' })

    expect(edit.noticeText.get()).toBe(
      'Name changed elsewhere to "Spring bouquets".',
    )
    expect(edit.canSave.get()).toBe(false)
    edit.keepMine()
    expect(wire.actions.dispatch).toHaveBeenCalledTimes(1)
    await edit.save(async () => true)

    expect(wire.actions.dispatch).toHaveBeenNthCalledWith(2, CATEGORY_UPDATE, {
      id: 2,
      name: 'Dried flowers',
      description: 'Fresh seasonal arrangements',
      pictureFileId: null,
      expectedName: 'Spring bouquets',
      expectedDescription: 'Fresh seasonal arrangements',
      expectedPictureFileId: 12,
    })
  })

  it('follows the focused row when it leaves the filtered set', () => {
    edit.patchForm({ description: 'Arrangements for spring' })
    delta({
      kind: 'row_removed',
      reason: 'left_set',
      row: categoryFrame({ name: 'Dried flowers' }),
    })

    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get()).toEqual({
      name: 'Dried flowers',
      description: 'Arrangements for spring',
      picture: { fileId: 12, url: '/files/12/catalog_sm' },
    })
    expect(edit.canSave.get()).toBe(true)
  })

  it('keeps the draft extractable and blocks dispatch on a real deletion', async () => {
    edit.patchForm({ name: 'Dried flowers', picture: localPicture })
    delta({ kind: 'row_removed', reason: 'deleted' })

    expect(edit.opened.get()).toBe(true)
    expect(edit.form.get()).toEqual({
      name: 'Dried flowers',
      description: 'Fresh seasonal arrangements',
      picture: { fileId: 23, url: '/files/23/catalog_sm' },
    })
    expect(edit.saveLabel.get()).toBe('Deleted')
    expect(edit.canSave.get()).toBe(false)
    const run = vi.fn(async () => true)
    await edit.save(run)
    expect(run).not.toHaveBeenCalled()
    expect(wire.actions.dispatch).not.toHaveBeenCalled()
  })
})

describe('category edit submit verdict', () => {
  const readyToSave = {
    canSave: true,
    locked: false,
    picturePending: false,
    uploadBusy: false,
    selectedUploadId: null,
    readyUploads: [],
  }

  it.each([
    ['the edit session blocks saving', { canSave: false }],
    ['the viewer is locked', { locked: true }],
    ['the upload is busy', { uploadBusy: true }],
    ['publication is pending', { picturePending: true }],
    [
      'the selected upload is ready but publication has not started',
      {
        selectedUploadId: 'category-picture',
        readyUploads: ['category-picture'],
      },
    ],
  ])('blocks both submit paths when %s', (_reason, state) => {
    expect(canSubmitCategoryEdit({ ...readyToSave, ...state })).toBe(false)
  })

  it('allows both paths once the published picture is accepted into the draft', () => {
    expect(
      canSubmitCategoryEdit({
        ...readyToSave,
        readyUploads: ['category-picture'],
      }),
    ).toBe(true)
  })
})
