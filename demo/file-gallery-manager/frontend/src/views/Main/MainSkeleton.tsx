// The main page's skeleton (HIL-983): what the routed outlet draws while the
// page waits for its first answer. Decorative: the outlet announces the wait
// once.
export default function MainSkeleton() {
  return (
    <p className="placeholder-glow" aria-hidden="true">
      <span className="placeholder col-4 rounded" />
    </p>
  )
}
