export function Widget() {
  return (
    <>
      <div data-id="shared-static" />
      <div data-id={`shared-dynamic-${row.id}`} />
      <div data-id={`shared-long-${row.id}`} />
      <div data-id={dataId} />
      <div data-id={`${dataId}-slot`} />
    </>
  )
}
