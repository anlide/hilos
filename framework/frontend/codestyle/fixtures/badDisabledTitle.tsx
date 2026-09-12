// Deliberately broken sample: the four ways a title on a control that can be
// disabled stops being its name, in JSX. DISABLED-TITLE must report each element
// once, on the line of its title.

/** Stand-in for the SDK's button, which turns itself off while it loads. */
declare function LoadingButton(props: {
  loading: boolean
  title: string
  'aria-label': string
}): JSX.Element

/** The four forms, one element each. */
export function Row({
  reason,
  saving,
  pinned,
}: {
  reason: string | null
  saving: boolean
  pinned: boolean
}): JSX.Element {
  return (
    <div className="d-flex gap-2">
      <button
        type="button"
        disabled={reason !== null}
        title={reason ?? 'Restore this backup'}
        aria-label="Restore this backup"
      />
      <button type="button" disabled title="Delete backup" />
      <LoadingButton
        loading={saving}
        title="Saving the draft"
        aria-label="Save"
      />
      <input
        type="checkbox"
        disabled={saving}
        aria-label={pinned ? 'Unpin from rotation' : 'Pin out of rotation'}
        title={pinned ? 'Pinned out of rotation' : 'Pin out of rotation'}
      />
    </div>
  )
}
