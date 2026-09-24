// HilosQrCode — a QR code drawn as SVG from the matrix the core computes
// (`qrMatrix`, HIL-494). The core owns the library; this owns nothing but the
// drawing: one path of dark squares over a light field, with the quiet zone of
// four modules a scanner needs around it. Light and dark are fixed rather than
// themed — a code inverted by a dark theme is a code most scanners refuse.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
} from '@angular/core'
import { qrMatrix } from '@hilos/core'

/** Modules of light border every side needs for a scanner to find the code. */
const QUIET_ZONE = 4

/** The QR code of a text, as an image with a name. */
@Component({
  selector: 'hilos-qr-code',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    class: 'd-block',
  },
  template: `
    <svg
      [attr.viewBox]="viewBox()"
      role="img"
      [attr.aria-label]="label()"
      shape-rendering="crispEdges"
      class="d-block mx-auto"
      width="176"
      height="176"
      data-id="qr-code"
    >
      <rect [attr.width]="size()" [attr.height]="size()" fill="#fff" />
      <path [attr.d]="path()" fill="#000" />
    </svg>
  `,
})
export class HilosQrCode {
  /** The text the code carries, e.g. an `otpauth://` address. */
  readonly text = input.required<string>()
  /** What the code is, for a reader who cannot see it. */
  readonly label = input.required<string>()

  private readonly matrix = computed(() => qrMatrix(this.text()))

  protected readonly size = computed(
    () => this.matrix().length + 2 * QUIET_ZONE,
  )

  protected readonly viewBox = computed(
    () => `0 0 ${this.size()} ${this.size()}`,
  )

  /** Every dark module as one unit square of a single path. */
  protected readonly path = computed(() => {
    const squares: string[] = []
    this.matrix().forEach((row, y) => {
      row.forEach((dark, x) => {
        if (dark) {
          squares.push(`M${x + QUIET_ZONE} ${y + QUIET_ZONE}h1v1h-1z`)
        }
      })
    })

    return squares.join('')
  })
}
