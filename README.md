# Element Relations for Craft CMS

**See where every element is used. Replace it everywhere, safely.**

One list of everything that links to an entry, asset, category, product or any other element: across element types, sites, rich text and the plugins your project uses. It is Craft's own element index, so it looks and works like the rest of the control panel.

![Element Relations](docs/screenshots/cover.jpg)

[Tour with screenshots](docs/README.md) · [Installation](#installation) · [Twig, GraphQL and element queries](#usage) · [Supported fields and plugins](#supported-fields-and-plugins) · [Pro](#editions)

## Why

Every Craft project ends up asking the same things: *Can I delete this asset? Which pages show this category? Where is the old logo still used?* Craft's relations table knows a part of the answer. It knows nothing about CKEditor links, Link fields of plugins, SEOmatic images, Navigation menus, Formie forms or Vizy blocks. Element Relations answers in one place, for all of it, and with Pro lets you act on the answer.

## What you get

- **One answer, every element type.** Entries, Matrix and other nested content, assets, categories, tags, users, products, global sets, Navigation menus, Formie forms. Each row names where it lives, the field that holds the link and the site; an element used in two sites or through two fields is one row and counts once, everywhere.
- **Everything that can link.** Anything in Craft's relations table works out of the box. Rich text, link fields and plugins that store references elsewhere are read by dedicated extractors, 13 plugins today (see [Supported fields and plugins](#supported-fields-and-plugins)).
- **Plugin settings count.** Usages that are not elements, such as SEOmatic's global or per-section SEO image, are listed below the field.
- **Native, not bolted on.** Craft's search, sorting, status and Drafts filter, table or card view, the View menu's columns, Craft's own filter rules and sort options of the types in the list (Section, Post Date, Author, Filename, Group, …), plus type and site dropdowns with counts. An index column shows the first usages and the total, and sorts by it.
- **Permission-aware.** Users only see usages they may view; a line says how many are hidden, so nobody takes an element for unused.
- **In code, too.** `isInUse`, counts and the relating elements in Twig and GraphQL, `usesElement` on any element query.
- **Built for scale.** Relations are extracted on save into one indexed table; saves that change no custom field skip the work. Lists and counts stay paginated and SQL-based; the load test runs one asset used by 12,000 entries ([`dev/stress.php`](dev/stress.php)).

**Pro** (ready behind a feature flag, see [Editions](#editions)):

- **Find what nobody uses.** A “Used anywhere” rule in Craft's filter, on top of any source and with Craft's bulk actions; `craft.assets().unused()`.
- **Replace anywhere.** Swap an element for another everywhere, or in the usages you tick, in a slideout right from the field, or on the command line (also with a CSV mapping, for migrations). Every supported field and plugin is rewritten the way it was read.
- **Undo, roll back, revisions.** Undo in the notice, a history with Roll back in Utilities, revisions with notes for entries, Revert to revisions.
- **Keep what is in use.** Editors can't delete or take offline what live content uses; Craft's delete dialog lists the usages with “Replace…” right there.
- **Never blocks the page.** Replacements and rollbacks run as batched queue jobs, as the user who started them, and resume when a worker dies.

## Tested

Three suites run on every push, on PHP 8.2 and 8.3 against MySQL 8 and PostgreSQL 14: the console smoke test, the control panel over HTTP and the control panel in headless Chrome. 1,444 assertions, every supported plugin seeded and round-tripped (extract, replace, roll back), 100 % line coverage of `src/`. [`dev/TESTS.md`](dev/TESTS.md) lists every assertion in one sentence each.

## Requirements

Craft CMS 5.3 or later, PHP 8.2 or later. Use the 3.1.x releases for Craft 4.

## Installation

Install from the Plugin Store in the control panel, or with Composer:

```sh
composer require internetztube/craft-element-relations
php craft plugin/install element-relations
```

Then create an **Element Relations** field and add it to the field layouts of the elements you want to inspect (assets, entries, categories, …). Installing the plugin builds the relations cache for existing content in the queue.

## Configuration

Copy [`src/config.php`](src/config.php) to `config/element-relations.php` to override the plugin settings per environment:

| Setting | Default | Description |
|---|---|---|
| `bulkRefreshBatchSize` | `2000` | Elements per queue job when the whole relations cache is rebuilt. |
| `features.editions` | `false` | Offer the Lite and Pro editions. |

Field settings (per field): View Mode (table or cards; authors can switch it, the View menu offers every column of the element types in the list), Show the status menu, Elements per page.

The cache can be rebuilt any time under Utilities → Element Relations or with `php craft element-relations/resave-relations`.

## Supported fields and plugins

Anything that stores its targets in Craft's relations table works out of the box, including:

- Entries, Assets, Categories, Tags, Users, Products and other relation fields, also inside Matrix, Neo, Super Table, Content Block and Addresses
- Craft's Link field and plugin fields implementing `RelationalFieldInterface`
- User photos

Dedicated extractors (each one can also rewrite what it reads, for Pro's replace):

| Plugin | What is read |
|---|---|
| CKEditor, Redactor | reference tags in links and images |
| SEOmatic | SEO, Twitter and Open Graph images in the field, global and per-section settings |
| Hyper, LinkIt, Typed Link Field, LinkMate, FreeLink | element links |
| Vizy | link marks, images, fields inside blocks |
| SEO (by Ether) | social images |
| Navigation | nodes linking an element (listed as their menu) |
| Wishlist | list items (listed as their list) |
| Formie | redirect entries and rules, element field defaults, notification attachments, submission values |

Missing one? [Open an issue](https://github.com/internetztube/craft-element-relations/issues), or register your own extractor (see [Events](#events)).

## Usage

### Twig

Get the `RelationsModel` from the element's field:

```twig
{# @var relationsModel \internetztube\elementRelations\models\RelationsModel #}
{% set relationsModel = element.relationsField %}

{% if relationsModel.isInUse %}…{% endif %}                     {# in the element's site #}
{% if relationsModel.isInUse([]) %}…{% endif %}                 {# in any site #}
{% if relationsModel.isInUse(status: ['live']) %}…{% endif %}   {# only live elements count #}
{% if relationsModel.isInUse(sections: ['news'], entryTypes: ['article']) %}…{% endif %}

{% set elements = relationsModel.elements(siteIds: [], limit: 5, sections: ['news'], status: null) %}
{% for element in relationsModel.elementsIterator(siteIds: [], batchSize: 100) %}…{% endfor %}
{% set sites = relationsModel.sites %}

{# plugin settings that reference the element, e.g. SEOmatic's global SEO image #}
{% for usage in relationsModel.globalUsages %}
    <a href="{{ usage.url }}">{{ usage.label }}</a>
{% endfor %}
```

A complete example site using all of this (destination pages listing their stories, backlinks, author pages): [`dev/templates`](dev/templates).

For elements with thousands of usages use `count`/`isInUse` (one SQL query each), pass a `limit` to `elements`, or walk them with `elementsIterator` (batches); `elements` without a limit loads every relating element.

`siteIds`: omitted = the element's site, `[]` = all sites. With `[]` an element used in two sites counts once and `elements` returns it once (in the first site it is used in); drafts never count on the front end unless `drafts: null`. `status` and `drafts`: omitted = what visitors may see on the front end (live entries, enabled elements, no drafts), every status and drafts in the control panel and console; `status: null, drafts: null` for everything.

### Element queries

```twig
{% set pages = craft.entries().usesElement(asset).all() %}
{% set unusedAssets = craft.assets().unused().all() %}   {# Pro #}
```

Across all element types at once (entries, products, menus, … returned as their real classes):

```php
use internetztube\elementRelations\elements\Usage;

$everything = Usage::find()->usesElement($asset)->search('contact')->all();
```

### GraphQL

The relations field only returns what the GraphQL schema may read: elements of sections, volumes, groups and sites outside the schema are left out, and element types without a GraphQL scope (Formie, Navigation, Wishlist, other plugin elements) are never returned. Without `status`, entries must be live and other elements enabled; `status` needs the “Query for non-enabled elements” scope.

```graphql
{
  entry(slug: "my-entry") {
    ... on page_Entry {
      relationsField {
        count
        isInUse
        elements(limit: 2) { id title }
        anywhere: count(siteIds: [])
        liveNews: elements(siteIds: [], sections: ["news"], status: ["live"]) { id title }
      }
    }
  }
}
```

## Editions

The plugin is a single Lite edition today; everything in [Features](#features) except the Pro list is Lite. The Pro edition is ready behind a feature flag (`'features' => ['editions' => true]` in `config/element-relations.php`) and adds:

- **Unused:** “Used anywhere” rule in Craft's index filter (on top of any source, with Craft's bulk actions) and in custom-source conditions; create a custom source with it for a fixed “Unused” entry in the sidebar. `unused()` element query param. Non-element usages (SEOmatic settings) count as used.
- **Replace:** tick usages in the field's list and choose “Replace…” (or “Replace everywhere…” in an element index); a slideout picks the replacement, lets you untick usages or take all in the filtered list, across pages, and shows a summary before anything changes. Plugin settings are listed for a manual change. Only elements the user may save are touched.
- **Undo and rollback:** the success notice has an Undo button; Utilities → Element Relations lists every replacement with Roll back. Rolling back swaps the reference back and keeps later edits. Entries get a revision with a note for each step.
- **Background:** replacements and rollbacks from the control panel run as queue jobs, in batches, as the user who started them, so saving many or large entries never blocks the page. The notice shows the progress and offers Undo when it is done. A job that got lost (a worker stopped) is resumed where it got: automatically while the notice waits, with Resume in the history, or on the command line.
- **Command line:** `php craft element-relations/replace` for scripts and migrations, see [Command line](#command-line-pro).
- **Protect what is in use:** in the control panel, an element that live content uses (what visitors see: live entries, enabled elements, plus plugin settings) can't be deleted, disabled, disabled for a site, expired or scheduled for later; the error names the usages. On Craft 5.10+ Craft's delete dialog lists them up front, with “Replace…” and, only with the permission, “Delete anyway”. The edit page, slideouts and the index's Delete / Set status / Delete for site actions are covered. Drafts and disabled elements that point to it don't count. Users with “Delete or disable elements that are in use” (admins) may; console commands, migrations, queue jobs and settings changes (e.g. deleting a section) are never blocked.

### Permissions

What a control panel user sees in the field follows Craft's permissions (`Elements::canView()`): elements they may not view are left out of the list, its search, sorting, counts and exports. A line below the list says how many usages are hidden, so nobody takes an element for unused.

Pro adds three permissions:

| Permission | Allows |
|---|---|
| Replace element references | “Replace…” in the field and “Replace everywhere…” |
| Roll back replacements | Undo, Roll back and Revert to revisions, for their own replacements |
| Delete or disable elements that are in use | Deleting, disabling, expiring or scheduling elements that live content uses |

Admins have both, can roll back anyone's replacements and see everyone's in the history; other users see their own. Replacing also needs permission to view both elements, to save every element that gets changed and to edit the field (a field layout's “Who can edit” condition); everything else is skipped and not named. A queued replacement runs as the user who started it and is not run when they are gone or lost the permission.

### Revisions (Pro)

Entries changed by a replacement or rollback get a revision with a note, including entries whose Matrix (or other nested) content changed. Element types without revisions (assets, categories, global sets, plugin elements) and entries in sections with versioning off get none; the wizard, the success message and the history say how many. The history offers two ways back:

- **Roll back:** swaps the replacement back for the original; changes made since are kept.
- **Revert to revisions:** reverts the changed entries to their revision from before the replacement with Craft's revision revert (changes since are discarded); elements without revisions are swapped back.

## Command line (Pro)

Replacements from the command line run right away (no queue), without a user, and land in the history like any other, so they can be rolled back:

```sh
php craft element-relations/replace 12 34                  # replace element #12 with #34 everywhere
php craft element-relations/replace 12 34 --usages=56,78   # only in these relating elements (ids as listed in the field)
php craft element-relations/replace 12 34 --dry-run        # list what would change, change nothing
php craft element-relations/replace 12 34 --site=de        # elements of another site (handle or id)
php craft element-relations/replace/map duplicates.csv     # many at once: one "from,to" pair of ids per line
php craft element-relations/replace/history --limit=50     # the latest replacements with their ids
php craft element-relations/replace/rollback 1234          # roll one back (--revisions: revert entries to their revisions)
php craft element-relations/replace/resume 1234            # continue one whose queue job got lost (e.g. a worker stopped)
```

A mapping file may have a header line and `#` comments, e.g. after merging duplicate assets:

```csv
from,to
# old asset, kept asset
412,388
415,388
```

In a content migration, call the service directly:

```php
use craft\elements\Asset;
use internetztube\elementRelations\services\ReplaceService;

public function safeUp(): bool
{
    $result = ReplaceService::replace(Asset::find()->id(412)->one(), Asset::find()->id(388)->one());
    echo "Replaced in {$result['replaced']} elements (rollback: #{$result['operationId']})\n";
    return true;
}
```

`ReplaceService::replace($from, $to, $onlySourceIds)` returns `replaced`, `skipped` (usages that need a manual change), `operationId` and `withoutRevision`; `ReplaceService::rollback()` undoes an operation.

## Events

Register your own extractors, or react to replacements:

```php
use craft\events\RegisterComponentTypesEvent;
use internetztube\elementRelations\events\ReplaceEvent;
use internetztube\elementRelations\services\ExtractorService;
use internetztube\elementRelations\services\ReplaceService;
use yii\base\Event;

// a field extractor implements InterfaceFieldExtractor (and InterfaceFieldReplacer to support replace);
// the first extractor that returns records for a field wins, so put specific ones first
Event::on(ExtractorService::class, ExtractorService::EVENT_REGISTER_FIELD_EXTRACTORS, function (RegisterComponentTypesEvent $event) {
    array_unshift($event->types, MyFieldExtractor::class);
});

// element extractors (InterfaceElementExtractor / InterfaceElementReplacer) and non-element usage providers
// (RelationsModel::EVENT_REGISTER_GLOBAL_USAGE_PROVIDERS, InterfaceGlobalUsage) work the same way

Event::on(ReplaceService::class, ReplaceService::EVENT_BEFORE_REPLACE, function (ReplaceEvent $event) {
    // $event->from, $event->to, $event->sourceIds; set $event->isValid = false to stop it
});
Event::on(ReplaceService::class, ReplaceService::EVENT_AFTER_REPLACE, function (ReplaceEvent $event) {
    // $event->result['replaced'], $event->result['skipped'], $event->operation
});
// ReplaceService::EVENT_AFTER_ROLLBACK: $event->result['restored'], $event->operation
```

## Logging

Extractor errors, replacements and rollbacks are logged to `storage/logs/element-relations-<date>.log`. In dev mode extractor errors are thrown instead.

## Screenshots

Every feature, in the demo site: [docs/README.md](docs/README.md).

## Development

`dev/dev.sh` spins up a throwaway Craft 5 project with every supported plugin and a second site, seeds test content and starts a server at http://localhost:8080/admin (admin / password). Next to the test fixtures (section “Pages”) the seed builds a demo site for screenshots ([`dev/demo.php`](dev/demo.php)): “Transit”, a travel journal with articles, team, company pages, a homepage of content blocks, menus, forms, drafts and German translations, with photos by Frederic Köberl on [Unsplash](https://unsplash.com/@internetztube) (downloaded on the first seed; drawn pictures without network).

```sh
dev/dev.sh                      # setup + serve (PostgreSQL)
DB_DRIVER=mysql PROJECT_DIR=../craft-element-relations-dev-mysql PORT=8081 dev/dev.sh
dev/dev.sh smoke                # seed and run dev/smoke.php (every integration, incl. replace and rollback)
dev/dev.sh e2e                  # control panel over HTTP: every usage through Replace…, undo, history
dev/dev.sh screenshots          # retake docs/screenshots from the demo site (headless Chrome)
vendor/bin/phpstan              # static analysis
```

GitHub Actions runs PHPStan, the smoke test and the control-panel test on PHP 8.2 and 8.3 against MySQL 8 and PostgreSQL 14.

## Support

Please report issues and feature requests on [GitHub](https://github.com/internetztube/craft-element-relations/issues).

Brought to you by [Frederic Koeberl](https://frederickoeberl.com/)
