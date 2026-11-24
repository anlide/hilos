import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  createSignal,
  ingest,
  type ActionHandle,
  type ConnectionState,
  type PageError,
  type PageRouteMatch,
} from '@hilos/core/base'

import { scopes } from '../../../src/bootstrap/session.js'
import { ADMIN_PRODUCT_DATA } from '../../../src/catalog/adminProductCard.js'
import { PAGE_ADMIN_PRODUCT } from '../../../src/pages/keys.js'
import { type AdminProduct } from '../../../src/types/AdminProduct.js'
import { PRODUCT_UPDATE } from '../../../src/views/AdminProduct/adminProductActions.js'
import {
  createAdminProductEdit,
  type ProductEditCard,
} from '../../../src/views/AdminProduct/adminProductEdit.js'
import { createAdminProductSignals } from '../../../src/views/AdminProduct/adminProductPage.js'

const wire = vi.hoisted(() => {
  const listeners = new Set<(state: ConnectionState) => void>()
  const connection = {
    state: 'connected' as ConnectionState,
    on: (_event: 'state', listener: (state: ConnectionState) => void) => {
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
  }
  return {
    connection,
    dispatch: vi.fn(),
    changeState(state: ConnectionState) {
      connection.state = state
      for (const listener of listeners) listener(state)
    },
  }
})

vi.mock('../../../src/bootstrap/connection.js', () => ({
  connection: wire.connection,
  actions: { dispatch: wire.dispatch },
}))

const FIRST_VERSION = 'a'.repeat(64)
const NEXT_VERSION = 'b'.repeat(64)
const STALE_REASON =
  'The product changed elsewhere. Review the current values and save again.'
const PRODUCT: AdminProduct = {
  productId: 7,
  name: 'Blue vase',
  slug: 'blue-vase',
  kind: 'unique',
  price: 210050,
  categoryId: 2,
  description: 'Made by hand.\nBlue glaze.',
  pictureUrl: '/pictures/blue-vase-lg.webp',
  pictureFileId: 16,
  editVersion: FIRST_VERSION,
  stock: { units: 1, warehouses: 1, warehouseId: 5 },
}
const CATEGORIES = [
  { value: 2, label: 'Vases' },
  { value: 3, label: 'Ceramics' },
]
const PICTURE = { fileId: 21, url: '/pictures/new-vase-sm.webp' }
const OTHER_PICTURE = {
  pictureFileId: 22,
  pictureUrl: '/pictures/other-vase-lg.webp',
  editVersion: NEXT_VERSION,
}

const cleanup: (() => void)[] = []

/** The tracked runner reports whether the server accepted the request. */
async function run(handle: ActionHandle): Promise<boolean> {
  return handle.done.then(
    () => true,
    () => false,
  )
}

/** Give the next save a tracked failure from the server. */
function refuse(reason: string): void {
  wire.dispatch.mockReturnValueOnce({
    requestId: 'product-update-refused',
    loading: createSignal(false),
    done: Promise.reject(new ActionError(PRODUCT_UPDATE, 'fail', reason)),
  })
}

/** Start the session on the live card, as the Overview does. */
function openEdit() {
  const card = createSignal<ProductEditCard>(PRODUCT)
  const categories = createSignal(CATEGORIES)
  const writable = createSignal(true)
  const edit = createAdminProductEdit({ card, categories, writable })
  edit.start()
  cleanup.push(edit.dispose)
  expect(edit.open()).toBe(true)

  return { card, categories, writable, edit }
}

beforeEach(() => {
  wire.changeState('connected')
  wire.dispatch.mockReset()
  wire.dispatch.mockReturnValue({
    requestId: 'product-update-saved',
    loading: createSignal(false),
    done: Promise.resolve({}),
  })
})

afterEach(() => {
  for (const dispose of cleanup.splice(0).reverse()) dispose()
  scopes.dropPage()
})

describe('product edit', () => {
  describe('opening and catalog payload', () => {
    it('prefills the saved fields and keeps the stored file on a text-only edit', async () => {
      const { edit } = openEdit()

      expect(edit.row.get()?.kind).toBe(PRODUCT.kind)
      expect(edit.form.get()).toEqual({
        name: PRODUCT.name,
        categoryId: PRODUCT.categoryId,
        price: '2 100.50',
        description: PRODUCT.description,
        pictureFileId: PRODUCT.pictureFileId,
        pictureUrl: PRODUCT.pictureUrl,
      })
      expect(edit.canSave.get()).toBe(false)

      edit.patchForm({ name: 'Tall blue vase', price: '2 101,50' })
      await edit.save(run)

      expect(wire.dispatch).toHaveBeenCalledExactlyOnceWith(PRODUCT_UPDATE, {
        productId: PRODUCT.productId,
        expectedVersion: FIRST_VERSION,
        name: 'Tall blue vase',
        categoryId: PRODUCT.categoryId,
        price: '2 101,50',
        description: PRODUCT.description,
        pictureFileId: null,
      })
    })

    it('opens only on a card with a stored picture and edit version', () => {
      const card = createSignal<ProductEditCard>({
        ...PRODUCT,
        pictureFileId: 0,
      })
      const edit = createAdminProductEdit({
        card,
        categories: createSignal(CATEGORIES),
        writable: createSignal(true),
      })

      expect(edit.open()).toBe(false)
      card.set({ ...PRODUCT, editVersion: '' })
      expect(edit.open()).toBe(false)
      card.set(PRODUCT)
      expect(edit.open()).toBe(true)
      edit.dispose()
    })
  })

  describe('independent live changes', () => {
    it.each(['2100,50', '\u00a0002\u202f100.5 ', '2\u00a0100,50'])(
      'converges on the same price and saves %s as typed',
      async (price) => {
        const { card, edit } = openEdit()
        card.set({ ...PRODUCT, price: 200000 })
        edit.patchForm({ price })
        card.set({ ...PRODUCT, editVersion: NEXT_VERSION })

        expect(edit.state.get().conflict).toBe(false)
        expect(edit.canSave.get()).toBe(false)
        expect(edit.form.get().price).toBe(price)

        edit.patchForm({ name: 'Tall blue vase' })
        await edit.save(run)
        expect(wire.dispatch).toHaveBeenCalledExactlyOnceWith(
          PRODUCT_UPDATE,
          expect.objectContaining({ expectedVersion: NEXT_VERSION, price }),
        )
      },
    )

    it('combines different fields and sends the accepted live version', async () => {
      const { card, edit } = openEdit()
      edit.patchForm({ name: 'Tall blue vase' })
      card.set({ ...PRODUCT, price: 225000, editVersion: NEXT_VERSION })

      expect(edit.form.get()).toMatchObject({
        name: 'Tall blue vase',
        price: '2 250',
      })
      expect(edit.state.get().conflict).toBe(false)
      expect(edit.state.get().notice?.kind).toBe('updated')
      await edit.save(run)

      expect(wire.dispatch).toHaveBeenCalledWith(
        PRODUCT_UPDATE,
        expect.objectContaining({
          expectedVersion: NEXT_VERSION,
          name: 'Tall blue vase',
          price: '2 250',
        }),
      )
    })

    it('converges on the same file id and retains the preview already shown', () => {
      const { card, edit } = openEdit()
      edit.replacePicture(PICTURE)
      card.set({
        ...PRODUCT,
        pictureFileId: PICTURE.fileId,
        pictureUrl: '/pictures/new-vase-lg.webp',
        editVersion: NEXT_VERSION,
      })

      expect(edit.state.get().conflict).toBe(false)
      expect(edit.canSave.get()).toBe(false)
      expect(edit.form.get().pictureUrl).toBe(PICTURE.url)
    })
  })

  describe('same-field conflicts', () => {
    it.each(['2100,51', '2100.500', '2100.50\n', '0000000000002100.50'])(
      'keeps a conflict with a different or invalid price (%j)',
      async (price) => {
        const { card, edit } = openEdit()
        card.set({ ...PRODUCT, price: 200000 })
        edit.patchForm({ price })
        card.set({ ...PRODUCT, editVersion: NEXT_VERSION })

        expect(edit.state.get().conflict).toBe(true)
        expect(edit.canSave.get()).toBe(false)
        expect(edit.form.get().price).toBe(price)
        edit.keepMine()
        await edit.save(run)
        expect(wire.dispatch).toHaveBeenCalledExactlyOnceWith(
          PRODUCT_UPDATE,
          expect.objectContaining({ expectedVersion: NEXT_VERSION, price }),
        )
      },
    )

    it.each(['keepMine', 'takeTheirs'] as const)(
      'resolves a different picture by %s',
      async (choice) => {
        const { card, edit } = openEdit()
        edit.replacePicture(PICTURE)
        card.set({ ...PRODUCT, ...OTHER_PICTURE })

        expect(edit.noticeText.get()).toBe('Picture changed elsewhere.')
        expect(edit.mergeable.get()).toBe(false)
        expect(edit.form.get().pictureUrl).toBe(PICTURE.url)
        await edit.save(run)
        expect(wire.dispatch).not.toHaveBeenCalled()

        edit[choice]()
        const keep = choice === 'keepMine'
        expect(edit.state.get().conflict).toBe(false)
        expect(edit.form.get()).toMatchObject({
          pictureFileId: keep ? PICTURE.fileId : OTHER_PICTURE.pictureFileId,
          pictureUrl: keep ? PICTURE.url : OTHER_PICTURE.pictureUrl,
        })
        edit.patchForm({ name: 'Tall blue vase' })
        await edit.save(run)
        expect(wire.dispatch).toHaveBeenCalledExactlyOnceWith(
          PRODUCT_UPDATE,
          expect.objectContaining({
            expectedVersion: NEXT_VERSION,
            pictureFileId: keep ? PICTURE.fileId : null,
          }),
        )
      },
    )

    it('offers Merge for edits to different lines of the description', () => {
      const { card, edit } = openEdit()
      edit.patchForm({ description: 'Handmade in Kyiv.\nBlue glaze.' })
      card.set({
        ...PRODUCT,
        description: 'Made by hand.\nDeep blue glaze.',
        editVersion: NEXT_VERSION,
      })

      expect(edit.state.get().conflict).toBe(true)
      expect(edit.mergeable.get()).toBe(true)
      edit.merge()
      expect(edit.form.get().description).toBe(
        'Handmade in Kyiv.\nDeep blue glaze.',
      )
      expect(edit.canSave.get()).toBe(true)
    })
  })

  describe('one verdict for Save and Enter', () => {
    it('blocks submission for a pending picture, view mode or missing choice', async () => {
      const { categories, writable, edit } = openEdit()
      edit.patchForm({ name: 'Tall blue vase' })
      expect(edit.canSave.get()).toBe(true)

      edit.setPicturePending(true)
      expect(edit.canSave.get()).toBe(false)
      await edit.save(run)
      edit.setPicturePending(false)
      writable.set(false)
      expect(edit.canUpload.get()).toBe(false)
      edit.replacePicture(PICTURE)
      expect(edit.form.get().pictureFileId).toBe(PRODUCT.pictureFileId)
      expect(edit.canSave.get()).toBe(false)
      await edit.save(run)

      writable.set(true)
      categories.set(CATEGORIES.slice(1))
      expect(edit.canSave.get()).toBe(false)
      await edit.save(run)
      expect(wire.dispatch).not.toHaveBeenCalled()
      edit.patchForm({ categoryId: CATEGORIES[1].value })
      expect(edit.canSave.get()).toBe(true)
    })

    it('waits for a new card after reconnecting, even with the same version', async () => {
      const scope = scopes.openPage(PAGE_ADMIN_PRODUCT)
      ingest(scope, { data: { [ADMIN_PRODUCT_DATA]: PRODUCT } })
      const pageError = createSignal<PageError | null>(null)
      const page = createAdminProductSignals({
        currentRoute: createSignal<PageRouteMatch>({
          page: PAGE_ADMIN_PRODUCT,
          params: { productId: String(PRODUCT.productId) },
          admin: true,
        }),
        pageError,
        pageLoading: createSignal(false),
      })
      const edit = createAdminProductEdit({
        card: page.editCard,
        categories: createSignal(CATEGORIES),
        writable: createSignal(true),
      })
      cleanup.push(page.watchFreshness(), edit.dispose)
      edit.start()
      expect(edit.open()).toBe(true)
      edit.patchForm({ name: 'Tall blue vase' })
      expect(edit.canSave.get()).toBe(true)

      wire.changeState('reconnecting')
      wire.changeState('connected')
      expect(edit.canUpload.get()).toBe(false)
      expect(edit.canSave.get()).toBe(false)
      expect(edit.saveLabel.get()).toBe('Save')
      await edit.save(run)
      expect(wire.dispatch).not.toHaveBeenCalled()

      ingest(scope, { data: { [ADMIN_PRODUCT_DATA]: { ...PRODUCT } } })
      expect(edit.canSave.get()).toBe(true)

      pageError.set({
        httpCode: 404,
        errorCode: 'not_found',
        title: 'Page Not Found',
        message: 'This product no longer exists.',
      })
      expect(edit.saveLabel.get()).toBe('Deleted')
      expect(edit.form.get().name).toBe('Tall blue vase')
    })
  })

  describe('deletion and tracked replies', () => {
    it('keeps the draft and blocks Save on a deleted card', async () => {
      const { card, edit } = openEdit()
      edit.patchForm({ description: 'A longer description to keep.' })
      card.set(null)

      expect(edit.opened.get()).toBe(true)
      expect(edit.form.get().description).toBe('A longer description to keep.')
      expect(edit.saveLabel.get()).toBe('Deleted')
      expect(edit.canSave.get()).toBe(false)
      await edit.save(run)
      expect(wire.dispatch).not.toHaveBeenCalled()
    })

    it('keeps ordinary refusals editable and closes only on success', async () => {
      const { edit } = openEdit()
      edit.patchForm({ price: 'wrong price' })
      refuse('Enter a valid product price.')

      await edit.save(run)
      expect(edit.opened.get()).toBe(true)
      expect(edit.form.get().price).toBe('wrong price')
      expect(edit.canSave.get()).toBe(true)

      edit.patchForm({ price: '4 100' })
      await edit.save(run)
      expect(wire.dispatch).toHaveBeenCalledTimes(2)
      expect(edit.opened.get()).toBe(false)
    })

    it('holds a stale refusal until a new version and an explicit resolution', async () => {
      const { card, edit } = openEdit()
      edit.patchForm({ name: 'Tall blue vase' })
      refuse(STALE_REASON)
      await edit.save(run)

      expect(edit.opened.get()).toBe(true)
      expect(edit.form.get().name).toBe('Tall blue vase')
      card.set({ ...PRODUCT, stock: { ...PRODUCT.stock, warehouseId: 9 } })
      expect(edit.canSave.get()).toBe(false)
      await edit.save(run)

      expect(edit.canUpload.get()).toBe(false)
      edit.replacePicture(PICTURE)
      expect(edit.form.get().pictureFileId).toBe(PRODUCT.pictureFileId)

      card.set({
        ...PRODUCT,
        name: 'Round blue vase',
        editVersion: NEXT_VERSION,
      })
      expect(edit.canUpload.get()).toBe(true)
      expect(edit.canSave.get()).toBe(false)
      expect(edit.state.get().conflict).toBe(true)
      edit.keepMine()
      expect(edit.canSave.get()).toBe(true)
      expect(wire.dispatch).toHaveBeenCalledTimes(1)
      await edit.save(run)
      expect(wire.dispatch).toHaveBeenLastCalledWith(
        PRODUCT_UPDATE,
        expect.objectContaining({ expectedVersion: NEXT_VERSION }),
      )
      expect(edit.opened.get()).toBe(false)
    })
  })
})
