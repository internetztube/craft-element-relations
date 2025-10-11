<?php
namespace internetztube\elementRelations\gql\types;

use Craft;
use craft\gql\base\ObjectType;
use craft\gql\base\SingularTypeInterface;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element as ElementInterface;
use craft\helpers\Gql as GqlHelper;
use craft\services\Gql as GqlService;
use GraphQL\Type\Definition\Type;
use internetztube\elementRelations\models\RelationsModel;

class Relations extends ObjectType implements SingularTypeInterface
{
    public static function getName(): string
    {
        return 'ElementRelations';
    }

    public static function getType(): self
    {
        return GqlEntityRegistry::getEntity(self::getName()) ?: GqlEntityRegistry::createEntity(self::getName(), new self([
            'name' => self::getName(),
            'fields' => [self::class, 'getFieldDefinitions'],
        ]));
    }

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
                ],
                'resolve' => function(RelationsModel $source, array $arguments) {
                    return $source->getCount(
                        siteIds: $arguments['siteIds'] ?? null,
                        sections: $arguments['sections'] ?? null,
                        entryTypes: $arguments['entryTypes'] ?? null
                    );
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
                ],
                'resolve' => function(RelationsModel $source, array $arguments) {
                    return $source->getIsInUse(
                        siteIds: $arguments['siteIds'] ?? null,
                        sections: $arguments['sections'] ?? null,
                        entryTypes: $arguments['entryTypes'] ?? null
                    );
                },
            ],
            'isUsedInSeomaticGlobalSettings' => [
                'name' => 'isUsedInSeomaticGlobalSettings',
                'type' => Type::boolean(),
                'resolve' => fn(RelationsModel $source) => $source->getIsUsedInSeomaticGlobalSettings(),
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
                    'limit' => [
                        'name' => 'limit',
                        'type' => Type::int(),
                    ],
                    'offset' => [
                        'name' => 'offset',
                        'type' => Type::int(),
                    ],
                ],
                'resolve' => function(RelationsModel $source, array $arguments) {
                    $siteIds = $arguments['siteIds'] ?? null;
                    $sections = $arguments['sections'] ?? null;
                    $entryTypes = $arguments['entryTypes'] ?? null;
                    $limit = $arguments['limit'] ?? null;
                    $offset = $arguments['offset'] ?? 0;
                    return $source->getElements(
                        siteIds: $siteIds,
                        limit: $limit,
                        offset: $offset,
                        sections: $sections,
                        entryTypes: $entryTypes
                    );
                },
                'complexity' => GqlHelper::relatedArgumentComplexity(GqlService::GRAPHQL_COMPLEXITY_EAGER_LOAD),
            ],
        ], self::getName());
    }
}
