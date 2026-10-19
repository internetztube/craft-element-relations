# Element Relations (Craft CMS plugin)

Shows where an element is used. Relations are extracted on element save into `{{%elementrelations_cache}}` (via queue jobs) and read back through `RelationsModel` (Twig, GraphQL, CP field).

## Working on it
- `dev/dev.sh` creates/starts a Craft 5 dev project in `../craft-element-relations-dev` (PostgreSQL via Homebrew). `up|serve|stop|queue|seed|smoke|reset`.
- `dev/dev.sh smoke` seeds content and runs `dev/smoke.php`, the regression test. Run it after every change, ideally on both databases (`DB_DRIVER=mysql PROJECT_DIR=... dev/dev.sh smoke`). `dev/dev.sh ci` is what GitHub Actions runs (`.github/workflows/ci.yml`); keep the two in sync by changing the script, not the workflow.
- `vendor/bin/phpstan` must stay clean (level 5, `phpstan.neon`).
- Craft 5.3+ only (3.2+). No custom list UI and no build step: the field renders Craft's element index over the virtual `elements/Usage` type (abstract on purpose, see its docblock) filtered by the `usesElement` query param (`behaviors/ElementQueryBehavior`). The only JavaScript is one small inline snippet: the type dropdown (`templates/_field/type-menu.twig`, injected via the `cp.elements.toolbar` hook).
- Extractors are registered in `ExtractorService::FIELD_EXTRACTORS` / `ELEMENT_EXTRACTORS`; non-element usages (plugin settings) in `RelationsModel::GLOBAL_USAGE_PROVIDERS`.
- Editions: `ElementRelations::editions()` offers Pro only when the `features.editions` flag in `config/element-relations.php` is on (the dev seed writes that file); gate Pro behaviour with `ElementRelations::isPro()`, decided per call (never at init). Pro today: `unused()` query param + “Unused” index source (`PRO_UNUSED_TYPES`) + `elements/conditions/UsedConditionRule`, `elements/actions/ReplaceRelations` + `services/ReplaceService` (`PRO_ELEMENT_TYPES`). Detection/display stays free.
- `CHANGELOG.md` has an `Unreleased` section; add user-facing changes there.
