# Page Head

Read this before building or redrawing a section or entity head, declaring its
catalog identity, changing breadcrumbs, page tabs or a live instance name, or
moving an owner from child cards to the shared head.

This is the approved rule for adoption. Markers name the parts that later leaves
still implement; the binding points below describe today's code separately. An
owner that has not been adopted keeps its existing head and child cards. Country
is the first carrier in HIL-1559; it does not adopt the whole Internationalization
section. Other owners move in their own redraws after HIL-1606 has merged.

## Core Rule And Ownership

An adopted page renders, in order, **breadcrumb → section head or entity block
→ one row of page tabs → page body**. A section head has an icon, title and lead;
an entity block has an icon or avatar, instance name, badges and description.
The page module supplies the block's content. The shared component presents it
without a registry of concrete pages or their content, and without choosing the
page in place of the navigator. The head belongs to the page's answer, not to
the application shell. Vue's shared head and its country use are
(not in the code yet — HIL-1602).

The **owner** is the section or entity whose pages share that head and row.
Each tab is a separate page with its own address. A non-entity child of a tab
stays in that owner's row; an entity opened from a list row starts its own head
and row. Do not give every tab an owner of its own. The catalog declares entities
and enables adoption at the owner, covering all its tabs.

Keep exactly one `h1` on the rendered page: the section title or entity name in
the head. Preserve the heading's connection to a table's accessible name; do
not add a second heading for the entity block. See
[accessibility.md](../frontend/accessibility.md). The shared Vue head preserves
this relationship (not in the code yet — HIL-1602).

Rule 7 of the approved design puts entity management — edit, copy, delete — in
the body of **Overview**, with properties, summaries and aggregates. Each list
has its own tab, with one list table per page; Overview does not contain those
lists. A section whose page is itself the list, such as Users in the examples,
does not acquire a synthetic extra list page. Apply this content rule in that
owner's redraw; HIL-1598 specifies no country Overview tiles or their contents.

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
address or discard the link. This is part of the shared Vue presentation
(not in the code yet — HIL-1602).

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
(not in the code yet — HIL-1602), React (not in the code yet — HIL-1604), and
Angular (not in the code yet — HIL-1605).

Draw at most one row. With only one eligible tab, hide the row but keep the
head. Do not put counts, badges, alarm dots or disabled tabs in it; counts and
alerts belong on Overview. A switch inside a page without its own address is
not this navigation row. Vue's shared row, including client-side eligibility,
is (not in the code yet — HIL-1602).

## One Reply And Live Name

The server sends the owner, breadcrumb skeleton and tab row in the **same
`page_response`** as the page data. No second catalog request, action on mount,
or companion signal supplies the first screen. This extends the existing
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
When an adopted shared head draws the owner block, it can use the same name;
the shared head itself remains (not in the code yet — HIL-1602). A rename
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

An admin-view-mode viewer gets the same row as an administrator. The name is
the one already permitted in that viewer's entity block; neither breadcrumb
nor browser title may recover a hidden real person's name. Actions remain
governed by the page's [view-mode rules](admin-view-mode.md). Vue's shared head
uses that permitted block (not in the code yet — HIL-1602).

React must provide the same head, name binding and reactive browser title
(not in the code yet — HIL-1604).

Angular must provide the same head, name binding and reactive browser title
(not in the code yet — HIL-1605).

## States And Accessibility

On a tab click, navigate and subscribe to its page normally. While the reply
is pending, the previous screen is inert and its previous tab remains current.
If the reply is delayed, replace it with the page skeleton, without breadcrumb
or tab row. On cold entry, show a head skeleton without a row. The reply brings
the complete head and marks the new tab; the breadcrumb skeleton and instance
name must not appear in separate flashes. Apply the existing
[page-loading behavior](../frontend/core-and-connection.md) to the Vue head
(not in the code yet — HIL-1602).

Use link navigation semantics, not an in-page tab widget. The shared Vue
presentation follows these rules (not in the code yet — HIL-1602):

| Surface | Required markup and behavior | Implementation |
|---|---|---|
| Breadcrumb | `nav` with a translatable accessible name, `ol`, and `aria-current="page"` only on the last, non-link entry; decorative separators are not read aloud | (not in the code yet — HIL-1602) |
| Tab row | `nav` with a translatable accessible name for the owner, `ul` / `li` / `a`, and `aria-current="page"` only on the current tab; no `role="tablist"` or arrow-key tab-widget model | (not in the code yet — HIL-1602) |
| At 375 px | Breadcrumb wraps onto lines without truncation or horizontal scrolling; the row scrolls sideways without wrapping, centers the current tab within its scroll limits, and clips neighboring tabs at the edges; no fading or More menu | (not in the code yet — HIL-1602) |

The admin dashboard keeps its section cards, without breadcrumb or tab row.
A guest on an admin address gets no admin head or identity data. Error surfaces
have no page head, breadcrumb or row. Preserve these boundaries when adopting
the shared Vue head (not in the code yet — HIL-1602).

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
error. Show the server's one line only on the live transition; a reload shows
the ordinary 404 without it (not in the code yet — HIL-1603).

## Adoption Workflow

For redraws consuming the finished head, apply this sequence to one owner and
all its tabs. It does not adopt ancestors, neighbors or nested entities. Until
its own redraw, each other owner keeps the legacy head and child cards.
Country's initial implementation in HIL-1602, HIL-1604 and HIL-1605 builds the
prerequisite for those redraws; it does not wait for the downstream HIL-1606
gate or enable Internationalization as a whole.

1. Put the redraw behind **HIL-1606** with `blocked-after`, and wait for its
   merge: it is the shared component and navigation verification gate across
   Vue, React and Angular (not in the code yet — HIL-1606).
2. Identify the owner and its tabs from the catalog tree. Declare entity
   boundaries, Breadcrumb captions and adoption in the backend catalog:
   `entity: true`, `breadcrumb: '<caption>'`, and `pageHead: true` on the owner.
   `PageCatalogProviderInterface::pages()` accepts these optional fields, named
   by `PageCatalogConstants::CATALOG_ENTRY_ENTITY`,
   `CATALOG_ENTRY_BREADCRUMB`, and `CATALOG_ENTRY_PAGE_HEAD`.
3. Mount the shared head on every tab of this owner and supply its page-owned
   entity block content. Use the implemented interface, not today's manual
   country header as a proposed generic API. The first Vue carrier is
   (not in the code yet — HIL-1602).
4. Bind each tab's live instance name to the owner's entry and browser title
   through `bindPageInstanceName(scopes, ownerPageKey, name)`. The Vue country
   pages use this binding from setup and dispose it on unmount; drawing the name
   in the shared owner block remains (not in the code yet — HIL-1602).
5. Remove this owner's manual head, manual row and child-navigation cards as
   the shared head takes over. Preserve dashboard section cards and unchanged
   owners. The first Vue replacement is
   (not in the code yet — HIL-1602).
6. Use the equivalent shared head and name path for React
   (not in the code yet — HIL-1604).
7. Use the equivalent shared head and name path for Angular
   (not in the code yet — HIL-1605).
8. Move this owner's navigation checks from child cards to tabs, using the
   shared coverage established by HIL-1606. That coverage is
   (not in the code yet — HIL-1606).

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
Country is the only owner currently opted in. Its server reply carries the
catalog head skeleton; `<name>` is data from the page, not a literal caption.
The core binding and title path are implemented, while the shared Country head
is first drawn by HIL-1602, with React and Angular following in HIL-1604 and
HIL-1605. Bold marks the current tab. Roles, Users and the other examples wait
for their own redraws; naming them here does not enable them.

| Open page | Owner | Breadcrumb | Row |
|---|---|---|---|
| Country Overview | Country instance | Hilos › Internationalization › Countries › `<name>` | **Overview** / Names |
| Country Names | Same country instance | Hilos › Internationalization › Countries › `<name>` | Overview / **Names** |
| Role Overview, from a separate Roles list tab | Role instance | Hilos › Roles › Roles › `<name>` | **Overview** / Rights / Grants / History |
| Users, where the section itself is the list | Users section | Hilos › Users | No row: only the owner page |
| Person Overview, from that Users list | Person instance | Hilos › Users › `<name>` | **Overview** / Roles / Access / Sessions |
| Machine, a non-entity child of Gaps | Internationalization section | Hilos › Internationalization | Overview / Languages / Countries / Entities / Pages / Groups / Actions / Messages / Gaps / **Machine** / Other findings |

The two country tabs share the chain ending at the country, not at Names. Roles
repeats because the section and its separate list are different entries. Users
does not repeat because its section is its list. Machine follows Gaps in the
same row and is itself current; it does not create another row. The other rows
illustrate the calculation without opting those owners in.

## Implementation Status And Binding Points

The paths here locate implementation work; an author can follow the adoption
sequence without studying these files. They do not specify a future API.

| Area | Current binding point | Current state and remaining behavior |
|---|---|---|
| Backend catalog | `PageCatalogProviderInterface::pages()`, `PageCatalogConstants`, `HilosPageCatalog::CATALOG`, `TopologyValidator::validatePageCatalogEntry()` and `validatePageCatalogHead()` | Optional `breadcrumb`, `entity` and `pageHead` fields; validation checks their types, owner placement and non-empty captions for tabs of an adopted owner. Country is the only current owner opted in. |
| Backend owner and row calculation | `PageCatalogResolver::identity()`, `rowOwner()`, `rowOwnerOf()`, `breadcrumb()`, `head()`, `children()` and private `pageTabs()` | Resolves the nearest entity or top-level admin section, uses legacy page-label breadcrumbs for unadopted owners, and builds the adopted breadcrumb and depth-first row. The row filters pages the project serves and pages applicable to the owner's route params. |
| Backend assembly | `AbstractPage::onSubscribe()`, `withPageIdentity()`, `servedPageCards()` and `PageHead::toArray()` | Adds `pageHeadOwner`, `pageBreadcrumb`, `pageTabs` and empty `pageChildren` to the same `page_response`. The readonly `PageHead` is built for the subscription; `pageLabel` and `pageLead` remain the subscribing page's identity, with the existing admin/viewer payload precedence. |
| Name and Vue title | `framework/frontend/core/src/admin/identity/hilosPageInstanceName.ts`, `framework/frontend/core/src/admin/identity/hilosPageIdentity.ts`, `framework/frontend/core/src/routing/pageTitle.ts`, `framework/frontend/core/src/bootstrap/bootHilos.ts` | `bindPageInstanceName()` keeps a live source per scope manager and owner page key; `pageIdentity()` applies it to matching crumbs and the optional `pageHeadOwner`; `bootHilos` reads identity inside the reactive title closure. `resolvePageTitle()` chooses project title, framework footer label, owner label, then catalog label. The country Vue pages bind their page-scoped card name during setup. The shared owner block remains HIL-1602. |
| Vue head and country | `framework/frontend/vue/src/HilosAdminPage.vue`; `framework/frontend/vue/src/admin/i18n/details/HilosI18nCountryHeader.vue`, `HilosI18nCountryPage.vue`, `HilosI18nCountryNamesPage.vue` | Shared head/block/row and both country tabs using it (not in the code yet — HIL-1602) |
| React parity | `framework/frontend/react/src/HilosAdminPage.tsx` and its country page modules | Equivalent head, states, accessibility, live name and title (not in the code yet — HIL-1604) |
| Angular parity | `framework/frontend/angular/src/HilosAdminPage.ts` and its country page modules | Equivalent head, states, accessibility, live name and title (not in the code yet — HIL-1605) |
| Live 404, server | `AbstractPage::resolvePageResource`, `PageResource::db`, `BrowserContext::refuseDeletedPageResources`, `PageSignalRouter::refuseDeletedResource` | In the code: other open windows get `not_found` and `transitionMessage`; the initiator keeps its acknowledgement and goes to the country list |
| Live 404, clients | The page error surfaces in all three view layers | Show that line, remove the head and preserve an open modal draft (not in the code yet — HIL-1603) |
| Adoption gate | HIL-1606 | Shared e2e navigation coverage before other owners' redraws (not in the code yet — HIL-1606) |

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

`AbstractPage::onSubscribe()` passes the route params to `withPageIdentity()`,
which adds `pageHeadOwner`, `pageBreadcrumb`, `pageTabs` and an empty
`pageChildren` to the existing shell in the same `page_response`. The resolver
does not filter by modules built in the active frontend; that part remains with
the Vue, React and Angular leaves above. `pageLabel` and `pageLead` continue to
describe the subscribing page, including Analytics' existing use of
`pageLabel`. The page payload still wins over catalog identity for an
administrator (`$payload->data + $shell`); the shell wins for an admin-view-mode
viewer (`$shell + $payload->data`) after the page's own data is hidden. The
current country header still draws a manual row; it is not the shared frontend
head API described here. Its display name comes from
`hilosI18nCountryDisplayName(card)`: `null` and `''` produce `Unnamed country`,
and any other name is returned unchanged, without trimming or translation. The
country code remains a separate badge. Country addresses use the unique,
unchanging `countryCode` in `/hilos/i18n/countries/{countryCode}` and
`/hilos/i18n/countries/{countryCode}/names`; renaming a country does not change
its URL, and equal display names do not share an address. Do not turn the legacy
chain into the owner's chain on pages whose owner has not opted in.

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
Profile, project pages and public documents are Phase 2; their mockup examples
do not activate them here, and HIL-1607 decides its own boundary. Own deletion
and redirect behavior are not decided by this document.
