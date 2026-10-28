// Angular components carrying their templates inline: a template literal, a
// quoted string whose escapes shift nothing in the file, and templates that are
// not literals at all, which are never run and come back as limits.

/** Stand-in for Angular's decorator: the fixture depends on nothing. */
declare function Component(definition: object): ClassDecorator

/** A template kept apart from its component, which the scanner does not run. */
const SHARED_TEMPLATE = '<p>{{ created.toLocaleString() }}</p>'

@Component({
  selector: 'hilos-card',
  template: `
    <section class="card" [title]="created | date: 'short'">
      <h2 [attr.aria-label]="heading">{{ heading }}</h2>
      @if (created) {
        <p>Created {{ created | date }} by {{ author?.name }}</p>
      }
      <button type="button" (click)="open(created.toLocaleString())">
        Open
      </button>
    </section>
  `,
})
export class Card {
  created = new Date()
  heading = 'Card'
  author: { name: string } | null = null

  /**
   * @param moment What the button opens
   */
  open(moment: string): void {
    console.info(moment)
  }
}

@Component({
  selector: 'hilos-escaped',
  template: '<p title="a" data-id="b">\n  {{ seen[\'toLocaleString\']() }}</p>',
})
export class Escaped {
  seen = new Date()
}

@Component({ selector: 'hilos-shared', template: SHARED_TEMPLATE })
export class Shared {}

@Component({
  selector: 'hilos-assembled',
  template: '<div>' + SHARED_TEMPLATE + '</div>',
})
export class Assembled {}

@Component({
  selector: 'hilos-substituted',
  template: `<div>${SHARED_TEMPLATE}</div>`,
})
export class Substituted {}
