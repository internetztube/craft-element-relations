<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\jobs\ReplaceElementJob;
use internetztube\elementRelations\models\RelationsModel;
use internetztube\elementRelations\records\ReplacementRecord;
use internetztube\elementRelations\services\extractors\InterfaceElementReplacer;
use internetztube\elementRelations\services\extractors\InterfaceFieldReplacer;

/**
 * Pro: replaces usages of one element by another of the same type, going through the relating elements' field values
 * and Craft's save path (cache, search index and revisions stay consistent; entries get a revision with a note).
 *
 * Each extractor is also the replacer for its field or element type (InterfaceFieldReplacer / InterfaceElementReplacer),
 * so whatever can be detected can be swapped. Usages without a replacer (plugin settings) are reported as "manual".
 *
 * Every operation is logged (records\ReplacementRecord) and can be rolled back: the replaced reference is swapped back,
 * later edits to the element stay. Where the replacement was already referenced before (a relation field that held
 * both), the field's previous value is restored instead, as long as the field was not changed since.
 */
class ReplaceService
{
    /**
     * @param int[]|null $onlySourceIds restrict to these relating elements as listed in the field (top-level owners;
     * nested entries, navigation nodes etc. are matched through their owner); null = everywhere
     * @param ReplacementRecord|null $operation a queued operation to run (ReplaceElementJob); null = start a new one
     * @param callable|null $progress called with 0..1 while running (queue job progress)
     * @param bool $allowQueue run as a queue job when more elements than the `replaceSyncLimit` setting are affected
     * @return array{replaced: int, skipped: string[], operationId: int|null, queued: bool}
     */
    public static function replace(
        ElementInterface $from,
        ElementInterface $to,
        ?array $onlySourceIds = null,
        ?ReplacementRecord $operation = null,
        ?callable $progress = null,
        bool $allowQueue = false,
    ): array {
        if ($from::class !== $to::class || $from->id === $to->id) {
            throw new \InvalidArgumentException('Replacement must be a different element of the same type.');
        }

        $rows = (new Query())
            ->select(['sourceElementId', 'sourceSiteId', 'fieldId', 'type'])
            ->distinct()
            ->from('{{%elementrelations_cache}}')
            ->where(['targetElementId' => $from->id])
            ->andWhere(['not', ['sourceElementId' => null]]) // non-element usages are reported below
            ->andFilterWhere(['sourcePrimaryOwnerId' => $onlySourceIds])
            ->all();
        $types = (new Query())->select(['id', 'type'])->from(Table::ELEMENTS)->where(['id' => array_column($rows, 'sourceElementId')])->pairs();

        $bySource = [];
        foreach ($rows as $row) {
            $bySource["{$row['sourceElementId']}:{$row['sourceSiteId']}"][] = $row;
        }

        $userId = $operation?->userId ?? self::currentUserId();
        if (!$operation && $allowQueue && count($types) > ElementRelations::getInstance()->getSettings()->replaceSyncLimit) {
            $operation = self::newOperation($from, $to, $onlySourceIds, $userId, ReplacementRecord::STATUS_QUEUED);
            Craft::$app->getQueue()->push(new ReplaceElementJob(['operationId' => $operation->id]));
            return ['replaced' => 0, 'skipped' => [], 'operationId' => $operation->id, 'queued' => true];
        }

        $user = self::permissionUser($userId);
        $notes = Craft::t('element-relations', 'replace-revision-notes', ['from' => (string)$from, 'to' => (string)$to]);
        $replaced = 0;
        $skipped = [];
        $changes = [];
        $saved = []; // element ids rewritten (in whichever site)
        $pending = []; // "not rewritten" per element id; decided once every site has been seen
        $done = 0;
        foreach ($bySource as $key => $sourceRows) {
            if ($progress) {
                $progress($done++ / max(1, count($bySource)));
            }
            [$id, $siteId] = array_map('intval', explode(':', $key));
            $type = $types[$id] ?? null;
            $element = self::load($type, $id, $siteId);
            if (!$element) {
                continue;
            }
            if ($user && !$element->canSave($user)) {
                $skipped[] = (string)$element . ' (' . Craft::t('element-relations', 'replace-no-permission') . ')';
                continue;
            }

            $elementChanges = [];
            foreach ($sourceRows as $row) {
                $field = $row['fieldId'] ? Craft::$app->getFields()->getFieldById((int)$row['fieldId']) : null;
                $field = $field instanceof Field ? $field : null;
                if ($row['fieldId'] && !$field) {
                    $pending[$id][] = (string)$element;
                    continue;
                }
                $preHadTo = in_array($to->id, ExtractorService::targetIds($element, $field), true);
                $before = $field ? $field->serializeValue($element->getFieldValue($field->handle), $element) : null;
                $ok = $field ? self::replaceInField($field, $element, $from, $to) : self::replaceInElement($element, $from, $to);
                if ($ok) {
                    $elementChanges[] = ['elementId' => $id, 'siteId' => $siteId, 'fieldId' => $field?->id, 'preHadTo' => $preHadTo, 'before' => $before];
                } else {
                    $pending[$id][] = $field ? "{$field->name}: $element" : (string)$element;
                }
            }
            if (!$elementChanges) {
                continue;
            }
            $element->setRevisionNotes($notes);
            if ($userId) {
                $element->setRevisionCreatorId($userId);
            }
            if (!Craft::$app->getElements()->saveElement($element)) {
                $skipped[] = (string)$element . ' (' . implode(' ', $element->getFirstErrors()) . ')';
                continue;
            }
            $replaced++;
            $saved[$id] = true;
            $fresh = self::load($type, $id, $siteId);
            foreach ($elementChanges as $change) {
                $field = $change['fieldId'] ? Craft::$app->getFields()->getFieldById($change['fieldId']) : null;
                $change['after'] = $field && $fresh ? $field->serializeValue($fresh->getFieldValue($field->handle), $fresh) : null;
                $changes[] = $change;
            }
        }

        // Data shared across sites (non-translatable fields, Formie forms) is swapped by whichever site got saved first;
        // the other sites then hold no reference anymore and Craft fires no save event for them. So: an element saved
        // in any site is refreshed in every site it exists in, and only elements saved nowhere count as skipped.
        foreach ($pending as $id => $labels) {
            if (!isset($saved[$id])) {
                $skipped = [...$skipped, ...$labels];
            }
        }
        self::refreshEverySite(array_keys($saved), $types);

        if ($onlySourceIds === null) {
            foreach ((new RelationsModel($from->id, $from->siteId))->getGlobalUsages() as $usage) {
                $skipped[] = $usage->label;
            }
        }
        $skipped = array_values(array_unique($skipped));

        if ($changes || $operation) {
            $operation ??= self::newOperation($from, $to, $onlySourceIds, $userId, ReplacementRecord::STATUS_APPLIED);
            $operation->status = ReplacementRecord::STATUS_APPLIED;
            $operation->changes = json_encode($changes);
            $operation->result = json_encode(['replaced' => $replaced, 'skipped' => $skipped]);
            $operation->save(false);
        }
        if ($progress) {
            $progress(1);
        }

        return ['replaced' => $replaced, 'skipped' => $skipped, 'operationId' => $operation?->id, 'queued' => false];
    }

    /**
     * Undoes a replace operation: swaps the replacement back for the original in every element it changed.
     * @return array{restored: int, skipped: string[]}
     */
    public static function rollback(ReplacementRecord $operation): array
    {
        if ($operation->status !== ReplacementRecord::STATUS_APPLIED) {
            throw new \InvalidArgumentException('Only applied operations can be rolled back.');
        }
        $elements = Craft::$app->getElements();
        $from = $elements->getElementById($operation->fromId, null, $operation->siteId);
        $to = $from ? $elements->getElementById($operation->toId, $from::class, $operation->siteId) : null;
        $userId = self::currentUserId();
        $user = self::permissionUser($userId);
        $notes = $from && $to ? Craft::t('element-relations', 'rollback-revision-notes', ['from' => (string)$from, 'to' => (string)$to]) : null;

        $byElement = [];
        foreach ($operation->getChanges() as $change) {
            $byElement["{$change['elementId']}:{$change['siteId']}"][] = $change;
        }
        $types = (new Query())->select(['id', 'type'])->from(Table::ELEMENTS)->where(['id' => array_map(fn($k) => (int)explode(':', $k)[0], array_keys($byElement))])->pairs();

        $restored = 0;
        $skipped = [];
        $saved = [];
        foreach ($byElement as $key => $changes) {
            [$id, $siteId] = array_map('intval', explode(':', $key));
            $element = self::load($types[$id] ?? null, $id, $siteId);
            if (!$element || !$from || !$to) {
                $skipped[] = Craft::t('element-relations', 'rollback-gone', ['id' => $id]);
                continue;
            }
            if ($user && !$element->canSave($user)) {
                $skipped[] = (string)$element . ' (' . Craft::t('element-relations', 'replace-no-permission') . ')';
                continue;
            }
            $changed = false;
            foreach ($changes as $change) {
                $field = $change['fieldId'] ? Craft::$app->getFields()->getFieldById($change['fieldId']) : null;
                $field = $field instanceof Field ? $field : null;
                $label = $field ? "{$field->name}: $element" : (string)$element;
                if (!$change['preHadTo']) {
                    // the replacement only got there through this operation: swap it back, keep later edits
                    $ok = $field ? self::replaceInField($field, $element, $to, $from) : self::replaceInElement($element, $to, $from);
                } elseif ($field && json_encode($field->serializeValue($element->getFieldValue($field->handle), $element)) === json_encode($change['after'])) {
                    // it was referenced before too: restore the previous value exactly, the field is unchanged since
                    $element->setFieldValue($field->handle, $change['before']);
                    $ok = true;
                } else {
                    $ok = false;
                }
                if ($ok) {
                    $changed = true;
                } else {
                    $skipped[] = $label . ' (' . Craft::t('element-relations', 'rollback-changed-since') . ')';
                }
            }
            if (!$changed) {
                continue;
            }
            if ($notes) {
                $element->setRevisionNotes($notes);
            }
            if ($userId) {
                $element->setRevisionCreatorId($userId);
            }
            if ($elements->saveElement($element)) {
                $restored++;
                $saved[$id] = true;
            } else {
                $skipped[] = (string)$element . ' (' . implode(' ', $element->getFirstErrors()) . ')';
            }
        }
        self::refreshEverySite(array_keys($saved), $types);

        $operation->status = ReplacementRecord::STATUS_ROLLED_BACK;
        $operation->result = json_encode([...$operation->getResult(), 'restored' => $restored, 'rollbackSkipped' => $skipped]);
        $operation->save(false);
        return ['restored' => $restored, 'skipped' => $skipped];
    }

    /**
     * Swaps `$from` for `$to` in the element's value of this field. The field extractors double as replacers
     * (InterfaceFieldReplacer); the first one that recognises the field type decides. False = not supported / no change.
     */
    public static function replaceInField(Field $field, ElementInterface $element, ElementInterface $from, ElementInterface $to): bool
    {
        foreach (array_filter(ExtractorService::FIELD_EXTRACTORS, 'class_exists') as $extractor) {
            if (!is_subclass_of($extractor, InterfaceFieldReplacer::class)) {
                continue;
            }
            $result = $extractor::replace($field, $element, $from, $to);
            if ($result !== null) {
                return $result;
            }
        }
        return false;
    }

    /** Same for usages stored outside custom fields (user photo, navigation node, …), see InterfaceElementReplacer. */
    public static function replaceInElement(ElementInterface $element, ElementInterface $from, ElementInterface $to): bool
    {
        foreach (array_filter(ExtractorService::ELEMENT_EXTRACTORS, 'class_exists') as $extractor) {
            if (!is_subclass_of($extractor, InterfaceElementReplacer::class)) {
                continue;
            }
            $result = $extractor::replace($element, $from, $to);
            if ($result !== null) {
                return $result;
            }
        }
        return false;
    }

    private static function load(?string $type, int $id, int $siteId): ?ElementInterface
    {
        return is_subclass_of($type, ElementInterface::class)
            ? $type::find()->id($id)->siteId($siteId)->status(null)->drafts(null)->provisionalDrafts(null)->one()
            : null;
    }

    /** @param int[] $ids @param array<int, string> $types */
    private static function refreshEverySite(array $ids, array $types): void
    {
        foreach ($ids as $id) {
            $type = $types[$id] ?? null;
            if (!is_subclass_of($type, ElementInterface::class)) {
                continue;
            }
            foreach ($type::find()->id($id)->site('*')->status(null)->drafts(null)->provisionalDrafts(null)->all() as $siteElement) {
                ExtractorService::refreshRelationsForElement($siteElement);
            }
        }
    }

    private static function currentUserId(): ?int
    {
        $identity = Craft::$app->getUser()->getIdentity();
        return $identity ? (int)$identity->id : null;
    }

    /** The user whose save permissions apply (console runs without one are unrestricted). */
    private static function permissionUser(?int $userId): ?User
    {
        return $userId ? User::find()->id($userId)->status(null)->one() : null;
    }

    private static function newOperation(ElementInterface $from, ElementInterface $to, ?array $onlySourceIds, ?int $userId, string $status): ReplacementRecord
    {
        $operation = new ReplacementRecord([
            'fromId' => $from->id,
            'toId' => $to->id,
            'siteId' => $from->siteId,
            'userId' => $userId,
            'sourceIds' => $onlySourceIds !== null ? json_encode(array_values($onlySourceIds)) : null,
            'status' => $status,
        ]);
        $operation->save(false);
        return $operation;
    }
}
