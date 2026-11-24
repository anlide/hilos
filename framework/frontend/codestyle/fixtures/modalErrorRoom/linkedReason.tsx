export function Reason({ action }: { action: { error: string | null } }) {
  return (
    <p className={`small${action.error ? '' : ' invisible'}`}>
      Changes could not be saved.
    </p>
  )
}
