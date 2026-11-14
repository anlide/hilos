// Shared motion effects (browser-only, plain DOM, no framework). Views bind
// them so their animations follow one reduced-motion preference across
// frameworks (multiframework-core.md).

const REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)'

/**
 * Report the current reduced-motion preference immediately and follow changes.
 *
 * @param onChange The callback for the initial state and each media change.
 * @returns A function that removes the media listener.
 */
export function bindReducedMotion(
  onChange: (reduced: boolean) => void,
): () => void {
  let media: MediaQueryList | null = null
  try {
    media = globalThis.matchMedia?.(REDUCED_MOTION_QUERY) ?? null
  } catch {
    // A prerender or restricted browser has no motion query to follow.
  }

  onChange(media?.matches ?? false)
  if (media === null) return () => {}

  const onMediaChange = (event: MediaQueryListEvent): void => {
    onChange(event.matches)
  }
  media.addEventListener('change', onMediaChange)
  return () => media.removeEventListener('change', onMediaChange)
}
