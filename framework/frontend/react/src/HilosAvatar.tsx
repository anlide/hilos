// HilosAvatar — one circle of initials for a person in the header, profile and
// admin card. The circle is decorative: its surroundings carry the name, visibly
// or as hidden text, and any link or tooltip. Photos arrive in HIL-1205.
// In the header it may carry the mark of the session's standing (HIL-945): a ring
// in the standing's color and its icon in the corner, the pair of the strip that
// says the same in words — so the mark is decorative too.
import { formatInitials, type HilosAvatarMark } from '@hilos/core'

/** Props for {@link HilosAvatar}. */
export interface HilosAvatarProps {
  /** The person's name, from the same source as the surrounding text. */
  name: string
  /** Header (sm), admin card (md), or profile (lg). Defaults to sm. */
  size?: 'sm' | 'md' | 'lg'
  /** The standing mark by the header avatar, or none (`hilosSessionAvatarMark`). */
  mark?: HilosAvatarMark | null
}

/**
 * Render a decorative circle with the person's initials or a person icon.
 *
 * @param props The person's name, the size of the circle, and its mark.
 */
export function HilosAvatar({
  name,
  size = 'sm',
  mark = null,
}: HilosAvatarProps) {
  const initials = formatInitials(name)
  const ringClasses =
    mark === null
      ? ''
      : ` position-relative border border-2 border-${mark.tone}`

  return (
    <span
      className={`hilos-avatar-${size} rounded-circle bg-secondary-subtle d-inline-flex align-items-center justify-content-center fw-semibold flex-shrink-0${ringClasses}`}
      data-id="hilos-avatar"
      aria-hidden="true"
    >
      {initials || <i className="bi bi-person" />}
      {mark !== null && (
        <i
          className={`bi ${mark.icon} position-absolute hilos-avatar-mark text-${mark.tone}-emphasis`}
          data-id="avatar-mark"
        />
      )}
    </span>
  )
}
