# Page Head

Read this before building or redrawing a section or entity head, declaring its
catalog identity, changing breadcrumbs, page tabs or a live instance name, or
moving an owner from child cards to the shared head.

This is the approved rule for adoption, written ahead of its implementation.
The markers name the leaves that implement each part; the binding points below
describe today's code separately. An owner that has not been adopted keeps its
existing head and child cards. Country is the first carrier in HIL-1559; it does
not adopt the whole Internationalization section. Other owners move in their
own redraws after HIL-1606 has merged.

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
and enables adoption at the owner, covering all its tabs
(not in the code yet — HIL-1599).

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
different catalog entries on that path have the same caption
(not in the code yet — HIL-1599).

Distinguish three values: the page's **title**, its catalog **Breadcrumb
caption**, and the **instance name** supplied by the page's data. The Breadcrumb
caption is separate from title and lead. It labels breadcrumb links and child
tabs; an empty caption omits that breadcrumb link. The owner's first tab is
Overview. These are concepts, not proposed field or constant names
(not in the code yet — HIL-1599).

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
in the containing owner's row (not in the code yet — HIL-1599).

Use the same eligibility decision as dashboard cards: omit pages that are
absent, disabled, not connected by the project, not built in the active view
layer, or inapplicable to this instance. For example, a letter-frame page is
applicable to Email, not SMS. Preserve the existing server-served and
view-layer-built filtering when calculating the new row
(not in the code yet — HIL-1599).

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
the owner-based identity in that reply is
(not in the code yet — HIL-1599).

Each tab's page supplies the instance name from its own data to the breadcrumb
entry with the **entity's page key**, never by position from the end. The same
reactive name appears in that entry, the entity block and the browser title
`<name> · <app>`. A rename here or in another window updates all three without
reload; direct entry and F5 show the same result. Do not introduce a local name
catalog, a static `document.title`, or replace the name with a code. The
carrier's interview owns missing-name data and fallback policy; this document
does not choose one. Key-based name binding and the Vue title path are
(not in the code yet — HIL-1601).

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
2. Identify the owner and its tabs from the catalog tree. Declare the entity
   boundaries, Breadcrumb captions and adoption on the owner in the backend
   catalog, using the API landed by HIL-1599. Its enabling mechanism is
   (not in the code yet — HIL-1599).
3. Mount the shared head on every tab of this owner and supply its page-owned
   entity block content. Use the implemented interface, not today's manual
   country header as a proposed generic API. The first Vue carrier is
   (not in the code yet — HIL-1602).
4. Bind each tab's live instance name to the owner's entry, block and browser
   title through the implemented name interface. The Vue binding is
   (not in the code yet — HIL-1601).
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
one-tab and filtered-tab cases, an addressless breadcrumb entry, loading,
375 px, the viewer, and deletion from another window with a modal draft open.
These are acceptance requirements for the redraw; this documentation change
adds no product tests or selectors.

## Examples

These are navigation scenarios, not declarations of page keys, routes or
activation. `<name>` is data from the page, not a literal caption. Bold marks
the current tab. The catalog calculation illustrated below belongs to HIL-1599;
the country head is first applied by HIL-1602, with React and Angular following
in HIL-1604 and HIL-1605. Roles, Users and the other examples wait for their own
redraws; naming them here does not enable them.

| Open page | Owner | Breadcrumb | Row | Catalog calculation |
|---|---|---|---|---|
| Country Overview | Country instance | Hilos › Internationalization › Countries › `<name>` | **Overview** / Names | (not in the code yet — HIL-1599) |
| Country Names | Same country instance | Hilos › Internationalization › Countries › `<name>` | Overview / **Names** | (not in the code yet — HIL-1599) |
| Role Overview, from a separate Roles list tab | Role instance | Hilos › Roles › Roles › `<name>` | **Overview** / Rights / Grants / History | (not in the code yet — HIL-1599) |
| Users, where the section itself is the list | Users section | Hilos › Users | No row: only the owner page | (not in the code yet — HIL-1599) |
| Person Overview, from that Users list | Person instance | Hilos › Users › `<name>` | **Overview** / Roles / Access / Sessions | (not in the code yet — HIL-1599) |
| Machine, a non-entity child of Gaps | Internationalization section | Hilos › Internationalization | Overview / Languages / Countries / Entities / Pages / Groups / Actions / Messages / Gaps / **Machine** / Other findings | (not in the code yet — HIL-1599) |

The two country tabs share the chain ending at the country, not at Names. Roles
repeats because the section and its separate list are different entries. Users
does not repeat because its section is its list. Machine follows Gaps in the
same row and is itself current; it does not create another row. These examples
exercise the ownership calculation (not in the code yet — HIL-1599).

## Implementation Status And Binding Points

The paths here locate implementation work; an author can follow the adoption
sequence without studying these files. They do not specify a future API.

| Area | Current binding point | Remaining behavior and owner |
|---|---|---|
| Backend catalog | `framework/backend/Database/Pages/PageCatalogProviderInterface.php`, `PageCatalogResolver.php`, `PageCatalogConstants.php` | Separate Breadcrumb caption, entity declaration, owner switch, chain/row calculation and identity carried in the page reply (not in the code yet — HIL-1599) |
| Backend assembly | `framework/backend/Core/Page/AbstractPage.php`, `servedPageCards()` and `withPageIdentity()` | Assemble the adopted owner's identity while preserving serving filters and existing payload precedence (not in the code yet — HIL-1599) |
| Name and Vue title | `framework/frontend/core/src/admin/identity/hilosPageIdentity.ts`, `framework/frontend/core/src/routing/pageTitle.ts`, `framework/frontend/core/src/bootstrap/bootHilos.ts` | Instance name by entity key and its reactive title binding (not in the code yet — HIL-1601) |
| Vue head and country | `framework/frontend/vue/src/HilosAdminPage.vue`; `framework/frontend/vue/src/admin/i18n/details/HilosI18nCountryHeader.vue`, `HilosI18nCountryPage.vue`, `HilosI18nCountryNamesPage.vue` | Shared head/block/row and both country tabs using it (not in the code yet — HIL-1602) |
| React parity | `framework/frontend/react/src/HilosAdminPage.tsx` and its country page modules | Equivalent head, states, accessibility, live name and title (not in the code yet — HIL-1604) |
| Angular parity | `framework/frontend/angular/src/HilosAdminPage.ts` and its country page modules | Equivalent head, states, accessibility, live name and title (not in the code yet — HIL-1605) |
| Live 404, server | `AbstractPage::resolvePageResource`, `PageResource::db`, `BrowserContext::refuseDeletedPageResources`, `PageSignalRouter::refuseDeletedResource` | In the code: other open windows get `not_found` and `transitionMessage`; the initiator keeps its acknowledgement and goes to the country list |
| Live 404, clients | The page error surfaces in all three view layers | Show that line, remove the head and preserve an open modal draft (not in the code yet — HIL-1603) |
| Adoption gate | HIL-1606 | Shared e2e navigation coverage before other owners' redraws (not in the code yet — HIL-1606) |

Today the resolver walks through the current page using `label` and returns
children as cards. The constants describe `label`, `lead`, `parent`, `icon` and
the existing `pageLabel`, `pageLead`, `pageBreadcrumb`, `pageChildren` identity.
`withPageIdentity()` lets a page's payload override the catalog, reversing that
precedence for a viewer; preserve both behaviors and Analytics' existing use of
`pageLabel`. The current country header has a manual row; it is not the shared
API described here. Do not turn the legacy chain into the owner's chain on
pages whose owner has not opted in.

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
