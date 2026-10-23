<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Internetztube\ElementRelations\Http\Controllers\ReplaceController;

// actions/element-relations/…, the same paths as in 4.x; control panel users only (the controller refuses site requests)
Route::middleware('auth')->group(function (): void {
    Route::get('replace/wizard', [ReplaceController::class, 'wizard']);
    Route::post('replace/replace', [ReplaceController::class, 'replace']);
    Route::post('replace/status', [ReplaceController::class, 'status']);
    Route::post('replace/rollback', [ReplaceController::class, 'rollback']);
    Route::post('replace/resume', [ReplaceController::class, 'resume']);
    Route::post('rebuild', [ReplaceController::class, 'rebuild']);
});
