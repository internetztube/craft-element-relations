<?php
/**
 * Smoke test against a seeded Craft project (see seed.php). Exits non-zero on the first failed assertion.
 * Every `dev/smoke/<name>.php` returns a closure `function (array $ctx): void` with the assertions of one integration.
 *
 * Run: dev/dev.sh smoke   (or: php dev/smoke.php <craft-project-dir>, after seeding and rebuilding the cache)
 */
require ($argv[1] ?? __DIR__) . '/bootstrap.php';
/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

/** `ONLY=matrix,neo` limits the integration seeds/smoke tests to those names (default: all). */
function onlySelected(string $file): bool
{
    $only = array_filter(explode(',', (string)getenv('ONLY')));
    return !$only || in_array(basename($file, '.php'), $only, true);
}

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\models\RelationsModel;

function check(bool $ok, string $what): void
{
    echo($ok ? "  ok   " : "  FAIL ") . $what . "\n";
    if (!$ok) {
        exit(1);
    }
}

/** Runs `$fn` with web request/response components (Craft's index templates read request params and the response). */
function withWebRequest(callable $fn): mixed
{
    $request = Craft::$app->getRequest();
    $response = Craft::$app->getResponse();
    Craft::$app->set('request', ['class' => craft\web\Request::class, 'isCpRequest' => true]);
    Craft::$app->set('response', ['class' => craft\web\Response::class]);
    try {
        return $fn();
    } finally {
        Craft::$app->set('request', $request);
        Craft::$app->set('response', $response);
    }
}

/** Titles of the elements (all sites, all statuses) that relate to `$element`. */
function relatingTitles(ElementInterface $element): array
{
    $titles = array_map(fn($e) => (string)$e, (new RelationsModel($element->id, $element->siteId))->getElements([]));
    sort($titles);
    return $titles;
}

/** Asserts that `$sourceTitle` is among the elements relating to `$target`. */
function checkRelation(ElementInterface $target, string $sourceTitle, string $what): void
{
    $titles = relatingTitles($target);
    check(in_array($sourceTitle, $titles, true), "$what: '$sourceTitle' relates to '$target' (found: " . implode(', ', $titles) . ")");
}

$b = Entry::find()->title('Page B')->one() ?? throw new RuntimeException('Run seed.php first');
/** @var RelationsModel $rel */
$rel = $b->getFieldValue('relations');
$field = Craft::$app->getFields()->getFieldByHandle('relations');

echo "RelationsModel\n";
check($rel instanceof RelationsModel, 'field value is a RelationsModel');
check($rel->getCount() === 12, 'count() = 12');
check($rel->getCountFast() === 12, 'countFast() = 12');
check($rel->getCount(status: 'live') === 11, 'count(status: live) = 11 (excludes the disabled entry)');
check($rel->getCount(status: 'disabled') === 1, 'count(status: disabled) = 1');
check($rel->getCount(sections: 'pages') === 12 && $rel->getCount(sections: 'nope') === 0, 'section filter');
check($rel->getCount(entryTypes: 'page') === 12 && $rel->getCount(entryTypes: 'nope') === 0, 'entry type filter');
check($rel->getIsInUse() && $rel->getIsInUseFast(), 'isInUse / isInUseFast');
check(count($rel->getElements(limit: 10)) === 10 && count($rel->getElements(limit: 10, offset: 10)) === 2, 'limit/offset pagination');
check(count($rel->getSourceElementIds()) === 12, 'getSourceElementIds() = 12 ids');
check(count($rel->getSitesFast()) === 1, 'sitesFast');

echo "Field\n";
$serialized = $field->serializeValue($rel, $b);
check(($serialized['count'] ?? null) === 12 && count($serialized['elementIds'] ?? []) === 12, 'serializeValue() exposes count + elementIds (export)');
check($field->serializeValueForDb($rel, $b) === null, 'serializeValueForDb() stores nothing');

$unused = Entry::find()->title('Page A1')->one();
check($unused->getFieldValue('relations')->getCount() === 0, 'Page A1 is unused');

echo "usesElement query param\n";
$n = (int)Entry::find()->usesElement($b)->status(null)->count();
check($n === 12, "Entry::find()->usesElement(\$element) finds the 12 relating entries (got $n)");
check((int)Entry::find()->usesElement($b->id)->count() === 11, 'usesElement($id) respects the default status filter (11 live)');
$q = Entry::find()->usesElement($b)->status(null);
check((int)(clone $q)->count() === 12, 'usesElement survives cloning the query');
check((int)Craft::configure(Entry::find(), ['usesElement' => $b->id, 'status' => null])->count() === 12, 'usesElement works as criteria (element index)');
check((int)craft\elements\Asset::find()->usesElement($b)->count() === 0, 'no assets use Page B');
check((int)Entry::find()->usesElement(null)->status(null)->count() === (int)Entry::find()->status(null)->count(), 'usesElement(null) does not filter');

echo "Usage element type (one query across all element types)\n";
$usages = Usage::find()->usesElement($b)->status(null)->all();
check(count($usages) === 12 && count(array_filter($usages, fn($e) => $e instanceof Entry)) === 12, 'Usage::find()->usesElement() returns the 12 relating entries as Entry instances');
check((int)Usage::find()->usesElement($b)->status(null)->search('A1')->count() === 4, 'search across the Usage query (A1, A10, A11, A12)');
check((int)Usage::find()->usesElement($b)->status(null)->drafts(true)->count() === 0, 'drafts(true) on the Usage query');
check((int)Usage::find()->usesElement($b)->status('disabled')->count() === 1, 'status filter on the Usage query');
$titles = array_map(fn($e) => $e->title, Usage::find()->usesElement($b)->status(null)->orderBy(['title' => SORT_ASC])->limit(2)->all());
check($titles === ['Page A1', 'Page A10'], 'sorting + limit on the Usage query');

echo "Field UI (Craft's own element index)\n";
$preview = $field->getPreviewHtml($rel, $b);
check(str_contains($preview, 'inline-chips') && str_contains($preview, 'Page A'), 'preview renders Craft element chips');
check(str_contains($preview, 'previewCountBadge'), "preview uses Craft's +N badge");
check(str_contains($preview, '12 total'), 'preview links to the field with the total count');
[$input, $unusedInput] = withWebRequest(fn() => [
    $field->getInputHtml($rel, $b),
    $field->getInputHtml($unused->getFieldValue('relations'), $unused),
]);
check(str_contains($input, 'class="element-index') && str_contains($input, 'search-container') && str_contains($input, 'statusmenubtn'), "input renders Craft's element index with search and status menu");
check(!str_contains($input, 'export-btn') || str_contains($input, 'export-btn" class="btn hidden"'), 'export button stays hidden');
$js = implode("\n", array_merge(...array_values(Craft::$app->getView()->js ?: [[]])));
$colOpts = html_entity_decode($input);
check(str_contains($colOpts, '"attr":"status"') && str_contains($colOpts, '"attr":"postDate"') && str_contains($colOpts, '"attr":"link"'), 'the View menu offers Status, Post Date and Link as table columns');
check(str_contains($js, 'Craft.createElementIndex') && str_contains($js, 'usesElement') && str_contains($js, '"static":true') && str_contains($js, '"canHaveDrafts":true') && str_contains($js, 'Usage'), 'the index JS is bound to the Usage type, read-only, filtered by usesElement');
$settingsHtml = withWebRequest(fn() => $field->getSettingsHtml());
check(str_contains($settingsHtml, 'name="viewMode"') && str_contains($settingsHtml, 'name="showStatusMenu"') && str_contains($settingsHtml, 'name="pageSize"'), 'field settings offer View Mode, status menu and page size');
$configured = clone $field;
$configured->viewMode = 'cards';
$configured->showStatusMenu = false;
$configured->pageSize = 5;
check($configured->validate(), 'field settings validate');
$configured->pageSize = 0;
check(!$configured->validate() && $configured->pageSize === 0, 'page size must be at least 1');
$configured->pageSize = 5;
Craft::$app->getView()->clear();
$configuredInput = withWebRequest(fn() => $configured->getInputHtml($rel, $b));
$configuredJs = implode("\n", array_merge(...array_values(Craft::$app->getView()->js ?: [[]])));
check(str_contains($input, 'data-status="live"') && !str_contains($configuredInput, 'data-status="live"'), 'field setting: statuses hidden (Craft keeps Drafts/Trashed for admins)');
check(str_contains($configuredJs, '"batchSize":5'), 'field setting: 5 elements per page');
check(str_contains($configuredInput, 'cards'), 'field setting: cards view');
$a1 = Entry::find()->title('Page A1')->one();
check($a1->getAttributeHtml(Usage::ATTR_TYPE) === 'Entry' && $a1->getAttributeHtml(Usage::ATTR_WHERE) === 'Pages › Page' && $a1->getAttributeHtml(Usage::ATTR_SITE) !== '', 'index columns Type / Where / Site come from the attribute HTML hook');
check(str_contains($a1->getAttributeHtml('dateUpdated'), '<'), "Craft's own Date Updated column still works on the real element");
$a1Usage = Usage::find()->usesElement($b)->status(null)->id($a1->id)->one();
$a1Usage->setAuthorIds([craft\elements\User::find()->admin()->one()->id]); // seeded entries have no author
$authorsHtml = $a1Usage->getAttributeHtml('authors'); // Craft Solo has no Authors column
check((Craft::$app->edition === craft\enums\CmsEdition::Solo ? $authorsHtml === '' : str_contains($authorsHtml, 'chip')) && str_contains($a1Usage->getAttributeHtml('status'), 'status-label') && $a1Usage->getAttributeHtml('postDate') !== '' && str_contains($a1Usage->getAttributeHtml('link'), 'href'), 'Authors / Status / Post Date / Link columns render on an entry loaded through Usage');
$assetUsage = Asset::find()->volume('test')->one();
Usage::$via["$assetUsage->id:$assetUsage->siteId"] ??= [];
check($assetUsage->getAttributeHtml('authors') === '' && $assetUsage->getAttributeHtml('postDate') === '' && str_contains($assetUsage->getAttributeHtml('filename'), '.png'), 'columns an element type lacks render empty in a Usage index (asset: Authors, Post Date), its own still work (Filename)');
$a1Loaded = Usage::find()->usesElement($b)->status(null)->id($a1->id)->one();
$via = $a1Loaded->getAttributeHtml(Usage::ATTR_FIELD);
check($via === 'Related Entries', 'Field column names the field (no link: Craft drops URL hashes)');
$unusedHtml = $field->getPreviewHtml($unused->getFieldValue('relations'), $unused);
check(str_contains($unusedHtml, 'Unused') && str_contains($unusedHtml, 'status-label'), 'preview shows a Craft "Unused" status label for Page A1');
check(str_contains($unusedInput, 'status-label'), 'input shows the "Unused" status label for Page A1');

echo "GraphQL\n";
$schema = new craft\models\GqlSchema(['scope' => [
    'sections.' . $b->getSection()->uid . ':read',
    'entrytypes.' . $b->getType()->uid . ':read',
]]);
$query = '{ entry(id: ' . $b->id . ') { ... on page_Entry { relations {
    count liveCount: count(status: ["live"]) isInUse
    elements(limit: 2, status: ["disabled"]) { id title }
} } } }';
$result = Craft::$app->getGql()->executeQuery($schema, $query);
$gql = $result['data']['entry']['relations'] ?? null;
check($gql !== null, 'query succeeded: ' . json_encode($result['errors'] ?? null));
check($gql['count'] === 12 && $gql['liveCount'] === 11 && $gql['isInUse'] === true, 'count / count(status) / isInUse');
check(count($gql['elements']) === 1 && $gql['elements'][0]['title'] === 'Page A12', 'elements(status: ["disabled"]) returns only Page A12');

echo "Live save path (save event -> queued refresh job -> cache)\n";
// Seeds never fire EVENT_AFTER_REQUEST, so everything above was built by resave-relations. This exercises the real flow.
$c = Entry::find()->title('Page C')->one();
$section = Craft::$app->getEntries()->getSectionByHandle('pages');
$live = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => 'Page via save event']);
$live->setFieldValue('relatedEntries', [$c->id]);
Craft::$app->getElements()->saveElement($live) or throw new RuntimeException(json_encode($live->getErrors()));
Craft::$app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
Craft::$app->getQueue()->run();
check(in_array('Page via save event', relatingTitles($c), true), 'saving an entry queues a refresh that records its relation');
Craft::$app->getElements()->deleteElement($live, true);
check(!in_array('Page via save event', relatingTitles($c), true), 'deleting the entry removes its relation');

echo "Cache hygiene\n";
$failedJobs = (new craft\db\Query())->select(['description', 'error'])->from('{{%queue}}')->where(['fail' => true])->all();
check(!$failedJobs, 'no failed queue jobs' . ($failedJobs ? ': ' . json_encode($failedJobs) : ''));
$revisionRows = (new craft\db\Query())
    ->from(['c' => '{{%elementrelations_cache}}'])
    ->innerJoin(['e' => craft\db\Table::ELEMENTS], '[[e.id]] = [[c.sourceElementId]]')
    ->where(['not', ['e.revisionId' => null]])
    ->count();
check((int)$revisionRows === 0, 'cache holds no rows for revisions');

$ctx = [
    'target' => Entry::find()->title('Page C')->one(),
    'asset' => Asset::find()->volume('test')->orderBy(['elements.id' => SORT_ASC])->one(),
];
foreach (glob(__DIR__ . '/smoke/*.php') as $file) {
    if (!onlySelected($file)) {
        continue;
    }
    echo ucfirst(basename($file, '.php')) . "\n";
    (require $file)($ctx);
}

echo "All good.\n";
