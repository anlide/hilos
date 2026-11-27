import {
  createHilosRowEdit,
  hilosTableRowEditSource,
  type HilosTrackedRowEdit,
  type TableViewportController,
} from '@hilos/core/base'

import { sendCategoryUpdate } from './adminCategoriesActions.js'
import {
  EMPTY_CATEGORY_DRAFT,
  hasCategoryText,
  type CategoryForm,
} from './categoryAddDraft.js'
import { type CategoryRow } from './types/tables/CategoryRow.js'

interface CategoryEditFields {
  readonly name: string
  readonly description: string
  readonly pictureFileId: number | null
}

/** The edit session over one focused category row. */
export type CategoryEdit = HilosTrackedRowEdit<
  CategoryRow,
  CategoryEditFields,
  CategoryForm
>

interface CategoryEditWriteState {
  readonly locked: boolean
  readonly picturePending: boolean
  readonly uploadBusy: boolean
  readonly selectedUploadId: string | null
  readonly readyUploads: readonly string[]
}

/**
 * Whether either submit path can save with the current upload and edit state.
 *
 * @param state The session verdict, permission and upload state at submission.
 */
export function canSubmitCategoryEdit(
  state: CategoryEditWriteState & { readonly canSave: boolean },
): boolean {
  return state.canSave && canWriteCategoryEdit(state)
}

/** Both writes wait for a selected upload to finish publishing. */
function canWriteCategoryEdit(state: CategoryEditWriteState): boolean {
  const awaitingPublication =
    state.selectedUploadId !== null &&
    state.readyUploads.includes(state.selectedUploadId)

  return (
    !state.locked &&
    !state.picturePending &&
    !state.uploadBusy &&
    !awaitingPublication
  )
}

const TEXT_LABELS = { name: 'Name', description: 'Description' } as const

/**
 * The preview and identity of a row's saved picture.
 *
 * @param row The opening or live row.
 */
function pictureOf(row: CategoryRow): NonNullable<CategoryForm['picture']> {
  return { fileId: row.pictureFileId, url: row.picturePreview }
}

/**
 * Edit the focused category and compare the draft to the live row at save time.
 *
 * @param controller The categories table whose row the window edits.
 */
export function createCategoryEdit(
  controller: TableViewportController<CategoryRow>,
): CategoryEdit {
  const source = hilosTableRowEditSource(controller, (row) => String(row.id))
  const edit = createHilosRowEdit<
    CategoryRow,
    CategoryEditFields,
    CategoryForm
  >(source, {
    initial: EMPTY_CATEGORY_DRAFT,
    fields: (row) => ({
      name: row.name,
      description: row.description,
      pictureFileId: row.pictureFileId,
    }),
    form: (row) => ({
      name: row.name,
      description: row.description,
      picture: pictureOf(row),
    }),
    draft: (form) => ({
      name: form.name,
      description: form.description,
      pictureFileId: form.picture?.fileId ?? null,
    }),
    take: (form, taken, _row, live) => ({
      name: taken.name ?? form.name,
      description: taken.description ?? form.description,
      picture:
        taken.pictureFileId !== undefined && live !== undefined
          ? pictureOf(live)
          : form.picture,
    }),
    valid: hasCategoryText,
    notice: {
      conflict: (state) =>
        (state.notice?.fields ?? [])
          .map((field) =>
            field === 'pictureFileId'
              ? 'Picture changed elsewhere.'
              : `${TEXT_LABELS[field]} changed elsewhere to "${state.fields[field].incoming}".`,
          )
          .join(' '),
    },
  })

  return {
    ...edit,
    save: (run) =>
      edit.save((draft, row) => {
        const live = source.live.get()
        if (live === undefined) return Promise.resolve(false)

        return run(
          sendCategoryUpdate({
            id: row.id,
            name: draft.name,
            description: draft.description,
            pictureFileId:
              draft.pictureFileId === live.pictureFileId
                ? null
                : draft.pictureFileId,
            expectedName: live.name,
            expectedDescription: live.description,
            expectedPictureFileId: live.pictureFileId,
          }),
        )
      }),
  }
}
