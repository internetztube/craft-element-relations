# Element Relations Plugin for Craft CMS 4.x

This plugin shows all relations of an element. For example, where an asset, entry or any other element is linked.

__Feature Requests are welcome!__

![Share](screenshots/share.png)

## Requirements

This plugin requires Craft CMS 4.0.0 or later.

## Installation
To install this plugin, follow these steps:
1. Install with Composer via `composer require internetztube/craft-element-relations`
2. Install plugin in the Craft Control Panel under Settings > Plugins

You can also install this plugin via the Plugin Store in the Craft Control Panel.

Optional:
Copy config file into project.
```
cp vendor/internetztube/craft-element-relations/src/config.php config/element-relations.php
```

## Support
As a basis the relations table is used. This means that any field that stores relations in the relations table will work out of the box.
* Most Craft CMS internal fields
* NEO
* SuperTable
* SEOmatic
* Profile Photos
* Redactor
* LinkIt
* ... and many more.


## Caching
Caching is enabled by default and set to `1 week`. These settings can be overridden using a local config file.

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

## Issues
Please report any issues you find to the [Issues](https://github.com/internetztube/craft-structure-disable-reorder/issues) page.


Brought to you by [Frederic Koeberl](https://frederickoeberl.com/)
