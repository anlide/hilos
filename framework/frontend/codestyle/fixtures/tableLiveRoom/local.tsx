import {
  HilosModal as Dialog,
  HilosViewportTable as Grid,
} from '@hilos/react/base'

const cells = {
  name: () =>
    error ? (
      <div className="alert alert-danger">{error}</div>
    ) : (
      <div style={{ minHeight: '2rem' }} data-id="cell-reserve" />
    ),
}

export function Screen() {
  return (
    <section>
      <div className="alert invisible" data-id="page-reserve" />
      <HilosActionError />
      <Grid
        cells={cells}
        details={{
          note: () => (
            <>
              {error && <div className="alert alert-danger">{error}</div>}
              <div className="opacity-0" aria-hidden="true" />
              <i className="bi bi-snow invisible" />
              <span className="hilos-table-marks invisible">
                <span className="badge border border-warning-subtle text-warning-emphasis">
                  Will move
                </span>
              </span>
              <div className="hilos-table-work-space hilos-table-work-reserve" />
              <div className="hilos-table-record-space">Gone</div>
              {rowMessage && (
                <span data-id="hilos-table-row-message">{rowMessage}</span>
              )}
              {/* <div className="alert invisible" /> */}
              <Dialog>
                <div className="alert invisible" data-id="dialog-reserve" />
                <Grid
                  tableProgress={() => (
                    <div
                      className="alert alert-warning opacity-0"
                      data-id="nested-reserve"
                    />
                  )}
                />
              </Dialog>
              <div className={message === 'alert' ? 'invisible' : ''} />
              <div hidden className="alert invisible" />
              {error && (
                <div className={error ? 'alert alert-danger' : ''}>{error}</div>
              )}
              {!error && <div style={{ minHeight: 36 }} />}
            </>
          ),
        }}
      />
    </section>
  )
}
