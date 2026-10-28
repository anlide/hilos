// Angular components with inline templates: a literal one is read in the
// coordinates of the file, and one that is not a literal is a limit — neither
// read nor reported — while the TypeScript around both is judged.

/** Stand-in for Angular's decorator: the fixture depends on nothing. */
declare function Component(definition: object): ClassDecorator

/** A template kept apart from its component, never run. */
const SHARED_TEMPLATE = '<p>{{ created.toLocaleString() }}</p>'

@Component({
  selector: 'hilos-card',
  template: `
    <section [title]="created | date">
      <p>{{ created.toLocaleDateString() }}</p>
      <p>{{ created.getFullYear() }}</p>
    </section>
  `,
})
export class Card {
  created = new Date()

  /** @returns A moment printed in TypeScript, below the template */
  label(): string {
    return this.created.toLocaleTimeString()
  }
}

@Component({
  selector: 'hilos-escaped',
  template: '<p title="a">\n  {{ seen[\'toLocaleDateString\']() }}</p>',
})
export class Escaped {}

@Component({ selector: 'hilos-shared', template: SHARED_TEMPLATE })
export class Shared {
  created = new Date()

  /** @returns A moment printed in TypeScript beside a template not read */
  label(): string {
    return this.created.toUTCString()
  }
}
