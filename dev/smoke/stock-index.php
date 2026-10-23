<?php

declare(strict_types=1);

/**
 * Stock sorting and filtering on the usage list: Craft's own sort options and filter rules of the element types in the
 * list, on UsageQuery's stock params (sectionId, filename, email, …) and its joined tables.
 */

use CraftCms\Cms\Asset\Conditions\FilenameConditionRule;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\ElementIndexes;
use CraftCms\Cms\Entry\Conditions\SectionConditionRule;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Conditions;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\User\Conditions\EmailConditionRule;
use CraftCms\Cms\User\Elements\User;
use Internetztube\ElementRelations\Elements\Queries\UsageQuery;
use Internetztube\ElementRelations\Elements\RestrictedUsage;
use Internetztube\ElementRelations\Elements\Usage;

use function CraftCms\Cms\t;

return function (array $ctx): void {
    $c = $ctx['target'];
    $q = fn(): UsageQuery => Usage::find()->site('*')->status(null)->usesElement($c->id);
    $pages = Sections::getSectionByHandle('pages') ?? throw new RuntimeException('pages');
    $entries = $q()->elementTypes(Entry::class)->count();
    check($entries > 0 && $q()->sectionId($pages->id)->count() === $entries && $q()->sectionId(['not', $pages->id])->count() === 0 && $q()->section('*')->count() === $entries, "stock params: sectionId matches the $entries entries, 'not' none, section('*') the entries only");
    check($q()->filename('*.png')->count() === 0 && $q()->postDate('>= 2000-01-01')->count() === $entries, 'a param of another type matches nothing (filename on entries); postDate matches the entries');
    $admin = admin();
    check(UsageQuery::hiddenCount([(int)$c->id], $admin) === 0, 'an admin may see every usage (none hidden)');
    $firstPost = $q()->elementTypes(Entry::class)->orderBy('entries.postDate')->one();
    assert($firstPost instanceof Entry);
    check($q()->postDate($firstPost->postDate)->count() >= 1 && in_array((int)$firstPost->id, array_map('intval', $q()->postDate($firstPost->postDate)->ids()), true), 'a date param as a DateTime matches that moment, as on Craft\'s queries');
    $rows = $q()->asArray()->all();
    check($rows && is_array($rows[0]) && isset($rows[0]['id']), 'asArray() gives the rows as arrays');
    // the seed saves its entries from the console, without an author: give one to the usage for this check
    $authored = Entry::find()->id($firstPost->id)->one() ?? throw new RuntimeException('first post');
    $authorsBefore = $authored->getAuthorIds();
    if (!$authorsBefore) {
        $authored->setAuthorIds([(int)$admin->id]);
        Elements::saveElement($authored) or fail($authored);
    }
    check($q()->authorId($admin->id)->count() > 0, "authorId finds the admin's entries among the usages");
    if (!$authorsBefore) {
        $authored->setAuthorIds([]);
        Elements::saveElement($authored) or fail($authored);
        flushQueue();
    }
    $qa = fn(): UsageQuery => Usage::find()->site('*')->status(null)->usesElement($ctx['asset']->id);
    check($qa()->email('*@*')->count() >= 1 && $qa()->email('nobody@nowhere.test')->count() === 0, 'email matches the user whose photo the asset is');

    // every sort option, Craft's own included, makes a valid query (sorted the way the element index sorts)
    Usage::$listedTypes = Usage::STOCK_TYPES;
    $attributes = [];
    foreach (Usage::sortOptions() as $key => $option) {
        $option = is_string($option) ? ['label' => $option, 'orderBy' => $key, 'attribute' => $key] : $option;
        $attribute = $option['attribute'] ?? $key;
        $sorted = $q();
        app(ElementIndexes::class)->applySort(Usage::class, $sorted, '*', [['field' => $attribute, 'direction' => 'desc']]);
        check(count($sorted->ids()) === $q()->count(), "sort by {$option['label']} works");
        $attributes[] = $attribute;
    }
    check(!array_diff(['section', 'type', 'postDate', 'filename', 'size', 'email'], $attributes), "Craft's own sort options of entries, assets and users are offered (" . implode(', ', $attributes) . ')');
    $ruleClasses = fn(): array => array_map(fn($rule) => $rule::class, Usage::createCondition()->getSelectableConditionRules());
    $rules = $ruleClasses();
    check(in_array(SectionConditionRule::class, $rules, true) && in_array(FilenameConditionRule::class, $rules, true) && in_array(EmailConditionRule::class, $rules, true), "the filter offers Craft's own rules of those types (" . count($rules) . ' rules)');
    Usage::$listedTypes = [Entry::class];
    $rules = $ruleClasses();
    check(in_array(SectionConditionRule::class, $rules, true) && !in_array(FilenameConditionRule::class, $rules, true), 'a list of entries only offers entry rules, no Filename');
    $condition = Usage::createCondition();
    $condition->addConditionRule(Conditions::createConditionRule(['class' => SectionConditionRule::class, 'values' => [$pages->uid]]));
    $filtered = $q();
    $condition->modifyQuery($filtered);
    check($filtered->count() === $entries, 'a Section rule on the list keeps exactly the entries of that section');
    $broken = Usage::createCondition();
    $broken->addConditionRule(Conditions::createConditionRule(['class' => SectionConditionRule::class, 'values' => [$pages->uid], 'operator' => 'no such operator']));
    $unfiltered = $q();
    $broken->modifyQuery($unfiltered);
    check($unfiltered->count() === $q()->count(), 'a misconfigured rule is skipped, as Craft does, and the list stays whole');
    Usage::$listedTypes = null;

    // the stock params as a template chains them: each narrows to its own type, matching none of the others
    $type = $pages->getEntryTypes()[0];
    $own = fn() => Entry::find()->site('*')->status(null)->usesElement($c->id); // Craft's own entry query as the oracle
    check($q()->typeId($type->id)->count() === $own()->typeId($type->id)->count() && $q()->expiryDate(':empty:')->count() === $own()->expiryDate(':empty:')->count() && $q()->typeId($type->id)->count() > 0, 'typeId and expiryDate on entries count what Craft counts for them');
    check($q()->expiryDate(':empty:')->count() === $own()->expiryDate(':empty:')->count() && $q()->expiryDate(':empty:')->elementTypes(Asset::class)->count() === 0, '“expiry date is empty” narrows the list to entries without one, not every asset');
    $volumeId = (int)$ctx['asset']->volumeId;
    check($qa()->volumeId($volumeId)->count() === $qa()->volumeId($volumeId)->elementTypes(Asset::class)->count() && $q()->volumeId($volumeId)->count() === 0, 'volumeId matches assets only');
    check($q()->kind('image')->count() === 0 && $q()->size('> 0')->count() === 0 && $q()->width('> 0')->count() === 0 && $q()->height('> 0')->count() === 0 && $q()->dateModified('> 2000-01-01')->count() === 0, 'kind, size, width, height, dateModified match no entries');
    check($q()->username('*')->count() === 0 && $q()->firstName('*')->count() === 0 && $q()->lastName('*')->count() === 0 && $q()->lastLoginDate('> 2000-01-01')->count() === 0, 'the user params match no entries');

    // a row the viewer may not see (the list's last line of defence, see UsageQuery::hydrate()): only its type
    $a1 = Entry::find()->title('Page A1')->one() ?? throw new RuntimeException('A1');
    $restricted = RestrictedUsage::fromElement($a1);
    check((string)$restricted === t('restricted-title', category: 'element-relations') && $restricted::displayName() === t('restricted-title', category: 'element-relations') && $restricted->getCpEditUrl() === null, 'a restricted row has no title of its own and no link');
    check(!$restricted->canView($admin) && !$restricted->canSave($admin) && $restricted::hasTitles() && $restricted::isLocalized() && $restricted::tableAttributes() === [], 'it can be neither viewed nor saved, and brings no columns of its own');
    check($restricted->getAttributeHtml(Usage::ATTR_TYPE) === 'Entry' && $restricted->getAttributeHtml(Usage::ATTR_SITE) !== '' && $restricted->getAttributeHtml(Usage::ATTR_FIELD) === '' && $restricted->getAttributeHtml('postDate') === '', 'its row shows the type and site, nothing else');
};
