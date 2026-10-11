// Semantic classes and paint inherited from the surface are legal.
export function Card() {
  return (
    <>
      <div className="bg-body-tertiary text-body-emphasis border" />
      <div className="bg-dark-subtle text-light-emphasis border-black-subtle" />
      <div className="alert alert-light" />
      <div className="list-group-item list-group-item-dark" />
      <svg>
        <path fill="currentColor" stroke="none" />
        <path fill="none" stroke="transparent" />
        <path fill="inherit" stroke="context-stroke" />
        <path fill="context-fill" stroke="url(#paint)" />
      </svg>
      <Widget color="secondary" />
    </>
  )
}
