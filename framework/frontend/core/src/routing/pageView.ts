// A page entry describes code without starting an import. Components remain
// opaque to core, including eager function components.

/** A deferred page component, identified by its explicit tag. */
export interface LazyPageView<T> {
  readonly kind: 'lazy-page'
  readonly load: () => Promise<T>
}

/** An eager component or a descriptor created once outside rendering. */
export type PageView<T> = T | LazyPageView<T>

/**
 * Describe a page loader without invoking it.
 *
 * @param load Resolves the component when its page is first selected.
 */
export function lazyPage<T>(load: () => Promise<T>): LazyPageView<T> {
  return { kind: 'lazy-page', load }
}
