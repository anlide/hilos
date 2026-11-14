// Shared screen effects (browser-only, plain DOM, no framework). Views bind them
// around the DOM-free picker so its narrow view follows one rule across
// frameworks (multiframework-core.md).

// The SDK's one phone threshold: Bootstrap md less a hair. Date pickers, toast
// stacks and modal sheets select their classes from this fact, not CSS queries.
const NARROW_SCREEN_QUERY = '(max-width: 767.98px)'

/**
 * Report the current narrow-screen state immediately and follow its changes.
 *
 * @param onChange The callback for the initial state and each media change.
 * @returns A function that removes the media listener.
 */
export function bindNarrowScreen(
  onChange: (narrow: boolean) => void,
): () => void {
  let media: MediaQueryList | null = null
  try {
    media = globalThis.matchMedia?.(NARROW_SCREEN_QUERY) ?? null
  } catch {
    // A prerender or restricted browser has no screen query to follow.
  }

  onChange(media?.matches ?? false)
  if (media === null) return () => {}

  const onMediaChange = (event: MediaQueryListEvent): void => {
    onChange(event.matches)
  }
  media.addEventListener('change', onMediaChange)
  return () => media.removeEventListener('change', onMediaChange)
}
