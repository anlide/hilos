// The composer's file-upload engine, lifted out of the Main view so the view
// keeps only the message composer (draft, cooldown, moderation) and the markup.
// This owns everything from a picked file to its streamed bytes: the drag/drop
// and picker UX, a sequential upload queue, and the binary-frame streaming. Each
// file is announced over the `file_upload_init` action; the backend reserves a
// single slot and replies — over the selfConnection `fileUpload` state — with
// `ready` to stream or `failed`. The bytes then ride the WebSocket frame_binary
// channel in UPLOAD_CHUNK_SIZE slices, and the published draft chips render from
// the page's attachmentDrafts list (see mainPage.ts), not from here.
import { computed, ref, watch, type ComputedRef, type Ref } from 'vue'

import {
  UPLOAD_CHUNK_SIZE,
  initFileUpload,
  sendUploadChunk,
} from './mainActions'
import {
  type FileUploadProgress,
  type SelfConnection,
} from './types/SelfConnection'

// The picker accepts images, PDFs and text; drag/drop and paste bypass the
// filter and the backend is the authority on size and type, so this is advisory
// UX only.
const FILE_ACCEPT = 'image/*,.pdf,.txt,text/plain,application/pdf'

// Bridge the backend's per-upload `ready` / `failed` state (pushed on
// selfConnection.fileUpload, correlated by clientUploadId) to the awaiting
// upload routine. The backend reserves a single upload slot, so one pending
// entry at a time is enough and files upload sequentially.
interface UploadOutcome {
  ok: boolean
  message: string | null
}

/** The reactive state and event handlers the composer view binds to drive uploads. */
export interface ComposerUpload {
  /** The advisory accept filter for the hidden file input. */
  fileAccept: string
  /** Bound to the hidden `<input type=file>` the attach button opens. */
  fileInputRef: Ref<HTMLInputElement | null>
  /** True while a file drag hovers the composer on a live connection. */
  isDragging: ComputedRef<boolean>
  /** True while the queue drains; the composer gates Send on it. */
  isUploading: Ref<boolean>
  /** The active upload's byte progress, or null when nothing streams. */
  uploadProgress: ComputedRef<FileUploadProgress | null>
  /** The active upload's completion percent, 0–100. */
  uploadProgressPercent: ComputedRef<number>
  /** The backend or client upload error to surface, or null. */
  uploadError: ComputedRef<string | null>
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
 * Wire the composer's file-upload engine to the live connection state.
 *
 * @param isConnected Whether the socket is connected; a drop abandons the active upload and clears the queue.
 * @param selfConnection This connection's composer state, read for the per-upload `ready`/`failed` phase and byte progress.
 */
export function useComposerUpload(
  isConnected: Readonly<Ref<boolean>>,
  selfConnection: Readonly<Ref<SelfConnection | undefined>>,
): ComposerUpload {
  const fileInputRef = ref<HTMLInputElement | null>(null)
  const dragDepth = ref(0)
  const uploadQueue = ref<File[]>([])
  const isUploading = ref(false)
  const uploadClientError = ref<string | null>(null)

  const isDragging = computed(() => dragDepth.value > 0 && isConnected.value)
  const uploadProgress = computed(
    () => selfConnection.value?.fileProgress ?? null,
  )

  const uploadProgressPercent = computed(() => {
    const progress = uploadProgress.value
    if (progress === null || progress.totalBytes <= 0) {
      return 0
    }

    return Math.min(
      100,
      Math.round((progress.uploadedBytes / progress.totalBytes) * 100),
    )
  })

  // A failed upload state pushed by the backend, else any local (not-connected)
  // error from this composer.
  const uploadError = computed(() => {
    const upload = selfConnection.value?.fileUpload
    if (upload?.phase === 'failed') {
      return upload.errorMessage ?? 'Upload failed'
    }

    return uploadClientError.value
  })

  let pendingUpload: {
    clientUploadId: string
    resolve: (outcome: UploadOutcome) => void
  } | null = null

  watch(
    () => selfConnection.value?.fileUpload ?? null,
    (upload) => {
      if (pendingUpload === null || upload === null) {
        return
      }
      if (upload.clientUploadId !== pendingUpload.clientUploadId) {
        return
      }
      if (upload.phase === 'ready') {
        const pending = pendingUpload
        pendingUpload = null
        pending.resolve({ ok: true, message: null })
      } else if (upload.phase === 'failed') {
        const pending = pendingUpload
        pendingUpload = null
        pending.resolve({ ok: false, message: upload.errorMessage })
      }
    },
  )

  const awaitUploadReady = (clientUploadId: string): Promise<UploadOutcome> =>
    new Promise((resolve) => {
      pendingUpload = { clientUploadId, resolve }
    })

  const streamFile = async (file: File): Promise<boolean> => {
    let offset = 0
    while (offset < file.size) {
      const buffer = await file
        .slice(offset, offset + UPLOAD_CHUNK_SIZE)
        .arrayBuffer()
      if (!sendUploadChunk(buffer)) {
        return false
      }
      offset += buffer.byteLength
    }

    return true
  }

  // Announce one file, wait for `ready` (or a failure), then stream its bytes.
  const uploadOne = async (file: File): Promise<void> => {
    const clientUploadId = crypto.randomUUID()
    const ready = awaitUploadReady(clientUploadId)
    const announced = initFileUpload({
      filename: file.name,
      mimeType: file.type || 'application/octet-stream',
      size: file.size,
      clientUploadId,
    })
    if (!announced) {
      pendingUpload = null
      uploadClientError.value = 'Not connected'

      return
    }
    const outcome = await ready
    if (!outcome.ok) {
      uploadClientError.value = outcome.message ?? 'Upload failed'

      return
    }
    if (!(await streamFile(file))) {
      uploadClientError.value = 'Upload interrupted'
    }
  }

  // Drain the queue one file at a time; a single pass holds the lock so files
  // added mid-upload join the same drain.
  const processQueue = async (): Promise<void> => {
    if (isUploading.value) {
      return
    }
    isUploading.value = true
    try {
      while (uploadQueue.value.length > 0) {
        const [next, ...rest] = uploadQueue.value
        uploadQueue.value = rest
        if (next !== undefined) {
          await uploadOne(next)
        }
      }
    } finally {
      isUploading.value = false
    }
  }

  const enqueueFiles = (files: FileList | null): void => {
    if (files === null) {
      return
    }
    uploadClientError.value = null
    const accepted = Array.from(files).filter((file) => file.size > 0)
    if (accepted.length === 0) {
      return
    }
    uploadQueue.value = [...uploadQueue.value, ...accepted]
    void processQueue()
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

  const onPaste = (event: ClipboardEvent): void => {
    const files = event.clipboardData?.files
    if (files && files.length > 0) {
      event.preventDefault()
      enqueueFiles(files)
    }
  }

  // A dropped connection abandons the active upload and clears the queue so the
  // composer never wedges on `uploading` waiting for a reply that cannot arrive.
  watch(isConnected, (connected) => {
    if (connected) {
      return
    }
    if (pendingUpload !== null) {
      const pending = pendingUpload
      pendingUpload = null
      pending.resolve({ ok: false, message: 'Connection lost' })
    }
    uploadQueue.value = []
  })

  return {
    fileAccept: FILE_ACCEPT,
    fileInputRef,
    isDragging,
    isUploading,
    uploadProgress,
    uploadProgressPercent,
    uploadError,
    openFilePicker,
    onFileInputChange,
    onDragEnter,
    onDragLeave,
    onDrop,
    onPaste,
  }
}
