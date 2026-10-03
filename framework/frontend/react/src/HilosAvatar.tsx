// HilosAvatar — one circle of a person's photo or initials in the header,
// profile and admin card. The circle is decorative: its surroundings carry the
// name, visibly or as hidden text, and any link or tooltip.
// In the header it may carry the mark of the session's standing (HIL-945): a ring
// in the standing's color and its icon in the corner, the pair of the strip that
// says the same in words — so the mark is decorative too.
import { formatInitials, type HilosAvatarMark } from '@hilos/core'
import { useEffect, useState } from 'react'

/** Props for {@link HilosAvatar}. */
export interface HilosAvatarProps {
  /** The person's name, from the same source as the surrounding text. */
  name: string
  /** Published photo URL; initials return if it cannot be loaded. */
  photo?: string | null
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
  photo = null,
  size = 'sm',
  mark = null,
}: HilosAvatarProps) {
  const initials = formatInitials(name)
  const [failedPhoto, setFailedPhoto] = useState<string | null>(null)
  useEffect(() => setFailedPhoto(null), [photo])
  const shownPhoto = photo !== failedPhoto ? photo : null
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
      {shownPhoto !== null ? (
        <img
          src={shownPhoto}
          alt=""
          className="w-100 h-100 rounded-circle object-fit-cover"
          data-id="hilos-avatar-photo"
          onError={() => setFailedPhoto(shownPhoto)}
        />
      ) : (
        initials || <i className="bi bi-person" />
      )}
      {mark !== null && (
        <i
          className={`bi ${mark.icon} position-absolute hilos-avatar-mark text-${mark.tone}-emphasis`}
          data-id="avatar-mark"
        />
      )}
    </span>
  )
}
