// HilosAvatar — one circle of initials for a person in the header, profile and
// admin card. The circle is decorative: its surroundings carry the name, visibly
// or as hidden text, and any link or tooltip. Photos arrive in HIL-1205.
// In the header it may carry the mark of the session's standing (HIL-945): a ring
// in the standing's color and its icon in the corner, the pair of the strip that
// says the same in words — so the mark is decorative too.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
} from '@angular/core'
import { formatInitials, type HilosAvatarMark } from '@hilos/core'

/** A decorative circle with the person's initials or a person icon. */
@Component({
  selector: 'hilos-avatar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <span
      class="rounded-circle bg-secondary-subtle d-inline-flex align-items-center justify-content-center fw-semibold flex-shrink-0"
      [class]="circleClass()"
      data-id="hilos-avatar"
      aria-hidden="true"
    >
      @if (initials()) {
        {{ initials() }}
      } @else {
        <i class="bi bi-person"></i>
      }
      @if (mark(); as shown) {
        <i
          class="bi position-absolute hilos-avatar-mark"
          [class]="shown.icon + ' text-' + shown.tone + '-emphasis'"
          data-id="avatar-mark"
        ></i>
      }
    </span>
  `,
})
export class HilosAvatar {
  /** The person's name, from the same source as the surrounding text. */
  readonly name = input.required<string>()
  /** Header (sm), admin card (md), or profile (lg). */
  readonly size = input<'sm' | 'md' | 'lg'>('sm')
  /** The standing mark by the header avatar, or none (`hilosSessionAvatarMark`). */
  readonly mark = input<HilosAvatarMark | null>(null)

  protected readonly initials = computed(() => formatInitials(this.name()))
  protected readonly circleClass = computed(() => {
    const mark = this.mark()
    const size = 'hilos-avatar-' + this.size()

    return mark === null
      ? size
      : `${size} position-relative border border-2 border-${mark.tone}`
  })
}
