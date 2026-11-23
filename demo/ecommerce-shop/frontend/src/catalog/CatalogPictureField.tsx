import {
  useEffect,
  useLayoutEffect,
  useRef,
  useState,
  type ComponentType,
} from 'react'
import {
  ActionError,
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
  return createHilosUploadField({
    target: CATALOG_PICTURE_TARGET,
    selectionOnly: true,
  })
}

/**
 * The upload field of one dialog opening, or null before its first layout.
 *
 * Disposal is permanent, so each setup — StrictMode replay included — owns one
 * field and sees only its own selections. Closing the dialog discards those
 * selections from the queue and requests server cleanup.
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
 * @param field The dialog's single-selection upload field.
 * @param report The current files and the newly observed pick, if any.
 */
export function followCatalogPictureFiles(
  field: HilosUploadField,
  report: (
    current: readonly HilosUploadFieldFile[],
    newest: HilosUploadFieldFile | undefined,
  ) => void,
): () => void {
  let reportedId: string | undefined

  function follow(current: readonly HilosUploadFieldFile[]): void {
    const file = current.at(-1)
    if (
      file !== undefined &&
      !file.canceling &&
      file.clientUploadId !== reportedId
    ) {
      reportedId = file.clientUploadId
      report(current, file)
    } else {
      report(current, undefined)
    }
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
  readonly onPicture: (picture: CatalogPicture, clientUploadId: string) => void
  /** Retire the selection and report that its sent publication lost its reply. */
  readonly onPublicationUnknown?: (clientUploadId: string) => void
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
  onPublicationUnknown,
  onPending,
}: CatalogPictureFieldProps) {
  const ready = useSignal(field.ready)
  const uploading = useSignal(field.busy)
  const disabled = useSignal(field.disabled)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [settledId, setSettledId] = useState<string | null>(null)
  const selected = useRef<string | null>(null)
  const requested = useRef<string | null>(null)
  const report = useRef({
    onSelection,
    onPicture,
    onPublicationUnknown,
    onPending,
  })
  report.current = { onSelection, onPicture, onPublicationUnknown, onPending }

  // Selection has to be recorded even if React batches the upload updates.
  // Handing a file over for publication removes its chip before the reply.
  useLayoutEffect(() => {
    const stop = followCatalogPictureFiles(field, (_current, newest) => {
      if (newest !== undefined && newest.clientUploadId !== selected.current) {
        selected.current = newest.clientUploadId
        setSelectedId(newest.clientUploadId)
        report.current.onSelection?.(newest.clientUploadId)
      }
    })
    return () => {
      stop()
      selected.current = null
    }
  }, [field])

  const { busy: publishing, run } = publish
  const awaitingPublication = selectedId !== null && selectedId !== settledId
  const selectedReady = selectedId !== null && ready.includes(selectedId)
  const pending =
    uploading ||
    (awaitingPublication && (selectedReady || selectedId === requested.current))

  useLayoutEffect(() => {
    report.current.onPending(pending)
  }, [pending, selectedId])

  useEffect(() => {
    if (
      disabled ||
      field.disabled.get() ||
      publishing ||
      !awaitingPublication ||
      !selectedReady ||
      selectedId === null ||
      selectedId !== selected.current ||
      selectedId === requested.current
    ) {
      return
    }
    requested.current = selectedId
    const handle = sendPublish(selectedId)
    void run(handle)
    void handle.done.then(
      ({ reply }) => {
        if (selected.current === selectedId) {
          if (reply !== undefined) {
            report.current.onPicture(
              { fileId: reply.fileId, url: reply.picture },
              selectedId,
            )
          }
          setSettledId(selectedId)
        }
      },
      (failure: unknown) => {
        if (selected.current !== selectedId) return
        setSettledId(selectedId)
        if (!(failure instanceof ActionError)) return

        if (
          report.current.onPublicationUnknown !== undefined &&
          handle.sent === true &&
          (failure.outcome === 'timeout' || failure.outcome === 'disconnected')
        ) {
          selected.current = null
          setSelectedId(null)
          field.discard(selectedId)
          report.current.onPublicationUnknown(selectedId)
        } else if (failure.outcome === 'fail') {
          field.discard(selectedId)
        }
      },
    )
  }, [
    awaitingPublication,
    disabled,
    field,
    publishing,
    run,
    selectedId,
    selectedReady,
    sendPublish,
  ])

  const uploadChips = {
    ...field,
    remove(clientUploadId: string) {
      if (field.disabled.get() || selected.current !== clientUploadId) return
      selected.current = null
      setSelectedId(null)
      report.current.onSelection?.(null)
      field.remove(clientUploadId)
    },
  }

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
      <HilosUploadFiles field={uploadChips} dataId={`${idPrefix}-upload`} />
    </div>
  )
}
