<?php

declare(strict_types=1);

namespace app\njax\classes\dto\task\request;

use app\njax\interfaces\serialization\IArrayable;
use app\njax\traits\collection\CountableIteratorAggregateTrait;
use app\njax\traits\collection\TypedItemsCollectionTrait;

/**
 * DTO коллекции определений задач.
 *
 * Возвращает внутренний список DTO для доменной обработки; JSON-сериализация
 * выполняется на уровне отдельных {@see TaskDefinitionDto}.
 */
final class TaskDefinitionCollectionDto implements \Countable, \IteratorAggregate, IArrayable
{
    use CountableIteratorAggregateTrait;
    use TypedItemsCollectionTrait;

    /**
     * @param array<int, TaskDefinitionDto> $items Определения задач.
     */
    public function __construct(array $items)
    {
        $this->initializeItems($items);
    }

    /**
     * @return array<int, TaskDefinitionDto>
     */
    public function toArray(): array
    {
        /** @var array<int, TaskDefinitionDto> $items */
        $items = $this->getItems();

        return $items;
    }

    /**
     * @return class-string<TaskDefinitionDto>
     */
    protected static function getAllowedItemClass(): string
    {
        return TaskDefinitionDto::class;
    }

    /**
     * @return string
     */
    protected static function getCollectionName(): string
    {
        return 'TaskDefinitionCollectionDto';
    }
}
