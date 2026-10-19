<?php

declare(strict_types=1);

namespace internetztube\elementRelations\gql\types;

use Craft;
use craft\gql\base\ObjectType;
use craft\gql\base\SingularTypeInterface;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element as ElementInterface;
use craft\helpers\Gql as GqlHelper;
use craft\models\Site;
use craft\services\Gql as GqlService;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\Type;
use internetztube\elementRelations\models\RelationsModel;

/**
 * The relations field in GraphQL. Only relating elements the active schema may read are returned and counted: each
 * type's query comes from Craft's own GraphQL resolver (see RESOLVERS), sites are limited to the schema's sites,
 * drafts and revisions are never included.
 *
 * @phpstan-type Arguments array{siteIds?: int[], sections?: string[], entryTypes?: string[], status?: string[], limit?: int|null, offset?: int|null}
 */
class Relations extends ObjectType implements SingularTypeInterface
{
    /**
     * Element type => [Craft GraphQL resolver, check whether the schema may query that type at all], the same check
     * Craft uses to offer the type's top-level query. The resolver's `prepareQuery(null, [])` returns a query
     * restricted to what the schema may read (sections, volumes, groups, ...).
     * Relating elements of any other type are never exposed through GraphQL (fail closed).
     */
    public const RESOLVERS = [
        \craft\elements\Entry::class => [\craft\gql\resolvers\elements\Entry::class, [GqlHelper::class, 'canQueryEntries']],
        \craft\elements\Asset::class => [\craft\gql\resolvers\elements\Asset::class, [GqlHelper::class, 'canQueryAssets']],
        \craft\elements\Category::class => [\craft\gql\resolvers\elements\Category::class, [GqlHelper::class, 'canQueryCategories']],
        \craft\elements\Tag::class => [\craft\gql\resolvers\elements\Tag::class, [GqlHelper::class, 'canQueryTags']],
        \craft\elements\User::class => [\craft\gql\resolvers\elements\User::class, [GqlHelper::class, 'canQueryUsers']],
        \craft\elements\GlobalSet::class => [\craft\gql\resolvers\elements\GlobalSet::class, [GqlHelper::class, 'canQueryGlobalSets']],
        \craft\elements\Address::class => [\craft\gql\resolvers\elements\Address::class, [GqlHelper::class, 'canQueryUsers']],
        'craft\commerce\elements\Product' => ['craft\commerce\gql\resolvers\elements\Product', ['craft\commerce\helpers\Gql', 'canQueryProducts']],
    ];

    /**
     * `$source` limited to the active schema, and the named arguments for its getters.
     * @param Arguments $arguments
     * @return array{0: RelationsModel, 1: array{siteIds: int[], sections: string[]|null, entryTypes: string[]|null, status: string[]|null}}
     */
    private static function scoped(RelationsModel $source, array $arguments): array
    {
        // Craft only offers `status` to schemas that may read non-enabled elements
        if (isset($arguments['status']) && !GqlHelper::canQueryInactiveElements()) {
            throw new UserError('The `status` argument requires the “Query for non-enabled elements” schema scope.');
        }
        $model = $source->withQueryFactory(function (string $elementType): mixed {
            [$resolver, $canQuery] = self::RESOLVERS[$elementType] ?? [null, null];
            if ($resolver === null || !class_exists($resolver) || !is_callable($canQuery)) {
                return null; // e.g. Commerce is not installed
            }
            /** @var callable(): bool $canQuery */
            return $canQuery() ? $resolver::prepareQuery(null, []) : null;
        });
        $allowed = array_map(fn(Site $site): int => (int)$site->id, GqlHelper::getAllowedSites());
        $siteIds = $arguments['siteIds'] ?? [$source->getSiteId()];
        $siteIds = $siteIds === [] ? array_values($allowed) : array_values(array_intersect($siteIds, $allowed));
        return [$model, [
            // no allowed site left: an invalid site id makes every query return nothing ([] would mean all sites)
            'siteIds' => $siteIds ?: [0],
            'sections' => $arguments['sections'] ?? null,
            'entryTypes' => $arguments['entryTypes'] ?? null,
            'status' => $arguments['status'] ?? null,
        ]];
    }

    public static function getName(): string
    {
        return 'ElementRelations';
    }

    public static function getType(): self
    {
        /** @var self $type the registry only ever holds this type under this name */
        $type = GqlEntityRegistry::getEntity(self::getName()) ?: GqlEntityRegistry::createEntity(self::getName(), new self([
            'name' => self::getName(),
            'fields' => [self::class, 'getFieldDefinitions'],
        ]));
        return $type;
    }

    /** @return array<string, mixed> */
    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions([
            'count' => [
                'name' => 'count',
                'type' => Type::int(),
                'args' => [
                    'siteIds' => [
                        'name' => 'siteIds',
                        'type' => Type::listOf(Type::int()),
                    ],
                    'sections' => [
                        'name' => 'sections',
                        'type' => Type::listOf(Type::string()),
                    ],
                    'entryTypes' => [
                        'name' => 'entryTypes',
                        'type' => Type::listOf(Type::string()),
                    ],
                    'status' => [
                        'name' => 'status',
                        'type' => Type::listOf(Type::string()),
                        'description' => 'Only include source elements with these statuses (e.g. ["disabled"]). Defaults to live entries and enabled other elements. Requires the “Query for non-enabled elements” schema scope.',
                    ],
                ],
                'resolve' => function (RelationsModel $source, array $arguments): int {
                    [$model, $args] = self::scoped($source, $arguments);
                    return $model->getCount(...$args);
                },
            ],
            'isInUse' => [
                'name' => 'isInUse',
                'type' => Type::boolean(),
                'args' => [
                    'siteIds' => [
                        'name' => 'siteIds',
                        'type' => Type::listOf(Type::int()),
                    ],
                    'sections' => [
                        'name' => 'sections',
                        'type' => Type::listOf(Type::string()),
                    ],
                    'entryTypes' => [
                        'name' => 'entryTypes',
                        'type' => Type::listOf(Type::string()),
                    ],
                    'status' => [
                        'name' => 'status',
                        'type' => Type::listOf(Type::string()),
                        'description' => 'Only include source elements with these statuses (e.g. ["disabled"]). Defaults to live entries and enabled other elements. Requires the “Query for non-enabled elements” schema scope.',
                    ],
                ],
                'resolve' => function (RelationsModel $source, array $arguments): bool {
                    // only what the schema may read, consistent with `count` and `elements`; plugin settings
                    // (SEOmatic) are not mixed in here, see `isUsedInSeomaticGlobalSettings`
                    [$model, $args] = self::scoped($source, $arguments);
                    return $model->getCount(...$args) > 0;
                },
            ],
            'isUsedInSeomaticGlobalSettings' => [
                'name' => 'isUsedInSeomaticGlobalSettings',
                'type' => Type::boolean(),
                'resolve' => fn(RelationsModel $source): bool => $source->getIsUsedInSeomaticGlobalSettings(),
            ],
            'elements' => [
                'name' => 'elements',
                'type' => Type::listOf(ElementInterface::getType()),
                'args' => [
                    'siteIds' => [
                        'name' => 'siteIds',
                        'type' => Type::listOf(Type::int()),
                    ],
                    'sections' => [
                        'name' => 'sections',
                        'type' => Type::listOf(Type::string()),
                    ],
                    'entryTypes' => [
                        'name' => 'entryTypes',
                        'type' => Type::listOf(Type::string()),
                    ],
                    'status' => [
                        'name' => 'status',
                        'type' => Type::listOf(Type::string()),
                        'description' => 'Only include source elements with these statuses (e.g. ["disabled"]). Defaults to live entries and enabled other elements. Requires the “Query for non-enabled elements” schema scope.',
                    ],
                    'limit' => [
                        'name' => 'limit',
                        'type' => Type::int(),
                    ],
                    'offset' => [
                        'name' => 'offset',
                        'type' => Type::int(),
                    ],
                ],
                'resolve' => function (RelationsModel $source, array $arguments): array {
                    [$model, $args] = self::scoped($source, $arguments);
                    // never more than Craft's maxGraphqlResults, like Craft's own queries
                    $max = Craft::$app->getConfig()->getGeneral()->maxGraphqlResults;
                    $limit = $arguments['limit'] ?? null;
                    if ($max && ($limit === null || $limit > $max)) {
                        $limit = $max;
                    }
                    return $model->getElements(...$args + ['limit' => $limit, 'offset' => $arguments['offset'] ?? 0]);
                },
                'complexity' => GqlHelper::relatedArgumentComplexity(GqlService::GRAPHQL_COMPLEXITY_EAGER_LOAD),
            ],
        ], self::getName());
    }
}
