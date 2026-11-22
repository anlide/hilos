// The Picture zone of a category dialog: the framework upload field over the
// catalog picture target, and the publication of what it receives. The field
// keeps one picture: a newly taken file takes the place of every earlier one.
// A file the server received is published at once; its reply is the file the
// category is created with and the address of the catalog_sm copy the preview
// draws. A publication refused is the dialog's to draw, in its refusal slot;
// its file is taken off the field, so a new pick starts clean. A file published
// and then replaced, or left when the dialog closes, stays unbound and goes to
// the registry janitor. Bootstrap classes only (styling-rules.md).
import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { createHilosUploadField, type HilosUploadField } from '@hilos/core/base'
import {
  HilosUploadFiles,
  HilosUploadPicker,
  useSignal,
  type TrackedAction,
} from '@hilos/react/base'

import { sendCategoryPicturePublish } from './adminCategoriesActions.js'
import { type CategoryAddPicture } from './categoryAddDraft.js'

/** The shop's upload target of catalog pictures (PHP `CatalogPictureUploadTarget::NAME`). */
const CATALOG_PICTURE_TARGET = 'catalog_picture'

/** The types the chooser offers; the server judges what actually arrives. */
const PICTURE_ACCEPT = 'image/jpeg,image/png'

const PICTURE_LABEL_ID = 'admin-categories-add-picture-label'

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

/** Props for {@link CategoryPictureField}. */
export interface CategoryPictureFieldProps {
  /** The dialog's upload field; the dialog disposes it. */
  readonly field: HilosUploadField
  /** The dialog's tracker of the publication, whose refusal the dialog draws. */
  readonly publish: TrackedAction
  /** The picture published in this opening, or null before the first one. */
  readonly picture: CategoryAddPicture | null
  /** Take a newly published picture into the draft. */
  readonly onPicture: (picture: CategoryAddPicture) => void
  /** Report whether a picture is still uploading or waiting for its publication. */
  readonly onPending: (pending: boolean) => void
}

/**
 * Draw the Picture zone and publish each file it receives.
 *
 * @param props The field, the publication tracker, the published picture and
 *   the two reports to the dialog.
 */
export function CategoryPictureField({
  field,
  publish,
  picture,
  onPicture,
  onPending,
}: CategoryPictureFieldProps) {
  const files = useSignal(field.files)
  const ready = useSignal(field.ready)
  const uploading = useSignal(field.busy)
  // Ids already handed to a publication; read only inside effects.
  const asked = useRef(new Set<string>())
  // Ids whose publication answered: received files that no longer hold Add.
  const [settled, setSettled] = useState<ReadonlySet<string>>(new Set())
  const report = useRef({ onPicture, onPending })
  report.current = { onPicture, onPending }

  const pending =
    uploading || publish.busy || ready.some((id) => !settled.has(id))

  // Before paint, so Add never shows enabled for a frame it should not.
  useLayoutEffect(() => {
    report.current.onPending(pending)
  }, [pending])

  // One picture: the newest file is the one kept.
  useEffect(() => {
    const newest = files[files.length - 1]
    for (const file of files) {
      if (file !== newest && !file.canceling) {
        field.remove(file.clientUploadId)
      }
    }
  }, [files, field])

  // One publication at a time, the newest received file first.
  const { busy: publishing, run } = publish
  useEffect(() => {
    if (publishing) {
      return
    }
    const next = [...ready].reverse().find((id) => !asked.current.has(id))
    if (next === undefined) {
      return
    }
    asked.current.add(next)
    const handle = sendCategoryPicturePublish(next)
    void run(handle).then(async (published) => {
      setSettled((current) => new Set(current).add(next))
      if (!published) {
        field.remove(next)

        return
      }
      const { reply } = await handle.done
      if (reply !== undefined) {
        report.current.onPicture({ fileId: reply.fileId, url: reply.picture })
      }
    })
  }, [ready, publishing, run, field])

  return (
    <div role="group" aria-labelledby={PICTURE_LABEL_ID}>
      <span id={PICTURE_LABEL_ID} className="form-label d-block">
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
            data-id="admin-categories-add-preview"
          />
        ) : null}
        <HilosUploadPicker
          field={field}
          dataId="admin-categories-add-picture"
          accept={PICTURE_ACCEPT}
        />
        <span className="form-text m-0">JPG or PNG</span>
      </div>
      <HilosUploadFiles field={field} dataId="admin-categories-add-upload" />
    </div>
  )
}
