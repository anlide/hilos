const example = '<div data-id="hilos-table-live-slot" />'
// <div data-id="hilos-table-live-idle" />

export function Screen() {
  return (
    <section>
      {/* <div data-id="hilos-table-live-idle" /> */}
      <div data-id="hilos-table-live-slot" />
      <HilosModal>
        <HilosViewportTable />
        <div data-id={'hilos-table-live-idle'} />
      </HilosModal>
      <p>{example}</p>
    </section>
  )
}
