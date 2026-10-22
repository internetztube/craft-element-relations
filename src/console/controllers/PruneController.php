<?php

declare(strict_types=1);

namespace internetztube\elementRelations\console\controllers;

use craft\console\Controller;
use internetztube\elementRelations\services\PruneService;
use yii\console\ExitCode;

/**
 * Removes cache rows nothing can read: their element, owner or target is gone, or the owner is a revision. Every
 * garbage collection does this too.
 */
class PruneController extends Controller
{
    public function actionIndex(): int
    {
        PruneService::prune(fn(string $line) => $this->stdout($line . PHP_EOL));
        return ExitCode::OK;
    }
}
