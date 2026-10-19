# Element Relations Plugin for Craft CMS 5

This plugin shows all relations of an element. For example, where an asset, entry or any other element is linked.

__Feature Requests are welcome!__

![Share](screenshots/share.png)
![Asset Detail](screenshots/asset-detail-en.png)

## Requirements

This plugin requires Craft CMS 5.3 or later. Use the 3.1.x releases for Craft 4.


## Installation
To install this plugin, follow these steps:
1. Install with Composer via `composer require internetztube/craft-element-relations`
2. Install plugin in the Craft Control Panel under Settings > Plugins

You can also install this plugin via the Plugin Store in the Craft Control Panel.

## Support
As a basis the relations table is used. This means that any field that stores relations in the relations table will work out of the box.

* Entries, Assets, Categories, Globals, Users, Products, ... 
* Matrix
* Content Block, Addresses (Craft 5 core nested fields)
* Neo
* SuperTable
* User Photo
* SEOmatic
* Redactor
* CkEditor
* Hyper
* LinkIt
* TypedLinkField
* Link (Craft 5 core field)
* Vizy
* Navigation (nodes linking an element, shown as their menu)
* Wishlist (list items, shown as their list)
* Formie (form redirect entries, element field defaults, notification attachments, submission field values)
* FreeLink
* SEO (by Ether)
* LinkMate  
... and many more.

## Usage

### Field settings

The field is Craft's element index, so its settings are the ones you know from Craft's relational fields: View Mode (table or cards; authors can switch it in the View menu, which also offers more table columns), Show the status menu and Elements per page. On multi-site installs the list covers every site at once, with a Site column.

### GraphQL
The Element Relations field exposes its data in GraphQL. Query the field by its handle to
access counts, usage flags, and related elements. Below is a minimal example fetching usage
information for a field named `relationsField` on an entry:

```graphql
{
  entry(slug: "my-entry") {
    ... on entry_default_Entry {
      title
      relationsField {
        count
        isInUse
        elements(limit: 2) {
          id
          title
        }
        
        globalCount: count(siteIds: []),
        globalIsInUse: isInUse(siteIds: []),
        globalElements: elements(
            siteIds: [],
            limit: 2,
            sections: ['sectionHandle'],
            entryTypes: ['entryTypeHandle'],
            status: ['live']
        ) {
          id
          title
        }
      }
    }
  }
}
```

### Twig
Obtain a `RelationsModel` instance from any element’s relations field, then use its methods to inspect usage.
```twig
{# Fetch the RelationsModel from a field named `relationsField` #}

{# @var relationsModel \internetztube\elementRelations\models\RelationsModel #}
{% set relationsModel = element.relationsField %}
```

#### Is in use?
```twig
{# Check if the element is used in the current site #}
{% if relationsModel.isInUse %}
    {# ... #}
{% endif %}

{# Check usage across all sites #}
{% if relationsModel.isInUse([]) %}
    {# ... #}
{% endif %}

{# Check usage in specific sections/entry types #}
{% if relationsModel.isInUse(sections: ['sectionHandle'], entryTypes: ['entryTypeHandle']) %}
    {# ... #}
{% endif %}

{# Only count live elements (default: every status, including disabled) #}
{% if relationsModel.isInUse(status: ['live']) %}
    {# ... #}
{% endif %}
```

#### Elements
```twig
{# All related elements in elements site #}
{% set elements = relationsModel.elements %}

{# All related elements across all sites #}
{% set allElements = relationsModel.elements(siteIds: []) %}

{# Filter by specific site id(s) #}
{% set site1Elements = relationsModel.elements(siteIds: [1]) %}

{# Limit number of results (e.g., first 2 items) #}
{% set firstTwo = relationsModel.elements(siteIds: [], limit: 2) %}

{# Filter by sections and entry types #}
{% set filteredElements = relationsModel.elements(sections: ['sectionHandle'], entryTypes: ['entryTypeHandle']) %}

{# Combine filters with limit #}
{% set limitedFiltered = relationsModel.elements(limit: 5, sections: ['news', 'blog']) %}

{# All options #}
{% set elements = relationsModel.elements(
    siteIds: [],
    limit: null,
    offset: 0,
    sections: [],
    entryTypes: [],
    status: null
) %}
```

#### Elements Iterator
```twig
{% for element in relationsModel.elementsIterator(siteIds: [], limit: null, offset: 0, batchSize: 100) %}
    {{ dump(element) }}
{% endfor %}

{# Filter by sections/entry types while iterating #}
{% for element in relationsModel.elementsIterator(sections: ['news'], entryTypes: ['article'], limit: 50) %}
    {{ element.title }}
{% endfor %}
```

#### Sites
```twig
{# List of sites where the element is in use #}
{% set sites = relationsModel.sites %}
```

#### Element queries
```twig
{# Any element query can be filtered by what it uses #}
{% set pages = craft.entries().usesElement(asset).all() %}
```
Across all element types at once (entries, products, menus, ... returned as their real classes), in PHP:
```php
use internetztube\elementRelations\elements\Usage;

$everything = Usage::find()->usesElement($asset)->search('contact')->all();
```

#### Non-element usages
```twig
{# Plugin settings that reference the element, e.g. SEOmatic's global or per-section SEO image #}
{% for usage in relationsModel.globalUsages %}
    <a href="{{ usage.url }}">{{ usage.label }}</a>
{% endfor %}
```


## Screenshots

Asset Overview
![Asset Overview Primary Page](screenshots/asset-overview.png)

---

Asset detail
![Asset Detail](screenshots/asset-detail-en.png)

---

Create Field
![Field Edit Page](screenshots/field.png)

---

Add to Field Layout
![Field Edit Page](screenshots/fieldlayout.png)

## Development
`dev/dev.sh` spins up a throwaway Craft 5 project (PostgreSQL via Homebrew) with the plugin symlinked in, seeds test content and starts a server at http://localhost:8080/admin (admin / password).

```sh
dev/dev.sh                      # setup + serve (PostgreSQL)
DB_DRIVER=mysql dev/dev.sh      # same on MySQL (own project dir via PROJECT_DIR=...)
dev/dev.sh smoke                # seed, rebuild cache, run dev/smoke.php (180 assertions)
vendor/bin/phpstan              # static analysis
```

GitHub Actions (`.github/workflows/ci.yml`) runs PHPStan and the same `dev/dev.sh ci` smoke test on PHP 8.2 and 8.3 against MySQL 8 and PostgreSQL 14, with every supported plugin installed.

### Editions

Lite is everything above. Pro (behind a feature flag for now: `'features' => ['editions' => true]` in `config/element-relations.php`) adds:

- **Unused:** a “Used anywhere” rule in Craft’s index filter and custom-source conditions, an “Unused” source on the asset, category and tag indexes (with Craft’s bulk actions), and `craft.assets().unused()`. Sorting an index by the field (relation count) works in Lite.
- **Replace:** on the element itself, the field's “Replace…” button guides you through picking the replacement and ticking the usages to replace. In an element index, “Replace everywhere…” does all of them at once. Relational fields, nested entries, CKEditor/Redactor reference tags and SEOmatic images are rewritten through Craft’s save path; anything else is listed for a manual change.

## Issues
Please report any issues you find to the [Issues](https://github.com/internetztube/craft-element-relations/issues) page.


Brought to you by [Frederic Koeberl](https://frederickoeberl.com/)
