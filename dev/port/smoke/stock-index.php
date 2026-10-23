<?php
/**
 * Stock sorting and filtering on the usage list: Craft's own sort options and filter rules of the element types in the
 * list, on UsageQuery's stock params (sectionId, filename, email, …) and its joined tables.
 */

use craft\elements\Entry;
use internetztube\elementRelations\elements\Usage;

return function (array $ctx): void {
    $c = $ctx['target'];
    $q = fn() => Craft::configure(Usage::find()->site('*')->status(null), ['usesElement' => $c->id]);
    $pages = Craft::$app->getEntries()->getSectionByHandle('pages');
    $entries = (int)$q()->elementTypes(Entry::class)->count();
    check($entries > 0 && (int)$q()->sectionId($pages->id)->count() === $entries && (int)$q()->sectionId(['not', $pages->id])->count() === 0 && (int)$q()->section('*')->count() === $entries, "stock params: sectionId matches the $entries entries, 'not' none, section('*') the entries only");
    check((int)$q()->filename('*.png')->count() === 0 && (int)$q()->postDate('>= 2000-01-01')->count() === $entries, 'a param of another type matches nothing (filename on entries); postDate matches the entries');
    $admin = craft\elements\User::find()->username('admin')->one();
    check(internetztube\elementRelations\elements\db\UsageQuery::hiddenCount([(int)$c->id], $admin) === 0, 'an admin may see every usage (none hidden)');
    $firstPost = $q()->elementTypes(Entry::class)->orderBy(['entries.postDate' => SORT_ASC])->one();
    check((int)$q()->postDate($firstPost->postDate)->count() >= 1 && in_array((int)$firstPost->id, $q()->postDate($firstPost->postDate)->ids(), true), 'a date param as a DateTime matches that moment, as on Craft\'s queries');
    $rows = $q()->asArray()->all();
    check($rows && is_array($rows[0]) && isset($rows[0]['id']), 'asArray() gives the rows as arrays');
    check((int)$q()->authorId($admin->id)->count() > 0, "authorId finds the admin's entries among the usages");
    $qa = fn() => Craft::configure(Usage::find()->site('*')->status(null), ['usesElement' => $ctx['asset']->id]);
    check((int)$qa()->email('*@*')->count() >= 1 && (int)$qa()->email('nobody@nowhere.test')->count() === 0, 'email matches the user whose photo the asset is');

    // every sort option, Craft's own included, makes a valid query
    Usage::$listedTypes = Usage::STOCK_TYPES;
    $attributes = [];
    foreach (Usage::sortOptions() as $key => $option) {
        $option = is_string($option) ? ['label' => $option, 'orderBy' => $key, 'attribute' => $key] : $option;
        $orderBy = $option['orderBy'];
        $order = $orderBy instanceof Closure ? $orderBy(SORT_DESC, Craft::$app->getDb()) : [$orderBy => SORT_DESC];
        check(count($q()->orderBy($order)->ids()) === (int)$q()->count(), "sort by {$option['label']} works");
        $attributes[] = $option['attribute'] ?? $key;
    }
    check(!array_diff(['section', 'type', 'postDate', 'filename', 'size', 'group', 'email'], $attributes), "Craft's own sort options of entries, assets, categories, tags and users are offered (" . implode(', ', $attributes) . ')');
    $ruleClasses = fn(): array => array_map(fn($rule) => $rule::class, Usage::createCondition()->getSelectableConditionRules());
    $rules = $ruleClasses();
    check(in_array(craft\elements\conditions\entries\SectionConditionRule::class, $rules, true) && in_array(craft\elements\conditions\assets\FilenameConditionRule::class, $rules, true) && in_array(craft\elements\conditions\users\EmailConditionRule::class, $rules, true), "the filter offers Craft's own rules of those types (" . count($rules) . ' rules)');
    Usage::$listedTypes = [Entry::class];
    $rules = $ruleClasses();
    check(in_array(craft\elements\conditions\entries\SectionConditionRule::class, $rules, true) && !in_array(craft\elements\conditions\assets\FilenameConditionRule::class, $rules, true), 'a list of entries only offers entry rules, no Filename');
    $condition = Usage::createCondition();
    $condition->addConditionRule(Craft::createObject(['class' => craft\elements\conditions\entries\SectionConditionRule::class, 'values' => [$pages->uid]]));
    $filtered = $q();
    $condition->modifyQuery($filtered);
    check((int)$filtered->count() === $entries, 'a Section rule on the list keeps exactly the entries of that section');
    $broken = Usage::createCondition();
    $broken->addConditionRule(Craft::createObject(['class' => craft\elements\conditions\entries\SectionConditionRule::class, 'values' => [$pages->uid], 'operator' => 'no such operator']));
    $unfiltered = $q();
    $broken->modifyQuery($unfiltered);
    check((int)$unfiltered->count() === (int)$q()->count(), 'a misconfigured rule is skipped, as Craft does, and the list stays whole');
    Usage::$listedTypes = null;

    // the stock params as a template chains them: each narrows to its own type, matching none of the others
    $type = $pages->getEntryTypes()[0];
    $own = fn() => Craft::configure(Entry::find()->site('*')->status(null), ['usesElement' => $c->id]); // Craft's own entry query as the oracle
    check((int)$q()->typeId($type->id)->count() === (int)$own()->typeId($type->id)->count() && (int)$q()->expiryDate(':empty:')->count() === (int)$own()->expiryDate(':empty:')->count() && (int)$q()->typeId($type->id)->count() > 0, 'typeId and expiryDate on entries count what Craft counts for them');
    check((int)$q()->expiryDate(':empty:')->count() === (int)$own()->expiryDate(':empty:')->count() && (int)$q()->expiryDate(':empty:')->elementTypes(craft\elements\Asset::class)->count() === 0, '“expiry date is empty” narrows the list to entries without one, not every asset');
    $volumeId = (int)$ctx['asset']->volumeId;
    check((int)$qa()->volumeId($volumeId)->count() === (int)$qa()->volumeId($volumeId)->elementTypes(craft\elements\Asset::class)->count() && (int)$q()->volumeId($volumeId)->count() === 0, 'volumeId matches assets only');
    check((int)$q()->kind('image')->count() === 0 && (int)$q()->size('> 0')->count() === 0 && (int)$q()->width('> 0')->count() === 0 && (int)$q()->height('> 0')->count() === 0 && (int)$q()->dateModified('> 2000-01-01')->count() === 0, 'kind, size, width, height, dateModified match no entries');
    // category and tag groups are numbered separately: Craft's group rules filter their own type only
    $tag = craft\elements\Tag::find()->site('*')->status(null)->usesElement($c->id)->one();
    $category = craft\elements\Category::find()->site('*')->status(null)->usesElement($c->id)->one();
    $byRule = function (string $rule, string $uid) use ($q): int {
        $condition = Usage::createCondition();
        $condition->addConditionRule(Craft::createObject(['class' => $rule, 'values' => [$uid]]));
        $query = $q();
        $condition->modifyQuery($query);
        return (int)$query->count();
    };
    $tagGroup = $tag?->getGroup();
    $categoryGroup = $category?->getGroup();
    check($tagGroup && $categoryGroup && $byRule(craft\elements\conditions\tags\GroupConditionRule::class, $tagGroup->uid) === (int)craft\elements\Tag::find()->site('*')->status(null)->usesElement($c->id)->groupId($tagGroup->id)->count()
        && $byRule(craft\elements\conditions\categories\GroupConditionRule::class, $categoryGroup->uid) === (int)craft\elements\Category::find()->site('*')->status(null)->usesElement($c->id)->groupId($categoryGroup->id)->count()
        && (int)$q()->tagGroupId($categoryGroup->id)->elementTypes(craft\elements\Category::class)->count() === 0
        && (int)$q()->categoryGroupId($categoryGroup->id)->count() === (int)craft\elements\Category::find()->site('*')->status(null)->usesElement($c->id)->groupId($categoryGroup->id)->count()
        && (int)$q()->groupId($tagGroup->id)->elementTypes(Entry::class)->count() === 0, "the Group rules of tags and categories each match their own type, as Craft counts them, even where the group ids overlap; groupId matches no entries");
    check((int)$q()->username('*')->count() === 0 && (int)$q()->firstName('*')->count() === 0 && (int)$q()->lastName('*')->count() === 0 && (int)$q()->lastLoginDate('> 2000-01-01')->count() === 0, 'the user params match no entries');

    // a row the viewer may not see (the list's last line of defence, see UsageQuery::populate()): only its type
    $a1 = Entry::find()->title('Page A1')->one();
    $restricted = internetztube\elementRelations\elements\RestrictedUsage::fromElement($a1);
    $admin = craft\elements\User::find()->admin()->one();
    check((string)$restricted === Craft::t('element-relations', 'restricted-title') && $restricted::displayName() === Craft::t('element-relations', 'restricted-title') && $restricted->getCpEditUrl() === null, 'a restricted row has no title of its own and no link');
    check(!$restricted->canView($admin) && !$restricted->canSave($admin) && $restricted::hasTitles() && $restricted::isLocalized() && $restricted::tableAttributes() === [], 'it can be neither viewed nor saved, and brings no columns of its own');
    check($restricted->getAttributeHtml(Usage::ATTR_TYPE) === 'Entry' && $restricted->getAttributeHtml(Usage::ATTR_SITE) !== '' && $restricted->getAttributeHtml(Usage::ATTR_FIELD) === '' && $restricted->getAttributeHtml('postDate') === '', 'its row shows the type and site, nothing else');
};
