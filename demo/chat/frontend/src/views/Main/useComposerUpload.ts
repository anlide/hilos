// The composer's file-upload engine, lifted out of the Main view so the view
// keeps only the message composer (draft, cooldown, moderation) and the markup.
// Since HIL-144 the chat streams nothing itself: every picked file goes to the
// framework uploads client (@hilos/core uploadFile) under the chat's one target,
// and this composable only reads that client's list back. The queue, the one-
// at-a-time pace, the resend of unfinished files after a dropped connection and
// the refusal sentences are the client's and the server's (uploads.md, "The
// Browser Client"); what stays here is the picker, drag-and-drop and paste UX,
// and which of the client's records the composer shows as progress, error and
// chips.
import { computed, ref, type ComputedRef, type Ref } from 'vue'
import {
  UPLOAD_PHASE_COMPLETE,
  UPLOAD_PHASE_FAILED,
  UPLOAD_PHASE_QUEUED,
  UPLOAD_PHASE_READY,
  UPLOAD_PHASE_UPLOADING,
  cancelUpload,
  hilosUploads,
  uploadFile,
  type HilosUpload,
} from '@hilos/core'
import { useSignal } from '@hilos/vue'

/** The chat's upload target (PHP `ChatAttachmentUploadTarget::NAME`). */
export const CHAT_ATTACHMENT_TARGET = 'chat_attachment'

// The picker offers images, PDFs and text; drag/drop and paste bypass the
// filter, and the server reads the type from the content and refuses the rest,
// so this is advisory UX only.
const FILE_ACCEPT = 'image/*,.pdf,.txt,text/plain,application/pdf'

const PERCENT = 100

/** Phases of a record still on its way to the server. */
const IN_FLIGHT_PHASES: readonly string[] = [
  UPLOAD_PHASE_QUEUED,
  UPLOAD_PHASE_READY,
  UPLOAD_PHASE_UPLOADING,
]

/** The reactive state and event handlers the composer view binds to drive uploads. */
export interface ComposerUpload {
  /** The advisory accept filter for the hidden file input. */
  fileAccept: string
  /** Bound to the hidden `<input type=file>` the attach button opens. */
  fileInputRef: Ref<HTMLInputElement | null>
  /** True while a file drag hovers the composer of a signed-in person on a live connection. */
  isDragging: ComputedRef<boolean>
  /** True while a file is queued or on its way; the composer gates Send on it. */
  isUploading: ComputedRef<boolean>
  /** The first file on its way, whose progress the composer shows, or null. */
  uploadProgress: ComputedRef<HilosUpload | null>
  /** That file's received share, 0–100. */
  uploadProgressPercent: ComputedRef<number>
  /** The sentence of the last refused or failed file, or null. */
  uploadError: ComputedRef<string | null>
  /** Files received whole and waiting for the message — the chips, in attach order. */
  drafts: ComputedRef<readonly HilosUpload[]>
  /** Client ids of the chips a send carries, in attach order. */
  draftIds: ComputedRef<readonly string[]>
  /** Drop one chip: the client cancels the upload and the server forgets it. */
  removeDraft: (clientUploadId: string) => void
  /** Open the hidden file input's picker dialog. */
  openFilePicker: () => void
  /** Enqueue the picked files, then reset the input so re-picking fires again. */
  onFileInputChange: (event: Event) => void
  /** Count a drag entering the composer so nested elements don't flicker the overlay. */
  onDragEnter: () => void
  /** Count a drag leaving the composer. */
  onDragLeave: () => void
  /** Enqueue files dropped on the composer. */
  onDrop: (event: DragEvent) => void
  /** Enqueue files pasted into the composer input. */
  onPaste: (event: ClipboardEvent) => void
}

/**
 * Wire the composer's file picking to the framework uploads client.
 *
 * @param isAuthenticated Whether the session names a person; a guest's drop and paste are ignored.
 * @param isConnected Whether the socket is connected; the drop overlay shows only on a live one.
 */
export function useComposerUpload(
  isAuthenticated: Readonly<Ref<boolean>>,
  isConnected: Readonly<Ref<boolean>>,
): ComposerUpload {
  const fileInputRef = ref<HTMLInputElement | null>(null)
  const dragDepth = ref(0)
  const uploads = useSignal(hilosUploads)

  // Only the chat's own target: another surface of the page may upload too.
  const records = computed(() =>
    uploads.value.filter((upload) => upload.target === CHAT_ATTACHMENT_TARGET),
  )
  const inFlight = computed(() =>
    records.value.filter((upload) => IN_FLIGHT_PHASES.includes(upload.phase)),
  )

  const isDragging = computed(
    () => dragDepth.value > 0 && isConnected.value && isAuthenticated.value,
  )
  const isUploading = computed(() => inFlight.value.length > 0)
  const uploadProgress = computed(() => inFlight.value[0] ?? null)

  const uploadProgressPercent = computed(() => {
    const upload = uploadProgress.value
    if (upload === null || upload.declaredSize <= 0) {
      return 0
    }

    return Math.min(
      PERCENT,
      Math.round((upload.receivedBytes / upload.declaredSize) * PERCENT),
    )
  })

  const uploadError = computed(() => {
    const failed = records.value.filter(
      (upload) => upload.phase === UPLOAD_PHASE_FAILED,
    )

    return failed[failed.length - 1]?.errorMessage ?? null
  })

  const drafts = computed(() =>
    records.value.filter((upload) => upload.phase === UPLOAD_PHASE_COMPLETE),
  )
  const draftIds = computed(() =>
    drafts.value
      .filter((upload) => !upload.canceling)
      .map((upload) => upload.clientUploadId),
  )

  const removeDraft = (clientUploadId: string): void => {
    cancelUpload(clientUploadId)
  }

  const enqueueFiles = (files: FileList | null): void => {
    if (files === null || !isAuthenticated.value) {
      return
    }
    // An empty file is dropped silently, as the composer always did.
    const accepted = Array.from(files).filter((file) => file.size > 0)
    if (accepted.length === 0) {
      return
    }
    // A new pick clears the previous refusal: its record goes, and its line with it.
    for (const upload of records.value) {
      if (upload.phase === UPLOAD_PHASE_FAILED) {
        cancelUpload(upload.clientUploadId)
      }
    }
    for (const file of accepted) {
      uploadFile(CHAT_ATTACHMENT_TARGET, file)
    }
  }

  const openFilePicker = (): void => {
    fileInputRef.value?.click()
  }

  const onFileInputChange = (event: Event): void => {
    const input = event.target as HTMLInputElement
    enqueueFiles(input.files)
    // Reset so re-picking the same file fires `change` again.
    input.value = ''
  }

  const onDragEnter = (): void => {
    dragDepth.value += 1
  }

  const onDragLeave = (): void => {
    dragDepth.value = Math.max(0, dragDepth.value - 1)
  }

  const onDrop = (event: DragEvent): void => {
    dragDepth.value = 0
    enqueueFiles(event.dataTransfer?.files ?? null)
  }

  // Paste is taken over only when it carries files, and only for a signed-in person.
  const onPaste = (event: ClipboardEvent): void => {
    const files = event.clipboardData?.files
    if (isAuthenticated.value && files && files.length > 0) {
      event.preventDefault()
      enqueueFiles(files)
    }
  }

  return {
    fileAccept: FILE_ACCEPT,
    fileInputRef,
    isDragging,
    isUploading,
    uploadProgress,
    uploadProgressPercent,
    uploadError,
    drafts,
    draftIds,
    removeDraft,
    openFilePicker,
    onFileInputChange,
    onDragEnter,
    onDragLeave,
    onDrop,
    onPaste,
  }
}
