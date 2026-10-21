<?php
/**
 * Status and drafts filters of the unified Usage query, checked against the per-type element queries (the oracle)
 * for every combination. Adds entries in every state ("ST ...") relating to Page B and removes them again.
 */

use craft\elements\Entry;
use internetztube\elementRelations\elements\Usage;

return function (array $ctx): void {
    $elements = Craft::$app->getElements();
    $section = Craft::$app->getEntries()->getSectionByHandle('pages');
    $typeId = $section->getEntryTypes()[0]->id;
    $b = Entry::find()->title('Page B')->one();
    $adminId = craft\elements\User::find()->username('admin')->one()->id;
    $flush = function (): void {
        Craft::$app->trigger(yii\base\Application::EVENT_AFTER_REQUEST);
        Craft::$app->getQueue()->run();
    };
    $cleanup = function () use ($elements, $flush): void {
        foreach (Entry::find()->title('ST *')->status(null)->drafts(null)->provisionalDrafts(null)->all() as $old) {
            $elements->deleteElement($old, true);
        }
        $flush();
    };
    $make = function (string $title, array $attrs = []) use ($section, $typeId, $elements, $b): Entry {
        $e = new Entry(['sectionId' => $section->id, 'typeId' => $typeId, 'title' => $title] + $attrs);
        $e->setFieldValue('relatedEntries', [$b->id]);
        $elements->saveElement($e) or throw new RuntimeException(json_encode($e->getErrors()));
        return $e;
    };

    $cleanup();
    $make('ST pending', ['postDate' => new DateTime('+1 month')]);
    $make('ST expired', ['postDate' => new DateTime('-2 months'), 'expiryDate' => new DateTime('-1 month')]);
    $make('ST disabled', ['enabled' => false]);
    $live = $make('ST live');
    $elements->saveElement(Craft::$app->getDrafts()->createDraft($live, $adminId, 'ST draft'));
    $unpublished = new Entry(['sectionId' => $section->id, 'typeId' => $typeId, 'title' => 'ST unpublished']);
    $unpublished->setFieldValue('relatedEntries', [$b->id]);
    Craft::$app->getDrafts()->saveElementAsDraft($unpublished, $adminId, null, null, false);
    $flush();

    check(array_keys(Usage::statuses()) === ['live', 'pending', 'expired', 'disabled'], 'status menu offers live / pending / expired / disabled');

    $titles = fn($query) => implode(',', array_map(fn($e) => str_replace('Page ', '', $e->title) . ($e->getIsDraft() ? '*' : ''), $query->all()));
    $u = fn(?string $status, ?bool $drafts) => Usage::find()->usesElement($b)->status($status)->drafts($drafts)->site('*');
    check($titles($u('pending', false)) === 'ST pending', 'pending: only the pending entry');
    check($titles($u('expired', false)) === 'ST expired', 'expired: only the expired entry');
    check(str_contains($titles($u('disabled', false)), 'ST disabled') && str_contains($titles($u('disabled', false)), 'A12'), 'disabled: the disabled entries');
    $liveTitles = $titles($u('live', false));
    check(str_contains($liveTitles, 'ST live') && str_contains($liveTitles, 'A1') && !str_contains($liveTitles, 'ST pending') && !str_contains($liveTitles, 'ST expired') && !str_contains($liveTitles, 'disabled'), 'live: live entries only');
    check($titles($u('live', true)) === 'ST live*,ST unpublished*' || $titles($u('live', true)) === 'ST unpublished*,ST live*' || str_contains($titles($u('live', true)), 'ST live*'), 'drafts + live: enabled drafts');

    // the oracle: per-type queries must agree with the unified query, for every target with several element types
    $oracleStatus = function (string $type, ?string $status): ?string {
        $dated = $type === Entry::class || $type === 'craft\\commerce\\elements\\Product';
        return match (true) {
            $status === null, $dated => $status,
            $status === 'live' => 'enabled',
            default => null, // pending/expired can't apply to undated types
        };
    };
    foreach ([$b, $ctx['target']] as $target) {
        $types = (new internetztube\elementRelations\models\RelationsModel($target->id, $target->siteId))->getElementTypes();
        foreach ([null, 'live', 'pending', 'expired', 'disabled'] as $status) {
            foreach ([false, true, null] as $drafts) {
                $unified = (int)Usage::find()->usesElement($target)->status($status)->drafts($drafts)->site('*')->count();
                $expected = 0;
                foreach ($types as $type) {
                    $s = $oracleStatus($type, $status);
                    if ($s === null && $status !== null) {
                        continue;
                    }
                    $expected += (int)$type::find()->usesElement($target)->status($s)->drafts($drafts)->site('*')->count();
                }
                check($unified === $expected, sprintf('%s: status=%s drafts=%s → %d (per-type queries: %d)', $target->title, $status ?? 'all', var_export($drafts, true), $unified, $expected));
            }
        }
    }

    $cleanup();
};
