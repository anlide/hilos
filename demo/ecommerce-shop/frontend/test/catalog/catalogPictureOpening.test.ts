import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  SIGNAL_TYPE_PAGE_RESPONSE,
  SIGNAL_UPLOAD_STATE,
  UPLOAD_ACTION_CANCEL,
  UPLOAD_PHASE_COMPLETE,
  bindUploads,
  createSignal,
  hilosUploads,
  subscribeSignal,
  type ActionLifecycle,
  type ProjectSignal,
} from '@hilos/core/base'

import {
  createCatalogPictureUploadField,
  followCatalogPictureFiles,
} from '../../src/catalog/CatalogPictureField.js'
import { type CatalogPicture } from '../../src/catalog/catalogPictureReply.js'

const openingPicture = { fileId: 12, url: '/files/12/catalog_sm' }
const replacementPicture = { fileId: 23, url: '/files/23/catalog_sm' }

function pendingReply<T>() {
  let answer!: (reply: T) => void
  let refuse!: (error: Error) => void
  const done = new Promise<T>((resolve, reject) => {
    answer = resolve
    refuse = reject
  })
  return { answer, refuse, done }
}

function openPicture(
  picture: CatalogPicture,
  publish: (id: string) => Promise<CatalogPicture | null>,
) {
  const field = createCatalogPictureUploadField()
  const preview = createSignal(picture)
  let selected: string | null = null
  let requested: string | null = null

  function publishSelected(): void {
    const id = selected
    if (id === null || id === requested || !field.ready.get().includes(id))
      return
    requested = id
    void publish(id).then((reply) => {
      if (id === selected && reply !== null) preview.set(reply)
    })
  }

  const stopFiles = followCatalogPictureFiles(field, (_files, newest) => {
    if (newest === undefined) return
    selected = newest.clientUploadId
    publishSelected()
  })
  const stopReady = subscribeSignal(field.ready, publishSelected)
  return {
    field,
    preview,
    discard() {
      stopFiles()
      stopReady()
      selected = null
      field.dispose()
    },
  }
}

describe('catalog picture openings', () => {
  let unbind: (() => void) | undefined

  afterEach(() => unbind?.())

  it.each(['before', 'after'] as const)(
    'isolates a discarded upload when Cancel is refused %s reopening',
    async (refusalOrder) => {
      let signal!: (signal: ProjectSignal) => void
      const connection = {
        on(event: string, listener: (frame: ProjectSignal) => void) {
          if (event === 'projectSignal') signal = listener
          return () => undefined
        },
      } as unknown as Parameters<typeof bindUploads>[0]
      const cancelReply = pendingReply<void>()
      const initReply = pendingReply<void>()
      let firstCancel = true
      const dispatch = vi.fn((action: string) => {
        if (action !== UPLOAD_ACTION_CANCEL) return { done: initReply.done }
        if (!firstCancel) return { done: Promise.resolve() }
        firstCancel = false
        return { done: cancelReply.done }
      })
      unbind = bindUploads(
        connection,
        { dispatch } as unknown as ActionLifecycle,
        createSignal(1),
      )
      signal({ type: SIGNAL_TYPE_PAGE_RESPONSE } as ProjectSignal)

      function complete(id: string): void {
        signal({
          type: SIGNAL_UPLOAD_STATE,
          data: {
            clientUploadId: id,
            phase: UPLOAD_PHASE_COMPLETE,
            receivedBytes: 1,
          },
        } as ProjectSignal)
      }

      const firstReply = pendingReply<CatalogPicture | null>()
      const publish = vi
        .fn<(id: string) => Promise<CatalogPicture | null>>()
        .mockReturnValueOnce(firstReply.done)
        .mockResolvedValue(replacementPicture)
      const first = openPicture(
        { fileId: 7, url: '/files/7/catalog_sm' },
        publish,
      )
      first.field.add([new File(['a'], 'old.png', { type: 'image/png' })])
      const oldId = hilosUploads.get()[0]!.clientUploadId
      complete(oldId)
      expect(publish).toHaveBeenCalledExactlyOnceWith(oldId)

      first.discard()
      expect(dispatch).toHaveBeenLastCalledWith(UPLOAD_ACTION_CANCEL, {
        clientUploadId: oldId,
      })
      firstReply.answer(null)
      const second =
        refusalOrder === 'after' ? openPicture(openingPicture, publish) : null
      cancelReply.refuse(new Error('Cancel refused'))
      await cancelReply.done.catch(() => undefined)
      await Promise.resolve()
      expect(hilosUploads.get()[0]).toMatchObject({
        clientUploadId: oldId,
        phase: UPLOAD_PHASE_COMPLETE,
        canceling: false,
      })

      const reopened = second ?? openPicture(openingPicture, publish)
      let newId: string
      try {
        await Promise.resolve()
        expect(publish).toHaveBeenCalledExactlyOnceWith(oldId)
        expect(reopened.preview.get()).toEqual(openingPicture)
        expect(reopened.field.files.get()).toEqual([])
        expect(reopened.field.ready.get()).toEqual([])

        reopened.field.add([new File(['b'], 'new.png', { type: 'image/png' })])
        newId = hilosUploads.get()[1]!.clientUploadId
        complete(newId)
        await Promise.resolve()
        expect(publish.mock.calls).toEqual([[oldId], [newId]])
        expect(reopened.preview.get()).toEqual(replacementPicture)
      } finally {
        reopened.discard()
      }
      expect(
        dispatch.mock.calls.filter(
          ([action]) => action === UPLOAD_ACTION_CANCEL,
        ),
      ).toEqual([
        [UPLOAD_ACTION_CANCEL, { clientUploadId: oldId }],
        [UPLOAD_ACTION_CANCEL, { clientUploadId: newId }],
      ])
      await Promise.resolve()
      expect(hilosUploads.get().map((upload) => upload.clientUploadId)).toEqual(
        [oldId],
      )
    },
  )
})
