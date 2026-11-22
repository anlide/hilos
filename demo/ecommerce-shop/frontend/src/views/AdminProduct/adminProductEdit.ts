import {
  ActionError,
  computedSignal,
  createHilosRowEdit,
  createSignal,
  hilosSignalRowEditSource,
  mergeTextLines,
  subscribeSignal,
  type HilosTrackedRowEdit,
  type ReadonlySignal,
} from '@hilos/core/base'

import { type CatalogPicture } from '../../catalog/catalogPictureReply.js'
import { moneyInputComparisonText, moneyInputText } from '../../money/money.js'
import { type AdminProduct } from '../../types/AdminProduct.js'
import { sendProductUpdate } from './adminProductActions.js'

/** The live card, null for a confirmed deletion, or undefined while unavailable. */
export type ProductEditCard = AdminProduct | null | undefined

interface ProductEditFields {
  readonly name: string
  readonly categoryId: number | null
  readonly price: string
  readonly description: string
  readonly pictureFileId: number
}

interface ProductEditForm extends ProductEditFields {
  readonly pictureUrl: string
}

interface ProductEditOptions {
  readonly card: ReadonlySignal<ProductEditCard>
  readonly awaitingFreshCard?: ReadonlySignal<boolean>
  readonly categories: ReadonlySignal<
    readonly { value: number; label: string }[]
  >
  readonly writable: ReadonlySignal<boolean>
}

type PicturePublicationAccess = 'apply' | 'wait' | 'discard'

/** The session and its extra guards for a product's Edit dialog. */
export interface AdminProductEdit extends HilosTrackedRowEdit<
  AdminProduct,
  ProductEditFields,
  ProductEditForm
> {
  readonly canOpen: ReadonlySignal<boolean>
  readonly canUpload: ReadonlySignal<boolean>
  readonly picturePublicationAccess: ReadonlySignal<PicturePublicationAccess>
  readonly picturePending: ReadonlySignal<boolean>
  /** @param pending Whether a selection is uploading or being published. */
  setPicturePending(pending: boolean): void
  /** @param picture The result of publishing the selected upload. */
  replacePicture(picture: CatalogPicture): void
}

const EMPTY_FORM: ProductEditForm = {
  name: '',
  categoryId: null,
  price: '',
  description: '',
  pictureFileId: 0,
  pictureUrl: '',
}

const FIELD_LABELS = {
  name: 'Name',
  price: 'Price',
  description: 'Description',
} as const

// The update handler uses this validation reason when its version check fails.
const STALE_PRODUCT_UPDATE_REASON =
  'The product changed elsewhere. Review the current values and save again.'

/**
 * Whether the card carries the saved values necessary to open the editor.
 *
 * @param row The current card's decoded values.
 */
function editable(row: ProductEditCard): row is AdminProduct {
  return (
    row != null &&
    Number.isSafeInteger(row.productId) &&
    row.productId > 0 &&
    Number.isSafeInteger(row.pictureFileId) &&
    row.pictureFileId > 0 &&
    Number.isSafeInteger(row.price) &&
    row.price >= 0 &&
    row.editVersion !== ''
  )
}

/**
 * Project the card onto the five fields participating in the merge.
 *
 * @param row The saved card.
 */
function fieldsOf(row: AdminProduct): ProductEditFields {
  return {
    name: row.name,
    categoryId: row.categoryId,
    price: moneyInputText(row.price),
    description: row.description,
    pictureFileId: row.pictureFileId,
  }
}

/**
 * Keep a product's row edit across live updates and connection interruptions.
 *
 * @param options The page's card, category choices, and current write access.
 */
export function createAdminProductEdit({
  card,
  awaitingFreshCard,
  categories,
  writable,
}: ProductEditOptions): AdminProductEdit {
  const current = computedSignal(() => {
    const row = card.get()
    return editable(row) ? row : undefined
  })
  const lastCard = createSignal<AdminProduct | undefined>(undefined)
  const source = hilosSignalRowEditSource(
    computedSignal(() =>
      card.get() === null ? undefined : (current.get() ?? lastCard.get()),
    ),
  )
  const picturePending = createSignal(false)
  const refusedVersion = createSignal<string | null>(null)
  let stopCard: (() => void) | undefined

  const edit = createHilosRowEdit<
    AdminProduct,
    ProductEditFields,
    ProductEditForm
  >(source, {
    initial: EMPTY_FORM,
    fields: fieldsOf,
    form: (row) => ({ ...fieldsOf(row), pictureUrl: row.pictureUrl }),
    draft: ({ name, categoryId, price, description, pictureFileId }) => ({
      name,
      categoryId,
      price: moneyInputComparisonText(price),
      description,
      pictureFileId,
    }),
    take: (form, taken, _opening, live) => ({
      ...form,
      ...taken,
      pictureUrl:
        taken.pictureFileId !== undefined &&
        live?.pictureFileId === taken.pictureFileId
          ? live.pictureUrl
          : form.pictureUrl,
    }),
    merge: { description: mergeTextLines },
    valid: ({ name, categoryId, price, description }) =>
      name.trim() !== '' &&
      price.trim() !== '' &&
      description.trim() !== '' &&
      categories.get().some(({ value }) => value === categoryId),
    notice: {
      conflict: (state) =>
        (state.notice?.fields ?? [])
          .map((field) => {
            if (field === 'pictureFileId') return 'Picture changed elsewhere.'
            if (field === 'categoryId') {
              const category = categories
                .get()
                .find(({ value }) => value === state.fields.categoryId.incoming)
              return category === undefined
                ? 'Category changed elsewhere.'
                : `Category changed elsewhere to "${category.label}".`
            }
            return `${FIELD_LABELS[field]} changed elsewhere to "${state.fields[field].incoming}".`
          })
          .join(' '),
    },
  })
  const canOpen = computedSignal(() => current.get() !== undefined)
  const writeAvailable = computedSignal(() => {
    const row = current.get()
    return (
      row !== undefined &&
      row.editVersion !== refusedVersion.get() &&
      writable.get()
    )
  })
  const picturePublicationAccess = computedSignal<PicturePublicationAccess>(
    () => {
      if (!edit.opened.get() || edit.saving.get() || !writable.get()) {
        return 'discard'
      }
      if (writeAvailable.get()) return 'apply'
      if (
        awaitingFreshCard?.get() &&
        lastCard.get()?.editVersion !== refusedVersion.get()
      ) {
        return 'wait'
      }
      return 'discard'
    },
  )
  const canUpload = computedSignal(
    () => picturePublicationAccess.get() === 'apply',
  )
  const saveAvailable = computedSignal(
    () =>
      writeAvailable.get() && !picturePending.get() && edit.saveAvailable.get(),
  )
  const canSave = computedSignal(
    () => saveAvailable.get() && !edit.saving.get(),
  )

  function start(): void {
    stopCard?.()
    lastCard.set(current.get())
    stopCard = subscribeSignal(card, (row) => {
      if (editable(row) || row === null) lastCard.set(row ?? undefined)
    })
    edit.start()
  }

  return {
    ...edit,
    canOpen,
    canUpload,
    picturePublicationAccess,
    saveAvailable,
    canSave,
    picturePending,
    setPicturePending: picturePending.set,
    replacePicture(picture) {
      if (!canUpload.get()) return
      edit.patchForm({ pictureFileId: picture.fileId, pictureUrl: picture.url })
    },
    start,
    dispose() {
      stopCard?.()
      stopCard = undefined
      edit.dispose()
    },
    open() {
      if (!canOpen.get() || edit.opened.get()) return false
      picturePending.set(false)
      refusedVersion.set(null)

      return edit.open()
    },
    async save(run) {
      if (!canSave.get()) return
      const { price } = edit.form.get()
      await edit.save(async (draft) => {
        // The SDK passes the opening row. The verdict above was checked
        // against the current live card, including any accepted changes.
        const row = current.get()
        if (row === undefined || draft.categoryId === null) return false
        const handle = sendProductUpdate({
          productId: row.productId,
          expectedVersion: row.editVersion,
          name: draft.name,
          categoryId: draft.categoryId,
          price,
          description: draft.description,
          pictureFileId:
            draft.pictureFileId === row.pictureFileId
              ? null
              : draft.pictureFileId,
        })
        const shown = run(handle)
        try {
          await handle.done
        } catch (failure) {
          if (
            failure instanceof ActionError &&
            failure.outcome === 'fail' &&
            failure.message === STALE_PRODUCT_UPDATE_REASON
          ) {
            refusedVersion.set(row.editVersion)
          }
        }
        return shown
      })
    },
  }
}
