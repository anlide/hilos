// The look-alikes in JSX: a title that repeats the name as written, the same name
// as a string on one side and a quoted expression on the other, a title on an
// element that cannot be disabled, and a disabled control with a name and no
// title. DISABLED-TITLE must stay silent on every one of them.

/** The four forms, one element each. */
export function Row({
  busy,
  label,
  shipError,
}: {
  busy: boolean
  label: string
  shipError: string
}): JSX.Element {
  return (
    <div className="d-flex gap-2">
      <button
        type="button"
        disabled={busy}
        title="Delete backup"
        aria-label="Delete backup"
      />
      <button
        type="button"
        disabled={busy}
        title={`Send the code via ${label}`}
        aria-label={`Send the code via ${label}`}
      />
      <button type="button" disabled title="Close" aria-label={`Close`} />
      <span title={shipError}>failed</span>
      <button type="button" disabled={busy} aria-label="Restore this backup" />
    </div>
  )
}
