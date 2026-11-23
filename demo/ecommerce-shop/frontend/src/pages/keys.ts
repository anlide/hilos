// The ecommerce-shop application's own page keys: the identifier each app page
// subscribes under, mirroring the demo-specific values in backend
// `PageConstants`. The framework's `hilos_*` admin keys are not restated here —
// they come from `@hilos/core` (`HilosPages`/`HILOS_PAGE_ROUTES`), which the
// router in routes.ts merges in. Values must stay byte-for-byte equal to the
// backend page keys — they are the subscription wire identity.

export const PAGE_MAIN = 'main'
export const PAGE_CATEGORY = 'category'
export const PAGE_PRODUCT = 'product'
export const PAGE_ADMIN_WAREHOUSES = 'admin_warehouses'
export const PAGE_ADMIN_CATEGORIES = 'admin_categories'
export const PAGE_ADMIN_PRODUCTS = 'admin_products'
export const PAGE_ADMIN_PRODUCT = 'admin_product'
export const PAGE_ADMIN_PRODUCT_STOCK = 'admin_product_stock'
export const PAGE_ADMIN_VOUCHERS = 'admin_vouchers'
