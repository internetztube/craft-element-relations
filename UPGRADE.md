# Upgrading to 5.0 (Craft 6)

Version 5 is the same plugin, built for Craft 6. The field, the lists, replace, undo, the history and the protection
of elements in use work as in 4.x. This page lists what you may have to change in your own code, config and scripts.

## Requirements

- Craft 6 and PHP 8.5.
- Sites that stay on Craft 5 keep using 4.x.

## Update

```bash
composer require internetztube/craft-element-relations:^5.0
php artisan craft:migrate:all
```

The cache is rebuilt by itself after the update. You can rebuild it at any time under Utilities → Element Relations,
or with `php artisan element-relations:resave-relations`.

## Config file

Move `config/element-relations.php` to `config/craft/element-relations.php`. The settings are the same.

## Command line

Craft 6 runs commands with `php artisan`. The new names use colons:

| 4.x                                             | 5.x                                                  |
|-------------------------------------------------|------------------------------------------------------|
| `php craft element-relations/resave-relations`  | `php artisan element-relations:resave-relations`     |
| `php craft element-relations/prune`             | `php artisan element-relations:prune`                |
| `php craft element-relations/replace`           | `php artisan element-relations:replace`              |
| `php craft element-relations/replace/map`       | `php artisan element-relations:replace:map`          |
| `php craft element-relations/replace/history`   | `php artisan element-relations:replace:history`      |
| `php craft element-relations/replace/rollback`  | `php artisan element-relations:replace:rollback`     |
| `php craft element-relations/replace/resume`    | `php artisan element-relations:replace:resume`       |

The old names still work with `php artisan` (for example `php artisan element-relations/replace 12 34`).
The options are the same.

## Twig and GraphQL

Nothing changes in your templates and queries: `count`, `isInUse`, `elements`, `elementsIterator`, `globalUsages`,
`craft.usages()`, `usesElement` and `unused()` work as before.

One exception: pass `usesElement` and `unused` as methods, not inside a criteria array. Craft 6 ignores keys it does not
know in a criteria array, so the query would not be filtered.

```twig
{# works #}
{% set pages = craft.entries().usesElement(asset).all() %}

{# Craft 6 ignores usesElement here and returns every entry #}
{% set pages = craft.entries({ usesElement: asset }).all() %}
```

`craft.usages({ usesElement: asset })` still works, because that query knows the param.

## PHP: namespaces

The namespace follows Craft 6's style: `internetztube\elementRelations\…` is now `Internetztube\ElementRelations\…`,
and the folders start with a capital letter. The most used classes:

| 4.x                                                         | 5.x                                                          |
|-------------------------------------------------------------|--------------------------------------------------------------|
| `internetztube\elementRelations\ElementRelations`           | `Internetztube\ElementRelations\ElementRelations`            |
| `…\fields\ElementRelationsField`                            | `…\Fields\ElementRelationsField`                             |
| `…\models\RelationsModel`                                   | `…\Models\RelationsModel`                                    |
| `…\elements\Usage`                                          | `…\Elements\Usage`                                           |
| `…\services\ExtractorService`                               | `…\Services\ExtractorService`                                |
| `…\services\ReplaceService`                                 | `…\Services\ReplaceService`                                  |
| `…\services\extractors\InterfaceFieldExtractor` (and the other interfaces) | `…\Extractors\InterfaceFieldExtractor`        |
| `…\records\ReplacementRecord`                               | `…\Models\Replacement` (an Eloquent model)                   |

## PHP: events

Craft 6 uses Laravel events. Listen with `Event::listen()` and the event class:

| 4.x                                                              | 5.x                                                    |
|------------------------------------------------------------------|--------------------------------------------------------|
| `ExtractorService::EVENT_REGISTER_FIELD_EXTRACTORS`              | `Events\FieldExtractorsResolving` (`$event->types`)    |
| `ExtractorService::EVENT_REGISTER_ELEMENT_EXTRACTORS`            | `Events\ElementExtractorsResolving` (`$event->types`)  |
| `RelationsModel::EVENT_REGISTER_GLOBAL_USAGE_PROVIDERS`          | `Events\GlobalUsageProvidersResolving` (`$event->types`) |
| `ReplaceService::EVENT_BEFORE_REPLACE`                           | `Events\ReplacingUsages` (set `$event->isValid = false` to stop it) |
| `ReplaceService::EVENT_AFTER_REPLACE`                            | `Events\UsagesReplaced`                                |
| `ReplaceService::EVENT_AFTER_ROLLBACK`                           | `Events\ReplacementRolledBack`                         |

```php
use Illuminate\Support\Facades\Event;
use Internetztube\ElementRelations\Events\FieldExtractorsResolving;

Event::listen(function (FieldExtractorsResolving $event) {
    $event->types[] = MyFieldExtractor::class;
});
```

Extractors and replacers implement the same interfaces as before, with Craft 6's classes in their signatures
(`CraftCms\Cms\Element\Contracts\ElementInterface`, `CraftCms\Cms\Field\Field`).

## Fields and plugins

Craft's own fields work as before: Entries, Assets, Users, Link, Matrix, Content Block, Addresses, user photos. Craft 6's
Markdown field counts as rich text: reference tags in it (`{entry:12@1:url}`) are usages.

Craft 6 has no categories, tags or global sets of its own, so they are no longer listed.

The plugins 4.x supports have no Craft 6 version yet, so 5.0 does not read them: SEOmatic, Formie, Hyper, Vizy, Neo,
Navigation, CKEditor, Redactor, LinkIt, Typed Link, LinkMate, FreeLink, SEO (by Ether), Wishlist and Commerce. Fields
of other plugins that store their relations in Craft's relations table are still read by themselves. Support comes
back as these plugins are released for Craft 6.

## Logging

The plugin still writes its own log file, `storage/logs/element-relations-<date>.log`.
