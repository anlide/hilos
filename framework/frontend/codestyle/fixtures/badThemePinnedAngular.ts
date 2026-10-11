// Deliberately broken: pinned classes, literal/SVG/canvas paint and a local theme.
declare function Component(definition: object): ClassDecorator

@Component({
  template: `
    <div class="bg-light"></div>
    <span class="text-dark"></span>
    <span class="text-bg-light"></span>
    <button class="btn btn-black"></button>
    <button class="btn btn-outline-dark"></button>
    <div class="border-white"></div>
    <a class="link-light"></a>
    <table class="table-black"></table>
    <div class="dropdown-menu-dark"></div>
    <button class="btn-close-white"></button>
    <span class="text-black-50"></span>
    <svg><path fill="red" stroke="#abc" /></svg>
    <div data-bs-theme="light"></div>
  `,
})
export class Card {}

const accent = '#aabbcc'

export function paint(context: CanvasRenderingContext2D): void {
  context.fillStyle = accent
  context.strokeStyle = 'rgb(0 0 0)'
  context.shadowColor = '#000'
}
