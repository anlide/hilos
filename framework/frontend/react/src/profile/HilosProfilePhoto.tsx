// The profile photo window; core owns the crop, upload and server outcome.
import { useEffect, useRef } from 'react'
import type { ChangeEvent, DragEvent, KeyboardEvent, PointerEvent } from 'react'
import {
  drawHilosPhotoPreview,
  HILOS_PROFILE_PHOTO_COPY as COPY,
  type HilosProfilePhotoFlow,
} from '@hilos/core'
import { HilosAvatar } from '../HilosAvatar.js'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'
import { useSignal } from '../useSignal.js'

/** Props for the profile photo window. */
export interface HilosProfilePhotoProps {
  readonly flow: HilosProfilePhotoFlow
}

const ARROW_MOVE: Record<string, readonly [number, number]> = {
  ArrowLeft: [-8, 0],
  ArrowRight: [8, 0],
  ArrowUp: [0, -8],
  ArrowDown: [0, 8],
}

/**
 * Draw the four photo states inside a focus-trapped modal.
 *
 * @param props Core flow owned by the profile page.
 */
export function HilosProfilePhoto({ flow }: HilosProfilePhotoProps) {
  const step = useSignal(flow.step)
  const photo = useSignal(flow.photo)
  const name = useSignal(flow.name)
  const initials = useSignal(flow.initials)
  const preview = useSignal(flow.preview)
  const zoom = useSignal(flow.zoom)
  const busy = useSignal(flow.busy)
  const checking = useSignal(flow.checking)
  const refusal = useSignal(flow.refusal)
  const voice = useSignal(flow.voice)
  const input = useRef<HTMLInputElement>(null)
  const canvas = useRef<HTMLCanvasElement>(null)
  const dragging = useRef<{ id: number; x: number; y: number } | null>(null)

  useEffect(() => {
    if (canvas.current && preview) {
      drawHilosPhotoPreview(canvas.current, preview.bitmap, preview.square)
    }
  }, [preview, step])

  function picked(event: ChangeEvent<HTMLInputElement>): void {
    const file = event.target.files?.[0]
    if (file) void flow.pick(file)
    event.target.value = ''
  }
  function dropped(event: DragEvent<HTMLDivElement>): void {
    event.preventDefault()
    const file = event.dataTransfer.files[0]
    if (file) void flow.pick(file)
  }
  function startDrag(event: PointerEvent<HTMLCanvasElement>): void {
    dragging.current = {
      id: event.pointerId,
      x: event.clientX,
      y: event.clientY,
    }
    event.currentTarget.setPointerCapture(event.pointerId)
  }
  function drag(event: PointerEvent<HTMLCanvasElement>): void {
    const previous = dragging.current
    if (!previous || previous.id !== event.pointerId) return
    const side = canvas.current?.getBoundingClientRect().width ?? 0
    if (side > 0)
      flow.move(event.clientX - previous.x, event.clientY - previous.y, side)
    dragging.current = {
      id: event.pointerId,
      x: event.clientX,
      y: event.clientY,
    }
  }
  function arrow(event: KeyboardEvent<HTMLCanvasElement>): void {
    const motion = ARROW_MOVE[event.key]
    const side = canvas.current?.getBoundingClientRect().width ?? 0
    if (!motion || side <= 0) return
    event.preventDefault()
    flow.move(motion[0], motion[1], side)
  }

  return (
    <>
      <div
        className="visually-hidden"
        role="status"
        aria-live="polite"
        data-id="profile-photo-live-assertive"
      >
        {voice}
      </div>
      <HilosModal
        open={step !== 'closed'}
        onClose={() => flow.close()}
        title={COPY.title}
        initialFocus="dialog"
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-outline-secondary"
              data-id="profile-photo-cancel"
              onClick={requestClose}
            >
              {COPY.cancel}
            </button>
            {step === 'crop' ? (
              <LoadingButton
                className="btn-primary"
                loading={busy}
                disabled={checking}
                data-id="profile-photo-save"
                onClick={() => void flow.save()}
              >
                {COPY.save}
              </LoadingButton>
            ) : null}
          </>
        )}
      >
        <input
          ref={input}
          type="file"
          className="visually-hidden"
          tabIndex={-1}
          accept="image/jpeg,image/png,image/webp"
          data-id="profile-photo-input"
          onChange={picked}
        />
        {step === 'pick' ? (
          <div className="text-center">
            <div className="rounded-circle bg-secondary-subtle d-inline-flex align-items-center justify-content-center fw-semibold hilos-avatar-lg">
              {initials}
            </div>
            <div className="small text-body-secondary mt-2 mb-3">
              {COPY.initialsNow}
            </div>
            <div
              className="border border-2 hilos-photo-drop rounded py-4"
              data-id="profile-photo-drop"
              onDragOver={(event) => event.preventDefault()}
              onDrop={dropped}
            >
              <i
                className="bi bi-cloud-arrow-up text-body-secondary fs-4"
                aria-hidden="true"
              />
              <div className="small mt-1">
                {COPY.dropLead}{' '}
                <button
                  type="button"
                  className="btn btn-link btn-sm p-0 align-baseline"
                  data-id="profile-photo-choose"
                  onClick={() => input.current?.click()}
                >
                  {COPY.choose}
                </button>
              </div>
              <div className="form-text mb-0">{COPY.hint}</div>
            </div>
          </div>
        ) : step === 'crop' ? (
          <div className="text-center">
            <canvas
              ref={canvas}
              width={128}
              height={128}
              className="hilos-photo-crop rounded-circle mx-auto d-block"
              role="img"
              aria-label={COPY.position}
              tabIndex={0}
              data-id="profile-photo-preview"
              onPointerDown={startDrag}
              onPointerMove={drag}
              onPointerUp={() => {
                dragging.current = null
              }}
              onPointerCancel={() => {
                dragging.current = null
              }}
              onKeyDown={arrow}
            />
            <label
              className="form-label small fw-semibold d-block mt-3"
              htmlFor="profile-photo-zoom"
            >
              {COPY.zoom}
            </label>
            <input
              id="profile-photo-zoom"
              type="range"
              className="form-range"
              min={1}
              max={4}
              step={0.01}
              value={zoom}
              disabled={busy || checking}
              data-id="profile-photo-zoom"
              onChange={(event) => flow.setZoom(Number(event.target.value))}
            />
            <p className="small text-body-secondary mb-0">{COPY.cropNote}</p>
            {checking ? (
              <p
                className="small text-body-secondary mt-2"
                data-id="profile-photo-checking"
              >
                {COPY.checking}
              </p>
            ) : null}
          </div>
        ) : step === 'current' ? (
          <div className="text-center">
            <HilosAvatar name={name} photo={photo} size="lg" />
            <div className="d-grid gap-2 mt-3">
              <button
                type="button"
                className="btn btn-outline-secondary btn-sm"
                disabled={busy}
                data-id="profile-photo-upload-another"
                onClick={() => input.current?.click()}
              >
                {COPY.uploadAnother}
              </button>
              <button
                type="button"
                className="btn btn-outline-danger btn-sm"
                disabled={busy}
                data-id="profile-photo-remove"
                onClick={() => void flow.remove()}
              >
                {COPY.remove}
              </button>
            </div>
            <p className="small text-body-secondary mt-2 mb-0">
              {COPY.removeNote}
            </p>
          </div>
        ) : null}
        <HilosFormError message={refusal} dataId="profile-photo-error" />
      </HilosModal>
    </>
  )
}
