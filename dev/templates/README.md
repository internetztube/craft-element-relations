# Transit: the demo front end

A small travel journal on the demo content (`dev/demo.php`), showing what the plugin's data is for on a public site.
`dev/dev.sh seed` links this folder in as the dev project's `templates/` and gives the sections their templates;
open http://localhost:8080 after `dev/dev.sh up`.

The use case: **reverse relations without a reverse field.** Stories point to destinations, writers and photos; the
pages of those list the stories pointing to them. Nobody maintains a second field, nothing gets out of sync.

| Page | What it shows | How |
| --- | --- | --- |
| Destination (`destinations/_entry.twig`) | Stories from here, paginated | `craft.entries().section('journal').usesElement(entry)` with `{% paginate %}` |
| | Everything else pointing here, the total | `entry.usedIn.isInUse`, `.elements(limit: 8)`, `.count` |
| Article (`journal/_entry.twig`) | This photo also appears in … | `hero.usedIn.elements(sections: ['journal'], limit: 4)` |
| | Linked from (backlinks) | `entry.usedIn.elements(limit: 10)` |
| Writer (`team/_entry.twig`) | Their stories, with the number | `entry.usedIn.count(sections: ['journal'])`, `.elements(sections: ['journal'], limit: 12)` |
| Homepage (`index.twig`) | Destinations ranked by stories | `destination.usedIn.count(sections: ['journal'])` in a `sort` |
| Journal (`journal/index.twig`) | `?destination=hong-kong` | `.usesElement(destination)` on an ordinary query |

`usedIn` is the demo's Element Relations field. On the front end its `count`, `isInUse` and `elements` default to
what visitors may see (live entries, enabled elements, no drafts); `count` and `isInUse` are one SQL query each, and
`elements` takes a `limit`, so this stays fast with thousands of usages.
