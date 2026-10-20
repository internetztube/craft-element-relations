# Element Relations Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## Unreleased (3.2.0)

### Changed
- **Breaking (front end):** in site requests `count`, `isInUse` and `elements` in Twig default to what visitors may see (live entries, enabled elements, no drafts). Pass `status: null` and `drafts: null` for everything, as before. The control panel and console keep including every status and drafts.
- **Craft 5.3+ only.** Craft 4 stays on the 3.1.x releases.
- **One row per element.** An element used in several sites is listed once (in the site you are on when it uses the element there), its Site column says “English, German”, and every number counts elements: the list, the dropdown badges, the wizard, the notices, the history, the delete dialog, Twig's `count([])` and `elements([])` with all sites, and the command line.
- **The field is now Craft's own element index.** One list of everything that uses the element, across all element types, with Craft's search, sorting, pagination, table and card views, status menu (live, pending, expired, disabled; plus a Drafts toggle) and element chips linking to the edit pages. On multi-site installs it lists every site at once, with a Site column (sortable) instead of Craft's per-site site menu. On top of that: type and site dropdowns with counts, and columns for the element type, where it lives (section › entry type, volume, group, product type) and the field that holds the link. Sorting and the filter take Craft's own sort options and rules of the element types in the list (Section, Entry Type, Author, Post Date, Volume, Filename, File Type, File Size, Group, Email, …). The View menu offers every column of the element types in the list, like Craft's own indexes (Section, Entry Type, Post Date and Authors when entries use the element; Filename and File Size for assets; Group, Email, …); a row whose type has no such column stays empty. Below the list, non-element usages such as SEOmatic's global or per-section settings appear as chips linking to the respective settings page.
- **Field settings** like Craft's relational fields: View Mode (table or cards), Show the status menu, Elements per page.
- **Fewer relation refreshes.** Saving an element without changing a custom field no longer queues a refresh; that covers site propagation and the nested Matrix entries Craft saves along with their owner. A refresh writes to the cache table only when the relations actually changed. Elements read by an element-level extractor (user photo, navigation node, wishlist item, Formie form/submission) always refresh. Revisions never do.
- Any field that stores its targets in Craft's relations table (Craft 5.3+ `RelationalFieldInterface`) is picked up automatically, including Craft's core Link field and plugin fields implementing the interface.
- An exception in one extractor (e.g. after a third-party plugin update) no longer fails the whole refresh job, which left every other element of the batch without relations. Errors are logged under the `element-relations` category.
- The field no longer stores anything in the element content column; its value is computed from the relations cache.
- Drafts show the relations of their canonical element instead of a "Drafts cannot be used in relations" hint.
- `siteIds` consistently means the site of the *relating* (source) element in every method. Previously `count()`/`elements()` filtered by the target site while `countFast()` filtered by the source site, so the two could disagree on multi-site installs with cross-site relations.
- Element chips in element index columns link to the element's edit page again on Craft 5. Supersedes [#59](https://github.com/internetztube/craft-element-relations/pull/59). Thank you, [@sebastian-works](https://github.com/sebastian-works)!

### Added
- Editions (Lite/Pro) behind a feature flag: `'features' => ['editions' => true]` in `config/element-relations.php` makes the Pro edition selectable; `ElementRelations::isPro()` is the gate.
- Element indexes can sort by the field (number of relating elements), like any sortable field.
- **Pro: Unused.** A “Used anywhere” rule for Craft’s element conditions: in the index filter on top of any source, with Craft’s bulk actions, and in custom sources. Plus the `unused()` element query param (`craft.assets().unused()`). Non-element usages (SEOmatic settings) count as used.
- **Pro: Replace.** Tick usages in the field's list (Craft's checkboxes) and choose “Replace…” in its action bar, or “Replace everywhere…” on an element index; both open a slideout: pick the replacement, check the usages (the ticked ones, all in the list with its filters across every page, or everywhere), review a summary, replace. Everywhere a number appears (the list, the wizard, the notice, the history, the delete dialog, the command line) it counts elements: an element used in two sites is listed once, its Site column says “English, German”, and it counts once. The notice and the history count what the list lost or got back, measured before and after.
- **Pro: Undo and rollback.** Every replacement is logged; the wizard's notice has an Undo button and Utilities → Element Relations lists all replacements with Roll back. Rolling back swaps the reference back and keeps later edits.
- **Pro: In the background.** Replacements and rollbacks from the control panel run as queue jobs as the user who started them, in batches (elements with tens of thousands of usages stay within the queue's time limit; a batch that fails keeps what was done and stays undoable; one whose job got lost, e.g. because a worker stopped, is resumed where it got: by the field while it waits, with Resume in the history, or `replace/resume`); the notice shows the progress and offers Undo when it is done.
- **Pro: Protect what is in use.** In the control panel, elements with live usages (live entries, enabled elements, plugin settings) can't be deleted, disabled (also for a site), expired or scheduled, from the edit page or the index's actions, without the new permission “Delete or disable elements that are in use”; the error says where they are used. On Craft 5.10+ Craft's delete dialog lists the usages first, with “Replace…” and, with the permission, “Delete anyway”. Console, migrations, queue jobs and settings changes are not affected.
- **Pro: Command line.** `php craft element-relations/replace <from> <to>` (`--usages`, `--site`, `--dry-run`), `replace/map <file.csv>` for many pairs at once (e.g. after merging duplicates), `replace/history` and `replace/rollback`; and `ReplaceService::replace()` for content migrations.
- **Pro: Revisions.** Entries changed by a replacement or rollback get a revision with a note, attributed to the user.
- **Pro: Permissions** “Replace element references” and “Roll back replacements” (admins have both). Users can roll back their own replacements; admins all of them. Replacing needs permission to view both elements and to save every element that gets changed.
- **Pro: Revert to revisions.** Besides Roll back (swap the reference back, keep later edits), the history offers “Revert to revisions”: entries are reverted to their revision from before the replacement with Craft's own revision revert; other elements are swapped back. Entries whose Matrix (or other nested) content changed get an owner revision too, which Craft would not create for a nested save. The wizard, the success message and the history say how many usages keep no revision (element types without revisions, sections with versioning off).
- Events for developers: `ExtractorService::EVENT_REGISTER_FIELD_EXTRACTORS` / `EVENT_REGISTER_ELEMENT_EXTRACTORS` and `RelationsModel::EVENT_REGISTER_GLOBAL_USAGE_PROVIDERS` to add or remove extractors, `ReplaceService::EVENT_BEFORE_REPLACE` (cancelable), `EVENT_AFTER_REPLACE`, `EVENT_AFTER_ROLLBACK`.
- Own log file `storage/logs/element-relations-<date>.log` for extractor errors, replacements and rollbacks.
- Documented `src/config.php` to copy to `config/element-relations.php`; settings overridden there are shown read-only in the control panel.
- `usesElement` element query parameter, e.g. `craft.entries().usesElement(asset)` or `{usesElement: 123}` as element index criteria.
- `internetztube\elementRelations\elements\Usage`: a virtual element type whose query spans all element types, e.g. `Usage::find()->usesElement($asset)->search('contact')->all()` returns entries, products, menus, ... as their real classes.
- `relationsModel.globalUsages`: non-element usages (plugin settings) as `GlobalUsage` models (`label`, `url`, `provider`). `isUsedInSeomaticGlobalSettings` is kept. They are cache rows now (without a source element), refreshed when SEOmatic saves its settings and on full rebuilds, so the “unused” filter and the relation-count sort include them; `count` and `elements` stay element-only.
- `status` argument for `count`, `isInUse`, `elements` and `elementsIterator` in Twig and GraphQL, e.g. `count(status: ['live'])`. [#56](https://github.com/internetztube/craft-element-relations/issues/56)
- Element exports (CSV/JSON/XML) include the relation count and the IDs of the relating elements instead of `{}`. [#60](https://github.com/internetztube/craft-element-relations/issues/60)
- New integrations: Vizy (links, images and fields inside blocks), FreeLink, SEO (by Ether) social images, LinkMate, Navigation (nodes, shown as their menu), Wishlist (items, shown as their list), Formie (form redirect entries and redirect rules, element field defaults, notification attachments, submission field values).
- SEOmatic: Twitter and Open Graph images are covered in addition to the SEO image, and global settings are detected with any number of images (previously only with exactly one).
- `dev/dev.sh`: local development environment (Craft 5 on PostgreSQL or MySQL via `DB_DRIVER=mysql`) with every supported plugin installed and a second site, seeds and smoke tests for every integration and for multi-site (`dev/seed/`, `dev/smoke/`).
- GitHub Actions workflow running PHPStan, the smoke tests and `dev/e2e.php` (control panel over HTTP: login, edit page, the element index and replace endpoints the wizard uses) on PHP 8.2/8.3 × MySQL 8/PostgreSQL 14.

### Removed
- The hand-built field UI: the React/MUI bundle (660 KB, loaded on every control panel page) and its Node toolchain, the `element-relations/paginate` controller action, the asset bundle and the `elementRelationsElementPreviewHtml` Twig function (control-panel internal).

### Fixed
- Elements of a plugin that was removed or disabled no longer count as usages: they cannot be listed, so the counts and the “Used anywhere” filter leave them out too.
- Fresh installs had no indexes on the relations cache's element columns (only upgrades got them), which made usage lookups slow on large sites. The indexes are created on install now and added where missing.
- Rich text (CKEditor, Redactor) containing `||` outside a reference tag, e.g. “fast || cheap”, lost all its relations.
- The Field column could also list the fields of another usage list rendered earlier in the same request.
- Trashed elements keep their usages: they are left out of lists and counts (and no replacement touches them), Craft's “Trashed” status in the field lists them, and restoring brings them back as they were. Previously their relations were deleted with them and only came back with a full rebuild.
- A relation inside a disabled Matrix block is listed, and the Field column says it is in a disabled block (not live).
- **GraphQL only returns what the schema may read.** `count`, `isInUse` and `elements` of the relations field go through Craft's own GraphQL resolvers per element type (entries, assets, categories, tags, users, global sets, addresses, Commerce products), so sections, volumes, groups and sites outside the schema are left out; element types without a GraphQL scope model (Formie, Navigation, Wishlist, other plugin elements) are not returned. Breaking: without `status` the defaults are now live entries and enabled other elements (no drafts), and the `status` argument needs the “Query for non-enabled elements” scope, like Craft's own queries. `isInUse` no longer includes plugin settings; use `isUsedInSeomaticGlobalSettings`.
- Control panel users only see what they may view: relating elements they lack permission for are left out of the field's list, its search, sorting and counts, checked with Craft's own `Elements::canView()` (sections, volumes, groups, sites, other authors' entries, drafts, plugin elements). A line below the list says how many usages are hidden, so nobody takes an element for unused. The same goes for the index column preview, the type dropdown, exports, the revision hint and every message; the list only ever shows usages of an element the user may view itself. Index request criteria cannot switch this off.
- Pro: replacing and rolling back skip fields the user may not edit (a field layout's “Who can edit” condition), queued replacements are not run when the user who started them is gone or lost the permission, and the history in Utilities shows non-admins only their own replacements.
- GraphQL `elements` never returns more than Craft's `maxGraphqlResults`.
- The field's list and the Pro “Used anywhere” filter could show outdated results for up to an hour, because Craft caches element index queries per element type. Queries using the plugin's parameters bypass that cache now.
- `getTabHash()` caught a non-existent exception class, so a field layout referencing a deleted field could still throw.
- Settings page used the wrong translation category, and the batch size is validated server-side (integer, at least 1).

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
