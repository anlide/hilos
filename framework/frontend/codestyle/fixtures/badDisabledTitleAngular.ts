// Deliberately broken sample: the four ways a title on a control that can be
// disabled stops being its name, in an Angular component template. DISABLED-TITLE
// must report each element once, on the line of its title.

/** Stand-in for Angular's decorator: the fixture depends on nothing. */
declare function Component(definition: object): ClassDecorator

@Component({
  selector: 'hilos-row',
  template: `
    <div class="d-flex gap-2">
      <button
        type="button"
        [disabled]="reason !== null"
        [attr.title]="reason ?? 'Restore this backup'"
        aria-label="Restore this backup"
      ></button>
      <button type="button" disabled title="Delete backup"></button>
      <button
        hilosLoadingButton
        [loading]="saving"
        title="Saving the draft"
        aria-label="Save"
      ></button>
      <input
        type="checkbox"
        [attr.disabled]="busy"
        [attr.aria-label]="
          pinned ? 'Unpin from rotation' : 'Pin out of rotation'
        "
        [title]="pinned ? 'Pinned out of rotation' : 'Pin out of rotation'"
      />
    </div>
  `,
})
export class Row {
  reason: string | null = null
  saving = false
  busy = false
  pinned = true
}
