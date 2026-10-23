# Element Relations Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## Unreleased

### Fixed
- The field's list of usages (and `usesElement` queries) no longer scans every element: the using elements come from the cache first and are queried by id (from 10 s to 0.1 s for an entry on a site with 2M elements).

## 4.0.2 - 2026-10-22

### Added
- Field setting “Elements in index columns”: how many usages an element index column loads before the link to the total. Default 5 (was 10).
- `php craft element-relations/prune` removes cache rows nothing can read. Garbage collection does the same after every run.

### Changed
- The field lists what uses the element in the site you are editing (its version there), wherever the relating elements are; the site dropdown is gone. Below the list, “Also used in these other sites” shows the other sites whose version is used as Craft site chips, with how many and a link to the element there (as in 3.x). The index column, its counts and its sort follow the index's site.
- An element used only in other sites is never shown as unused. The field and the index column show a “Used in other sites” label where “Unused” would be, and the sites: in the field as Craft site chips with the number of usages, also for editors who may not edit those sites (without a link). Sorting by the field puts it between unused and used in this site. Plugin settings of another site (SEOmatic's German settings) count as such a use.
- The Site column is no longer shown by default (the View menu offers it).
- When only plugin settings use the element, the field shows their chips without an empty list above them.
- An element index column of the field loads its data for the whole page at once: a handful of queries instead of several per row (from 9 s to 0.2 s for 100 rows on a large site). Its first usages are the most recently updated ones.

### Fixed
- The “Used anywhere” filter turned on listed every element, unused ones too. It lists only what is used now; `unused(false)` does the same in queries.
- Sorting an element index by the field could take forever on large sites (hundreds of millions of cached relations), and so could the “Used anywhere” filter: PostgreSQL scanned every element once per listed element.
- Element index columns of the field load about twice as fast: fewer queries per row (no count before loading the usages, no drafts query when no draft uses the element, the total counted once).
- The cache kept the rows of elements Craft's garbage collection deleted, and of revisions; on large sites they were almost the whole table and slowed down every list, count and sort. The update removes them once (on PostgreSQL, a large table is rebuilt instead; writes to the cache wait until that is done).
- Rebuilding the cache (and refreshing it after saves) is faster: the elements of a batch are loaded together instead of one by one, relation fields read only the ids of their targets, the owner of nested elements is looked up once per batch, and the rows of an element are written in one insert.
- The cleanup of large caches is as fast on MySQL as on PostgreSQL: the rows to keep are copied out, the table emptied and the rows copied back (the table keeps its indexes and their names).

## 4.0.1 - 2026-10-22

### Fixed
- Updating from 3.x with `craft up` could fail with “column globalKey does not exist”: a Craft migration that saves sections made SEOmatic save its settings before the plugin's own migrations ran.

## 4.0.0 - 2026-10-22

### Breaking changes
- **Craft 5.3 or later.** Craft 4 sites stay on 3.1.x.
- **Twig on the front end** (`count`, `isInUse`, `elements`) now counts only what visitors can see: live entries, enabled elements, no drafts. Pass `status: null, drafts: null` for the old behaviour. The control panel and the console are unchanged.
- **GraphQL** uses the same defaults and returns only what the schema may read. The `status` argument needs the “Query for non-enabled elements” scope. `isInUse` no longer counts plugin settings (use `isUsedInSeomaticGlobalSettings`).
- **An element counts once**, no matter how many sites or fields it uses the target in. Some numbers get smaller.
- **Removed** (control panel internals): the `elementRelationsElementPreviewHtml` Twig function, the `element-relations/paginate` action, `assetbundles\ElementRelationsAsset`, `services\DatabaseService`.

### Added
- **The field is Craft's own element index.** One list of everything that uses the element, across element types and sites. Craft's search, sorting, filters, status menu, table and card views, plus dropdowns for type and site, and columns for type, place, site and field. Plugin settings that use the element (for example SEOmatic) appear below it.
- Field settings: view mode, status menu, elements per page.
- Element indexes can sort by the field (number of usages). Exports contain the count and the ids. [#60](https://github.com/internetztube/craft-element-relations/issues/60)
- Twig and GraphQL: a `status` argument for `count`, `isInUse`, `elements` and `elementsIterator`. [#56](https://github.com/internetztube/craft-element-relations/issues/56)
- Element queries: `usesElement(element)` in Twig and PHP (`craft.products().type('clothing').usesElement(entry)`), and as an argument of Craft's own GraphQL queries (`entries(section: "news", usesElement: 12)`, `products(…)`, `assets(…)`), with Craft's arguments, scoping and status defaults. `Usage::find()`, in Twig `craft.usages()`, queries all element types at once. `relationsModel.globalUsages` lists plugin settings. [#56](https://github.com/internetztube/craft-element-relations/issues/56)
- Integrations: Vizy, FreeLink, SEO (by Ether), LinkMate, Navigation, Wishlist, Formie; SEOmatic Twitter and Open Graph images. Any field that uses Craft's relations table (the Link field too) is picked up by itself.
- Events to add or remove extractors and usage providers, and around replacements.
- Own log file: `storage/logs/element-relations-<date>.log`.
- Translations of the control panel texts in every language Craft ships (33 languages).
- Settings can be set in `config/element-relations.php` (copy `src/config.php`).
- **Unused:** a “Used anywhere” filter on every element index, and the `unused()` query param.
- **Replace:** swap an element for another in the ticked usages, in the filtered list, or everywhere. Also from the command line (`element-relations/replace`, `replace/map <file.csv>`) and in PHP (`ReplaceService::replace()`).
- **Undo and history:** every replacement can be rolled back, or reverted to the revisions from before. Utilities → Element Relations lists them. Changed entries get a revision.
- **In the background:** large replacements run as queue jobs that can resume, with progress.
- **Protect what is in use:** elements with live usages can't be deleted or disabled in the control panel without a new permission. On Craft 5.10 and later, Craft's delete dialog lists the usages. Can be switched off (`protectInUse`).
- Permissions: “Replace element references”, “Roll back replacements”, “Delete or disable elements that are in use”.

### Changed
- Users only see usages they may view. A line says how many are hidden.
- Drafts show the relations of their canonical element.
- Saving without field changes no longer queues a refresh. An error in one extractor no longer fails the whole job.
- `siteIds` always means the site of the element that holds the link.
- The field stores nothing in the content table.

### Removed
- The React field UI (660 KB, loaded on every control panel page).

### Fixed
- Trashed elements keep their usages. Restoring brings them back.
- Relations in rich text with `||` were lost.
- Relations inside a disabled Matrix block are listed and marked as not live.
- `sections` / `entryTypes` in Twig and GraphQL also returned products, categories and tags. They return entries only now. [#56](https://github.com/internetztube/craft-element-relations/issues/56)
- `status` lists are applied per element type: `['live', 'enabled']` no longer drops categories and assets (Craft aborts a query that is asked for a status its type doesn't know, and “live” is an entry status). Tags, global sets and addresses have no statuses; they only match “enabled”.
- GraphQL: an explicit `status: null` returns every status, like Craft's own queries. It was treated as left out.
- An element used in several of the requested sites came back twice (GraphQL `siteIds: []`, Twig with several `siteIds`). Each element comes once now.
- Fresh installs had no indexes on the cache table (slow lookups on large sites).
- Elements of removed or disabled plugins counted as usages.
- On PostgreSQL, the list failed for users without access to any Formie form.
- Lists could show results up to an hour old (Craft's index cache).
- Element chips in index columns link to their edit page again. Thank you, [@sebastian-works](https://github.com/sebastian-works)! [#59](https://github.com/internetztube/craft-element-relations/pull/59)
- GraphQL `elements` respects `maxGraphqlResults`.
- The Field column could list fields of another list on the same page.
- A field layout that refers to a deleted field could throw.
- Settings page translations. The batch size is validated.

## 3.1.7 - 2025-10-15
### Fixed
- Use `EVENT_AFTER_REQUEST` in favor of `__destruct`. Thank you, [@alexkuzmin](https://github.com/alexkuzmin)! [#57](https://github.com/internetztube/craft-element-relations/issues/57)
- Fixed FieldNotFoundException after reinstall. Thank you, [@denisyilmaz](https://github.com/denisyilmaz)! [#58](https://github.com/internetztube/craft-element-relations/issues/58)

## 3.1.6 - 2025-10-11
### Updated
- Refresh Queue Jobs now get combined into batches.

## 3.1.5 - 2025-10-11
### Added
- Add support for `section` and `entryType` in Twig/GraphQl. [#56](https://github.com/internetztube/craft-element-relations/issues/56) 

## 3.1.4 - 2025-08-22
### Fixed
- Moved javascript logic into anonymous function to not bleed into global scope.

### Changed
- Changed field from multi to single instance

### Fixed
- Fixed field icon

## 3.1.3 - 2025-07-26
### Fixed
- Fixed User Photo relations when users have no custom fields
- Fixed multi-site hints
- Fixed Postgres SEOmatic exception
- Fixed AssetBundle register so its only render on cp requests

## 3.1.2 - 2025-07-25
### Added
- Added support for GraphQl

### Fixed
- Fixed field input loading

## 3.1.1 - 2025-07-25
### Fixed
- Fixed fields in slideouts

## 3.1.0 - 2025-07-25
### Changed
- Field value now contains model which allows using information in templates
- Table view directly renders first two elements without lazy loading

### Added
- Added other sites hint [#40](https://github.com/internetztube/craft-element-relations/issues/40)
- Added Pagination to Field Input
- Added `internetztube\elementRelations\RelationsModel`

### Removed
- Removed `internetztube\elementRelations\RelationsService`
- Removed Twig function `elementRelationsGetRelations`
- Removed Twig function `elementRelationsIsUsedInSeomaticGlobalSettings`

## 3.0.11.1 - 2025-06-26
### Fixed
- Fix rendering of non-entry relations. [#46](https://github.com/internetztube/craft-element-relations/issues/46)

## 3.0.11 - 2025-06-26
### Fixed
- Deeply nested relation did not disappear when removed. [#45](https://github.com/internetztube/craft-element-relations/issues/45)

### Updated
- Small template table update.

## 3.0.10 - 2025-06-13
### Fixed
- Replace `ElementRelationsCacheRecord::tableName()` with static string to make migrations more robust. [#42](https://github.com/internetztube/craft-element-relations/issues/42)

## 3.0.9 - 2025-06-13
### Added
- Added setting for bulk refresh batch size. Thank you, [@webrgp](https://github.com/webrgp)! [#39](https://github.com/internetztube/craft-element-relations/issues/39)

## 3.0.8 - 2025-06-11
### Added
- Display section names next to related elements in relations field. Thank you, [@albertvisuals](https://github.com/albertvisuals)! [#44](https://github.com/internetztube/craft-element-relations/pull/44) 

## 3.0.7 - 2024-10-04
### Added
- Add table indexes to improve read performance. Thank you, [@webrgp](https://github.com/webrgp)! [#38](https://github.com/internetztube/craft-element-relations/issues/38)

## 3.0.6 - 2024-10-04
### Fixed
- Removed unnecessary join in RelationsService to improve performance. [#37](https://github.com/internetztube/craft-element-relations/issues/37)

## 3.0.5 - 2024-09-14
### Added
- Added Support for Element API / PHP.

## 3.0.4 - 2024-06-21
### Fixed
- Fixed Field Preview on Craft 4.
- Fixed Craft 4 MySql SEOmatic Global Relations Issue. [#36](https://github.com/internetztube/craft-element-relations/issues/36)

## 3.0.3 - 2024-06-18
### Fixed
- Fixed an issue which occurred when an Entry has an `entries.primaryOwnerId` which does not exist anymore. [#35](https://github.com/internetztube/craft-element-relations/issues/35)

## 3.0.2 - 2024-06-18
### Fixed
- Move Resaving completely into queue + 2k batching.

## 3.0.1 - 2024-06-18
### Added
- Utility for pushing ResaveAllElementRelationsJob into Queue.

### Fixed
- Resaving issue when there a lot of Elements.

## 3.0.0 - 2024-06-18
### Added
- Added support for Craft 5 and kept support for Craft 4.
- Added support for Matrix on Craft 5.
- Added support for Hyper.
- Added support for CkEditor.
- Added support for TypedLinkField.

### Removed
- Removed `useCache` config option.
- Removed `elementEvents` config option.
- Removed `cacheDuration` config option.

## 2.0.3 - 2024-01-26
### Fixed
- When caching is enabled but the relation fetching is not finished yet, an exception got thrown. [#31](https://github.com/internetztube/craft-element-relations/issues/31)

## 2.0.2 - 2022-09-01
### Fixed
- Fix error, if entry doesn't exist in primary site. Thank you, [@sfsmfc](https://github.com/sfsmfc)! [#28](https://github.com/internetztube/craft-element-relations/pull/28)

## 2.0.1 - 2022-08-27
### Fixed
- Fallback to PrimarySiteId when siteId is not set. [#27](https://github.com/internetztube/craft-element-relations/issues/27)

## 2.0.0 - 2022-06-16
### Fixed
- Fix bug when requested element id is null in `ElementRelationsController::actionGetByElementId`. `elementId` in  is now always an int. (0 = null = unused). [#21](https://github.com/internetztube/craft-element-relations/issues/21)

## 2.0.0-beta.3 - 2022-05-25
### Fixed
- Fix Exception while creating a new entry. [#19](https://github.com/internetztube/craft-element-relations/issues/19)

## 2.0.0-beta.2 - 2022-05-21
### Fixed
- Fixed Utility Exception when total is zero. 

## 2.0.0-beta.1 - 2022-05-11
### Added
- Added basic support for Craft 4.

## 1.3.6 - 2022-05-11
### Fixed
- Fixed Exception which occurred while saving a user. [#16](https://github.com/internetztube/craft-element-relations/issues/16)

## 1.3.5 - 2022-04-11
### Fixed
- Fixed utilities total count mismatch. [#13](https://github.com/internetztube/craft-element-relations/issues/13)
- Fix percentage of CreateRefreshElementRelationsJobsJob. [#15](https://github.com/internetztube/craft-element-relations/issues/15)

## 1.3.4 - 2022-04-11
### Updated
- Minimize queue job duplicates.
- Updated database field `elementrelations.relations` from `text` to `mediumText`.

### Fixed
- Fixed performance when redactor/linkit is enabled.

### Removed
- Removed `bulkJobSize` config option.

## 1.3.3 - 2022-02-21
### Updated
- Prioritize tasks that arrived in the queue via the `request refresh` buttons.

### Fixed
- Fixed bug that occurred on overview pages when cache is disabled.

## 1.3.2 - 2022-02-20
### Updated
- Updated Queue Job Priority to 4096.

## 1.3.1 - 2022-02-20
### Added
- Added config option for bulk job size.

## 1.3.0 - 2022-02-20
### Added
- Added refresh and reload buttons to element detail field.
- Show last update date on element detail page.
- Allow for disabling element events when caching is enabled.

### Updated
- Updated Queue Job Names.
- Don't refresh non-stale element relations when rebuilding cache without force option.

### Fixed
- Use illuminate/collections as `collect()` package. [#9](https://github.com/internetztube/craft-element-relations/issues/9)
- Only load one element relation at a time. Improves performance on overview pages.

## 1.2.6 - 2022-02-04
Version Bump

## 1.2.5 - 2022-02-04
### Fixed
- Better fix for [#6](https://github.com/internetztube/craft-element-relations/issues/6) Safely dropping foreign keys and index on `siteId` and `markup` columns.

## 1.2.4 - 2022-02-02
### Fixed
- Fixed [#6](https://github.com/internetztube/craft-element-relations/issues/6).

## 1.2.3 - 2022-01-07
### Added
- Added support for LinkIt.

## 1.2.2 - 2022-01-06
### Fixed
- Fix purging of custom fields on User Elements.

## 1.2.1 - 2022-01-06
### Added
- Added Support for Redactor.

### Updated
- Improved Cache Invalidation.

## 1.2.0 - 2022-01-04
### Updated
- Update caching logic.

### Fixed
- There is now always a hint when an element is used in another site.

## 1.1.3 - 2021-12-28
### Fixed
- Add csrf token to utilities form.

## 1.1.2 - 2021-12-28
### Added
- Added Utility Page for Cache Refresh

## 1.1.1 - 2021-12-28
### Fixed
- Database Migrations

## 1.1.0 - 2021-12-28
### Added
- Added caching system and new table to store data and speed up repeated fetches. Big thanks to [@gbowne](https://github.com/gbowne-quickbase) for the implementation! [#3](https://github.com/internetztube/craft-element-relations/issues/3)

## 1.0.6 - 2021-11-18
### Added
- Added support for Profile Photos.

### Fixed
- Fixed bug that occurred on prefixed SEOmatic fields.
- SEOmatic and Profile Photo check now only occur when element is an Asset. -> Performance

## 1.0.5 - 2021-11-13
### Fixed
- Fixed bug that occurred on Craft CMS installations with table prefixes. Thank you @gbowne-quickbase! #1

## 1.0.4 - 2021-10-25
### Added
- Added support for SEOmatic.

## 1.0.3 - 2021-10-25
### Fixed
- Removed translation methods in field edit view..

## 1.0.2 - 2021-10-25
### Added
- Show element relations of other sites, when there are none in the current site.
- Improved frontend performance by delaying the requests by 100ms.

## 1.0.1 - 2021-10-23
### Fixed
- Fixed composer.lock

## 1.0.0 - 2021-10-23
### Added
- Initial release
