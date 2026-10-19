# Element Relations for Craft CMS

See where every element is used. One native list of everything that links to an entry, asset, category, product or any other element, across all element types, sites and the plugins your project uses.

![Share](screenshots/share.png)
![Asset Detail](screenshots/asset-detail-en.png)

## Features

- **One list, every element type.** The field is Craft's own element index: search, sort, status and Drafts filter, table or card view, chips that link to the edit pages. A type dropdown with counts, plus columns for where the element lives, the field that holds the link and the site.
- **Everything that can link to an element.** Anything Craft stores in its relations table works out of the box. Rich text, link fields and plugins that store references elsewhere are read by dedicated extractors (see [Supported fields and plugins](#supported-fields-and-plugins)).
- **Plugin settings, too.** Usages that are not elements, such as SEOmatic's global or per-section SEO image, are listed below the field.
- **Templates, GraphQL and element queries.** `isInUse`, counts and the relating elements in Twig and GraphQL; `usesElement` on any element query.
- **Fast.** Relations are extracted when an element is saved and cached in one table; saves that change no custom field skip the work.

**Pro** (behind a feature flag for now, see [Editions](#editions)):

- **Unused:** a “Used anywhere” filter rule, an “Unused” source on asset, category and tag indexes with Craft's bulk actions, `craft.assets().unused()`.
- **Replace:** swap an element for another everywhere or in the usages you pick, guided by a two-step wizard in the field. Every supported field and plugin is rewritten the way it was read.
- **Undo and rollback:** Undo right in the notice, a history with Roll back in Utilities, revisions with notes for entries, large replacements in the background.

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
| `replaceSyncLimit` | `25` | Pro: replacements touching more elements run as a queue job. |
| `features.editions` | `false` | Offer the Lite and Pro editions. |

Field settings (per field): View Mode (table or cards; authors can switch it, the View menu offers more columns), Show the status menu, Elements per page.

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

`siteIds`: omitted = the element's site, `[]` = all sites. `status`: omitted = every status.

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

- **Unused:** “Used anywhere” rule in Craft's index filter and custom-source conditions, an “Unused” source on asset, category and tag indexes (with Craft's bulk actions), `unused()` element query param. Non-element usages (SEOmatic settings) count as used.
- **Replace:** the field's “Replace…” button guides you through picking the replacement (step 1) and ticking the usages to replace (step 2). “Replace everywhere…” in an element index does all at once. Plugin settings are listed for a manual change. Only elements the user may save are touched.
- **Undo and rollback:** the success notice has an Undo button; Utilities → Element Relations lists every replacement with Roll back. Rolling back swaps the reference back and keeps later edits. Entries get a revision with a note for each step.
- **Background:** replacements touching more than `replaceSyncLimit` elements run as a queue job as the user who started them.

### Permissions (Pro)

| Permission | Allows |
|---|---|
| Replace element references | the wizard and “Replace everywhere…” |
| Roll back replacements | Undo and Roll back |

Admins have both. Users also need permission to save each element that gets changed.

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

Asset Overview
![Asset Overview Primary Page](screenshots/asset-overview.png)

Asset detail
![Asset Detail](screenshots/asset-detail-en.png)

Create Field
![Field Edit Page](screenshots/field.png)

Add to Field Layout
![Field Edit Page](screenshots/fieldlayout.png)

## Development

`dev/dev.sh` spins up a throwaway Craft 5 project with every supported plugin and a second site, seeds test content and starts a server at http://localhost:8080/admin (admin / password).

```sh
dev/dev.sh                      # setup + serve (PostgreSQL)
DB_DRIVER=mysql PROJECT_DIR=../craft-element-relations-dev-mysql PORT=8081 dev/dev.sh
dev/dev.sh smoke                # seed and run dev/smoke.php (every integration, incl. replace and rollback)
dev/dev.sh e2e                  # control panel over HTTP: every usage through the wizard, undo, history
vendor/bin/phpstan              # static analysis
```

GitHub Actions runs PHPStan, the smoke test and the control-panel test on PHP 8.2 and 8.3 against MySQL 8 and PostgreSQL 14.

## Support

Please report issues and feature requests on [GitHub](https://github.com/internetztube/craft-element-relations/issues).

Brought to you by [Frederic Koeberl](https://frederickoeberl.com/)
