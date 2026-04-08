/**
 * Single in-flight file_upload_init correlation (demo chat has one active upload at a time).
 */

export type UploadOutcome = { ok: true } | { ok: false; code: string; message?: string }

let current: { resolve: (o: UploadOutcome) => void } | null = null

export function registerFileUploadPending(): Promise<UploadOutcome> {
  return new Promise((resolve) => {
    current = { resolve }
  })
}

export function resolveFileUploadOutcome(outcome: UploadOutcome): void {
  if (current) {
    current.resolve(outcome)
    current = null
  }
}

export function rejectFileUploadPending(reason: string): void {
  resolveFileUploadOutcome({ ok: false, code: reason })
}
