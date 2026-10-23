<?php

declare(strict_types=1);

/**
 * Status and drafts filters of the unified Usage query, checked against the per-type element queries (the oracle)
 * for every combination. Adds entries in every state ("ST ...") relating to Page B and removes them again.
 */

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Drafts;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sections;
use Internetztube\ElementRelations\Elements\Usage;
use Internetztube\ElementRelations\Models\RelationsModel;

return function (array $ctx): void {
    $section = Sections::getSectionByHandle('pages') ?? throw new RuntimeException('pages');
    $typeId = $section->getEntryTypes()[0]->id;
    $b = Entry::find()->title('Page B')->one() ?? throw new RuntimeException('Page B');
    $adminId = (int)admin()->id;
    $cleanup = function (): void {
        foreach (Entry::find()->title('ST *')->status(null)->drafts(null)->provisionalDrafts(null)->all() as $old) {
            Elements::deleteElement($old, true);
        }
        flushQueue();
    };
    $make = function (string $title, array $attrs = []) use ($section, $typeId, $b): Entry {
        $e = new Entry(['sectionId' => $section->id, 'typeId' => $typeId, 'title' => $title] + $attrs);
        $e->setFieldValue('relatedEntries', [$b->id]);
        Elements::saveElement($e) or fail($e);
        return $e;
    };

    $cleanup();
    $make('ST pending', ['postDate' => new DateTime('+1 month')]);
    $make('ST expired', ['postDate' => new DateTime('-2 months'), 'expiryDate' => new DateTime('-1 month')]);
    $make('ST disabled', ['enabled' => false]);
    $live = $make('ST live');
    Elements::saveElement(Drafts::createDraft($live, $adminId, 'ST draft'));
    $unpublished = new Entry(['sectionId' => $section->id, 'typeId' => $typeId, 'title' => 'ST unpublished']);
    $unpublished->setFieldValue('relatedEntries', [$b->id]);
    Drafts::saveElementAsDraft($unpublished, $adminId, null, null, false);
    flushQueue();

    check(array_keys(Usage::statuses()) === ['live', 'pending', 'expired', 'disabled'], 'status menu offers live / pending / expired / disabled');

    $titles = fn($query) => implode(',', array_map(fn($e) => str_replace('Page ', '', (string)$e->title) . ($e->getIsDraft() ? '*' : ''), $query->all()));
    $u = fn(?string $status, ?bool $drafts) => Usage::find()->usesElement($b)->status($status)->drafts($drafts)->site('*');
    check($titles($u('pending', false)) === 'ST pending', 'pending: only the pending entry');
    check($titles($u('expired', false)) === 'ST expired', 'expired: only the expired entry');
    check(str_contains($titles($u('disabled', false)), 'ST disabled') && str_contains($titles($u('disabled', false)), 'A12'), 'disabled: the disabled entries');
    $liveTitles = $titles($u('live', false));
    check(str_contains($liveTitles, 'ST live') && str_contains($liveTitles, 'A1') && !str_contains($liveTitles, 'ST pending') && !str_contains($liveTitles, 'ST expired') && !str_contains($liveTitles, 'disabled'), 'live: live entries only');
    check(str_contains($titles($u('live', true)), 'ST live*'), 'drafts + live: enabled drafts (' . $titles($u('live', true)) . ')');

    // the oracle: per-type queries must agree with the unified query, for every target with several element types
    $oracleStatus = function (string $type, ?string $status): ?string {
        return match (true) {
            $status === null, $type === Entry::class => $status,
            $status === 'live' => 'enabled',
            default => null, // pending/expired can't apply to undated types
        };
    };
    // (Craft 6 has no categories, tags or global sets: the asset is used by an entry and a user, its photo)
    foreach ([$b, $ctx['target'], $ctx['asset']] as $target) {
        $types = (new RelationsModel((int)$target->id, (int)$target->siteId))->getElementTypes();
        foreach ([null, 'live', 'pending', 'expired', 'disabled'] as $status) {
            foreach ([false, true, null] as $drafts) {
                $unified = Usage::find()->usesElement($target)->status($status)->drafts($drafts)->site('*')->count();
                $expected = 0;
                foreach ($types as $type) {
                    $s = $oracleStatus($type, $status);
                    if ($s === null && $status !== null) {
                        continue;
                    }
                    $expected += $type::find()->usesElement($target)->status($s)->drafts($drafts)->site('*')->count();
                }
                check($unified === $expected, sprintf('%s: status=%s drafts=%s → %d (per-type queries: %d)', $target->title, $status ?? 'all', var_export($drafts, true), $unified, $expected));
            }
        }
    }

    $cleanup();
};
