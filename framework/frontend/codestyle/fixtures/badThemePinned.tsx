// Deliberately broken: pinned classes, literal/SVG/canvas paint and a local theme.
export function Card() {
  return (
    <>
      <div className="bg-light" />
      <span className="text-dark" />
      <span className="text-bg-light" />
      <button className="btn btn-black" />
      <button className="btn btn-outline-dark" />
      <div className="border-white" />
      <a className="link-light" />
      <table className="table-black" />
      <div className="dropdown-menu-dark" />
      <button className="btn-close-white" />
      <span className="text-black-50" />
      <svg>
        <path fill={'red'} stroke="#abc" />
      </svg>
      <div data-bs-theme="light" />
    </>
  )
}

const accent = '#aabbcc'

export function paint(context: CanvasRenderingContext2D): void {
  context.fillStyle = accent
  context.strokeStyle = 'rgb(0 0 0)'
  context.shadowColor = '#000'
}
