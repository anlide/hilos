// The framework admin dashboard (HilosPages.DASHBOARD). A placeholder for now:
// the point it proves is the no-refresh transition that reaches it (the gear in
// HilosLayout) over the live socket. The real dashboard content lands with the
// admin section.
export default function DashboardView() {
  return (
    <section data-id="dashboard-view">
      <h1 className="h4">Hilos dashboard</h1>
      <p className="text-body-secondary">Framework admin section.</p>
    </section>
  )
}
