@Component({ template: importedTemplate })
export class Imported {}
@Component({ template: '<select [value]="choice">' + '</select>' })
export class Concatenated {}
@Component({ template: `<select [value]="${choice}"></select>` })
export class Substitution {}
@Other({ template: '<select [value]="choice"></select>' })
export class Other {}
