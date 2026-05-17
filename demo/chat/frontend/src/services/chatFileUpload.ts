/**
 * Single in-flight file_upload_init correlation (demo chat has one active upload at a time).
 */

import type { FileUploadStatePayload } from '@/entities/browserPayloadParsers'

export type UploadOutcome = { ok: true } | { ok: false; code: string; message?: string }

let current: {
  clientUploadId: string
  resolve: (o: UploadOutcome) => void
} | null = null

export function registerFileUploadPending(clientUploadId: string): Promise<UploadOutcome> {
  return new Promise((resolve) => {
    current = { clientUploadId, resolve }
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

export function resolveFileUploadOutcomeFromState(state: FileUploadStatePayload | null): void {
  if (current === null || state === null || state.clientUploadId !== current.clientUploadId) {
    return
  }

  if (state.phase === 'ready') {
    resolveFileUploadOutcome({ ok: true })
  } else if (state.phase === 'failed') {
    resolveFileUploadOutcome({
      ok: false,
      code: state.errorCode ?? 'upload_failed',
      message: state.errorMessage ?? undefined,
    })
  }
}
