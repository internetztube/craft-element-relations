<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Database\Migrations;

use CraftCms\Cms\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The plugin's tables, as 4.x left them (5.0 requires 4.0.2.1 first, so an update finds them like this and runs nothing).
 * No foreign keys to elements: a cache row may point at an element that is gone (rich text keeps such references).
 */
class Install extends Migration
{
    public const CACHE = 'elementrelations_cache';
    public const REPLACEMENTS = 'elementrelations_replacements';
    public const CHANGES = 'elementrelations_replacement_changes';

    public function up(): void
    {
        Schema::create(self::CACHE, function (Blueprint $table): void {
            $table->integer('id', true);
            // null for non-element usages (plugin settings), see InterfaceGlobalUsage
            $table->integer('sourceElementId')->nullable();
            $table->integer('sourceSiteId')->nullable();
            $table->integer('sourcePrimaryOwnerId')->nullable();
            $table->integer('targetElementId');
            $table->integer('targetSiteId');
            $table->string('customFieldUid')->nullable();
            $table->integer('fieldId')->nullable();
            $table->string('type');
            $table->string('globalKey')->nullable();
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->char('uid', 36)->default('0');
            foreach (\Internetztube\ElementRelations\Models\CacheRecord::INDEXES as $columns) {
                $table->index($columns);
            }
        });

        // log of replace operations (Models\Replacement); the JSON columns may hold megabytes
        Schema::create(self::REPLACEMENTS, function (Blueprint $table): void {
            $table->integer('id', true);
            $table->integer('fromId');
            $table->integer('toId');
            $table->integer('siteId');
            $table->integer('userId')->nullable();
            $table->longText('sourceIds')->nullable();
            $table->string('status', 20);
            $table->longText('changes')->nullable();
            $table->longText('revisions')->nullable();
            $table->longText('result')->nullable();
            $table->longText('plan')->nullable();
            $table->longText('state')->nullable();
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->char('uid', 36)->default('0');
            $table->index(['dateCreated']);
        });

        // one row per changed element and field of a replacement (Services\ReplaceRun), removed with it
        Schema::create(self::CHANGES, function (Blueprint $table): void {
            $table->integer('id', true);
            $table->integer('operationId');
            $table->integer('elementId');
            $table->integer('siteId');
            $table->integer('ownerId');
            $table->integer('fieldId')->nullable();
            $table->boolean('preHadTo')->default(false);
            $table->longText('before')->nullable(); // JSON of the field value before
            $table->longText('after')->nullable(); // JSON of the field value after
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->char('uid', 36)->default('0');
            $table->index(['operationId', 'ownerId', 'siteId']);
            $table->foreign('operationId')->references('id')->on(self::REPLACEMENTS)->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // the changes first: their foreign key on the replacements would block dropping those
        Schema::dropIfExists(self::CHANGES);
        Schema::dropIfExists(self::REPLACEMENTS);
        Schema::dropIfExists(self::CACHE);
    }
}
