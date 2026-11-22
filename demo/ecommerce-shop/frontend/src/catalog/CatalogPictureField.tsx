import {
  useEffect,
  useLayoutEffect,
  useRef,
  useState,
  type ComponentType,
} from 'react'
import {
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

/**
 * The upload field of one dialog opening, or null before its first layout.
 *
 * Disposal is permanent, so each setup — StrictMode replay included — owns one
 * field. On close the field takes its files off first: a refusal or an upload
 * left behind would otherwise greet the next opening.
 */
export function useCatalogPictureField(): HilosUploadField | null {
  const [field, setField] = useState<HilosUploadField | null>(null)

  useLayoutEffect(() => {
    const created = createHilosUploadField({ target: CATALOG_PICTURE_TARGET })
    setField(created)

    return () => {
      created.setDisabled(false)
      for (const file of created.files.get()) {
        if (!file.canceling) {
          created.remove(file.clientUploadId)
        }
      }
      created.dispose()
    }
  }, [])

  return field
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

  // Selection has to be recorded even if React batches the upload updates.
  // Handing a file over for publication removes its chip before the reply.
  useLayoutEffect(() => {
    let previous = new Set<string>()
    function followSelection(current: readonly HilosUploadFieldFile[]): void {
      const newest = [...current]
        .reverse()
        .find((file) => !file.canceling && !previous.has(file.clientUploadId))
      previous = new Set(current.map((file) => file.clientUploadId))
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
    }

    followSelection(field.files.get())
    const stop = subscribeSignal(field.files, followSelection)
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
