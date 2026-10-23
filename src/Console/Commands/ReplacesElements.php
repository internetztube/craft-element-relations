<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Console\Commands;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sites;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Elements\Usage;
use Internetztube\ElementRelations\Http\Controllers\ReplaceController;
use Internetztube\ElementRelations\Services\ReplaceService;

use function CraftCms\Cms\t;

/**
 * What the replace commands share. They run right away (no queue), without a user (so nothing is skipped for
 * permissions), and are logged in the history like replacements in the control panel, so they can be rolled back.
 *
 * @mixin \Illuminate\Console\Command
 */
trait ReplacesElements
{
    /** An exception, not a quiet return: scripts must see a failing exit code. */
    private function requirePro(): void
    {
        if (!ElementRelations::isPro()) {
            throw new \RuntimeException('Replacing needs the Pro edition of Element Relations.');
        }
    }

    /** @param list<int>|null $usageIds */
    private function replaceOne(int $fromId, int $toId, ?array $usageIds, bool $dryRun): bool
    {
        $siteId = $this->siteId();
        $from = Elements::getElementById($fromId, null, $siteId);
        $to = $from ? Elements::getElementById($toId, $from::class, $siteId) : null;
        if (!$from || !$to || $from->id === $to->id) {
            $this->error(sprintf('#%d → #%d: %s', $fromId, $toId, t('replace-invalid', category: 'element-relations')));
            return false;
        }
        if ($dryRun) {
            $this->listUsages($from, $to, $usageIds);
            return true;
        }
        $result = ReplaceService::replace($from, $to, $usageIds);
        $message = ReplaceController::resultMessage($result, (string)$from, (string)$to);
        $result['skipped'] ? $this->warn($message) : $this->info($message);
        if ($result['operationId']) {
            $this->line(sprintf('Roll back: php craft element-relations/replace/rollback %d', $result['operationId']));
        }
        return true;
    }

    /**
     * What a replacement would change: every relating element, its place, the field and the site.
     * @param list<int>|null $usageIds
     */
    private function listUsages(ElementInterface $from, ElementInterface $to, ?array $usageIds): void
    {
        $query = Usage::find()->allSites()->status(null)->drafts(null)->usesElement($from->id);
        if ($usageIds !== null) {
            $query->id($usageIds);
        }
        $rows = array_map(fn(ElementInterface $element): array => [
            (string)$element->id,
            (string)$element,
            strip_tags((string)$element->getAttributeHtml(Usage::ATTR_WHERE)),
            strip_tags((string)$element->getAttributeHtml(Usage::ATTR_FIELD)),
            strip_tags((string)$element->getAttributeHtml(Usage::ATTR_SITE)), // every site it uses $from in
        ], array_values(array_filter($query->all(), fn(mixed $element): bool => $element instanceof ElementInterface)));
        $this->line(sprintf('“%s” (#%d) → “%s” (#%d): would change %d %s.', $from, $from->id, $to, $to->id, count($rows), count($rows) === 1 ? 'element' : 'elements'));
        if ($rows) {
            $this->table(['Id', 'Element', 'Where', 'Field', 'Site'], $rows);
        }
    }

    private function siteId(): int
    {
        $site = $this->option('site');
        if (!is_string($site) || $site === '') {
            return (int)Sites::getPrimarySite()->id;
        }
        $found = ctype_digit($site) ? Sites::getSiteById((int)$site) : Sites::getSiteByHandle($site);
        return $found ? (int)$found->id : throw new \InvalidArgumentException("Unknown site “{$site}”.");
    }
}
