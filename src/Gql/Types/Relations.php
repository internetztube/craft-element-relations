<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Gql\Types;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Gql\Contracts\SingularTypeInterface;
use CraftCms\Cms\Gql\Gql as GqlService;
use CraftCms\Cms\Gql\GqlEntityRegistry;
use CraftCms\Cms\Gql\GqlHelper;
use CraftCms\Cms\Gql\Interfaces\Element as ElementInterface;
use CraftCms\Cms\Gql\Types\ObjectType;
use CraftCms\Cms\Site\Data\Site;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\Type;
use Internetztube\ElementRelations\Models\RelationsModel;

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
        \CraftCms\Cms\Entry\Elements\Entry::class => [\CraftCms\Cms\Gql\Resolvers\Elements\Entry::class, [GqlHelper::class, 'canQueryEntries']],
        \CraftCms\Cms\Asset\Elements\Asset::class => [\CraftCms\Cms\Gql\Resolvers\Elements\Asset::class, [GqlHelper::class, 'canQueryAssets']],
        \CraftCms\Cms\User\Elements\User::class => [\CraftCms\Cms\Gql\Resolvers\Elements\User::class, [GqlHelper::class, 'canQueryUsers']],
        \CraftCms\Cms\Address\Elements\Address::class => [\CraftCms\Cms\Gql\Resolvers\Elements\Address::class, [GqlHelper::class, 'canQueryUsers']],
    ];

    /**
     * `$source` limited to the active schema, and the named arguments for its getters.
     * @param Arguments $arguments
     * @return array{0: RelationsModel, 1: array{siteIds: int[], sections: string[]|null, entryTypes: string[]|null, status: string[]|string|null}}
     */
    private static function scoped(RelationsModel $source, array $arguments): array
    {
        // Craft only offers `status` to schemas that may read non-enabled elements
        if (array_key_exists('status', $arguments) && !GqlHelper::canQueryInactiveElements()) {
            throw new UserError('The `status` argument requires the “Query for non-enabled elements” schema scope.');
        }
        $model = $source->withQueryFactory(function (string $elementType): mixed {
            [$resolver, $canQuery] = self::RESOLVERS[$elementType] ?? [null, null];
            if ($resolver === null || !class_exists($resolver) || !is_callable($canQuery)) {
                return null; // e.g. Commerce is not installed
            }
            /** @var callable(): bool $canQuery */
            $query = $canQuery() ? $resolver::prepareQuery(null, []) : null;
            // a schema that may read nothing of the type gets an empty collection instead of a query
            return $query instanceof \CraftCms\Cms\Element\Queries\ElementQuery ? $query : null;
        });
        $allowed = array_map(fn(Site $site): int => (int)$site->id, GqlHelper::getAllowedSites());
        $siteIds = $arguments['siteIds'] ?? [$source->getSiteId()];
        $siteIds = $siteIds === [] ? array_values($allowed) : array_values(array_intersect($siteIds, $allowed));
        return [$model, [
            // no allowed site left: an invalid site id makes every query return nothing ([] would mean all sites)
            'siteIds' => $siteIds ?: [0],
            'sections' => $arguments['sections'] ?? null,
            'entryTypes' => $arguments['entryTypes'] ?? null,
            // an explicit `status: null` means every status, like Craft's own queries; omitted: the resolver's default
            'status' => array_key_exists('status', $arguments) ? $arguments['status'] : RelationsModel::DEFAULT,
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
        return app(GqlService::class)->prepareFieldDefinitions([
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
                        'description' => 'Only include source elements with these statuses (e.g. ["disabled"]). Defaults to live entries and enabled other elements; null means every status. Statuses are per type (“live” for entries and products, “enabled” for assets, “active” for users) and a list may mix them. Requires the “Query for non-enabled elements” schema scope.',
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
                        'description' => 'Only include source elements with these statuses (e.g. ["disabled"]). Defaults to live entries and enabled other elements; null means every status. Statuses are per type (“live” for entries and products, “enabled” for assets, “active” for users) and a list may mix them. Requires the “Query for non-enabled elements” schema scope.',
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
                        'description' => 'Only include source elements with these statuses (e.g. ["disabled"]). Defaults to live entries and enabled other elements; null means every status. Statuses are per type (“live” for entries and products, “enabled” for assets, “active” for users) and a list may mix them. Requires the “Query for non-enabled elements” schema scope.',
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
                    $max = Cms::config()->maxGraphqlResults;
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
