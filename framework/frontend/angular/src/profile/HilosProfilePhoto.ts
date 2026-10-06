// The profile photo window; core owns the crop, upload and server outcome.
import {
  afterRenderEffect,
  ChangeDetectionStrategy,
  Component,
  effect,
  ElementRef,
  input,
  signal,
  viewChild,
} from '@angular/core'
import {
  drawHilosPhotoPreview,
  HILOS_PROFILE_PHOTO_COPY,
  subscribeSignal,
  type HilosProfilePhotoFlow,
  type HilosProfilePhotoPreview,
  type HilosProfilePhotoStep,
  type ReadonlySignal,
  type Unsubscribe,
} from '@hilos/core'
import { HilosAvatar } from '../HilosAvatar.js'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'

const ARROW_MOVE: Record<string, readonly [number, number]> = {
  ArrowLeft: [-8, 0],
  ArrowRight: [8, 0],
  ArrowUp: [0, -8],
  ArrowDown: [0, 8],
}

/**
 * Mirror a core signal into an Angular signal for this modal.
 *
 * @param source Core signal.
 * @param target Angular signal writable by this component.
 */
function mirror<T>(
  source: ReadonlySignal<T>,
  target: { set(value: T): void },
): Unsubscribe {
  target.set(source.get())
  return subscribeSignal(source, (value) => target.set(value))
}

/** Draw the photo chooser, crop and current picture inside one modal. */
@Component({
  selector: 'hilos-profile-photo',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAvatar, HilosFormError, HilosModal, LoadingButton],
  template: `
    <div
      class="visually-hidden"
      role="status"
      aria-live="polite"
      data-id="profile-photo-live-assertive"
    >
      {{ voice() }}
    </div>
    <hilos-modal
      [open]="step() !== 'closed'"
      (openChange)="onOpenChange($event)"
      [title]="copy.title"
      initialFocus="dialog"
    >
      <input
        #input
        type="file"
        class="visually-hidden"
        tabindex="-1"
        accept="image/jpeg,image/png,image/webp"
        data-id="profile-photo-input"
        (change)="picked($event)"
      />
      @if (step() === 'pick') {
        <div class="text-center">
          <div
            class="rounded-circle bg-secondary-subtle d-inline-flex align-items-center justify-content-center fw-semibold hilos-avatar-lg"
          >
            {{ initials() }}
          </div>
          <div class="small text-body-secondary mt-2 mb-3">
            {{ copy.initialsNow }}
          </div>
          <div
            class="border border-2 hilos-photo-drop rounded py-4"
            data-id="profile-photo-drop"
            (dragover)="$event.preventDefault()"
            (drop)="dropped($event)"
          >
            <i
              class="bi bi-cloud-arrow-up text-body-secondary fs-4"
              aria-hidden="true"
            ></i>
            <div class="small mt-1">
              {{ copy.dropLead }}
              <button
                type="button"
                class="btn btn-link btn-sm p-0 align-baseline"
                data-id="profile-photo-choose"
                (click)="choose()"
              >
                {{ copy.choose }}
              </button>
            </div>
            <div class="form-text mb-0">{{ copy.hint }}</div>
          </div>
        </div>
      } @else if (step() === 'crop') {
        <div class="text-center">
          <canvas
            #canvas
            width="128"
            height="128"
            class="hilos-photo-crop rounded-circle mx-auto d-block"
            role="img"
            [attr.aria-label]="copy.position"
            tabindex="0"
            data-id="profile-photo-preview"
            (pointerdown)="startDrag($event)"
            (pointermove)="drag($event)"
            (pointerup)="stopDrag()"
            (pointercancel)="stopDrag()"
            (keydown)="arrow($event)"
          ></canvas>
          <label
            class="form-label small fw-semibold d-block mt-3"
            for="profile-photo-zoom"
            >{{ copy.zoom }}</label
          >
          <input
            id="profile-photo-zoom"
            type="range"
            class="form-range"
            min="1"
            max="4"
            step="0.01"
            [value]="zoom()"
            [disabled]="busy() || checking()"
            data-id="profile-photo-zoom"
            (input)="onZoom($event)"
          />
          <p class="small text-body-secondary mb-0">{{ copy.cropNote }}</p>
          @if (checking()) {
            <p
              class="small text-body-secondary mt-2"
              data-id="profile-photo-checking"
            >
              {{ copy.checking }}
            </p>
          } @else {
            <p
              class="small text-body-secondary mt-2 invisible"
              aria-hidden="true"
              data-id="profile-photo-checking-idle"
            >
              {{ copy.checking }}
            </p>
          }
          <div class="d-grid mt-2">
            @if (refusal() !== null) {
              <button
                type="button"
                class="btn btn-outline-secondary btn-sm"
                [disabled]="busy() || checking()"
                data-id="profile-photo-upload-another"
                (click)="choose()"
              >
                {{ copy.uploadAnother }}
              </button>
            } @else {
              <button
                type="button"
                class="btn btn-outline-secondary btn-sm invisible"
                disabled
                tabindex="-1"
                aria-hidden="true"
                data-id="profile-photo-upload-another-idle"
              >
                {{ copy.uploadAnother }}
              </button>
            }
          </div>
        </div>
      } @else if (step() === 'current') {
        <div class="text-center">
          <hilos-avatar [name]="name()" [photo]="photo()" size="lg" />
          <div class="d-grid gap-2 mt-3">
            <button
              type="button"
              class="btn btn-outline-secondary btn-sm"
              [disabled]="busy()"
              data-id="profile-photo-upload-another"
              (click)="choose()"
            >
              {{ copy.uploadAnother }}
            </button>
            <button
              type="button"
              class="btn btn-outline-danger btn-sm"
              [disabled]="busy()"
              data-id="profile-photo-remove"
              (click)="flow().remove()"
            >
              {{ copy.remove }}
            </button>
          </div>
          <p class="small text-body-secondary mt-2 mb-0">
            {{ copy.removeNote }}
          </p>
        </div>
      }
      <hilos-form-error [message]="refusal()" dataId="profile-photo-error" />
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-outline-secondary"
          data-id="profile-photo-cancel"
          (click)="requestClose()"
        >
          {{ copy.cancel }}
        </button>
        @if (step() === 'crop') {
          <button
            hilosLoadingButton
            class="btn-primary"
            [loading]="busy()"
            [disabled]="checking()"
            data-id="profile-photo-save"
            (click)="flow().save()"
          >
            {{ copy.save }}
          </button>
        }
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosProfilePhoto {
  readonly flow = input.required<HilosProfilePhotoFlow>()
  protected readonly copy = HILOS_PROFILE_PHOTO_COPY
  protected readonly step = signal<HilosProfilePhotoStep>('closed')
  protected readonly photo = signal<string | null>(null)
  protected readonly name = signal('')
  protected readonly initials = signal('')
  protected readonly preview = signal<HilosProfilePhotoPreview | null>(null)
  protected readonly zoom = signal(1)
  protected readonly busy = signal(false)
  protected readonly checking = signal(false)
  protected readonly refusal = signal<string | null>(null)
  protected readonly voice = signal('')
  private readonly inputFile = viewChild<ElementRef<HTMLInputElement>>('input')
  private readonly canvas = viewChild<ElementRef<HTMLCanvasElement>>('canvas')
  private dragging: { id: number; x: number; y: number } | null = null

  constructor() {
    effect((onCleanup) => {
      const flow = this.flow()
      const stops = [
        mirror(flow.step, this.step),
        mirror(flow.photo, this.photo),
        mirror(flow.name, this.name),
        mirror(flow.initials, this.initials),
        mirror(flow.preview, this.preview),
        mirror(flow.zoom, this.zoom),
        mirror(flow.busy, this.busy),
        mirror(flow.checking, this.checking),
        mirror(flow.refusal, this.refusal),
        mirror(flow.voice, this.voice),
      ]
      onCleanup(() => stops.forEach((stop) => stop()))
    })
    afterRenderEffect(() => {
      const shown = this.preview()
      const canvas = this.canvas()?.nativeElement
      if (shown && canvas)
        drawHilosPhotoPreview(canvas, shown.bitmap, shown.square)
    })
  }

  protected onOpenChange(open: boolean): void {
    if (!open) this.flow().close()
  }
  protected choose(): void {
    this.inputFile()?.nativeElement.click()
  }
  protected picked(event: Event): void {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0]
    if (file) void this.flow().pick(file)
    input.value = ''
  }
  protected dropped(event: DragEvent): void {
    event.preventDefault()
    const file = event.dataTransfer?.files[0]
    if (file) void this.flow().pick(file)
  }
  protected onZoom(event: Event): void {
    this.flow().setZoom(Number((event.target as HTMLInputElement).value))
  }
  protected startDrag(event: PointerEvent): void {
    this.dragging = { id: event.pointerId, x: event.clientX, y: event.clientY }
    ;(event.currentTarget as HTMLCanvasElement).setPointerCapture(
      event.pointerId,
    )
  }
  protected drag(event: PointerEvent): void {
    const previous = this.dragging
    if (!previous || previous.id !== event.pointerId) return
    const side = this.canvas()?.nativeElement.getBoundingClientRect().width ?? 0
    if (side > 0)
      this.flow().move(
        event.clientX - previous.x,
        event.clientY - previous.y,
        side,
      )
    this.dragging = { id: event.pointerId, x: event.clientX, y: event.clientY }
  }
  protected stopDrag(): void {
    this.dragging = null
  }
  protected arrow(event: KeyboardEvent): void {
    const motion = ARROW_MOVE[event.key]
    const side = this.canvas()?.nativeElement.getBoundingClientRect().width ?? 0
    if (!motion || side <= 0) return
    event.preventDefault()
    this.flow().move(motion[0], motion[1], side)
  }
}
