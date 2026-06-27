// ConflictHeader — a modal header that flags an unresolved 3-way-merge conflict.
// Place it in a modal header; it shows the title and, once a field has diverged
// on both the user's and the server's side (the core threeWayMerge `conflict`
// result), a badge so the user knows a resolution is pending before Save
// re-enables. Bootstrap classes only (styling-rules.md).

/** Props for {@link ConflictHeader}. */
export interface ConflictHeaderProps {
  /** The dialog title. */
  title: string
  /** Whether an unresolved conflict is present. */
  conflict?: boolean
}

/**
 * A modal title that badges an unresolved conflict.
 *
 * @param props The title and whether a conflict is unresolved.
 */
export function ConflictHeader({
  title,
  conflict = false,
}: ConflictHeaderProps) {
  return (
    <h5 className="modal-title d-inline-flex align-items-center gap-2 mb-0">
      <span>{title}</span>
      {conflict ? (
        <span className="badge text-bg-danger" data-id="conflict-badge">
          conflict
        </span>
      ) : null}
    </h5>
  )
}
