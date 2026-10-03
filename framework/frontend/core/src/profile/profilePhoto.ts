import { z } from 'zod'
import { ActionErrorStore } from '../connection/ActionErrorStore.js'
import { ActionError } from '../connection/actionLifecycle.js'
import { formatInitials } from '../format/initials.js'
import { sessionUserName } from '../session/sessionScope.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
  type Unsubscribe,
} from '../state/signal.js'
import {
  cancelUpload,
  hilosUploads,
  uploadFile,
} from '../uploads/hilosUploads.js'
import {
  UPLOAD_PHASE_COMPLETE,
  UPLOAD_PHASE_FAILED,
} from '../uploads/uploadProtocol.js'
import {
  clampHilosPhotoCrop,
  hilosPhotoSourceSquare,
  moveHilosPhotoCrop,
  type HilosPhotoCrop,
  type HilosPhotoSourceSquare,
} from './photoCrop.js'
import {
  HilosPhotoPickError,
  openHilosPhoto,
  renderHilosPhotoSquare,
} from './photoPick.js'
import { type HilosProfilePageContext } from './profileRoot.js'

/** Framework upload target for a person's cropped JPEG. */
export const HILOS_PROFILE_PHOTO_UPLOAD_TARGET = 'hilos_profile_photo'
/** Tracked action to set the acting person's photo. */
export const PROFILE_PHOTO_SET_ACTION = 'hilos_profile_photo_set'
/** Tracked action to remove the acting person's photo. */
export const PROFILE_PHOTO_REMOVE_ACTION = 'hilos_profile_photo_remove'
/** Live checking flag for this connection. */
export const SIGNAL_PROFILE_PHOTO_CHECK = 'hilos_profile_photo_check'

/** The users library's checking flag. */
export const profilePhotoCheckSchema = z.looseObject({ checking: z.boolean() })

/** Framework signal schemas understood by the profile photo flow. */
export const PROFILE_PHOTO_SIGNAL_SCHEMAS = {
  [SIGNAL_PROFILE_PHOTO_CHECK]: profilePhotoCheckSchema,
}

/** The window's visible tiles. Checking stays over the crop tile. */
export type HilosProfilePhotoStep = 'closed' | 'pick' | 'crop' | 'current'

/** A bitmap and the square that both preview and export draw. */
export interface HilosProfilePhotoPreview {
  readonly bitmap: ImageBitmap
  readonly square: HilosPhotoSourceSquare
}

/** One profile photo window over the current person's live photo. */
export interface HilosProfilePhotoFlow {
  readonly step: ReadonlySignal<HilosProfilePhotoStep>
  readonly photo: ReadonlySignal<string | null>
  readonly name: ReadonlySignal<string>
  readonly initials: ReadonlySignal<string>
  readonly preview: ReadonlySignal<HilosProfilePhotoPreview | null>
  readonly crop: ReadonlySignal<HilosPhotoCrop>
  readonly zoom: ReadonlySignal<number>
  readonly busy: ReadonlySignal<boolean>
  readonly checking: ReadonlySignal<boolean>
  readonly refusal: ReadonlySignal<string | null>
  readonly voice: ReadonlySignal<string>
  open(): void
  /** @param file First selected or dropped picture. */
  pick(file: File): Promise<void>
  /** @param zoom Slider magnification. */
  setZoom(zoom: number): void
  /**
   * @param dxPx Picture movement right in preview pixels.
   * @param dyPx Picture movement down in preview pixels.
   * @param viewSidePx Preview diameter in pixels.
   */
  move(dxPx: number, dyPx: number, viewSidePx: number): void
  save(): Promise<void>
  remove(): Promise<void>
  close(): void
  dispose(): void
}

/** The approved words of the profile photo window. */
export const HILOS_PROFILE_PHOTO_COPY = {
  title: 'Change your photo',
  open: 'Change your photo',
  initialsNow: 'Initials are shown now',
  dropLead: 'Drag a picture here or',
  choose: 'choose one on your device',
  hint: 'JPEG, PNG or WebP, up to 40 MB',
  tooLarge: 'This picture is larger than 40 MB.',
  unreadable:
    'This file cannot be opened as a picture. Choose a JPEG, PNG or WebP.',
  zoom: 'Zoom',
  cropNote: 'The picture is cut to a circle — this is how it will look.',
  position: 'Photo position. Use the arrow keys to move it.',
  save: 'Save',
  cancel: 'Cancel',
  checking: 'Checking your photo…',
  uploadAnother: 'Upload another',
  remove: 'Remove photo',
  removeNote: 'Removing brings your initials back.',
  updated: 'Photo updated',
  removed: 'Photo removed',
  unreached: 'Could not reach the server.',
} as const

/**
 * Create the window over the profile's live session photo.
 *
 * @param context Page connection, scopes and action lifecycle.
 * @param photo The current user's published variant URL, or null.
 */
export function createHilosProfilePhotoFlow(
  context: HilosProfilePageContext,
  photo: ReadonlySignal<string | null>,
): HilosProfilePhotoFlow {
  const step = createSignal<HilosProfilePhotoStep>('closed')
  const crop = createSignal<HilosPhotoCrop>({ zoom: 1, centerX: 0, centerY: 0 })
  const bitmap = createSignal<ImageBitmap | null>(null)
  const busy = createSignal(false)
  const checking = createSignal(false)
  const refusal = createSignal<string | null>(null)
  const voice = createSignal('')
  const errors = new ActionErrorStore(context.connection)
  const name = sessionUserName(context.scopes)
  const setError = errors.signal(PROFILE_PHOTO_SET_ACTION)
  const preview = computedSignal(() => {
    const image = bitmap.get()
    return image === null
      ? null
      : {
          bitmap: image,
          square: hilosPhotoSourceSquare(image.width, image.height, crop.get()),
        }
  })
  let stops: Unsubscribe[] = []
  let uploadStop: Unsubscribe | null = null
  let uploadId: string | null = null
  let awaiting: 'set' | 'remove' | null = null
  let was: string | null = null
  let round = 0
  let disposed = false

  function forgetUpload(): void {
    uploadStop?.()
    uploadStop = null
    uploadId = null
  }

  function releaseBitmap(): void {
    bitmap.get()?.close()
    bitmap.set(null)
  }

  function finish(message: string): void {
    awaiting = null
    was = null
    voice.set(message)
    close()
  }

  function reconcile(): void {
    if (awaiting === 'set' && photo.get() !== was) {
      finish(HILOS_PROFILE_PHOTO_COPY.updated)
    } else if (awaiting === 'remove' && photo.get() === null) {
      finish(HILOS_PROFILE_PHOTO_COPY.removed)
    }
  }

  function listen(): void {
    if (stops.length > 0) return
    stops = [
      subscribeSignal(photo, () => {
        reconcile()
        if (
          awaiting === null &&
          step.get() === 'current' &&
          photo.get() === null
        ) {
          step.set('pick')
        }
      }),
      subscribeSignal(setError, (reason) => {
        if (reason === null || awaiting !== 'set') return
        awaiting = null
        busy.set(false)
        checking.set(false)
        refusal.set(reason)
        if (bitmap.get() !== null) step.set('crop')
      }),
      context.connection.on('projectSignal', (signal) => {
        if (signal.type !== SIGNAL_PROFILE_PHOTO_CHECK) return
        const parsed = profilePhotoCheckSchema.safeParse(signal.data)
        if (parsed.success) checking.set(parsed.data.checking)
      }),
    ]
  }

  function close(): void {
    round += 1
    if (uploadId !== null) {
      try {
        cancelUpload(uploadId)
      } catch {
        // The upload client already let this connection go.
      }
      forgetUpload()
      awaiting = null
      was = null
    }
    step.set('closed')
    busy.set(false)
    for (const stop of stops) stop()
    stops = []
    if (awaiting === null) releaseBitmap()
  }

  function watchUpload(clientUploadId: string, started: number): void {
    const scan = (): void => {
      if (round !== started || uploadId !== clientUploadId) return
      const upload = hilosUploads
        .get()
        .find((item) => item.clientUploadId === clientUploadId)
      if (!upload) return
      if (upload.phase === UPLOAD_PHASE_FAILED) {
        forgetUpload()
        busy.set(false)
        refusal.set(upload.errorMessage ?? HILOS_PROFILE_PHOTO_COPY.unreached)
        return
      }
      if (upload.phase !== UPLOAD_PHASE_COMPLETE) return
      forgetUpload()
      awaiting = 'set'
      errors.clear(PROFILE_PHOTO_SET_ACTION)
      void context.actions
        .dispatch(PROFILE_PHOTO_SET_ACTION, { clientUploadId })
        .done.catch((error: unknown) => {
          if (round !== started) return
          awaiting = null
          busy.set(false)
          checking.set(false)
          refusal.set(
            error instanceof ActionError && error.outcome === 'fail'
              ? error.message
              : HILOS_PROFILE_PHOTO_COPY.unreached,
          )
        })
    }
    uploadStop = subscribeSignal(hilosUploads, scan)
    scan()
  }

  return {
    step,
    photo,
    name,
    initials: computedSignal(() => formatInitials(name.get())),
    preview,
    crop,
    zoom: computedSignal(() => crop.get().zoom),
    busy,
    checking,
    refusal,
    voice,
    open() {
      if (disposed || step.get() !== 'closed') return
      voice.set('')
      listen()
      reconcile()
      if (step.get() !== 'closed') return
      const lateRefusal = setError.get()
      if (awaiting === 'set' && lateRefusal !== null) {
        awaiting = null
        checking.set(false)
        refusal.set(lateRefusal)
      } else {
        refusal.set(null)
      }
      step.set(
        bitmap.get() !== null
          ? 'crop'
          : photo.get() === null
            ? 'pick'
            : 'current',
      )
      busy.set(awaiting !== null)
    },
    async pick(file) {
      if (disposed || step.get() === 'closed' || busy.get() || checking.get())
        return
      const started = round
      busy.set(true)
      refusal.set(null)
      try {
        const image = await openHilosPhoto(file)
        if (round !== started) {
          image.close()
          return
        }
        releaseBitmap()
        bitmap.set(image)
        crop.set({
          zoom: 1,
          centerX: image.width / 2,
          centerY: image.height / 2,
        })
        step.set('crop')
      } catch (error) {
        if (round !== started) return
        refusal.set(
          error instanceof HilosPhotoPickError && error.failure === 'tooLarge'
            ? HILOS_PROFILE_PHOTO_COPY.tooLarge
            : HILOS_PROFILE_PHOTO_COPY.unreadable,
        )
      } finally {
        if (round === started) busy.set(false)
      }
    },
    setZoom(zoom) {
      const image = bitmap.get()
      if (image === null || step.get() !== 'crop') return
      crop.set(
        clampHilosPhotoCrop(image.width, image.height, { ...crop.get(), zoom }),
      )
    },
    move(dxPx, dyPx, viewSidePx) {
      const image = bitmap.get()
      if (image === null || step.get() !== 'crop') return
      crop.set(
        moveHilosPhotoCrop(
          image.width,
          image.height,
          crop.get(),
          dxPx,
          dyPx,
          viewSidePx,
        ),
      )
    },
    async save() {
      const image = bitmap.get()
      const square = preview.get()?.square
      if (
        image === null ||
        square === undefined ||
        step.get() !== 'crop' ||
        busy.get() ||
        checking.get()
      )
        return
      const started = round
      was = photo.get()
      busy.set(true)
      refusal.set(null)
      try {
        const blob = await renderHilosPhotoSquare(image, square)
        if (round !== started) return
        uploadId = uploadFile(
          HILOS_PROFILE_PHOTO_UPLOAD_TARGET,
          new File([blob], 'photo.jpg', { type: 'image/jpeg' }),
        )
        watchUpload(uploadId, started)
      } catch (error) {
        if (round !== started) return
        busy.set(false)
        refusal.set(
          error instanceof HilosPhotoPickError
            ? HILOS_PROFILE_PHOTO_COPY.unreadable
            : HILOS_PROFILE_PHOTO_COPY.unreached,
        )
      }
    },
    async remove() {
      if (step.get() === 'closed' || busy.get() || photo.get() === null) return
      const started = round
      awaiting = 'remove'
      was = photo.get()
      busy.set(true)
      refusal.set(null)
      try {
        await context.actions.dispatch(PROFILE_PHOTO_REMOVE_ACTION, {}).done
        if (round === started) reconcile()
      } catch (error) {
        if (round !== started) return
        awaiting = null
        busy.set(false)
        refusal.set(
          error instanceof ActionError && error.outcome === 'fail'
            ? error.message
            : HILOS_PROFILE_PHOTO_COPY.unreached,
        )
      }
    },
    close,
    dispose() {
      if (disposed) return
      close()
      releaseBitmap()
      errors.dispose()
      disposed = true
    },
  }
}
