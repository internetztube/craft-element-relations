# Element Relations: a tour

Screenshots of the plugin in the demo site “Transit”, a fictional travel journal (photos by Frederic Köberl on [Unsplash](https://unsplash.com/@internetztube)). The demo is part of the dev environment: `dev/dev.sh` builds it, `dev/dev.sh screenshots` retakes every picture on this page.

## See where every element is used

The **Element Relations** field lists everything that links to the element it sits on: entries, Matrix and other nested content, products, Navigation menus, Formie forms, global sets, users, in every site. Each row says where it lives, through which field, and in which site.

![See where every element is used](screenshots/usage-list.png)

## Filter by element type

When an element is used by several element types, a dropdown with counts per type joins Craft's own search, sorting, status menu and pagination.

![Filter by element type](screenshots/type-menu.png)

## Table or cards

The field renders Craft's native element index, so it looks and works like the rest of the control panel, including card view and the View menu's extra columns.

![Table or cards](screenshots/cards.png)

## On any element type

Add the field to the field layouts of entries, assets, categories, tags, users, products or global sets, for example on its own tab.

![On any element type](screenshots/entry-tab.png)

## Right in the element index

As an index column, the field shows the first usages and the total, and the index can be sorted by the number of usages.

![Right in the element index](screenshots/index-column.png)

## Plugin settings count, too

Usages that are not elements, such as SEOmatic's global SEO image, appear below the list, linking to their settings page.

![Plugin settings count, too](screenshots/global-usages.png)

## Find what nobody uses <sup>Pro</sup>

A “Used anywhere” rule in Craft's filter, on top of any source and with Craft's bulk actions. Set it to off for everything nobody links to; plugin settings count as a usage. The same rule works in custom sources, for a fixed “Unused” entry in the sidebar.

![Find what nobody uses](screenshots/used-filter.png)

## Replace an element everywhere <sup>Pro</sup>

The field's **Replace…** button guides through two steps: pick the replacement, then tick the usages to replace. Every supported field and plugin is rewritten the way it was read; only elements the user may save, and fields they may edit, are touched.

![Step 1: pick the replacement](screenshots/wizard-step1.png)

![Step 2: choose where to replace it](screenshots/wizard-step2.png)

## Undo in one click <sup>Pro</sup>

The success notice stays until closed and offers Undo. Entries get a revision with a note for every step.

![Undo in one click](screenshots/undo.png)

## History, rollback and revisions <sup>Pro</sup>

Utilities → Element Relations lists every replacement. **Roll back** swaps the reference back and keeps changes made since; **Revert to revisions** returns entries to their revision from before the replacement.

![History, rollback and revisions](screenshots/history.png)

## Permissions <sup>Pro</sup>

Replacing and rolling back are permissions of their own. Everything else follows Craft's view and save permissions: users only see usages they may view.

![Permissions](screenshots/permissions.png)

## Settings

Field settings like Craft's relation fields (view mode, status menu, page size), and plugin settings that can be overridden per environment in `config/element-relations.php`.

![Field settings](screenshots/field-settings.png)

![Plugin settings](screenshots/plugin-settings.png)
