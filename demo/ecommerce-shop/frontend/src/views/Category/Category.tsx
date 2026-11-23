import { useContext, useLayoutEffect, useMemo } from 'react'
import {
  HilosBreadcrumb,
  HilosPageHeadingIdContext,
  HilosRouterContext,
  useSignal,
} from '@hilos/react/base'

import { scopes } from '../../bootstrap/session.js'
import { PAGE_CATEGORY, PAGE_MAIN } from '../../pages/keys.js'
import CategoryProductsTable from './CategoryProductsTable.js'
import CategorySkeleton from './CategorySkeleton.js'
import { createCategoryPage } from './categoryPage.js'

const CATEGORY_HEADING_ID = 'shop-category-heading'

/** The public category page and its live storefront window. */
export default function Category() {
  const router = useContext(HilosRouterContext)
  if (!router) throw new Error('Category requires a provided Hilos router.')

  const { details, watchDetails } = useMemo(
    () => createCategoryPage(scopes, router),
    [router],
  )
  useLayoutEffect(watchDetails, [watchDetails])
  const category = useSignal(details)
  if (category === null) return <CategorySkeleton />

  return (
    <>
      <HilosBreadcrumb
        crumbs={[
          { page: PAGE_MAIN, label: 'Home', to: '/' },
          { page: PAGE_CATEGORY, label: category.name },
        ]}
        className="text-break"
      />
      <h1 id={CATEGORY_HEADING_ID} className="h4 mb-1 text-break">
        {category.name}
      </h1>
      <p className="text-body-secondary text-break">{category.description}</p>
      <HilosPageHeadingIdContext.Provider value={CATEGORY_HEADING_ID}>
        <CategoryProductsTable
          key={category.id}
          page={PAGE_CATEGORY}
          categoryId={category.id}
        />
      </HilosPageHeadingIdContext.Provider>
    </>
  )
}
