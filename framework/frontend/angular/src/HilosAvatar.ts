// HilosAvatar — one circle of initials for a person in the header, profile and
// admin card. The circle is decorative: its surroundings carry the name, visibly
// or as hidden text, and any link or tooltip. Photos arrive in HIL-1205.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
} from '@angular/core'
import { formatInitials } from '@hilos/core'

/** A decorative circle with the person's initials or a person icon. */
@Component({
  selector: 'hilos-avatar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <span
      class="rounded-circle bg-secondary-subtle d-inline-flex align-items-center justify-content-center fw-semibold flex-shrink-0"
      [class]="'hilos-avatar-' + size()"
      data-id="hilos-avatar"
      aria-hidden="true"
    >
      @if (initials()) {
        {{ initials() }}
      } @else {
        <i class="bi bi-person"></i>
      }
    </span>
  `,
})
export class HilosAvatar {
  /** The person's name, from the same source as the surrounding text. */
  readonly name = input.required<string>()
  /** Header (sm), admin card (md), or profile (lg). */
  readonly size = input<'sm' | 'md' | 'lg'>('sm')

  protected readonly initials = computed(() => formatInitials(this.name()))
}
