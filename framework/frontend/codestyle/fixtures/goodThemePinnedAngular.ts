// Semantic classes and paint inherited from the surface are legal.
declare function Component(definition: object): ClassDecorator

@Component({
  template: `
    <div class="bg-body-tertiary text-body-emphasis border"></div>
    <div class="bg-dark-subtle text-light-emphasis border-black-subtle"></div>
    <div class="alert alert-light"></div>
    <div class="list-group-item list-group-item-dark"></div>
    <svg>
      <path fill="currentColor" stroke="none" />
      <path fill="none" stroke="transparent" />
      <path fill="inherit" stroke="context-stroke" />
      <path fill="context-fill" stroke="url(#paint)" />
    </svg>
    <widget color="secondary"></widget>
  `,
})
export class Card {}
