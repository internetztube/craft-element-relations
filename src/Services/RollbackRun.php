<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Services;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Field;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Revisions;
use CraftCms\Cms\User\Elements\User;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Events\ReplacementRolledBack;
use Internetztube\ElementRelations\Models\Replacement;

use function CraftCms\Cms\t;

/**
 * One rollback while it runs, like ReplaceRun: the plan is the operation's changes grouped by owner and site, worked
 * through in batches by the queue job (RollbackJob) or in one go on the console. Per owner group: revert the owner to its
 * revision from before (mode "revisions", where there is one), otherwise swap the reference back.
 *
 * @phpstan-import-type Change from Replacement
 * @phpstan-import-type OwnerRevisions from Replacement
 * @phpstan-type RollbackGroup array{key: string, changes: list<Change>}
 */
final class RollbackRun
{
    private int $restored = 0;
    /** @var array<int, true> top-level owners this rollback swapped back or reverted (or found back) in any site */
    private array $doneOwners = [];
    /** owner groups done so far, for the progress the field shows */
    private int $processed = 0;
    /** all owner groups (counted once) */
    private ?int $total = null;
    private int $reverted = 0;
    /** @var list<string> */
    private array $skipped = [];

    private ?User $user;
    private ?string $notes;
    /** @var array<string, OwnerRevisions> "ownerId:siteId" => the owner's revisions of the replacement */
    private array $owners = [];

    private function __construct(
        private readonly Replacement $operation,
        private readonly string $mode,
        private readonly ?int $userId,
        private readonly ?ElementInterface $from,
        private readonly ?ElementInterface $to,
    ) {
        $user = $userId ? User::find()->id($userId)->status(null)->one() : null;
        $this->user = $user instanceof User ? $user : null;
        $this->notes = $from && $to ? t('rollback-revision-notes', ['from' => (string)$from, 'to' => (string)$to], 'element-relations') : null;
        foreach ($operation->getRevisions() as $owner) {
            $this->owners["{$owner['ownerId']}:{$owner['siteId']}"] = $owner;
        }
    }

    /**
     * @param ReplaceService::ROLLBACK_* $mode
     */
    public static function start(Replacement $operation, string $mode, ?int $userId): self
    {
        $from = Elements::getElementById((int)$operation->fromId, null, (int)$operation->siteId);
        $to = $from ? Elements::getElementById((int)$operation->toId, $from::class, (int)$operation->siteId) : null;
        $run = new self($operation, $mode, $userId, $from, $to);
        $state = $operation->getState();
        $run->restored = is_numeric($state['restored'] ?? null) ? (int)$state['restored'] : 0;
        $run->reverted = is_numeric($state['reverted'] ?? null) ? (int)$state['reverted'] : 0;
        /** @var list<string> $skipped */
        $skipped = (array)($state['skipped'] ?? []);
        $run->skipped = $skipped;
        $run->processed = is_numeric($state['processed'] ?? null) ? (int)$state['processed'] : 0;
        $run->total = is_numeric($state['total'] ?? null) ? (int)$state['total'] : null;
        $run->doneOwners = array_fill_keys(array_map(fn(mixed $id): int => is_numeric($id) ? (int)$id : 0, (array)($state['doneOwners'] ?? [])), true);
        return $run;
    }

    /**
     * The changes grouped by owner and site, in a stable order (the plan of every batch).
     * @return list<RollbackGroup>
     */
    public static function groups(Replacement $operation): array
    {
        $groups = [];
        foreach ($operation->changeList() as $change) {
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
        if ($this->user && !$canonical->canSave($this->user)) {
            $this->skipped[] = ReplaceService::label($canonical, $this->user) . ' (' . t('replace-no-permission', category: 'element-relations') . ')';
            return true; // do not touch it at all
        }
        $currentBefore = $canonical->getCurrentRevision()?->id;
        $creatorId = $this->userId ?? ($this->operation->userId !== null ? (int)$this->operation->userId : ReplaceService::anyAdminId());
        Revisions::revertToRevision($revision, $creatorId);
        ReplaceService::guaranteeRevision($owner, $currentBefore, $this->notes ?? '', $this->userId);
        ReplaceService::refreshOwnerTree($owner['ownerId']);
        $this->reverted++;
        $this->doneOwners[(int)$owner['ownerId']] = true;
        return true;
    }

    /**
     * Swaps the replacement back in every element of the group, keeping later edits.
     * @param RollbackGroup $group
     * @param OwnerRevisions|null $owner
     */
    private function swapBack(array $group, ?array $owner): void
    {
        $byElement = [];
        foreach ($group['changes'] as $change) {
            $byElement[$change['elementId']][] = $change;
        }
        $types = ReplaceService::types(array_keys($byElement));
        $revisionBefore = $owner ? ReplaceService::currentRevisionId($owner) : null;
        $ownerChanged = false;
        $saved = [];
        $groupDone = false; // a usage (this owner in this site) counts once, like the replacement counted it
        foreach ($byElement as $id => $changes) {
            $siteId = $changes[0]['siteId'];
            $element = ReplaceService::load($types[$id] ?? null, $id, $siteId);
            if (!$element || !$this->from || !$this->to) {
                $this->skipped[] = t('rollback-gone', ['id' => $id], 'element-relations');
                continue;
            }
            if ($this->user && !$element->canSave($this->user)) {
                $this->skipped[] = ReplaceService::label($element, $this->user) . ' (' . t('replace-no-permission', category: 'element-relations') . ')';
                continue;
            }
            $changed = false;
            $alreadyBack = false;
            foreach ($changes as $change) {
                $field = $change['fieldId'] ? Fields::getFieldById((int)$change['fieldId']) : null;
                $field = $field instanceof Field ? $field : null;
                if ($field && !ReplaceService::canEditField($element, $field, $this->user)) {
                    $this->skipped[] = ReplaceService::label($element, $this->user, $field) . ' (' . t('replace-no-permission', category: 'element-relations') . ')';
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
                } elseif ($this->isBack($element, $field, $change, $this->from, $this->to)) {
                    $alreadyBack = true; // rolled back before the job broke off
                } else {
                    $this->skipped[] = ReplaceService::label($element, $this->user, $field) . ' (' . t('rollback-changed-since', category: 'element-relations') . ')';
                }
            }
            if (!$changed) {
                if ($alreadyBack) {
                    $groupDone = true;
                }
                continue;
            }
            if ($this->notes) {
                $element->setRevisionNotes($this->notes);
            }
            if ($this->userId) {
                $element->setRevisionCreatorId($this->userId);
            }
            if (Elements::saveElement($element)) {
                $groupDone = true;
                $saved[] = $id;
                $ownerChanged = true;
            } else {
                $this->skipped[] = ReplaceService::label($element, $this->user) . ' (' . implode(' ', $element->errors()->all()) . ')';
            }
        }
        if ($groupDone) {
            $this->restored++;
            $this->doneOwners[(int)explode(':', $group['key'])[0]] = true;
        }
        if ($owner && $ownerChanged && $this->notes) {
            ReplaceService::guaranteeRevision($owner, $revisionBefore, $this->notes, $this->userId);
        }
        ReplaceService::refreshEverySite($saved, $types);
    }

    /**
     * The element is already as before the replacement: its field holds the previous value again, or (for references
     * the replacement added) the original instead of the replacement.
     * @param Change $change
     */
    private function isBack(ElementInterface $element, ?Field $field, array $change, ElementInterface $from, ElementInterface $to): bool
    {
        if ($field && $change['preHadTo']) {
            return json_encode(ReplaceService::serializedFieldValue($field, $element)) === json_encode($change['before']);
        }
        $targets = ExtractorService::targetIds($element, $field);
        return in_array($from->id, $targets, true) && !in_array($to->id, $targets, true);
    }

    /** Saves the state between batches. */
    public function persist(): void
    {
        $this->operation->state = json_encode(['restored' => $this->restored, 'reverted' => $this->reverted, 'skipped' => $this->skipped, 'processed' => $this->processed, 'total' => $this->total ??= count(self::groups($this->operation)), 'mode' => $this->mode, 'userId' => $this->userId, 'doneOwners' => array_keys($this->doneOwners)]) ?: null;
        $this->operation->save();
    }

    /** @return array{restored: int, skipped: list<string>, reverted: int} */
    public function finish(): array
    {
        // measured, not tallied per site: the owners this rollback changed (reverted ones included) that the list shows
        // again (ReplaceService::elementCount()); someone else's edits meanwhile are not among them
        if ($this->from) {
            $this->restored = ReplaceService::elementCount((int)$this->from->id, array_keys($this->doneOwners));
        }
        $skipped = array_values(array_unique($this->skipped));
        // nothing could be rolled back (no permission, changed since, …): the replacement stays applied, so someone who
        // may can still roll it back
        $back = $this->from ? $this->restored : $this->restored + $this->reverted;
        $this->operation->status = $back === 0 && $skipped ? Replacement::STATUS_APPLIED : Replacement::STATUS_ROLLED_BACK;
        $this->operation->state = null;
        $this->operation->result = json_encode([...$this->operation->getResult(), 'restored' => $this->restored, 'reverted' => $this->reverted, 'rollbackMode' => $this->mode, 'rollbackSkipped' => $skipped]) ?: null;
        $this->operation->save();
        ElementRelations::log()->info(sprintf('Rolled back replacement #%d (%s): %d swapped back, %d reverted to revisions%s', $this->operation->id, $this->mode, $this->restored, $this->reverted, $skipped ? '; skipped: ' . implode(', ', array_slice($skipped, 0, 50)) : ''));
        // restored is every element back, reverted ones included; reverted says how many of them by revision
        $result = ['restored' => $back, 'skipped' => $skipped, 'reverted' => $this->reverted];
        event(new ReplacementRolledBack($this->from, $this->to, $result, $this->operation));
        return $result;
    }
}
