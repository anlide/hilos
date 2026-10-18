// The support block's data: the three tiers the /about page offers and the one
// sentence it answers a subscribe attempt with. No DOM and no framework, so the
// three view layers carry markup alone and the list is not typed out three times
// (multiframework-core.md) — the mockup is one drawing, and three hand-kept
// copies of it disagree the first time a price changes.
//
// Everything here is hard-coded rather than configurable, and deliberately:
// there is nothing to configure against — no provider, no currency handling, no
// plan registry — and inventing a configuration surface now would pre-decide
// exactly what HIL-355 was left to decide.
//
// The refusal lives here for the opposite reason: it is the one place HIL-355
// deletes when a real payment step arrives, and three view layers cannot word it
// differently in the meantime. It is not an error report — nothing is sent, so
// there is no failure to describe — and it deliberately has no name on the wire.

/** One tier of support, as the dialog draws it. */
export interface HilosSupportTier {
  /** The tier's own identifier, used for its `data-id` and for the selection. */
  readonly key: HilosSupportTierKey
  /** The price as it is shown, currency sign and period included — the tier's heading, with no name. */
  readonly price: string
  /** The one line under the heading saying what the money means. */
  readonly blurb: string
  /** The Bootstrap icon class the tier is drawn with, `bi-heart`. */
  readonly icon: string
}

/** The tiers there are. A closed set: a tier is a drawing, not a configuration. */
export type HilosSupportTierKey = 'small' | 'medium' | 'large'

/** The three tiers, in the order the mockup draws them. */
export const HILOS_SUPPORT_TIERS: readonly HilosSupportTier[] = [
  {
    key: 'small',
    price: '€0.20 / month',
    blurb: 'Thank you — these coins add up to a server',
    icon: 'bi-heart',
  },
  {
    key: 'medium',
    price: '€2 / month',
    blurb: 'A tick on your profile and our honest affection',
    icon: 'bi-heart',
  },
  {
    key: 'large',
    price: '€20 / month',
    blurb: 'The same, only the tick sparkles a little',
    icon: 'bi-heart',
  },
]

/**
 * The tier the dialog opens on — the one the mockup draws highlighted.
 *
 * Something is always selected, so the primary button is live from the first
 * frame: a screen whose whole subject is the absence of dead buttons cannot open
 * with one.
 */
export const HILOS_SUPPORT_DEFAULT_TIER: HilosSupportTierKey = 'medium'

/** The honest answer to a subscribe attempt, for as long as there is no payment. */
export const HILOS_SUPPORT_REFUSAL =
  'The payment system is not built yet. Nothing was charged and no subscription was created.'
