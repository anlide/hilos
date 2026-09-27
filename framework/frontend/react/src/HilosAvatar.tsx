// HilosAvatar — one circle of initials for a person in the header, profile and
// admin card. The circle is decorative: its surroundings carry the name, visibly
// or as hidden text, and any link or tooltip. Photos arrive in HIL-1205.
import { formatInitials } from '@hilos/core'

/** Props for {@link HilosAvatar}. */
export interface HilosAvatarProps {
  /** The person's name, from the same source as the surrounding text. */
  name: string
  /** Header (sm), admin card (md), or profile (lg). Defaults to sm. */
  size?: 'sm' | 'md' | 'lg'
}

/**
 * Render a decorative circle with the person's initials or a person icon.
 *
 * @param props The person's name and the size of the circle.
 */
export function HilosAvatar({ name, size = 'sm' }: HilosAvatarProps) {
  const initials = formatInitials(name)

  return (
    <span
      className={`hilos-avatar-${size} rounded-circle bg-secondary-subtle d-inline-flex align-items-center justify-content-center fw-semibold flex-shrink-0`}
      data-id="hilos-avatar"
      aria-hidden="true"
    >
      {initials || <i className="bi bi-person" />}
    </span>
  )
}
