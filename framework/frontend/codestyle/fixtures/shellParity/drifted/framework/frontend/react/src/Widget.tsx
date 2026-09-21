export function Widget() {
  return (
    <>
      <div data-id="strict-parity" />
      <div data-id={`shared-dynamic-${row.id}`} />
      <div data-id={dataId} />
      <HilosChild dataId="react-prop-only" />
    </>
  )
}
