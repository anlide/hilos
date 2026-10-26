# Page Head

Read this before building or redrawing a section or entity head, declaring its
catalog identity, changing breadcrumbs, page tabs or a live instance name, or
moving an owner from child cards to the shared head.

This is the approved rule for adoption. Markers name the parts that later leaves
still implement; the binding points below describe today's code separately. An
owner that has not been adopted keeps its existing head and child cards. Country
was the first carrier in HIL-1559; that leaf did not adopt the whole
Internationalization section. Country, Daemon Node and the Internationalization
section use the shared head across Vue, React and Angular. HIL-1728 adopts the
Language entity head and its Overview / Names / Locales row in all three kits;
its breadcrumb, shared heading and document title use the live native name. The
updated language Overview body with its three summary tiles, Main and State
sections is implemented only in Vue. React and Angular language bodies remain
with HIL-1729 and HIL-1730.
Maintenance uses the shared head there, with Circle served only in Vue. Chat and
Binance opt the User Overview into the shared head in Vue; React and Angular
retain their legacy user card until their own redraws. Status is also opted in,
with Overview and Components served only by the Vue Chat and Binance
applications. Other unadopted owners move in their own redraws after HIL-1606 has
merged. Analytics Person is also opted into the shared head by Chat and
Binance. Its Vue page supplies the permitted name, avatar and a blank circular
loading visual. React and Angular keep their legacy Person presentation for
HIL-1739 and HIL-1740. This is separate from the User Overview adoption above.
Logs is opted in by the complete Chat and Binance project provider entries for
Vue. Its six pages share Hilos › Logs, the Logs section title, catalog icon and
lead, and the Overview / By key / By worker / Rotations / Settings / Viewer row.
The framework default Logs entry remains legacy; React and Angular adoption
belongs to HIL-1718 and HIL-1719.

## Core Rule And Ownership

An adopted page renders, in order, **breadcrumb → section head or entity block
→ one row of page tabs → page body**. A section head has an icon, title and lead;
an entity block has an icon or avatar, instance name, badges and description.
The page module supplies the block's content. The shared component presents it
without a registry of concrete pages or their content, and without choosing the
page in place of the navigator. The head belongs to the page's answer, not to
the application shell. Vue's shared head is `HilosPageHead`, mounted by
`HilosAdminPage`; both country tabs use it. React's is a `HilosPageHead` of
its own, mounted by its `HilosAdminPage`, and both React country tabs use it
too. So is Angular's, the `hilos-page-head` component mounted by its
`HilosAdminPage`.

The **owner** is the section or entity whose pages share that head and row.
Each tab is a separate page with its own address. A non-entity child of a tab
stays in that owner's row; an entity opened from a list row starts its own head
and row. Do not give every tab an owner of its own. The catalog declares entities
and enables adoption at the owner, covering all its tabs.

Keep exactly one `h1` on the rendered page: the section title or entity name in
the head. Preserve the heading's connection to a table's accessible name; do
not add a second heading for the entity block. See
[accessibility.md](../frontend/accessibility.md). The shared Vue head preserves
this relationship: its `h1` carries the heading id `HilosAdminPage` provides,
under a section head and an entity block alike. React's and Angular's heads do
the same.

Rule 7 of the approved design puts entity management — edit, copy, delete — in
the body of **Overview**, with properties, summaries and aggregates. Each list
has its own tab, with one list table per page; Overview does not contain those
lists. A section whose page is itself the list, such as Users in the examples,
does not acquire a synthetic extra list page. Apply this content rule in that
owner's redraw; HIL-1598 specifies no country Overview tiles or their contents.

## Public Static Page Presentation

`HilosStaticPage` is also a consumer of the shared `HilosPageHead` in Vue,
React and Angular. It builds a local section presentation from its title, icon,
subtitle and optional `HilosStaticPageNavigation`: the breadcrumb and child
lists are empty, while the local owner and tabs come from those inputs. The
frame provides its heading id through each kit's heading-id context, and the
shared head renders the page's single `h1` with that id. The public components
and their body behavior remain their own; the frame supplies their shared
presentation.

This presentation needs no server catalog adoption or `page_response` identity.
Catalog adoption and the one-reply rule below continue to describe admin
owners. A project can use the frame for its own static pages,
but this does not activate those pages in a project's catalog or routes. The
frame API, its defaults, and the four public pages' fixed presentation values
are described in [sdk-packaging.md](../frontend/sdk-packaging.md#tier-1-cross-cutting-components).

## Catalog And Breadcrumb

Follow the catalog's parent tree from the root of the branch through the owner
of the row. The current tab is not appended. A list contributes a link when it
is a separate tab on the path to an entity; when the section itself is the list,
the section link already serves that purpose. Keep repeated captions when two
different catalog entries on that path have the same caption.

Distinguish three values: the page's **title**, its catalog **Breadcrumb
caption**, and the **instance name** supplied by the page's data. The Breadcrumb
caption is separate from title and lead. It labels breadcrumb links and child
tabs; an empty caption omits that breadcrumb link. The owner's first tab is
Overview. These terms describe distinct concepts; their implementation names
are listed under Implementation Status And Binding Points.

Do not draw a chain of one link. The last link is text, never a link to itself.
The application resolves addresses from page keys and route params. An
intermediate link without an address remains text: do not substitute another
address or discard the link. The shared Vue head draws the breadcrumb this way,
and so do React's and Angular's.

## Tabs

Build the row in catalog order: the owner first as Overview, then its child
pages except entities. A non-entity child of a tab follows its parent in the
same row. The open page itself is current, including such a child; do not mark
its parent instead. Entity pages opened from lists are new owners, never tabs
in the containing owner's row.

Use the same eligibility decision as dashboard cards: omit pages that are
absent, disabled, not connected by the project, not built in the active view
layer, or inapplicable to this instance. For example, a letter-frame page is
applicable to Email, not SMS. The backend checks whether the project serves
each page and calls `AbstractPage::isTabApplicable()` with the owner's route
params; an absent or inapplicable page removes its branch. Filtering to pages
built in the active view layer remains part of each frontend adoption: Vue
drops every tab `HilosRouter.resolvePath` gives no address for, through
`hilosTabLinks`; React and Angular drop the same tabs through the same
function.

Draw at most one row. With only one eligible tab, hide the row but keep the
head. Do not put counts, badges, alarm dots or disabled tabs in it; counts and
alerts belong on Overview. A switch inside a page without its own address is
not this navigation row. Vue's shared row counts its tabs after that client-side
filter: fewer than two left hide the row and keep the head. React's and
Angular's rows do the same.

## One Reply And Live Name

For a catalog-adopted admin page, the server sends the owner, breadcrumb
skeleton and tab row in the **same `page_response`** as the page data. No second
catalog request, action on mount, or companion signal supplies the first
screen. This extends the existing
[one-reply rule](../signals/subscriptions.md#one-subscription-answers-everything-the-page-renders);
the owner-based identity in that reply is assembled by the backend catalog
resolver.

Each tab's page may supply the instance name from its own permitted data to the
breadcrumb entry with the **entity's page key**, never by position from the end.
The public API is
`bindPageInstanceName(scopes: ScopeManager, page: string, name: ReadonlySignal<string | undefined>): Unsubscribe`.
It stores read-only signal registrations in a `WeakMap` keyed by `ScopeManager`,
not name snapshots. Its reader reacts to registration and source changes, and
returns `undefined` when there is no current page scope; otherwise the current
source is projected onto matching existing breadcrumb entries and the optional
`pageHeadOwner` identity. It does not add a missing crumb, change the subscribing
page's own label or lead, or change its child cards. For a given scope manager
and owner key, the latest producer wins; its idempotent disposer removes only its
own registration, and an older producer is not restored. The caller-supplied
source must read the current page's permitted data, not another session's
identity. `undefined` keeps the catalog caption; every string, including `''`,
is a valid live name.

The client reads the existing wire `pageHeadOwner` as the optional
`{ page, label, lead, icon }` identity (`icon` is a string or `null`). An absent
or malformed owner is omitted, preserving the legacy identity shape.

`bootHilos` reads page identity inside its reactive title closure.
`resolvePageTitle` accepts `ownerLabel` as its optional sixth argument and uses
project page titles, framework footer labels, the live owner label, then the
subscribing page's catalog label. `appName` composition is unchanged, including
a bare label when it is empty; loading, error and route-miss behavior is
unchanged. No new `BootHilosConfig` field or `bindPageScope` option was added.
The shared Vue head draws the same name: its `h1` shows the owner label that
`pageIdentity()` has already renamed. A rename
updates the existing name consumers without reload; direct entry and F5 use the
same page data. Do not introduce a local name catalog, a static `document.title`,
or replace the name with a code.
The carrier's interview owns missing-name data and fallback policy, except for
the country display fallback specified below.

The binding API is exported from `@hilos/core/base` and the compatibility root;
both exports share one implementation and registration state. Its internal
`pageInstanceName` reader is not a barrel export. Both Vue country pages bind
under `HilosPages.I18N_COUNTRY` during setup, before rendering
`HilosAdminPage`. Their computed source reads the existing country card only
while the current page is Country or Country Names; no card yields `undefined`,
and unmount disposes that page's binding. This name binding and the Vue title
path are implemented; no extra mount action or name-catalog request is made.

React places the same binding through the hook
`usePageInstanceName(scopes, page, name)`, exported from `@hilos/react/base`
and the root. The hook registers `bindPageInstanceName` in a layout effect and
returns its disposer as the effect's cleanup, so unmount, or a new `scopes`,
`page` or `name`, releases the registration. It never registers in render: an
interrupted or replayed render, as under StrictMode, would leave a producer
with no committed view to dispose it, and a server render would register for
a view nobody mounts. After registering, the hook re-renders its own subtree
before paint, so the head below it shows the name in its first paint instead
of waiting for its own subscription. The source must be one stable signal for
the view's lifetime; a source made anew in render rebinds on every pass and
never settles. Both React country pages bind under `HilosPages.I18N_COUNTRY`
from the keyed component that holds the card, with the same source as Vue's,
and draw `HilosAdminPage` inside it. The React browser title takes the old
path, with no new writer: `bootHilos` computes `router.currentTitle` from the
identity, and `HilosLayout` mirrors it into `document.title`.

Angular calls `bindPageInstanceName` directly, from an `effect` of the page
component that holds the card, and returns the disposer from the effect's
`onCleanup`: the end of the page, or a new binding for another context or
country, releases the registration. Both Angular country pages bind under
`HilosPages.I18N_COUNTRY`, with the same rule as Vue's and React's: the source
is a core `computedSignal` that reads the current page scope and the core card,
not the Angular mirror of the card, and yields `undefined` while the page is
neither Country nor Country Names or the card is absent. The Angular browser
title takes the old path too, with no new writer: `bootHilos` computes
`router.currentTitle`, and Angular's `HilosLayout` mirrors it into
`document.title`.

An admin-view-mode viewer gets the same row as an administrator. The name is
the one already permitted in that viewer's entity block; neither breadcrumb
nor browser title may recover a hidden real person's name. Actions remain
governed by the page's [view-mode rules](admin-view-mode.md). Vue's shared head
uses that permitted block: the name is the owner label the page binds from its
own data, the rest is the page's slot content, and `HilosAdminPage` provides the
admin view mode under the shared head as under the legacy one. React's head
takes the same block from its page's props, and its `HilosAdminPage` provides
the view mode and the heading id around either head too. Angular's head takes
it from the templates its page projects, and its `HilosAdminPage` provides the
view mode (`HILOS_ADMIN_VIEW_MODE`) and the heading id (`HILOS_PAGE_HEADING_ID`)
around either head as `providers`, not `viewProviders`: the table and the
controls that read them are content the page projects, which a view provider
does not reach.

React and Angular provide the same head, name binding and reactive browser
title.

## States And Accessibility

On a tab click, navigate and subscribe to its page normally. While the reply
is pending, the previous screen is inert and its previous tab remains current.
If the reply is delayed, replace it with the page skeleton, without breadcrumb
or tab row. On cold entry, show a head skeleton without a row. The reply brings
the complete head and marks the new tab; the breadcrumb skeleton and instance
name must not appear in separate flashes. The Vue head is drawn inside the page
view, so the existing
[page-loading behavior](../frontend/core-and-connection.md) covers it: the
departing page, its current tab included, stays an inert copy, and the delayed
page skeleton has no breadcrumb or row. React's and Angular's heads are drawn
inside the same page view, `HilosView`, and get the same behavior. A mounted
head whose answer has not arrived draws only an `aria-hidden` skeleton — by
default, a round picture for a section or a square picture for an entity, and
the two existing bars. The Vue head alone has an optional `skeleton-visual`
slot that replaces only the picture; the Analytics Person page supplies a
blank circular span without data. There is no breadcrumb, row, name, real block
slot content or button.

Use link navigation semantics, not an in-page tab widget. The last column names
how the shared Vue, React and Angular presentations meet each rule:

| Surface | Required markup and behavior | Implementation |
|---|---|---|
| Breadcrumb | `nav` with a translatable accessible name, `ol`, and `aria-current="page"` only on the last, non-link entry; decorative separators are not read aloud | Vue: `HilosBreadcrumb` in `HilosPageHead`, named by `breadcrumbLabel`; the last entry is text with `aria-current="page"`. Its separators are Bootstrap's CSS `::before` content, not kept from being read aloud (not in the code yet — HIL-1602). React: `HilosBreadcrumb` in `HilosPageHead`, named by `breadcrumbLabel`; the last entry is text with `aria-current="page"`. Each separator is an `aria-hidden` slash span, and the scoped `.hilos-breadcrumb` rule in `hilos-styles.scss` switches Bootstrap's `::before` off, so no separator is read aloud. Angular: `HilosBreadcrumb` in `hilos-page-head`, named by `breadcrumbLabel` through its `label` input; the last entry is text, and its `li` carries `aria-current="page"`. The `li` has no `breadcrumb-item` class, whose `::before` separator a screen reader may speak: utilities draw the same look, each separator is an `aria-hidden` slash span, and no CSS rule is involved |
| Tab row | `nav` with a translatable accessible name for the owner, `ul` / `li` / `a`, and `aria-current="page"` only on the current tab; no `role="tablist"` or arrow-key tab-widget model | Vue: `HilosPageHead`'s `nav` named by `tabsLabel`, `ul` / `li` / `HilosLink`; `active` and `aria-current="page"` are set by page key, never by the link's own path match. React: the same `nav` named by `tabsLabel`, `ul` / `li` / `HilosLink`; `active` and an explicit `aria-current` are set by page key, and the explicit value keeps the link's own path match from marking another tab. Angular: the same `nav` named by `tabsLabel`, `ul` / `li` / `a[hilosLink]`; `active` and the `hilosLinkCurrent` input (`'page'` or `null`) are set by page key. The input exists because `HilosLink`'s dynamic host binding of `aria-current` wins over an `[attr.aria-current]` in the template; `'page'` and `null` win over the link's own path match, and the default `undefined` leaves the match in force |
| At 375 px | Breadcrumb wraps onto lines without truncation or horizontal scrolling; the row scrolls sideways without wrapping, centers the current tab within its scroll limits, and clips neighboring tabs at the edges; no fading or More menu | Vue: Bootstrap's wrapping breadcrumb with `text-break`; the row's `ul` is `flex-nowrap overflow-x-auto` over non-shrinking `text-nowrap` tabs, centered after mount, on a new current page or row, and on a resize of the row. React: the same classes and the same wrapping breadcrumb; the centering runs in a layout effect after mount and when the current page or the row's page keys change, and a `ResizeObserver` on the row covers a resize. Angular: the same classes and the same wrapping breadcrumb, with `text-break` on its `ol`; an effect on the current page and the row's page keys schedules the centering after the next render, a `ResizeObserver` on the row covers a resize, and only the row's `scrollLeft` changes |

The admin dashboard keeps its section cards, without breadcrumb or tab row.
A guest on an admin address gets no admin head or identity data. Error surfaces
have no page head, breadcrumb or row. The shared Vue head preserves these
boundaries: catalog-adopted admin owners draw it through `HilosAdminPage`, for
an answer that names `pageHeadOwner`; `HilosDashboardPage` directly uses
`HilosPageHead` with a local presentation from its answered label, lead and
fixed `bi-gear-fill` icon, without breadcrumb or tab row, keeping its section
cards; and the routed outlet's error page or auth surface replaces the whole
page view — or, for a page that opts in to [Deleted While Open](#deleted-while-open),
the shell's own error page replaces its head and body. React's and Angular's
heads keep the same three.

## Deleted While Open

Country Overview and Names name their row through
`AbstractPage::resolvePageResource`, returning `PageResource::db` of the
countries collection and the row's primary id. That id is the primary key.
The country code and the display name are not the reference. The subscription
keeps the link in `SubscriptionRegistry`. The hook's default names no row, so
a page that does not override it is outside this path.

Whoever was already on the page stays there, and the initiator of the action
makes the transition. That is the owner's decision. The initiator of a
successful deletion is the connection whose accept key equals the change's
`origin` and whose `originRequestId` is non-empty. That connection is not sent
a 404. Its action acknowledgement stays, the resource link is dropped, and the
successful deletion takes it to the country list. Every other open window,
including another window of the same person, stays on the previous address and
becomes the ordinary 404 (`not_found`). Only that live transition carries
`transitionMessage`, the server phrase "It was deleted just now.", and the
phrase does not include the name. A fresh subscribe and F5 carry the ordinary
404 and omit the field. The catalog wording is HIL-1516's. The flush match is
in [browser-source-fanout.md](browser-source-fanout.md#a-deleted-row-before-grouping),
the release in
[page-access-control.md](page-access-control.md#a-refusal-releases-what-the-page-holds),
and the field on the wire in
[wire-protocol.md](../frontend/wire-protocol.md#page-subscriptions-01).

On all three frontends, that live 404 replaces the page and removes its head,
breadcrumb and row, while an already-open modal keeps its draft above the
error. The boundary is opt-in: a page passes `preserveModalDraftsOnDelete` to
`HilosAdminPage` (default off) and hands the shell its dialogs apart from its
body (Vue `#modals`, React `modals`, Angular `#pageModals` beside `#pageBody`).
The current error then replaces the head, breadcrumb, row, cards and body with
one `ErrorPage`, and only the dialogs stay on the same mount; the routed outlet
keeps the same page instance instead of drawing its own error. Both country tabs
opt in with their edit window, the country window and the name window. Their
delete dialogs stay in the body and go with it. Without the opt-in the outlet
replaces the whole page, as before.

The server's one line is the existing `error.transitionMessage`, drawn by
`ErrorPage` as plain text under the description whenever the current error
carries it, and only then. Show it only on the live transition; a reload shows
the ordinary 404 without it. Only a 404 `not_found` is held. A loss of access
(a 401, 403 or 503) is another refusal, so the page and its dialogs are
destroyed as usual, as they are on a full answer, a navigation or a params
change. The outlet-to-shell hand-off is in
[multiframework-core.md](../frontend/multiframework-core.md#page-code-and-first-answer-state).

## Adoption Workflow

For redraws consuming the finished head, apply this sequence to one owner and
all its tabs. It does not adopt ancestors, neighbors or nested entities. Until
its own redraw, each other owner keeps the legacy head and child cards.
Country's initial implementation in HIL-1602, HIL-1604 and HIL-1605 builds the
prerequisite for those redraws; it does not wait for the downstream HIL-1606
gate or enable Internationalization as a whole.

1. Put the redraw behind **HIL-1606** with `blocked-after`, and wait for its
   merge: it is the shared component and navigation verification gate across
   Vue, React and Angular.
2. Identify the owner and its tabs from the catalog tree. Declare entity
   boundaries, Breadcrumb captions and adoption in the backend catalog:
   `entity: true`, `breadcrumb: '<caption>'`, and `pageHead: true` on the owner.
   `PageCatalogProviderInterface::pages()` accepts these optional fields, named
   by `PageCatalogConstants::CATALOG_ENTRY_ENTITY`,
   `CATALOG_ENTRY_BREADCRUMB`, and `CATALOG_ENTRY_PAGE_HEAD`.
   User is the current project-scoped adoption: the shared `hilos_user` entry
   has parent `hilos_users`, `entity: true`, and breadcrumb `User`, but no
   `pageHead: true`. Chat and Binance add `pageHead: true` in their complete
   provider entries for their Vue applications. React and Angular keep the
   legacy user card until their own redraws; this does not adopt neighboring
   owners.
   Appearance is opted in only by the Chat and Binance providers, with
   breadcrumb Appearance and pageHead true, for the Vue section head, whose
   sole Overview tab stays hidden, while the shared catalog entry and the
   React and Angular providers keep the legacy head until parity.
   The declared user page order is Overview, Roles, Access, Sessions. Only
   Overview has a served backend page and an SDK view. Roles, Access and
   Sessions have catalog and route metadata, but no served backend pages or
   views; all three remain unbuilt in Vue, React and Angular. They have no links,
   and the shared tab row is hidden while Overview is the only built page.
   `HilosUserPageShell` accepts the current page key for a later served tab to
   use the same shell; this describes the existing extension point, not a
   production tab switch.
3. Mount the shared head on every tab of this owner and supply its page-owned
   entity block content. In Vue, name the head's kind and labels on
   `HilosAdminPage` and fill its `head-*` slots; the name is not a slot. The
   first Vue carrier is the country, the same on both tabs (classes omitted):

   ```vue
   <HilosAdminPage
     :page="HilosPages.I18N_COUNTRY"
     head-kind="entity"
     tabs-label="Country pages"
   >
     <template v-if="card" #head-visual>
       <span aria-hidden="true" data-id="country-head-tile">{{ card.code }}</span>
     </template>
     <template v-if="card" #head-meta>
       <span data-id="country-head-code">{{ card.code }}</span>
     </template>
     <template v-if="card?.summary.isOwn" #head-badges>
       <span class="badge" data-id="country-head-own">…</span>
     </template>
     <template v-if="card" #head-description>
       <p data-id="country-head-description">Currency … · Default locale …</p>
     </template>
     <!-- the tab's body: Main and State on Overview, the names table on Names -->
   </HilosAdminPage>
   ```

   In React, `HilosAdminPage` takes the same inputs as props: `headKind`,
   `tabsLabel`, and the block's content as `headVisual`, `headMeta`,
   `headBadges` and `headDescription`. Each is a `ReactNode`: `undefined` keeps
   the head's default for it, `null` turns it off. The React country is the
   same on both tabs (classes omitted):

   ```tsx
   <HilosAdminPage
     page={HilosPages.I18N_COUNTRY}
     headKind="entity"
     tabsLabel="Country pages"
     headVisual={
       card ? (
         <span aria-hidden="true" data-id="country-head-tile">
           {card.code}
         </span>
       ) : undefined
     }
     headMeta={
       card ? <span data-id="country-head-code">{card.code}</span> : undefined
     }
     headBadges={
       card?.summary.isOwn ? (
         <span className="badge" data-id="country-head-own">…</span>
       ) : undefined
     }
     headDescription={
       card ? (
         <p data-id="country-head-description">
           Currency … · Default locale …
         </p>
       ) : undefined
     }
   >
     {/* the tab's body: Main and State on Overview, the names table on Names */}
   </HilosAdminPage>
   ```

   In Angular, `HilosAdminPage` takes `headKind` and `tabsLabel` as inputs, and
   the block's content as projected templates named `#headVisual`,
   `#headMeta`, `#headBadges` and `#headDescription`. It reads each with
   `contentChild` and hands it to `hilos-page-head` as `visual`, `meta`,
   `badges` and `description`, each a `TemplateRef<unknown> | undefined`. A
   template the page does not declare keeps the head's default for that part,
   and a template given in its place replaces the default. The Angular country
   is the same on both tabs (classes omitted). Its head templates stand beside
   the `#pageBody` and `#pageModals` of [Deleted While Open](#deleted-while-open),
   not inside them: the shell reads them from the page's content, and under the
   live 404 it draws neither head nor body.

   ```html
   <hilos-admin-page
     [page]="page"
     headKind="entity"
     tabsLabel="Country pages"
     [preserveModalDraftsOnDelete]="true"
   >
     @if (card(); as current) {
       <ng-template #headVisual>
         <span aria-hidden="true" data-id="country-head-tile">{{ current.code }}</span>
       </ng-template>
       <ng-template #headMeta>
         <span data-id="country-head-code">{{ current.code }}</span>
       </ng-template>
       @if (current.summary.isOwn) {
         <ng-template #headBadges>
           <span class="badge" data-id="country-head-own">…</span>
         </ng-template>
       }
       <ng-template #headDescription>
         <p data-id="country-head-description">Currency … · Default locale …</p>
       </ng-template>
     }
     <ng-template #pageBody>
       <!-- the tab's body: Main and State on Overview, the names table on Names -->
     </ng-template>
     <ng-template #pageModals>
       <!-- the tab's edit window: the country window or the name window -->
     </ng-template>
   </hilos-admin-page>
   ```
4. Bind each tab's live instance name to the owner's entry and browser title
   through `bindPageInstanceName(scopes, ownerPageKey, name)`. The Vue country
   pages use this binding from setup and dispose it on unmount; the shared Vue
   head draws that name as its `h1`. React calls
   `usePageInstanceName(scopes, ownerPageKey, name)` instead, from the
   component that also draws `HilosAdminPage`: the hook registers in a layout
   effect, not in render, so an interrupted or replayed render leaves no
   producer behind, and it releases the registration when the view unmounts.
   Angular calls `bindPageInstanceName` from an `effect` of the page component
   that also draws `HilosAdminPage`, with a core `computedSignal` as the source,
   and releases it in the effect's `onCleanup`.
5. Remove this owner's manual head, manual row and child-navigation cards as
   the shared head takes over. Preserve dashboard section cards and unchanged
   owners. The first Vue replacement is the country: its manual header and row
   are removed, and `HilosAdminPage` draws no child cards under the shared head.
   The React and Angular countries' manual headers are removed the same way.
6. Use the same shared head and name path in React: the `head*` props of step 3
   and the hook of step 4.
7. Use the same shared head and name path in Angular: the `#head*` templates of
   step 3 and the `bindPageInstanceName` effect of step 4.
8. Move this owner's navigation checks from child cards to tabs, using the
   shared coverage established by HIL-1606. That coverage is the shared check
   `expectCountryPageHead` in `framework/frontend/e2e/i18n.ts` and its three
   carriers. The check reads a country's head on either tab: one `h1`, the
   last crumb and the browser tab carry the shown name, the named Breadcrumb
   ends in a crumb that is text, the Overview · Names row keeps its code-based
   addresses with only the tab on screen current, and no child cards stand
   under the head. The carriers are the country specs of `binance-btc-tracker`
   (Vue), `tasks` (React) and `polls` (Angular): `i18n-country-card` reads both
   tabs opened directly, switched in place and reloaded,
   `i18n-country-name-write` the live name in two windows,
   `i18n-country-name-delete` the head's title, and `i18n-country-delete` the
   live 404 through `expectDeletedCountryCard` and
   `expectDeletedCountryCardReloaded`. The country's own block stays in each
   spec, with the fixture it describes.

After adoption, check both Overview and a child tab: same breadcrumb through
the owner, correct current tab, one head and row, no leftover child cards, and
the same live name in all three places. Check a rename in either window,
direct URL and F5; a neighbor not adopted must remain unchanged. Also cover
one-tab and filtered-tab cases, an addressless breadcrumb entry, loading, the
viewer, and deletion from another window with a modal draft open.
These are acceptance requirements for the redraw; this documentation change
adds no product tests or selectors.

## Examples

These are navigation scenarios, not declarations of page keys, routes or
activation. The backend owner, breadcrumb and tab calculations are implemented;
Country, Daemon Node, Maintenance, and Internationalization are the current owners opted in.
Their server replies carry the catalog head skeleton; `<name>` in the country
examples is data from the page, not a literal caption. The section has Overview,
Languages, and Countries tabs in all three SDKs. The core binding, title path and
shared heads for these owners are implemented in Vue, React and Angular. The
Language entity now uses its own shared head in all three kits: its breadcrumb
continues through Languages to the live native name, and its heading and
document title resolve to that name. Overview, Names and Locales share the
entity row. Vue has the updated language Overview body; React and Angular
language bodies remain HIL-1729 and HIL-1730 work. Status is also opted in:
Vue Chat and Binance serve Overview and Components; React and Angular
keep both Status pages unbuilt. Maintenance's Circle view is
built only in Vue; React and Angular still mark that page unbuilt, hide its tab
and return `not_served` for its direct address. The User Overview has a shared
head only in the Vue Chat and Binance applications; React and Angular retain the
legacy user card. Roles, Access and Sessions are declared user page metadata,
but their pages remain unbuilt in all three SDKs. Bold marks the current tab.
The Role example and the future user tabs describe catalog shapes, not served
pages or project adoption.

| Open page | Owner | Breadcrumb | Row |
|---|---|---|---|
| Internationalization Overview | Internationalization section | Hilos › Internationalization | **Overview** / Languages / Countries |
| Internationalization Languages | Same section | Hilos › Internationalization | Overview / **Languages** / Countries |
| Internationalization Countries | Same section | Hilos › Internationalization | Overview / Languages / **Countries** |
| Language Overview | Language instance | Hilos › Internationalization › Languages › `<nativeName>` | **Overview** / Names / Locales |
| Language Names | Same language instance | Hilos › Internationalization › Languages › `<nativeName>` | Overview / **Names** / Locales |
| Language Locales | Same language instance | Hilos › Internationalization › Languages › `<nativeName>` | Overview / Names / **Locales** |
| Country Overview | Country instance | Hilos › Internationalization › Countries › `<name>` | **Overview** / Names |
| Country Names | Same country instance | Hilos › Internationalization › Countries › `<name>` | Overview / **Names** |
| Maintenance Overview | Maintenance section | Hilos › Maintenance | **Overview** / Circle in Vue; **Overview** in React/Angular |
| Maintenance Circle (Vue) | Same Maintenance section | Hilos › Maintenance | Overview / **Circle** |
| Status Overview | Status section | Hilos › Status | **Overview** / Components in Vue |
| Status Components | Same Status section | Hilos › Status | Overview / **Components** in Vue |
| Role Overview, from a separate Roles list tab | Role instance | Hilos › Roles › Roles › `<name>` | **Overview** / Rights / Grants / History |
| Users, where the section itself is the list | Users section | Hilos › Users | No row: only the owner page |
| Person Overview in Vue Chat and Binance | Person instance | Hilos › Users › `<name>` | No row: Overview is the only built user page |
| Analytics Person in Vue Chat and Binance | Analytics Person instance | Hilos › Analytics › People › `<permitted pageLabel>` | No row: Overview is the only built Person page |
| Machine, a non-entity child of Gaps | Internationalization section | Hilos › Internationalization | Overview / Languages / Countries / Entities / Pages / Groups / Actions / Messages / Gaps / **Machine** / Other findings |

The two country tabs share the chain ending at the country, not at Names. The
three language tabs share a chain ending at the language; its live native name
replaces the catalog's short `Language` caption, while Names and Locales appear
as child tabs in the shared row.
The Maintenance pages share their section owner and row; only Vue currently serves
Circle. Vue serves the Status row today. The User Overview resolves to the
person instance. Its declared child order remains Roles, Access, Sessions, but those pages have no backend page or
SDK view, so none adds a link or keeps the single-tab row visible. Roles repeats
because the section and its separate list are different entries. Users does not
repeat because its section is its list. Machine follows Gaps in the same row and
is itself current; it does not create another row. The other rows illustrate the
calculation without opting those owners in.
Analytics Person is a separate entity page from the User Overview above. Chat
and Binance copy its complete framework catalog entry and add `pageHead: true`;
the framework entry remains unopted. The permitted `pageLabel` supplies the
last crumb as `Name (#N)` or `Deleted user #<id>`. Person has only Overview, so
its row stays hidden. The custom avatar and description block described here is
Vue-only; React and Angular retain their legacy Person presentation.

## Implementation Status And Binding Points

The paths here locate implementation work; an author can follow the adoption
sequence without studying these files. They do not specify a future API.

| Area | Current binding point | Current state and remaining behavior |
|---|---|---|
| Backend catalog | `PageCatalogProviderInterface::pages()`, `PageCatalogConstants`, `HilosPageCatalog::CATALOG`, `TopologyValidator::validatePageCatalogEntry()` and `validatePageCatalogHead()` | Optional `breadcrumb`, `entity` and `pageHead` fields; validation checks their types, owner placement and non-empty captions for tabs of an adopted owner. Country instance, Daemon Node instance, the Maintenance section, the Internationalization section, the Language entity and Status are opted in. `hilos_i18n` has `pageHead=true` and breadcrumb `Internationalization`; `hilos_i18n_language` has `entity=true`, `pageHead=true` and breadcrumb `Language`, and its child captions are Names and Locales. Country's entity/head metadata is unchanged. `hilos_maintenance_circle` is a child of the Maintenance owner. The shared `hilos_user` entry has parent `hilos_users`, `entity=true` and breadcrumb `User`, with no `pageHead=true`; only the Chat and Binance provider entries opt that owner in. Appearance is opted in only by the Chat and Binance providers, with breadcrumb Appearance and pageHead true, for the Vue section head, whose sole Overview tab stays hidden, while the shared catalog entry and the React and Angular providers keep the legacy head until parity. |
| Communications Letter frame | `AbstractHilosCommunicationsChannelFramePage`, `MailLetterFrameData::wireFields()`, `CommunicationsChannelSummaryAudience`; `HilosCommunicationsChannelFramePage` in Vue, React and Angular | All six demos register and lazily mount the contextual Frame page. Email has Overview / Configuration / Deliveries / Letter frame; the server omits Frame for other channels and refuses a direct non-email subscription with 404. The existing channel head keeps its permitted live name and one h1. The same initial answer carries summary, frame and owner identity; live settings facts refresh both data objects through the guarded publisher. Preview HTML stays in a sandboxed iframe; the modal uses the shared frame editor and the existing Overview set/reset actions. |
| Status catalog | `HilosPageCatalog::CATALOG`, `HilosPageConstants::HILOS_STATUS` and `HILOS_STATUS_COMPONENTS` | Status is a section under Dashboard, in the Platform card, with `pageHead=true`, breadcrumb `Status`, icon `bi-activity` and its current lead. Components is its child with breadcrumb `Components`. The server row is Overview / Components. |
| Backend owner and row calculation | `PageCatalogResolver::identity()`, `rowOwner()`, `rowOwnerOf()`, `breadcrumb()`, `head()`, `children()` and private `pageTabs()` | Resolves the nearest entity or top-level admin section, uses legacy page-label breadcrumbs for unadopted owners, and builds the adopted breadcrumb and depth-first row. The row filters pages the project serves and pages applicable to the owner's route params. |
| User child pages | `HilosPageConstants::HILOS_USER_ROLES`, `HILOS_USER_ACCESS`, `HILOS_USER_SESSIONS`; `HILOS_UNBUILT_PAGES`; the user page registrations in each app | The catalog and frontend routes declare Roles, Access and Sessions after Overview. None has a served backend page or a view in Vue, React or Angular; all three are marked unbuilt, so no child link is rendered. With Overview as the sole built page, the shared tabs row is hidden. A later served user tab can mount the existing shell. |
| Backend assembly | `AbstractPage::onSubscribe()`, `withPageIdentity()`, `servedPageCards()` and `PageHead::toArray()` | Adds `pageHeadOwner`, `pageBreadcrumb`, `pageTabs` and empty `pageChildren` to the same `page_response`. The Internationalization root carries Overview, Languages and Countries in its raw `pageTabs`; frontends resolve those against their built routes. The readonly `PageHead` is built for the subscription; `pageLabel` and `pageLead` remain the subscribing page's identity, with the existing admin/viewer payload precedence. |
| Status backend pages | `framework/backend/Pages/Status/AbstractHilosStatusPage.php`, `AbstractHilosStatusComponentsPage.php`, `StatusFeature.php`; `framework/backend/Status/DTO/StatusOverviewData.php` | Overview carries its temporary `data.status` projection in `page_response`; Components carries its first `hilosStatusComponents` viewport in `windows`. Overview's committed sources use the existing whole-page resend path. Components uses its normal multi-row table deltas and pending/Apply behavior. |
| Internationalization overview payload | `framework/backend/Pages/AbstractHilosI18nPage.php`, `framework/backend/I18n/DTO/I18nOverviewData.php`, `framework/backend/Core/Browser/Context/BrowserContext.php` | The section root reads languages and countries through `Hilos::$db` View collections and carries the complete `i18nOverview` in its `page_response` with the nonpersonal verdict. `BrowserContext` observes committed changes to those two sources for this page and resends through the existing `PageResender`, deduplicated within the flush by `publishedWholeThisFlush`. |
| Name and title | `framework/frontend/core/src/admin/identity/hilosPageInstanceName.ts`, `framework/frontend/core/src/admin/identity/hilosPageIdentity.ts`, `framework/frontend/core/src/routing/pageTitle.ts`, `framework/frontend/core/src/bootstrap/bootHilos.ts`; `framework/frontend/react/src/usePageInstanceName.ts`, `framework/frontend/react/src/HilosLayout.tsx`; `framework/frontend/angular/src/HilosLayout.ts` | `bindPageInstanceName()` keeps a live source per scope manager and owner page key; `pageIdentity()` applies it to matching crumbs and the optional `pageHeadOwner`; `bootHilos` reads identity inside the reactive title closure. `resolvePageTitle()` chooses project title, framework footer label, owner label, then catalog label. The language Vue, React and Angular detail pages bind the `languageCard.nativeName` to the language owner so it updates the matching breadcrumb and document title. The country Vue pages bind their page-scoped card name during setup, and the shared Vue head draws it. The country React pages bind the same name through `usePageInstanceName()` in a layout effect, the React head draws it, and `HilosLayout` mirrors `router.currentTitle` into `document.title`; React has no title writer of its own. The country Angular pages bind the same name through `bindPageInstanceName()` in an effect whose `onCleanup` releases it, the Angular head draws it, and Angular's `HilosLayout` mirrors `router.currentTitle` into `document.title` the same way. |
| Vue Logs head | `demo/chat/backend/Database/Pages/PageCatalog.php`, `demo/binance-btc-tracker/backend/Database/Pages/PageCatalog.php`; the six pages under `framework/frontend/vue/src/admin/logs/`; `framework/tests/Unit/PageCatalogResolverTest.php`, `HilosLogsPageHead.test.ts`, `framework/frontend/e2e/logs.ts::expectLogsPageHead` | The two project providers copy the complete framework Logs entry and add `pageHead: true`, preserving its label, lead, parent and `bi-journal-text` icon. Overview, By key, By worker, Rotations, Settings and Viewer mount the shared section head with `Logs pages` navigation and no child cards. Settings passes the head attributes through the single root of `HilosSettingPresetsPage` to `HilosAdminPage`. The breadcrumb ends at Logs; `state=due` and Viewer stream/anchor params keep their existing page keys and resolved addresses. Resolver tests cover both providers, the served-page filter and legacy default; real Vue DOM tests cover all six pages, loading and address filtering. Binance's admin, Logs and accessibility specs cover navigation, direct entry, F5, history, viewer mode and keyboard links. The framework default remains legacy, with React and Angular adoption left to HIL-1718 and HIL-1719. |
| Vue Analytics Person | `framework/frontend/vue/src/admin/analytics/HilosAnalyticsPersonPage.vue`; `framework/frontend/core/src/admin/analytics/hilosAnalyticsPerson.ts`; `demo/chat/backend/Database/Pages/PageCatalog.php`; `demo/binance-btc-tracker/backend/Database/Pages/PageCatalog.php` | The two project catalogs copy the full framework Person entry and add `pageHead: true`; the framework catalog entry remains unopted. Core exports `hilosAnalyticsPerson(scopes)` and `HilosAnalyticsPerson` from the analytics barrel and root. The selector returns `ReadonlySignal<HilosAnalyticsPerson | undefined>` with hideable `string | null` name and photo; it reads the active Person scope, keeps null and empty values valid, normalizes hidden values, and returns `undefined` for missing or malformed data or another page. The `ANALYTICS_PERSON_ROUTE_PARAM` export is unchanged. The Vue page uses `HilosAdminPage` with an entity head and binds the permitted `pageLabel` through `bindPageInstanceName()` to the owner, last crumb and browser title. It draws `HilosAvatar` from the page-scoped name and published photo; its description reads the current catalog lead about browser sessions. The server supplies the four-part Analytics breadcrumb, only the Overview tab, and empty `pageChildren`, so no tab row is shown. The custom avatar and description use `analytics-person-head-avatar` and `analytics-person-head-description`; its optional loading visual is a blank circular span without data. React and Angular keep their legacy Person presentation. |
| Vue overview selector | `framework/frontend/core/src/admin/i18n/hilosI18nOverview.ts`, `framework/frontend/core/src/admin/i18n/index.ts`, `framework/frontend/core/src/index.ts`, `framework/frontend/vue/src/admin/i18n/HilosI18nPage.vue` | `I18N_OVERVIEW_DATA`, `i18nOverviewSchema`, `createHilosI18nOverview`, `HilosI18nOverview` and `HilosI18nOverviewContext` are exported from `@hilos/core/admin/i18n` and the compatibility root. `HilosI18nPage` requires the context, reads the live page data, and shows route-eligible Languages and Countries tiles or a skeleton. |
| Vue section and entity heads | `framework/frontend/vue/src/HilosPageHead.vue`, `HilosBreadcrumb.vue`, `HilosAdminPage.vue`; `framework/frontend/core/src/routing/hilosAdmin.ts`; `framework/frontend/vue/src/admin/i18n/HilosI18nPage.vue`, `lists/HilosI18nLanguagesPage.vue`, `lists/HilosI18nCountriesPage.vue`, `details/HilosI18nLanguagePage.vue`, `details/HilosI18nLanguageNamesPage.vue`, `details/HilosI18nLanguageLocalesPage.vue`, `details/HilosI18nCountryPage.vue`, `details/HilosI18nCountryNamesPage.vue`; `framework/frontend/vue/src/admin/maintenance/HilosMaintenanceCirclePage.vue` | In the code: Internationalization Overview and both lists use the section head and `Internationalization pages` row; the overview requires `HilosI18nOverviewContext` with the current page scopes and draws the two live read-only tiles. The language Overview has Names, Locales and countryless-locale summary tiles plus Main and State; its Names and Locales pages share the live language head and Overview · Names · Locales row. The Binance BTC Tracker and Chat mount project wrappers for the overview and lists; `hilosAdminViews()` has no defaults for these three context-bound pages. Both country tabs use Overview · Names, and Maintenance Overview/Circle use the Overview · Circle row. |
| Vue user shell and Overview | `framework/frontend/vue/src/admin/users/HilosUserPageShell.vue`, `HilosUserPage.vue`, `index.ts`; `framework/frontend/core/src/admin/users/hilosUsers.ts`; `demo/chat/frontend/src/views/Hilos/Users/User.vue`, `demo/binance-btc-tracker/frontend/src/views/Hilos/Users/User.vue` | `@hilos/vue/admin/users` exports `HilosUserPageShell(context: HilosUsersContext, page: string)` with optional default and `modals` slots; `HilosUserPage` uses it. `HilosAdminPage` provides admin view mode around the body and dialogs. The shell draws the one shared head and merged notice, binds the live name from the permitted current user page scope, and keeps hidden names hidden. The head shows `#id`, the current absolute `Last seen` value when present, three-state presence and standing, and an Analytics navigation link when enabled and served. Mutation controls remain in Overview; the shell exposes no header mutation slot. Overview shows `Open connections` from `onlineSessionCount` as the temporary D-264 measure; actual session counts, roles and delegations are not implemented. The existing flag reads `Administrator` / `Not an administrator`, not `Superadmin`. Chat fills the optional `project-overview` slot with its existing participant route; Binance supplies no slot. |
| Status Vue pages | `framework/frontend/vue/src/admin/status/HilosStatusPage.vue`, `HilosStatusComponentsPage.vue`; `demo/chat/frontend/src/views/Hilos/Status/`, `demo/binance-btc-tracker/frontend/src/views/Hilos/Status/` | Both framework views use the shared `HilosAdminPage` head and one Status context. Chat and Binance BTC Tracker mount thin wrappers; React and Angular keep both Status pages in `HILOS_UNBUILT_PAGES` until HIL-1695 and HIL-1696. |
| React head, Internationalization lists, language and country, and Maintenance Overview | `framework/frontend/react/src/HilosPageHead.tsx`, `HilosBreadcrumb.tsx`, `HilosAdminPage.tsx`, `usePageInstanceName.ts`; `framework/frontend/core/src/routing/hilosAdmin.ts`; `framework/frontend/react/src/admin/i18n/HilosI18nPage.tsx`, `lists/HilosI18nLanguagesPage.tsx`, `lists/HilosI18nCountriesPage.tsx`, `details/HilosI18nLanguagePage.tsx`, `details/HilosI18nLanguageNamesPage.tsx`, `details/HilosI18nLanguageLocalesPage.tsx`, `details/HilosI18nCountryPage.tsx`, `details/HilosI18nCountryNamesPage.tsx`; `framework/frontend/react/src/admin/maintenance/HilosMaintenancePage.tsx` | In the code: the shared head and section row draw from server metadata on the Internationalization root and both lists; the language pages use their live entity head and Overview · Names · Locales row, while their body remains the previous language presentation under HIL-1729. Maintenance Overview uses the Maintenance head, and both country tabs use Overview · Names. The section root remains its context-free default shell; overview tiles and their context are HIL-1726. The Circle view remains unbuilt in React. |
| Angular head, Internationalization lists, language and country, and Maintenance Overview | `framework/frontend/angular/src/HilosPageHead.ts`, `HilosBreadcrumb.ts`, `HilosLink.ts`, `HilosAdminPage.ts`; `framework/frontend/core/src/routing/hilosAdmin.ts`; `framework/frontend/angular/src/admin/i18n/HilosI18nPage.ts`, `lists/HilosI18nLanguagesPage.ts`, `lists/HilosI18nCountriesPage.ts`, `details/HilosI18nLanguagePage.ts`, `details/HilosI18nLanguageNamesPage.ts`, `details/HilosI18nLanguageLocalesPage.ts`, `details/HilosI18nCountryPage.ts`, `details/HilosI18nCountryNamesPage.ts`; `framework/frontend/angular/src/admin/maintenance/HilosMaintenancePage.ts` | In the code: the shared head and section row draw from server metadata on the Internationalization root and both lists; the language pages use their live entity head and Overview · Names · Locales row, while their body remains the previous language presentation under HIL-1730. Maintenance Overview uses the Maintenance head, and both country tabs use Overview · Names. The section root remains its context-free default shell; overview tiles and their context are HIL-1727. The Circle view remains unbuilt in Angular. |
| Live 404, server | `AbstractPage::resolvePageResource`, `PageResource::db`, `BrowserContext::refuseDeletedPageResources`, `PageSignalRouter::refuseDeletedResource` | In the code: other open windows get `not_found` and `transitionMessage`; the initiator keeps its acknowledgement and goes to the country list |
| Live 404, clients | `framework/frontend/core/src/routing/pageViewState.ts`, `framework/frontend/core/src/dom/focusTrap.ts`; per kit under `framework/frontend/{vue,react,angular}/src/`: `ErrorPage`, `HilosView`, `HilosAdminPage` and the internal bridge `hilosPageErrorBoundaryKey.ts` (Vue), `hilosPageErrorBoundaryContext.ts` (React), `hilosPageErrorBoundaryToken.ts` (Angular); both country tabs in `admin/i18n/details/` (`HilosI18nCountryPage`, `HilosI18nCountryNamesPage`) | In the code: `ErrorPage` draws `error.transitionMessage` as a line; the outlet keeps a registered page through a live 404 `not_found`; `HilosAdminPage` with `preserveModalDraftsOnDelete` replaces head, row, cards and body with one `ErrorPage` and keeps its dialogs mounted, returning their focus to Back to home; both country tabs opt in with their edit window |
| Adoption coverage | `I18nOverviewTest.php`, `I18nOverviewFanoutTest.php`, `demo/chat/tests/Integration/I18nCountryCardTest.php`; `expectI18nPageHead` and `expectCountryPageHead` in `framework/frontend/e2e/i18n.ts`, `expectMaintenancePageHead` in `framework/frontend/e2e/maintenance.ts`; `framework/frontend/vue/src/admin/users/HilosUserPageShell.test.ts`, `HilosUserPage.test.ts`, `framework/frontend/e2e/hilosUserOverview.ts`, `demo/chat/tests/e2e/tests/hilos-user-overview.spec.ts`, `demo/binance-btc-tracker/tests/e2e/tests/users.spec.ts`; `i18n-overview.spec.ts` and country e2e specs in `demo/binance-btc-tracker`, `demo/tasks` and `demo/polls`; `protected-mode.spec.ts` and `backup.spec.ts` in `demo/binance-btc-tracker` | In the code: the framework unit tests cover the overview payload and source fan-out; the Chat integration covers the subscribed overview. `expectI18nPageHead` checks the section head, three links and current tab. Binance's Vue overview spec covers the first whole response, live updates, tile navigation with Back/page row/direct URLs and F5, and the viewer. HIL-1606 checks the country head and Overview · Names row across all three kits. The Vue user unit tests cover the shared shell and Overview. Chat's spec covers cold navigation, F5 and the participant slot; the shared user overview helper checks the head and absence of child links in Chat and Binance. Binance's users spec also covers presence, rename, conflict, keyboard rename and accessibility in its slotless Overview. The Binance maintenance spec checks direct address, F5, Back/Forward, one h1 and current tab, and no table/controller traffic on Overview; the backup spec keeps its pointer on Overview before selecting Circle. Each SDK's manual-window checks remain on Overview. |
| Language head coverage | `framework/frontend/e2e/i18n.ts::expectLanguagePageHead`; i18n e2e specs in `demo/binance-btc-tracker` (Vue), `demo/tasks` (React) and `demo/polls` (Angular) | The shared helper checks the live language name, document title and Overview / Names / Locales row on all three kits. The framework component tests also cover the language, Names and Locales pages in each kit. |
| Status head coverage | `framework/tests/Integration/StatusAdminPageIntegrationTest.php`; `framework/frontend/e2e/status.ts`; `demo/binance-btc-tracker/tests/e2e/tests/status.spec.ts` and `a11y.spec.ts` | In the code: the integration covers ADMIN gatekeeper forwarding, writer commit/refusal and initial/live viewer wire. The shared e2e checks the Status head, two addresses and current tab. Binance's real-socket specs cover the Vue pages and Overview action lifecycle; React and Angular remain unbuilt. |
| Analytics Person head coverage | `framework/frontend/core/test/admin/analytics/hilosAnalyticsPerson.test.ts`, `framework/frontend/vue/src/admin/analytics/HilosAnalyticsPersonPage.test.ts`, `framework/frontend/vue/src/HilosPageHead.test.ts`, `framework/frontend/vue/src/HilosAdminPage.test.ts`; `framework/frontend/e2e/analytics.ts::expectAnalyticsPeoplePerson`; `demo/chat/tests/e2e/tests/analytics.spec.ts`, `demo/chat/tests/e2e/tests/a11y.spec.ts`, `demo/binance-btc-tracker/tests/e2e/tests/a11y.spec.ts` | Core tests cover the active Person scope, null and empty values, hidden normalization and malformed fields. Vue unit tests cover the avatar, deleted and hidden names, a live empty name with a photo, the catalog lead and the optional skeleton visual. Chat's real-socket flow covers People-to-Person navigation, the first name/photo response without a second page or action request, and direct entry, F5 and Back. The Chat and Binance accessibility specs check one `h1`, the table name, decorative avatar, current crumb and absent tabs. |

`PageCatalogConstants` names the optional catalog fields `breadcrumb`, `entity`
and `pageHead`, separately from wire keys. `PageCatalogResolver::rowOwner()`
finds the nearest entity even when its head is not enabled, or the top-level
admin section; it returns null for the dashboard and pages outside the admin
tree. `breadcrumb()` keeps the legacy label chain through the subscribing page
unless its owner opted in. For an adopted owner it walks through that owner,
uses `breadcrumb` with `label` as fallback, and omits empty captions. `children()`
continues to build legacy child cards. `head()` returns a fresh readonly
`PageHead` only for an adopted owner; its `pageHeadOwner` is that owner's
catalog identity, `pageBreadcrumb` ends at the owner, and `pageTabs` starts with
Overview followed by served non-entity descendants in depth-first catalog
order. A missing or inapplicable page removes its branch. `TopologyValidator`
checks the metadata types and owner/caption constraints after catalog shape,
parents and cycles validate.

### Moderation Kind owner and Overview

The `HILOS_MODERATION_KIND` entry in `HilosPageCatalog::CATALOG` has parent
`HILOS_MODERATION`, breadcrumb `Kind`, `entity=true` and `pageHead=true`.
`AbstractHilosModerationKindPage` is registered through Chat's thin
`ModerationKindPage`, which binds it to `HILOS_INDEX`. The Vue
`HilosModerationKindPage.vue` renders the shared entity head and binds the
selected row's `title` with `bindPageInstanceName()`, so the heading, breadcrumb
and document title use the live kind name. Overview shows the owner badge and
key, model and profile, refusal reasons, and rules in force. Overview is the
only served kind page, so its single tab stays hidden. The Rules page remains
HIL-1631 work; no empty Rules registration or tab is present.

`AbstractPage::onSubscribe()` passes the route params to `withPageIdentity()`,
which adds `pageHeadOwner`, `pageBreadcrumb`, `pageTabs` and an empty
`pageChildren` to the existing shell in the same `page_response`. The resolver
does not filter by modules built in the active frontend; that part remains with
the Vue, React and Angular leaves above. `pageLabel` and `pageLead` continue to
describe the subscribing page, including Analytics' existing use of
`pageLabel`. The page payload still wins over catalog identity for an
administrator (`$payload->data + $shell`); the shell wins for an admin-view-mode
viewer (`$shell + $payload->data`) after the page's own data is hidden. Both
country tabs draw the shared head described below, in Vue, in React and in
Angular. The
country's display name comes from `hilosI18nCountryDisplayName(card)`: `null`
and `''` produce `Unnamed country`, and any other name is returned unchanged,
without trimming or translation. The country code stays separate from the name,
in the block's tile and the mark beside the title. Country addresses use the
unique, unchanging `countryCode` in `/hilos/i18n/countries/{countryCode}` and
`/hilos/i18n/countries/{countryCode}/names`; renaming a country does not change
its URL, and equal display names do not share an address. Do not turn the legacy
chain into the owner's chain on pages whose owner has not opted in.

Core reads the wire `pageTabs` into the optional `HilosPageIdentity.pageTabs`
only beside a valid `pageHeadOwner`, in the order it arrived, skipping an entry
without a string page and label. Tab captions stay the catalog's: the live name
renames the owner and its crumb, never a tab. `hilosTabLinks(tabs, params,
resolvePath)` resolves the row to `HilosPageTabLink`s in that order and drops a
tab `HilosRouter.resolvePath` gives no address for — a page not built in this
view layer, or one whose route needs a param the current route lacks.
`hilosCrumbLinks` still keeps an addressless crumb as text. `hilosTabLinks` and
the `HilosPageTabLink` and `HilosPageHeadAction` types are exported from
`@hilos/core` and `@hilos/core/base`.

Vue's `HilosPageHead`, exported from `@hilos/vue/base` and the root, draws the
breadcrumb, the owner's block and its row from the identity it is given, and
names no concrete page. Its props are `identity`, `page` (the rendered page
key: the tab with that key is current, a child of a tab included), `params`,
`resolvePath`, `headingId`, `kind` (`section` by default, or `entity`),
`breadcrumbLabel` (`Breadcrumb` by default), `tabsLabel` (`Page sections` by
default) and `action`. The slots `visual`, `meta`, `badges` and `description`
fill the block only; the title is not a slot. The optional `skeleton-visual`
slot replaces only the picture in the existing `aria-hidden` skeleton; without
it, the picture remains round for a section and square for an entity, and the
two bars remain. The one `h1` shows
`pageHeadOwner.label` and carries `headingId`, or a local id when none is
given, for a section head and an entity block alike. A section defaults
`visual` to its catalog icon in a circle and `description` to its lead; an
entity draws only what its page supplies. The breadcrumb is drawn from two
links up and the row from two tabs left after `hilosTabLinks`. The only button
is `action`, a `HilosPageHeadAction` `{ page, label, params? }`: it opens
`resolvePath(page, params ?? currentParams)`, explicit params being the whole
target context, and is not drawn without an address. There is no slot for free
buttons.

React's `HilosPageHead`, exported from `@hilos/react/base` and the root, draws
the same head from the same identity and takes the same props — `identity`,
`page`, `params`, `resolvePath`, `headingId`, `kind`, `breadcrumbLabel`,
`tabsLabel` and `action` — with the same defaults; `identity` is optional, as
the answer may not have arrived. The block's content comes as `ReactNode`
props `visual`, `meta`, `badges` and `description` in place of slots: omitted
(`undefined`) keeps a section's icon and lead, and `null` turns that default
off; an entity has no default to keep. Without a valid `pageHeadOwner` the
head draws only its `aria-hidden` skeleton. The row is centered in
`useLayoutEffect`, with a `ResizeObserver` on the row's list that is attached
when the row is drawn and released when it goes or the head unmounts. Its
`HilosBreadcrumb` takes an optional `label` and `className`, shows only the
last crumb as current, and draws each separator as an `aria-hidden` slash span.

Angular's `HilosPageHead` is the standalone `hilos-page-head` component with
`OnPush` change detection, exported from `@hilos/angular/base` and the root. It
draws the same head from the same identity and takes the same inputs —
`identity`, `page`, `params`, `resolvePath`, `headingId`, `kind`,
`breadcrumbLabel`, `tabsLabel` and `action` — with the same defaults; `identity`
is optional, as the answer may not have arrived. The block's content comes as
inputs `visual`, `meta`, `badges` and `description`, each a
`TemplateRef<unknown> | undefined` drawn through `NgTemplateOutlet`: omitted
keeps a section's icon and lead, a template given in its place replaces the
default, and an entity has no default to keep. Without a valid `pageHeadOwner`
the head draws only its `aria-hidden` skeleton, and none of the templates is
created. The row is centered by an `effect` on the rendered page key and the
row's page keys, which schedules the work with `afterNextRender` and drops a
callback still waiting when a newer change comes or the head is destroyed; a
second `effect` attaches a `ResizeObserver` to the row's list when the row is
drawn and disconnects it in `onCleanup`. Only the row's `scrollLeft` changes.
Its `HilosBreadcrumb` takes an optional `label` input (`breadcrumb` by default;
the head passes `breadcrumbLabel`), shows only the last crumb as current, and
draws each separator as an `aria-hidden` slash span.

Angular's `HilosLink` takes an optional input `hilosLinkCurrent`, of type
`'page' | null | undefined`. `'page'` and `null` win over the mark the
directive would set by matching its path; `undefined`, the default, leaves that
mark in force. The input exists because the directive's dynamic host binding of
`aria-current` wins over an `[attr.aria-current]` the template puts on the same
anchor, whereas in Vue and React the caller's own attribute overrides the mark.
The breadcrumb passes `null` to every intermediate link, and the tab row passes
`'page'` or `null` by the key of the rendered page.

`HilosAdminPage` draws `HilosPageHead` when the answer names `pageHeadOwner`,
with no heading, lead or child cards of its own; an answer without an owner
keeps the legacy head, cards and stub. It provides its heading id and the admin
view mode under either head. Its optional props `headKind`, `headAction`,
`breadcrumbLabel` and `tabsLabel` pass through to the head, whose defaults stand
for those not given. Its slots `head-visual`, `head-meta`, `head-badges` and
`head-description` reach the head only when given, so a section keeps the
head's own icon and lead. The optional `head-skeleton-visual` slot reaches
`skeleton-visual` only when supplied. `headKind` never opts a page in;
before the answer it only puts the page on the head's skeleton instead of the
legacy one.

React's `HilosAdminPage` does the same with the props `headKind`,
`headAction`, `breadcrumbLabel` and `tabsLabel`, and the `ReactNode` props
`headVisual`, `headMeta`, `headBadges` and `headDescription`, which reach the
head as `visual`, `meta`, `badges` and `description`: omitted keeps the
head's default, `null` turns it off. With a valid `pageHeadOwner` it draws only
the shared head, with no heading, lead or child cards of its own. Its heading
id and the admin view mode are provided by context around either head, and
`headKind` before the answer picks the head's skeleton instead of the legacy
one, as in Vue.

Angular's `HilosAdminPage` does the same with the inputs `headKind`,
`headAction`, `breadcrumbLabel` and `tabsLabel`, and the projected templates
`#headVisual`, `#headMeta`, `#headBadges` and `#headDescription`, which it reads
with `contentChild` and passes to the head as `visual`, `meta`, `badges` and
`description`: a template the page does not declare keeps the head's default.
With a valid `pageHeadOwner` it draws only the shared head, with no heading,
lead or child cards of its own; an answer without an owner keeps the legacy
branch, and the head inputs and templates are ignored there. Its heading id and
the admin view mode are provided as `providers` of the shell around either
head, and `headKind` before the answer picks the head's skeleton instead of the
legacy one, as in Vue.

The head's `data-id`s are `hilos-page-head`, `hilos-page-head-block`,
`hilos-page-head-title`, `hilos-page-head-action`, `hilos-page-head-skeleton`,
`hilos-page-tabs` and `hilos-page-tab-<page>`, beside the breadcrumb's
`hilos-breadcrumb` and `hilos-breadcrumb-<page>`. The country block adds
`country-head-tile`, `country-head-code`, `country-head-own` and
`country-head-description`. The React and Angular heads and countries draw the
same `data-id`s. Each kit reads them in the same country specs of its demo —
`binance-btc-tracker` (Vue), `tasks` (React) and `polls` (Angular):
`i18n-country-card` and `i18n-country-name-write` check the head, trail and row
through `expectCountryPageHead` in `framework/frontend/e2e/i18n.ts` and the
country block on their own, `i18n-country-name-write` also covers the live name
in two windows, and `i18n-country-name-delete` reads the head's title.
`i18n-country-names` reads only the Names table under that head.

The implementing leaf clears only the markers for behavior it lands, in the
same commit, following [rule-authoring.md](../rule-authoring.md#a-rule-written-ahead-of-its-code).
Landing Vue alone does not clear the React or Angular markers. Future API
exports, catalog and wire keys, constants, component props/slots and `data-id`
values belong to the owning code leaves' interviews. Implementing a gated
contract still follows [agents.md](../../../agents.md).

## Not In This Phase

HIL-1598 changes documentation only. HIL-1599 through HIL-1605 own the product
implementation; HIL-1606 owns the epic's e2e work. Other owners' redraws, country
Overview tiles and their contents, and catalog translations are separate work.
React and Angular's User redraws are also separate work.
The local public-page presentation through `HilosStaticPage` is implemented
across the three kits; it does not add server catalog adoption or route changes.
Profile and other project-page redraws, including their server adoption, remain
Phase 2. Public route and page-key additions and revision history remain
HIL-1609; LICENSE content remains HIL-1610; dependencies remain HIL-1611.
Mockup examples do not activate those changes. Own deletion and redirect
behavior are not decided by this document.
