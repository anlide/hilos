// What a removed row's placeholder says in every view. The wire leaves the
// reason open so an older client can meet a newer backend; the core narrows it
// to the three reasons it knows, and the three view packages receive one copy.

/** The three reasons a shown row can leave its window. */
export type TableRemovalReason = 'deleted' | 'left_set' | 'moved_out'

/** The icon and words a placeholder uses for each removal reason. */
export const TABLE_PLACEHOLDER_COPY: Readonly<
  Record<TableRemovalReason, { readonly icon: string; readonly text: string }>
> = {
  deleted: { icon: 'bi-dash-circle', text: 'Removed' },
  moved_out: { icon: 'bi-arrows-move', text: 'Moved to another page' },
  left_set: { icon: 'bi-box-arrow-right', text: 'No longer in this list' },
}

/**
 * Narrow the wire's open removal reason to the reasons this client knows.
 *
 * Unknown and absent reasons keep the previous behavior: before placeholders
 * distinguished their cause, every one of them said "Removed".
 *
 * @param raw The open reason string from the wire.
 * @returns The reason this client can display.
 */
export function hilosTableRemovalReason(
  raw: string | undefined,
): TableRemovalReason {
  return raw === 'left_set' || raw === 'moved_out' ? raw : 'deleted'
}

/**
 * Give a view the icon and words for a removed-row placeholder.
 *
 * A view also asks for the fallback while it has a placeholder with no explicit
 * cause, so null takes the legacy deleted form.
 *
 * @param removal The reason carried by the viewport row, or null for the fallback.
 * @returns The icon and words the view draws.
 */
export function hilosTablePlaceholder(removal: TableRemovalReason | null): {
  readonly icon: string
  readonly text: string
} {
  return TABLE_PLACEHOLDER_COPY[removal ?? 'deleted']
}
