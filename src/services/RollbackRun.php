<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\Entry;
use craft\elements\User;
use internetztube\elementRelations\events\ReplaceEvent;
use internetztube\elementRelations\records\ReplacementRecord;
use yii\base\Event;

/**
 * One rollback while it runs, like ReplaceRun: the plan is the operation's changes grouped by owner and site, worked
 * through in batches by the queue job (RollbackJob) or in one go on the console. Per owner group: revert the owner to its
 * revision from before (mode "revisions", where there is one), otherwise swap the reference back.
 *
 * @phpstan-import-type Change from ReplacementRecord
 * @phpstan-import-type OwnerRevisions from ReplacementRecord
 * @phpstan-type RollbackGroup array{key: string, changes: list<Change>}
 */
final class RollbackRun
{
    private int $restored = 0;
    /** owner groups done so far, for the progress the field shows */
    private int $processed = 0;
    private int $reverted = 0;
    /** @var list<string> */
    private array $skipped = [];

    private ?User $user;
    private ?string $notes;
    /** @var array<string, OwnerRevisions> "ownerId:siteId" => the owner's revisions of the replacement */
    private array $owners = [];

    private function __construct(
        private readonly ReplacementRecord $operation,
        private readonly string $mode,
        private readonly ?int $userId,
        private readonly ?ElementInterface $from,
        private readonly ?ElementInterface $to,
    ) {
        $user = $userId ? User::find()->id($userId)->status(null)->one() : null;
        $this->user = $user instanceof User ? $user : null;
        $this->notes = $from && $to ? Craft::t('element-relations', 'rollback-revision-notes', ['from' => (string)$from, 'to' => (string)$to]) : null;
        foreach ($operation->getRevisions() as $owner) {
            $this->owners["{$owner['ownerId']}:{$owner['siteId']}"] = $owner;
        }
    }

    /**
     * @param ReplaceService::ROLLBACK_* $mode
     */
    public static function start(ReplacementRecord $operation, string $mode, ?int $userId): self
    {
        $elements = Craft::$app->getElements();
        $from = $elements->getElementById((int)$operation->fromId, null, (int)$operation->siteId);
        $to = $from ? $elements->getElementById((int)$operation->toId, $from::class, (int)$operation->siteId) : null;
        $run = new self($operation, $mode, $userId, $from, $to);
        $state = $operation->getState();
        $run->restored = is_numeric($state['restored'] ?? null) ? (int)$state['restored'] : 0;
        $run->reverted = is_numeric($state['reverted'] ?? null) ? (int)$state['reverted'] : 0;
        /** @var list<string> $skipped */
        $skipped = (array)($state['skipped'] ?? []);
        $run->skipped = $skipped;
        $run->processed = is_numeric($state['processed'] ?? null) ? (int)$state['processed'] : 0;
        return $run;
    }

    /**
     * The changes grouped by owner and site, in a stable order (the plan of every batch).
     * @return list<RollbackGroup>
     */
    public static function groups(ReplacementRecord $operation): array
    {
        $groups = [];
        foreach ($operation->getChanges() as $change) {
            $key = ($change['ownerId'] ?? $change['elementId']) . ':' . $change['siteId'];
            $groups[$key] ??= ['key' => $key, 'changes' => []];
            $groups[$key]['changes'][] = $change;
        }
        return array_values($groups);
    }

    /** @param RollbackGroup $group */
    public function processGroup(array $group): void
    {
        $this->processed++;
        $owner = $this->owners[$group['key']] ?? null;
        if ($this->mode === ReplaceService::ROLLBACK_REVISIONS && $owner && !empty($owner['revisionBeforeId']) && $this->revert($owner)) {
            return;
        }
        $this->swapBack($group, $owner);
    }

    /**
     * Reverts the owner to its revision from before the replacement with Craft's revision revert.
     * @param OwnerRevisions $owner
     * @return bool false when that is not possible (the caller swaps back instead)
     */
    private function revert(array $owner): bool
    {
        $canonical = Entry::find()->id($owner['ownerId'])->siteId($owner['siteId'])->status(null)->one();
        $revision = Entry::find()->id($owner['revisionBeforeId'])->siteId($owner['siteId'])->revisions()->status(null)->one();
        if (!$canonical instanceof Entry || !$revision instanceof Entry) {
            return false;
        }
        if ($this->user && !Craft::$app->getElements()->canSave($canonical, $this->user)) {
            $this->skipped[] = ReplaceService::label($canonical, $this->user) . ' (' . Craft::t('element-relations', 'replace-no-permission') . ')';
            return true; // do not touch it at all
        }
        $currentBefore = $canonical->getCurrentRevision()?->id;
        $creatorId = $this->userId ?? ($this->operation->userId !== null ? (int)$this->operation->userId : ReplaceService::anyAdminId());
        Craft::$app->getRevisions()->revertToRevision($revision, $creatorId);
        ReplaceService::guaranteeRevision($owner, $currentBefore, $this->notes ?? '', $this->userId);
        ReplaceService::refreshOwnerTree($owner['ownerId']);
        $this->reverted++;
        return true;
    }

    /**
     * Swaps the replacement back in every element of the group, keeping later edits.
     * @param RollbackGroup $group
     * @param OwnerRevisions|null $owner
     */
    private function swapBack(array $group, ?array $owner): void
    {
        $elementsService = Craft::$app->getElements();
        $byElement = [];
        foreach ($group['changes'] as $change) {
            $byElement[$change['elementId']][] = $change;
        }
        $types = ReplaceService::types(array_keys($byElement));
        $revisionBefore = $owner ? ReplaceService::currentRevisionId($owner) : null;
        $ownerChanged = false;
        $saved = [];
        foreach ($byElement as $id => $changes) {
            $siteId = $changes[0]['siteId'];
            $element = ReplaceService::load($types[$id] ?? null, $id, $siteId);
            if (!$element || !$this->from || !$this->to) {
                $this->skipped[] = Craft::t('element-relations', 'rollback-gone', ['id' => $id]);
                continue;
            }
            if ($this->user && !$elementsService->canSave($element, $this->user)) {
                $this->skipped[] = ReplaceService::label($element, $this->user) . ' (' . Craft::t('element-relations', 'replace-no-permission') . ')';
                continue;
            }
            $changed = false;
            foreach ($changes as $change) {
                $field = $change['fieldId'] ? Craft::$app->getFields()->getFieldById((int)$change['fieldId']) : null;
                $field = $field instanceof Field ? $field : null;
                if ($field && !ReplaceService::canEditField($element, $field, $this->user)) {
                    $this->skipped[] = ReplaceService::label($element, $this->user, $field) . ' (' . Craft::t('element-relations', 'replace-no-permission') . ')';
                    continue;
                }
                if (!$change['preHadTo']) {
                    // the replacement only got there through this operation: swap it back, keep later edits
                    $ok = $field ? ReplaceService::replaceInField($field, $element, $this->to, $this->from) : ReplaceService::replaceInElement($element, $this->to, $this->from);
                } elseif ($field && json_encode(ReplaceService::serializedFieldValue($field, $element)) === json_encode($change['after'] ?? null)) {
                    // it was referenced before too: restore the previous value exactly, the field is unchanged since
                    $element->setFieldValue((string)$field->handle, $change['before']);
                    $ok = true;
                } else {
                    $ok = false;
                }
                if ($ok) {
                    $changed = true;
                } else {
                    $this->skipped[] = ReplaceService::label($element, $this->user, $field) . ' (' . Craft::t('element-relations', 'rollback-changed-since') . ')';
                }
            }
            if (!$changed) {
                continue;
            }
            if ($this->notes) {
                $element->setRevisionNotes($this->notes);
            }
            if ($this->userId) {
                $element->setRevisionCreatorId($this->userId);
            }
            if ($elementsService->saveElement($element)) {
                $this->restored++;
                $saved[] = $id;
                $ownerChanged = true;
            } else {
                $this->skipped[] = ReplaceService::label($element, $this->user) . ' (' . implode(' ', $element->getFirstErrors()) . ')';
            }
        }
        if ($owner && $ownerChanged && $this->notes) {
            ReplaceService::guaranteeRevision($owner, $revisionBefore, $this->notes, $this->userId);
        }
        ReplaceService::refreshEverySite($saved, $types);
    }

    /** Saves the state between batches. */
    public function persist(): void
    {
        $this->operation->state = json_encode(['restored' => $this->restored, 'reverted' => $this->reverted, 'skipped' => $this->skipped, 'processed' => $this->processed, 'total' => count(self::groups($this->operation))]) ?: null;
        $this->operation->save(false);
    }

    /** @return array{restored: int, skipped: list<string>, reverted: int} */
    public function finish(): array
    {
        $skipped = array_values(array_unique($this->skipped));
        $this->operation->status = ReplacementRecord::STATUS_ROLLED_BACK;
        $this->operation->state = null;
        $this->operation->result = json_encode([...$this->operation->getResult(), 'restored' => $this->restored, 'reverted' => $this->reverted, 'rollbackMode' => $this->mode, 'rollbackSkipped' => $skipped]) ?: null;
        $this->operation->save(false);
        Craft::info(sprintf('Rolled back replacement #%d (%s): %d swapped back, %d reverted to revisions%s', $this->operation->id, $this->mode, $this->restored, $this->reverted, $skipped ? '; skipped: ' . implode(', ', array_slice($skipped, 0, 50)) : ''), 'element-relations');
        $result = ['restored' => $this->restored + $this->reverted, 'skipped' => $skipped, 'reverted' => $this->reverted];
        if ($this->from && $this->to && Event::hasHandlers(ReplaceService::class, ReplaceService::EVENT_AFTER_ROLLBACK)) {
            Event::trigger(ReplaceService::class, ReplaceService::EVENT_AFTER_ROLLBACK, new ReplaceEvent(['from' => $this->from, 'to' => $this->to, 'result' => $result, 'operation' => $this->operation]));
        }
        return $result;
    }
}
