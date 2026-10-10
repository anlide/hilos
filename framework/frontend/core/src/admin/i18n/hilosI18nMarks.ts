// Visual mark attributes and legend items for the i18n section (HIL-112, HIL-1481).
// Shared between languages, countries, and translation screens.

/** Visual mark attributes attached to an i18n section row or displayed in the legend. */
export interface HilosI18nMark {
  readonly icon: string
  readonly tone: string
  readonly label: string
}

/** A row in an i18n section marks legend. */
export interface HilosI18nLegendRow {
  readonly key: string
  readonly mark: HilosI18nMark
  readonly meaning: string
}
