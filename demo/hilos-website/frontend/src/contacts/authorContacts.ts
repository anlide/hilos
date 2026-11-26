/** The name shown with the author's contact links. */
export const AUTHOR_NAME = 'Oleksandr Baranov'

/** A contact link with its visible label and Bootstrap icon. */
export interface AuthorContact {
  label: string
  href: string
  icon: string
}

/** The author's contact links in display order. */
export const AUTHOR_CONTACTS: readonly AuthorContact[] = [
  {
    label: 'LinkedIn',
    href: 'https://www.linkedin.com/in/anlide/',
    icon: 'bi-linkedin',
  },
  {
    label: 'alexander.baranov@anlide.online',
    href: 'mailto:alexander.baranov@anlide.online',
    icon: 'bi-envelope',
  },
  {
    label: 'elfanlide@gmail.com',
    href: 'mailto:elfanlide@gmail.com',
    icon: 'bi-envelope',
  },
  {
    label: 'Telegram',
    href: 'https://t.me/alexander_baranov_kharkiv',
    icon: 'bi-telegram',
  },
  {
    label: 'GitHub',
    href: 'https://github.com/anlide',
    icon: 'bi-github',
  },
]
