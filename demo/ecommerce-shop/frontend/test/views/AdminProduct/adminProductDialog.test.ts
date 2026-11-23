// @vitest-environment happy-dom

import { act, createElement } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  bindAdminAccess,
  bindPageScope,
  bindUploads,
  createHilosRouter,
  hilosAdminAccess,
  hilosUploads,
  ingest,
  pageResponseSchema,
  pageSubscriptionErrorSchema,
  SIGNAL_TYPE_PAGE_RESPONSE,
  SIGNAL_TYPE_PAGE_SUBSCRIBE,
  SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR,
  SIGNAL_UPLOAD_STATE,
  UPLOAD_ACTION_CANCEL,
  UPLOAD_ACTION_INIT,
  UPLOAD_PHASE_COMPLETE,
  UPLOAD_PHASE_UPLOADING,
  USER_ENTITY_TYPE,
  type ConnectionState,
  type HilosConnection,
} from '@hilos/core/base'
import { HilosRouterContext, HilosView } from '@hilos/react/base'

import { actions, connection } from '../../../src/bootstrap/connection.js'
import { currentUserId, scopes } from '../../../src/bootstrap/session.js'
import { ADMIN_PRODUCT_DATA } from '../../../src/catalog/adminProductCard.js'
import { ADMIN_PRODUCT_CATEGORIES_LIST } from '../../../src/catalog/catalog.js'
import {
  PAGE_ADMIN_PRODUCT,
  PAGE_ADMIN_PRODUCTS,
} from '../../../src/pages/keys.js'
import { router as routes } from '../../../src/pages/routes.js'
import { type AdminProduct as Product } from '../../../src/types/AdminProduct.js'
import { ADMIN_PRODUCT_WAREHOUSES_LIST } from '../../../src/views/AdminProduct/adminProduct.js'
import AdminProduct from '../../../src/views/AdminProduct/AdminProduct.js'
import {
  PRODUCT_UPDATE,
  PRODUCT_WAREHOUSE_PLACE,
} from '../../../src/views/AdminProduct/adminProductActions.js'
import { PRODUCT_PICTURE_PUBLISH } from '../../../src/views/AdminProducts/adminProductsActions.js'
import { ProductAddModal } from '../../../src/views/AdminProducts/ProductAddModal.js'

const wire = vi.hoisted(() => {
  const listeners = new Map<string, Set<(payload: unknown) => void>>()
  const connection = {
    state: 'connected' as ConnectionState,
    reconnectDragging: false,
    send: vi.fn<HilosConnection['send']>(() => true),
    sendAction: vi.fn<HilosConnection['sendAction']>(() => true),
    forgetPageFrames: () => {},
    tableWindowDescriptors: () => ({}),
    on(event: string, listener: (payload: unknown) => void) {
      const members = listeners.get(event) ?? new Set()
      members.add(listener)
      listeners.set(event, members)
      return () => members.delete(listener)
    },
  }
  function emit(event: string, payload: unknown): void {
    for (const listener of listeners.get(event) ?? []) listener(payload)
  }
  return {
    connection,
    listeners,
    emit,
    changeState(state: ConnectionState) {
      connection.state = state
      emit('state', state)
    },
  }
})

vi.mock('../../../src/bootstrap/connection.js', async () => {
  const { ActionLifecycle } = await import('@hilos/core/base')
  return {
    connection: wire.connection,
    actions: new ActionLifecycle(wire.connection as unknown as HilosConnection),
  }
})

const FIRST_VERSION = 'a'.repeat(64)
const PRODUCT: Product = {
  productId: 7,
  name: 'Blue vase',
  kind: 'unique',
  price: 210050,
  categoryId: 2,
  description: 'Made by hand.',
  pictureUrl: '/pictures/blue-vase-lg.webp',
  pictureFileId: 16,
  editVersion: FIRST_VERSION,
  stock: { units: 0, warehouses: 0, warehouseId: null },
}
const CHOSEN_CATEGORY = { id: 3, name: 'Ceramics', position: 2 }
const CATEGORIES = [
  { id: PRODUCT.categoryId, name: 'Vases', position: 1 },
  CHOSEN_CATEGORY,
]
const CHOSEN_WAREHOUSE = { id: 5, name: 'Kyiv · Podil' }
const OTHER_WAREHOUSE = { id: 8, name: 'Lviv · Sykhiv' }
const SECOND_PRODUCT = { ...PRODUCT, productId: 8, name: 'Clay jug' }
const RECONNECT_NOT_FOUND = {
  page: PAGE_ADMIN_PRODUCT,
  httpCode: 404,
  errorCode: 'not_found',
  title: 'Page Not Found',
  message: 'The page you are looking for does not exist.',
}
const DRAFT_NAME = 'A name to copy'
const DRAFT_DESCRIPTION = 'A description to copy'
const STALE_REASON =
  'The product changed elsewhere. Review the current values and save again.'
const FILE = 'vase.jpg'
const ADMIN_ID = 1
const PUBLISHED_PICTURE = { fileId: 27, picture: '/pictures/vase.webp' }
const release: (() => void)[] = []
let root: Root | undefined

function projectFrame(type: string, data: unknown): void {
  wire.emit('projectSignal', { type, data })
}

function cardFrame(product: Product, page = PAGE_ADMIN_PRODUCT): void {
  projectFrame(
    SIGNAL_TYPE_PAGE_RESPONSE,
    pageResponseSchema.parse({
      page,
      payload: {
        data: { [ADMIN_PRODUCT_DATA]: product },
        lists: {
          [ADMIN_PRODUCT_CATEGORIES_LIST]: {
            items: CATEGORIES.map((category) => ({
              itemKey: category.id,
              slots: { categories: category },
            })),
          },
        },
      },
    }),
  )
}

function warehousesFrame(
  items: (typeof CHOSEN_WAREHOUSE)[],
  deleted: number[] = [],
): void {
  projectFrame(
    SIGNAL_TYPE_PAGE_RESPONSE,
    pageResponseSchema.parse({
      page: PAGE_ADMIN_PRODUCT,
      payload: {
        lists: {
          [ADMIN_PRODUCT_WAREHOUSES_LIST]: {
            items: items.map((warehouse) => ({
              itemKey: warehouse.id,
              slots: { warehouses: warehouse },
            })),
            deleted,
          },
        },
      },
    }),
  )
}

function missing(transition?: string): void {
  projectFrame(
    SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR,
    pageSubscriptionErrorSchema.parse({
      ...RECONNECT_NOT_FOUND,
      ...(transition === undefined ? {} : { transitionMessage: transition }),
    }),
  )
}

async function mount() {
  const pages = bindPageScope(connection, scopes)
  let path = routes.path(PAGE_ADMIN_PRODUCT, {
    productId: String(PRODUCT.productId),
  })!
  const router = createHilosRouter(routes, pages, {
    pathname: () => path,
    pushState: (next) => {
      path = next
    },
    replaceState: (next) => {
      path = next
    },
    onPopState: () => () => {},
  })
  function Add() {
    return createElement(ProductAddModal, { onClose: () => {} })
  }
  const container = document.body.appendChild(document.createElement('div'))
  root = createRoot(container)
  router.start()
  pages.releaseOnSession()
  await act(async () => {
    root!.render(
      createElement(
        HilosRouterContext.Provider,
        { value: router },
        createElement(HilosView, {
          pages: {
            [PAGE_ADMIN_PRODUCT]: AdminProduct,
            [PAGE_ADMIN_PRODUCTS]: Add,
          },
        }),
      ),
    )
  })
  expect(wire.connection.send).toHaveBeenCalledWith(
    JSON.stringify({
      type: SIGNAL_TYPE_PAGE_SUBSCRIBE,
      page: PAGE_ADMIN_PRODUCT,
      params: { productId: String(PRODUCT.productId) },
    }),
  )
  return { pages, router }
}

function control<T extends HTMLElement>(dataId: string): T {
  const node = document.querySelector<T>(`[data-id="${dataId}"]`)
  if (!node) throw new Error(`Missing control ${dataId}`)
  return node
}

function input<T extends HTMLElement>(name: string): T {
  const label = [...document.querySelectorAll('label')].find(
    (item) => item.textContent === name,
  )
  if (!label?.control) throw new Error(`Missing field ${name}`)
  return label.control as T
}

function type(
  field: HTMLInputElement | HTMLTextAreaElement,
  value: string,
): void {
  Object.getOwnPropertyDescriptor(
    Object.getPrototypeOf(field),
    'value',
  )!.set!.call(field, value)
  field.dispatchEvent(new Event('input', { bubbles: true }))
}

function chooseCategory(): void {
  const field = input<HTMLSelectElement>('Category')
  field.value = String(CHOSEN_CATEGORY.id)
  field.dispatchEvent(new Event('change', { bubbles: true }))
}

function chooseWarehouse(field: HTMLSelectElement): void {
  field.value = String(CHOSEN_WAREHOUSE.id)
  field.dispatchEvent(new Event('change', { bubbles: true }))
}

function submit(): void {
  // The native submit event is also the form's entry point for implicit Enter.
  control('admin-product-edit').dispatchEvent(
    new Event('submit', { bubbles: true, cancelable: true }),
  )
}

async function openEditor(): Promise<void> {
  await act(async () => control('admin-product-edit-open').click())
}

async function openWarehouseEditor(): Promise<void> {
  await act(async () => control('admin-product-warehouse-open').click())
}

async function pressKey(key: string): Promise<void> {
  await act(async () => {
    document.activeElement?.dispatchEvent(
      new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }),
    )
  })
}

function sent(action: string) {
  return wire.connection.sendAction.mock.calls.filter(
    ([name]) => name === action,
  )
}

function finish(action: string, reason?: string): void {
  const [, , requestId] = sent(action).at(-1)!
  wire.emit(reason === undefined ? 'actionSuccess' : 'actionError', {
    action,
    requestId,
    reason,
  })
}

function completeUpload(clientUploadId: string): void {
  finish(UPLOAD_ACTION_INIT)
  projectFrame(SIGNAL_UPLOAD_STATE, {
    clientUploadId,
    phase: UPLOAD_PHASE_COMPLETE,
  })
}

function publishReply(): void {
  const [, , requestId] = sent(PRODUCT_PICTURE_PUBLISH).at(-1)!
  wire.emit('actionSuccess', {
    action: PRODUCT_PICTURE_PUBLISH,
    requestId,
    reply: PUBLISHED_PICTURE,
  })
}

function jpeg(): File {
  return new File(['picture'], FILE, { type: 'image/jpeg' })
}

function pick(id = 'admin-product-edit-picture-input', file = jpeg()): void {
  const chooser = control<HTMLInputElement>(id)
  Object.defineProperty(chooser, 'files', {
    configurable: true,
    value: [file],
  })
  chooser.dispatchEvent(new Event('change', { bubbles: true }))
}

function drop(): void {
  const event = new Event('drop', { bubbles: true, cancelable: true })
  Object.defineProperty(event, 'dataTransfer', {
    value: { types: ['Files'], files: [jpeg()] },
  })
  control('admin-product-edit-drop').dispatchEvent(event)
}

function signInAs(id: number, admin = true): void {
  ingest(
    scopes.session,
    {
      data: { adminViewMode: true },
      entities: { currentUser: { id, admin } },
    },
    {
      entityTypes: { currentUser: USER_ENTITY_TYPE },
    },
  )
}

beforeEach(() => {
  vi.stubGlobal('IS_REACT_ACT_ENVIRONMENT', true)
  wire.connection.send.mockClear()
  wire.connection.sendAction.mockClear()
  wire.changeState('connected')
  signInAs(ADMIN_ID)
  release.push(
    bindAdminAccess(scopes),
    bindUploads(connection, actions, currentUserId),
  )
})

afterEach(async () => {
  await act(async () => {
    root?.unmount()
    wire.changeState('disconnected')
  })
  root = undefined
  for (const stop of release.splice(0).reverse()) stop()
  // The mocked bootstrap keeps the action lifecycle's listeners for the next test.
  wire.listeners.delete('projectSignal')
  scopes.dropPage()
  document.body.replaceChildren()
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('the product editor through a real page subscription and outlet', () => {
  it('holds the draft when the same address returns a plain 404 after reconnect', async () => {
    const { pages } = await mount()
    await act(async () => cardFrame(PRODUCT))
    await openEditor()
    await act(async () => {
      type(input<HTMLInputElement>('Name'), DRAFT_NAME)
      chooseCategory()
    })
    expect(
      control<HTMLButtonElement>('admin-product-edit-submit').disabled,
    ).toBe(false)

    await act(async () => {
      wire.changeState('reconnecting')
      wire.changeState('connected')
      pages.releaseOnSession()
    })
    expect(control('admin-product-edit-submit').textContent).toBe('Save')
    expect(
      control<HTMLButtonElement>('admin-product-edit-submit').disabled,
    ).toBe(true)
    expect(wire.connection.send).toHaveBeenCalledTimes(2)
    await act(async () => missing())

    expect(control('admin-product-edit-submit').textContent).toBe('Deleted')
    const name = input<HTMLInputElement>('Name')
    expect(name.value).toBe(DRAFT_NAME)
    expect(name.readOnly).toBe(true)
    const category = input<HTMLInputElement>('Category')
    expect(category.value).toBe(CHOSEN_CATEGORY.name)
    expect(category.readOnly).toBe(true)
    await act(async () => {
      control('admin-product-edit-submit').click()
      submit()
    })
    expect(sent(PRODUCT_UPDATE)).toHaveLength(0)
  })

  it('holds the copyable description on the live resource deletion frame', async () => {
    await mount()
    await act(async () => cardFrame(PRODUCT))
    await openEditor()
    await act(async () =>
      type(input<HTMLTextAreaElement>('Description'), DRAFT_DESCRIPTION),
    )
    expect(
      control<HTMLButtonElement>('admin-product-edit-submit').disabled,
    ).toBe(false)
    await act(async () => missing('It was deleted just now.'))

    const description = input<HTMLTextAreaElement>('Description')
    expect(description.value).toBe(DRAFT_DESCRIPTION)
    expect(description.readOnly).toBe(true)
    const save = control<HTMLButtonElement>('admin-product-edit-submit')
    expect(save.textContent).toBe('Deleted')
    expect(save.disabled).toBe(true)
    await act(async () => {
      save.click()
      submit()
    })
    expect(sent(PRODUCT_UPDATE)).toHaveLength(0)
  })

  it('keeps the renamed Warehouse draft copyable after its choice and product disappear', async () => {
    await mount()
    await act(async () => {
      cardFrame(PRODUCT)
      warehousesFrame([CHOSEN_WAREHOUSE, OTHER_WAREHOUSE])
    })
    await openWarehouseEditor()
    const field = input<HTMLSelectElement>('Warehouse')
    const save = control<HTMLButtonElement>('admin-product-warehouse-submit')
    await act(async () => chooseWarehouse(field))
    expect(save.disabled).toBe(false)

    const renamed = { ...CHOSEN_WAREHOUSE, name: 'Kyiv · Obolon' }
    await act(async () => warehousesFrame([renamed]))
    expect(field.selectedOptions[0]?.textContent).toBe(renamed.name)
    await act(async () => warehousesFrame([], [CHOSEN_WAREHOUSE.id]))
    expect(field.value).toBe(String(CHOSEN_WAREHOUSE.id))
    expect(field.disabled).toBe(false)
    expect(save.disabled).toBe(true)

    field.focus()
    await act(async () => missing('It was deleted just now.'))

    const draft = input<HTMLInputElement>('Warehouse')
    expect(draft).toBeInstanceOf(HTMLInputElement)
    expect(draft.value).toBe(renamed.name)
    expect(draft.readOnly).toBe(true)
    expect(draft.disabled).toBe(false)
    expect(document.activeElement?.textContent).toBe('Cancel')
    expect(save.textContent).toBe('Deleted')
    expect(save.disabled).toBe(true)
    await act(async () => {
      save.click()
      draft.form!.dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }),
      )
    })
    expect(sent(PRODUCT_WAREHOUSE_PLACE)).toHaveLength(0)

    await pressKey('Tab')
    expect(document.activeElement).toBe(control('modal-close'))
    await pressKey('Escape')
    expect(document.activeElement).toBe(control('modal-confirm'))
    await act(async () => control('modal-confirm-discard').click())
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })

  it('keeps the Warehouse id copyable if no name was available', async () => {
    await mount()
    await act(async () => {
      cardFrame({
        ...PRODUCT,
        stock: { units: 1, warehouses: 1, warehouseId: CHOSEN_WAREHOUSE.id },
      })
      warehousesFrame([OTHER_WAREHOUSE])
    })
    await openWarehouseEditor()
    await act(async () => missing('It was deleted just now.'))

    const field = input<HTMLInputElement>('Warehouse')
    expect(field).toBeInstanceOf(HTMLInputElement)
    expect(field.readOnly).toBe(true)
    expect(field.value).toBe(String(CHOSEN_WAREHOUSE.id))
  })

  it('moves focus before disabling Warehouse when the final choice disappears', async () => {
    await mount()
    await act(async () => {
      cardFrame(PRODUCT)
      warehousesFrame([CHOSEN_WAREHOUSE])
    })
    await openWarehouseEditor()
    const field = input<HTMLSelectElement>('Warehouse')
    expect(document.activeElement).toBe(field)
    const disabledOnBlur = vi.fn(() => field.disabled)
    field.addEventListener('blur', disabledOnBlur)

    await act(async () => warehousesFrame([], [CHOSEN_WAREHOUSE.id]))

    expect(field.disabled).toBe(true)
    expect(disabledOnBlur).toHaveReturnedWith(false)
    expect(document.activeElement?.textContent).toBe('Cancel')
    await pressKey('Tab')
    expect(document.activeElement).toBe(control('modal-close'))
    await pressKey('Escape')
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })

  it('moves focus before disabling Put when its target disappears', async () => {
    await mount()
    await act(async () => {
      cardFrame(PRODUCT)
      warehousesFrame([CHOSEN_WAREHOUSE, OTHER_WAREHOUSE])
    })
    await openWarehouseEditor()
    const field = input<HTMLSelectElement>('Warehouse')
    const save = control<HTMLButtonElement>('admin-product-warehouse-submit')
    await act(async () => chooseWarehouse(field))
    expect(save.disabled).toBe(false)
    save.focus()
    const disabledOnBlur = vi.fn(() => save.disabled)
    save.addEventListener('blur', disabledOnBlur)

    await act(async () => warehousesFrame([], [CHOSEN_WAREHOUSE.id]))

    expect(save.disabled).toBe(true)
    expect(disabledOnBlur).toHaveReturnedWith(false)
    expect(document.activeElement).toBe(field)
    await act(async () => {
      save.click()
      field.form!.dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }),
      )
    })
    expect(sent(PRODUCT_WAREHOUSE_PLACE)).toHaveLength(0)

    await pressKey('Escape')
    const confirm = control('modal-confirm')
    expect(document.activeElement).toBe(confirm)
    await act(async () => warehousesFrame([], [OTHER_WAREHOUSE.id]))
    expect(document.activeElement).toBe(confirm)
    await act(async () => control('modal-confirm-discard').click())
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })

  it('draws the normal 404 on a first visit and when changing product addresses', async () => {
    const { router } = await mount()
    await act(async () => missing())
    expect(control('page-error').textContent).toContain(
      RECONNECT_NOT_FOUND.title,
    )
    expect(document.querySelector('[role="dialog"]')).toBeNull()

    await act(async () => cardFrame(PRODUCT))
    await openEditor()
    await act(async () => type(input<HTMLInputElement>('Name'), DRAFT_NAME))
    await act(async () => {
      router.navigate(
        router.resolvePath(PAGE_ADMIN_PRODUCT, {
          productId: String(SECOND_PRODUCT.productId),
        })!,
      )
      missing()
    })
    expect(control('page-error').textContent).toContain(
      RECONNECT_NOT_FOUND.title,
    )
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })

  it('blocks both upload doors on a stale refusal until a fresh card arrives', async () => {
    await mount()
    await act(async () => cardFrame(PRODUCT))
    await openEditor()
    await act(async () => {
      type(input<HTMLInputElement>('Name'), DRAFT_NAME)
      submit()
      finish(PRODUCT_UPDATE, STALE_REASON)
    })
    const chooser = control<HTMLButtonElement>('admin-product-edit-picture')
    await act(async () => {
      pick()
      drop()
      // Any mistakenly accepted file can already have completed at the server.
      for (const upload of hilosUploads.get()) {
        projectFrame(SIGNAL_UPLOAD_STATE, {
          clientUploadId: upload.clientUploadId,
          phase: UPLOAD_PHASE_COMPLETE,
        })
      }
    })
    expect(sent(PRODUCT_PICTURE_PUBLISH)).toHaveLength(0)
    expect(sent(UPLOAD_ACTION_INIT)).toHaveLength(0)
    expect(chooser.disabled).toBe(true)

    await act(async () =>
      cardFrame({ ...PRODUCT, editVersion: 'b'.repeat(64) }),
    )
    expect(chooser.disabled).toBe(false)
    expect(sent(PRODUCT_UPDATE)).toHaveLength(1)
    await act(async () => control('admin-product-edit-submit').click())
    expect(sent(PRODUCT_UPDATE)).toHaveLength(2)
  })

  it.each(['remove', 'replace'] as const)(
    'ends ownership on %s even when the cancel never succeeds',
    async (ending) => {
      const { pages } = await mount()
      await act(async () => cardFrame(PRODUCT))
      await openEditor()
      vi.useFakeTimers()
      await act(async () => {
        type(input<HTMLInputElement>('Name'), DRAFT_NAME)
        pick()
      })
      const [old] = hilosUploads.get()
      const replacement = new File(['new picture'], 'jug.jpg', {
        type: 'image/jpeg',
      })
      await act(async () => {
        finish(UPLOAD_ACTION_INIT)
        projectFrame(SIGNAL_UPLOAD_STATE, {
          clientUploadId: old.clientUploadId,
          phase: UPLOAD_PHASE_UPLOADING,
        })
      })
      await act(async () => {
        if (ending === 'remove') {
          control('admin-product-edit-upload-file-remove').click()
        } else {
          pick(undefined, replacement)
        }
      })
      if (ending === 'remove') {
        await act(async () => vi.runOnlyPendingTimersAsync())
        expect(
          control<HTMLButtonElement>('admin-product-edit-submit').disabled,
        ).toBe(false)
        await act(async () => pick(undefined, replacement))
      } else {
        await act(async () =>
          finish(UPLOAD_ACTION_CANCEL, 'The upload could not be canceled.'),
        )
      }
      expect(sent(UPLOAD_ACTION_CANCEL)).toHaveLength(1)
      expect(hilosUploads.get().map(({ filename }) => filename)).toEqual([
        replacement.name,
      ])
      const [selected] = hilosUploads.get()
      expect(
        document.querySelector('[role="dialog"]')?.textContent,
      ).not.toContain(FILE)

      await act(async () => finish(UPLOAD_ACTION_INIT))
      await act(async () => {
        wire.changeState('reconnecting')
        wire.changeState('connected')
        pages.releaseOnSession()
        cardFrame({ ...PRODUCT })
      })
      expect(sent(UPLOAD_ACTION_INIT).map(([, payload]) => payload)).toEqual([
        expect.objectContaining({ clientUploadId: old.clientUploadId }),
        expect.objectContaining({ clientUploadId: selected.clientUploadId }),
        expect.objectContaining({ clientUploadId: selected.clientUploadId }),
      ])
      await act(async () => completeUpload(selected.clientUploadId))
      expect(sent(PRODUCT_PICTURE_PUBLISH)).toEqual([
        expect.arrayContaining([
          PRODUCT_PICTURE_PUBLISH,
          { clientUploadId: selected.clientUploadId },
        ]),
      ])
      await act(async () => publishReply())
      expect(
        control<HTMLButtonElement>('admin-product-edit-submit').disabled,
      ).toBe(false)
    },
  )

  it('applies the received publication once a fresh card allows editing again', async () => {
    const { pages } = await mount()
    await act(async () => cardFrame(PRODUCT))
    await openEditor()
    await act(async () => {
      type(input<HTMLInputElement>('Name'), DRAFT_NAME)
      pick()
    })
    const [{ clientUploadId }] = hilosUploads.get()
    await act(async () => completeUpload(clientUploadId))

    await act(async () => {
      // Publication removes the temporary upload before its tracked reply.
      projectFrame(SIGNAL_UPLOAD_STATE, { clientUploadId })
      publishReply()
      wire.changeState('reconnecting')
    })
    const preview = control<HTMLImageElement>('admin-product-edit-preview')
    expect(preview.getAttribute('src')).toBe(PRODUCT.pictureUrl)
    await act(async () => {
      wire.changeState('connected')
      pages.releaseOnSession()
      submit()
    })
    expect(
      control<HTMLButtonElement>('admin-product-edit-submit').disabled,
    ).toBe(true)
    expect(sent(PRODUCT_UPDATE)).toHaveLength(0)

    await act(async () => cardFrame({ ...PRODUCT }))
    expect(preview.getAttribute('src')).toBe(PUBLISHED_PICTURE.picture)
    expect(sent(PRODUCT_PICTURE_PUBLISH)).toHaveLength(1)
    await act(async () => submit())
    expect(sent(PRODUCT_UPDATE)).toEqual([
      expect.arrayContaining([
        PRODUCT_UPDATE,
        expect.objectContaining({ pictureFileId: PUBLISHED_PICTURE.fileId }),
      ]),
    ])
  })

  it.each(['identity change', 'view mode'] as const)(
    'discards a received publication after %s before a fresh card',
    async (changed) => {
      const { pages } = await mount()
      await act(async () => cardFrame(PRODUCT))
      await openEditor()
      await act(async () => pick())
      const [{ clientUploadId }] = hilosUploads.get()
      await act(async () => completeUpload(clientUploadId))
      await act(async () => {
        projectFrame(SIGNAL_UPLOAD_STATE, { clientUploadId })
        publishReply()
        wire.changeState('reconnecting')
      })

      await act(async () => {
        if (changed === 'identity change') {
          signInAs(2)
        } else {
          signInAs(ADMIN_ID, false)
          expect(hilosAdminAccess.get()).toBe('view')
        }
        signInAs(ADMIN_ID)
        wire.changeState('connected')
        pages.releaseOnSession()
        cardFrame({ ...PRODUCT })
      })

      const preview = control<HTMLImageElement>('admin-product-edit-preview')
      expect(preview.getAttribute('src')).toBe(PRODUCT.pictureUrl)
      expect(sent(PRODUCT_PICTURE_PUBLISH)).toHaveLength(1)
    },
  )

  it('shows the domain refusal after removing the refused publication chip', async () => {
    const reason = 'The picture is no longer available.'
    await mount()
    await act(async () => cardFrame(PRODUCT))
    await openEditor()
    await act(async () => pick())
    const [{ clientUploadId }] = hilosUploads.get()
    await act(async () => completeUpload(clientUploadId))

    await act(async () => finish(PRODUCT_PICTURE_PUBLISH, reason))

    expect(hilosUploads.get()).toEqual([])
    expect(control('admin-product-edit-error').textContent).toContain(reason)
    expect(
      control<HTMLImageElement>('admin-product-edit-preview').getAttribute(
        'src',
      ),
    ).toBe(PRODUCT.pictureUrl)
  })

  it('distinguishes a publication the connection never sent', async () => {
    await mount()
    await act(async () => cardFrame(PRODUCT))
    await openEditor()
    await act(async () => pick())
    const [{ clientUploadId }] = hilosUploads.get()

    wire.connection.sendAction.mockReturnValueOnce(false)
    await act(async () => completeUpload(clientUploadId))

    expect(sent(PRODUCT_PICTURE_PUBLISH)).toHaveLength(1)
    expect(control('admin-product-edit-error').textContent).toContain(
      'The connection dropped before the action completed.',
    )
    expect(hilosUploads.get()).toMatchObject([{ clientUploadId }])
  })

  it.each(['disconnect', 'disconnect after gone', 'timeout'] as const)(
    'retires an unanswered publication on %s and allows a fresh choice',
    async (ending) => {
      const { pages } = await mount()
      await act(async () => cardFrame(PRODUCT))
      await openEditor()
      vi.useFakeTimers()
      await act(async () => {
        type(input<HTMLInputElement>('Name'), DRAFT_NAME)
        pick()
      })
      const [{ clientUploadId }] = hilosUploads.get()
      await act(async () => completeUpload(clientUploadId))
      expect(sent(PRODUCT_PICTURE_PUBLISH)).toHaveLength(1)

      const save = control<HTMLButtonElement>('admin-product-edit-submit')
      expect(save.disabled).toBe(true)
      await act(async () => submit())
      expect(sent(PRODUCT_UPDATE)).toHaveLength(0)

      if (ending === 'timeout') {
        await act(async () => vi.runOnlyPendingTimersAsync())
      }
      if (ending === 'disconnect after gone') {
        await act(async () =>
          projectFrame(SIGNAL_UPLOAD_STATE, { clientUploadId }),
        )
      }
      if (ending !== 'timeout') {
        await act(async () => wire.changeState('reconnecting'))
      }

      const error = control('admin-product-edit-error')
      expect(error.textContent).toContain(
        'Picture publication outcome is unknown.',
      )
      expect(hilosUploads.get()).toEqual([])
      expect(input<HTMLInputElement>('Name').value).toBe(DRAFT_NAME)
      expect(
        control<HTMLImageElement>('admin-product-edit-preview').getAttribute(
          'src',
        ),
      ).toBe(PRODUCT.pictureUrl)

      if (ending !== 'timeout') {
        await act(async () => {
          wire.changeState('connected')
          pages.releaseOnSession()
          submit()
        })
        expect(save.disabled).toBe(true)
        expect(sent(PRODUCT_UPDATE)).toHaveLength(0)
        await act(async () => cardFrame({ ...PRODUCT }))
      }
      expect(save.disabled).toBe(false)
      expect(error.textContent).toContain(
        'Picture publication outcome is unknown.',
      )
      expect(sent(UPLOAD_ACTION_INIT)).toHaveLength(1)
      expect(sent(PRODUCT_PICTURE_PUBLISH)).toHaveLength(1)

      await act(async () => {
        if (ending === 'timeout') {
          publishReply()
          save.click()
        } else {
          submit()
        }
      })
      expect(sent(PRODUCT_UPDATE)).toEqual([
        expect.arrayContaining([
          PRODUCT_UPDATE,
          expect.objectContaining({ name: DRAFT_NAME, pictureFileId: null }),
        ]),
      ])
      await act(async () =>
        finish(PRODUCT_UPDATE, 'The name is already taken.'),
      )
      await act(async () => pick())
      expect(error.textContent).not.toContain(
        'Picture publication outcome is unknown.',
      )
      const [fresh] = hilosUploads.get()
      expect(fresh.clientUploadId).not.toBe(clientUploadId)
      await act(async () => completeUpload(fresh.clientUploadId))
      expect(sent(PRODUCT_PICTURE_PUBLISH)).toHaveLength(2)
      await act(async () => publishReply())
      expect(
        control<HTMLImageElement>('admin-product-edit-preview').getAttribute(
          'src',
        ),
      ).toBe(PUBLISHED_PICTURE.picture)
    },
  )

  it('leaves a newer pick alone when the earlier publication loses its reply', async () => {
    await mount()
    await act(async () => cardFrame(PRODUCT))
    await openEditor()
    vi.useFakeTimers()
    await act(async () => pick())
    const [first] = hilosUploads.get()
    await act(async () => completeUpload(first.clientUploadId))

    await act(async () => pick())
    const [next] = hilosUploads.get()
    expect(next.clientUploadId).not.toBe(first.clientUploadId)
    await act(async () => finish(UPLOAD_ACTION_INIT))
    await act(async () => vi.runOnlyPendingTimersAsync())

    expect(hilosUploads.get()).toMatchObject([
      { clientUploadId: next.clientUploadId },
    ])
    expect(control('admin-product-edit-error').textContent).not.toContain(
      'Picture publication outcome is unknown.',
    )
    await act(async () =>
      projectFrame(SIGNAL_UPLOAD_STATE, {
        clientUploadId: next.clientUploadId,
        phase: UPLOAD_PHASE_COMPLETE,
      }),
    )
    expect(sent(PRODUCT_PICTURE_PUBLISH).map(([, payload]) => payload)).toEqual(
      [
        { clientUploadId: first.clientUploadId },
        { clientUploadId: next.clientUploadId },
      ],
    )
    await act(async () => publishReply())
    expect(
      control<HTMLImageElement>('admin-product-edit-preview').getAttribute(
        'src',
      ),
    ).toBe(PUBLISHED_PICTURE.picture)
  })

  it.each(['Edit', 'Add'] as const)(
    'keeps a discarded upload out of replay and the next %s after cancel is refused',
    async (next) => {
      const { pages, router } = await mount()
      await act(async () => cardFrame(PRODUCT))
      await openEditor()
      await act(async () => pick())
      expect(sent(UPLOAD_ACTION_INIT)).toHaveLength(1)
      const [upload] = hilosUploads.get()
      await act(async () => {
        finish(UPLOAD_ACTION_INIT)
        projectFrame(SIGNAL_UPLOAD_STATE, {
          clientUploadId: upload.clientUploadId,
          phase: UPLOAD_PHASE_UPLOADING,
        })
        control('admin-product-edit-cancel').click()
      })
      await act(async () => control('modal-confirm-cancel').click())
      expect(hilosUploads.get()).toMatchObject([
        { clientUploadId: upload.clientUploadId },
      ])
      expect(sent(UPLOAD_ACTION_CANCEL)).toHaveLength(0)
      await act(async () => control('admin-product-edit-cancel').click())
      await act(async () => control('modal-confirm-discard').click())
      expect(sent(UPLOAD_ACTION_CANCEL)).toHaveLength(1)
      await act(async () => {
        finish(UPLOAD_ACTION_CANCEL, 'The upload could not be canceled.')
      })

      await act(async () => {
        if (next === 'Edit') {
          router.navigate(
            router.resolvePath(PAGE_ADMIN_PRODUCT, {
              productId: String(SECOND_PRODUCT.productId),
            })!,
          )
          cardFrame(SECOND_PRODUCT)
        } else {
          router.navigate(router.resolvePath(PAGE_ADMIN_PRODUCTS)!)
          cardFrame(SECOND_PRODUCT, PAGE_ADMIN_PRODUCTS)
        }
      })
      await act(async () => {
        wire.changeState('reconnecting')
        wire.changeState('connected')
        pages.releaseOnSession()
      })
      await act(async () => {
        cardFrame(
          { ...SECOND_PRODUCT },
          next === 'Edit' ? PAGE_ADMIN_PRODUCT : PAGE_ADMIN_PRODUCTS,
        )
      })
      expect(sent(UPLOAD_ACTION_INIT).map(([, payload]) => payload)).toEqual([
        expect.objectContaining({ clientUploadId: upload.clientUploadId }),
      ])
      await act(async () => {
        projectFrame(SIGNAL_UPLOAD_STATE, {
          clientUploadId: upload.clientUploadId,
          phase: UPLOAD_PHASE_COMPLETE,
        })
      })
      if (next === 'Edit') {
        await openEditor()
        await act(async () => type(input<HTMLInputElement>('Name'), DRAFT_NAME))
        expect(
          control<HTMLButtonElement>('admin-product-edit-submit').disabled,
        ).toBe(false)
        await act(async () => control('admin-product-edit-submit').click())
        expect(sent(PRODUCT_UPDATE)).toEqual([
          expect.arrayContaining([
            PRODUCT_UPDATE,
            expect.objectContaining({
              productId: SECOND_PRODUCT.productId,
              pictureFileId: null,
            }),
          ]),
        ])
      }
      expect(
        document.querySelector('[role="dialog"]')?.textContent,
      ).not.toContain(FILE)
      expect(sent(PRODUCT_PICTURE_PUBLISH)).toHaveLength(0)
      if (next === 'Add') {
        const newFile = new File(['new picture'], 'jug.jpg', {
          type: 'image/jpeg',
        })
        await act(async () => pick('admin-products-add-picture-input', newFile))
        const fresh = hilosUploads.get().at(-1)!
        expect(fresh.clientUploadId).not.toBe(upload.clientUploadId)
        expect(sent(UPLOAD_ACTION_INIT).map(([, payload]) => payload)).toEqual([
          expect.objectContaining({ clientUploadId: upload.clientUploadId }),
          expect.objectContaining({
            clientUploadId: fresh.clientUploadId,
            filename: newFile.name,
          }),
        ])
        await act(async () => {
          finish(UPLOAD_ACTION_INIT)
          projectFrame(SIGNAL_UPLOAD_STATE, {
            clientUploadId: fresh.clientUploadId,
            phase: UPLOAD_PHASE_COMPLETE,
          })
        })
        expect(sent(PRODUCT_PICTURE_PUBLISH)).toEqual([
          expect.arrayContaining([
            PRODUCT_PICTURE_PUBLISH,
            { clientUploadId: fresh.clientUploadId },
          ]),
        ])
      }
    },
  )

  it('ignores a publication reply after opening another product', async () => {
    const { router } = await mount()
    await act(async () => cardFrame(PRODUCT))
    await openEditor()
    await act(async () => pick())
    const [upload] = hilosUploads.get()
    await act(async () => {
      finish(UPLOAD_ACTION_INIT)
      projectFrame(SIGNAL_UPLOAD_STATE, {
        clientUploadId: upload.clientUploadId,
        phase: UPLOAD_PHASE_COMPLETE,
      })
    })
    const [[, , requestId]] = sent(PRODUCT_PICTURE_PUBLISH)

    await act(async () => {
      router.navigate(
        router.resolvePath(PAGE_ADMIN_PRODUCT, {
          productId: String(SECOND_PRODUCT.productId),
        })!,
      )
      cardFrame(SECOND_PRODUCT)
    })
    await openEditor()
    await act(async () => {
      wire.emit('actionSuccess', {
        action: PRODUCT_PICTURE_PUBLISH,
        requestId,
        reply: { fileId: 27, picture: '/pictures/discarded.webp' },
      })
    })

    expect(
      control<HTMLImageElement>('admin-product-edit-preview').getAttribute(
        'src',
      ),
    ).toBe(SECOND_PRODUCT.pictureUrl)
  })
})
