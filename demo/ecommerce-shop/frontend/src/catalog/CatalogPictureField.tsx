import {
  useEffect,
  useLayoutEffect,
  useRef,
  useState,
  type ComponentType,
} from 'react'
import {
  computedSignal,
  createHilosUploadField,
  subscribeSignal,
  type ActionHandle,
  type HilosUploadField,
  type HilosUploadFieldFile,
} from '@hilos/core/base'
import {
  HilosUploadFiles,
  HilosUploadPicker,
  useSignal,
  type HilosUploadPickerProps,
  type TrackedAction,
} from '@hilos/react/base'

import {
  type CatalogPicture,
  type CatalogPictureReply,
} from './catalogPictureReply.js'

/** The shop's upload target of catalog pictures (PHP `CatalogPictureUploadTarget::NAME`). */
const CATALOG_PICTURE_TARGET = 'catalog_picture'

/** The types the chooser offers; the server judges what actually arrives. */
const PICTURE_ACCEPT = 'image/jpeg,image/png'

/** Create the upload field owned by one catalog dialog opening. */
export function createCatalogPictureUploadField(): HilosUploadField {
  const field = createHilosUploadField({ target: CATALOG_PICTURE_TARGET })
  // A refused cancel can return an earlier opening's upload to ready.
  const existingIds = new Set(
    field.files.get().map((file) => file.clientUploadId),
  )
  const files = computedSignal(() =>
    field.files.get().filter((file) => !existingIds.has(file.clientUploadId)),
  )
  return {
    ...field,
    files,
    ready: computedSignal(() =>
      field.ready.get().filter((id) => !existingIds.has(id)),
    ),
    busy: computedSignal(() =>
      files
        .get()
        .some(
          (file) => file.reading === 'waiting' || file.reading === 'uploading',
        ),
    ),
    refusal: computedSignal(() =>
      files.get().some((file) => file.reading === 'failed')
        ? field.refusal.get()
        : null,
    ),
    dispose() {
      field.setDisabled(false)
      for (const file of files.get()) {
        if (!file.canceling) {
          field.remove(file.clientUploadId)
        }
      }
      field.dispose()
    },
  }
}

/**
 * The upload field of one dialog opening, or null before its first layout.
 *
 * Disposal is permanent, so each setup — StrictMode replay included — owns one
 * field. On close it cancels the uploads selected during that opening.
 */
export function useCatalogPictureField(): HilosUploadField | null {
  const [field, setField] = useState<HilosUploadField | null>(null)

  useLayoutEffect(() => {
    const created = createCatalogPictureUploadField()
    setField(created)

    return () => created.dispose()
  }, [])

  return field
}

/**
 * Follow picks synchronously, even when React batches the upload updates.
 *
 * @param field The dialog's upload field.
 * @param report The current files and the newest pick, if one arrived.
 */
export function followCatalogPictureFiles(
  field: HilosUploadField,
  report: (
    current: readonly HilosUploadFieldFile[],
    newest: HilosUploadFieldFile | undefined,
  ) => void,
): () => void {
  let previous = new Set<string>()

  function follow(current: readonly HilosUploadFieldFile[]): void {
    const newest = [...current]
      .reverse()
      .find((file) => !file.canceling && !previous.has(file.clientUploadId))
    previous = new Set(current.map((file) => file.clientUploadId))
    report(current, newest)
  }

  follow(field.files.get())
  return subscribeSignal(field.files, follow)
}

// Products and categories share the catalog upload target. A new selection
// supersedes any in-flight publication, whose unused file the registry sweeps.
/** Props for {@link CatalogPictureField}. */
export interface CatalogPictureFieldProps {
  /** The upload field owned by the dialog. */
  readonly field: HilosUploadField
  /** The dialog's publication tracker, whose refusal the dialog draws. */
  readonly publish: TrackedAction
  /** Dispatch the page's tracked publication of this upload. */
  readonly sendPublish: (
    clientUploadId: string,
  ) => ActionHandle<CatalogPictureReply>
  /** Prefix of the label, chooser, preview and upload status ids. */
  readonly idPrefix: string
  /** The project's chooser, when it needs a different button. */
  readonly chooser?: ComponentType<HilosUploadPickerProps>
  /** The picture shown in the preview, or null while there is none. */
  readonly picture: CatalogPicture | null
  /** Report the selected upload before publication, or null when canceled. */
  readonly onSelection?: (clientUploadId: string | null) => void
  /** Take a newly published picture into the draft. */
  readonly onPicture: (picture: CatalogPicture) => void
  /** Report whether a picture is uploading or waiting for its publication. */
  readonly onPending: (pending: boolean) => void
}

/**
 * Draw the Picture zone and publish the latest file selected for it.
 *
 * @param props The upload field, page publication, preview and draft callbacks.
 */
export function CatalogPictureField({
  field,
  publish,
  sendPublish,
  idPrefix,
  chooser: Chooser = HilosUploadPicker,
  picture,
  onSelection,
  onPicture,
  onPending,
}: CatalogPictureFieldProps) {
  const files = useSignal(field.files)
  const ready = useSignal(field.ready)
  const uploading = useSignal(field.busy)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [settledId, setSettledId] = useState<string | null>(null)
  const selected = useRef<string | null>(null)
  const requested = useRef<string | null>(null)
  const removedAfterRefusal = useRef<string | null>(null)
  const report = useRef({ onSelection, onPicture, onPending })
  report.current = { onSelection, onPicture, onPending }

  // Handing a file over for publication removes its chip before the reply.
  useLayoutEffect(() => {
    const stop = followCatalogPictureFiles(field, (current, newest) => {
      if (newest !== undefined) {
        selected.current = newest.clientUploadId
        setSelectedId(newest.clientUploadId)
        report.current.onSelection?.(newest.clientUploadId)
      } else if (
        selected.current !== removedAfterRefusal.current &&
        current.some(
          (file) => file.clientUploadId === selected.current && file.canceling,
        )
      ) {
        selected.current = null
        setSelectedId(null)
        report.current.onSelection?.(null)
      }
    })

    return () => {
      stop()
      selected.current = null
    }
  }, [field])

  const { busy: publishing, run } = publish
  const awaitingPublication =
    selectedId !== null &&
    ready.includes(selectedId) &&
    selectedId !== settledId
  const pending = uploading || publishing || awaitingPublication

  useLayoutEffect(() => {
    report.current.onPending(pending)
  }, [pending])

  useEffect(() => {
    const newest = files.at(-1)
    for (const file of files) {
      if (file !== newest && !file.canceling) {
        field.remove(file.clientUploadId)
      }
    }
  }, [files, field])

  useEffect(() => {
    if (
      publishing ||
      !awaitingPublication ||
      selectedId === null ||
      selectedId !== selected.current ||
      selectedId === requested.current
    ) {
      return
    }
    requested.current = selectedId
    const handle = sendPublish(selectedId)
    void run(handle).then(async (published) => {
      if (!published) {
        if (selected.current === selectedId) {
          // Taking away the refused upload's chip leaves its refusal in the dialog.
          removedAfterRefusal.current = selectedId
          setSettledId(selectedId)
          field.remove(selectedId)
        }
        return
      }
      const { reply } = await handle.done
      if (selected.current === selectedId) {
        setSettledId(selectedId)
        if (reply !== undefined) {
          report.current.onPicture({ fileId: reply.fileId, url: reply.picture })
        }
      }
    })
  }, [awaitingPublication, field, publishing, run, selectedId, sendPublish])

  const labelId = `${idPrefix}-picture-label`

  return (
    <div role="group" aria-labelledby={labelId}>
      <span id={labelId} className="form-label d-block">
        Picture
      </span>
      <div className="d-flex align-items-center gap-2">
        {picture !== null ? (
          <img
            src={picture.url}
            alt=""
            width={64}
            height={48}
            className="d-block flex-shrink-0 rounded object-fit-cover"
            data-id={`${idPrefix}-preview`}
          />
        ) : null}
        <Chooser
          field={field}
          dataId={`${idPrefix}-picture`}
          accept={PICTURE_ACCEPT}
        />
        <span className="form-text m-0">JPG or PNG</span>
      </div>
      <HilosUploadFiles field={field} dataId={`${idPrefix}-upload`} />
    </div>
  )
}
