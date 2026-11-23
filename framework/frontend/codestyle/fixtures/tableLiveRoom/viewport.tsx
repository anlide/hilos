import { HilosTableLive as LiveRoom } from './HilosTableLive.js'

export function HilosViewportTable() {
  return (
    <section>
      {/* <LiveRoom /> */}
      <LiveRoom />
      <div className="table-responsive">
        <table />
      </div>
      <div data-id="hilos-table-cards" />
    </section>
  )
}
