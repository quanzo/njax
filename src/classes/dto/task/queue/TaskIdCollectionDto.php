<?php

declare(strict_types=1);

namespace app\njax\classes\dto\task\queue;

use app\njax\classes\task\TaskId;
use app\njax\interfaces\serialization\IJsonArrayable;
use app\njax\traits\collection\CountableIteratorAggregateTrait;
use app\njax\traits\collection\TypedItemsCollectionTrait;

/**
 * DTO коллекции идентификаторов задач.
 */
final class TaskIdCollectionDto implements \Countable, \IteratorAggregate, IJsonArrayable
{
    use CountableIteratorAggregateTrait;
    use TypedItemsCollectionTrait;

    /**
     * @param array<int, TaskId> $items Идентификаторы задач.
     */
    public function __construct(array $items)
    {
        $this->initializeItems($items);
    }

    /**
     * @return array<int, TaskId>
     */
    public function toArray(): array
    {
        /** @var array<int, TaskId> $items */
        $items = $this->getItems();

        return $items;
    }

    /**
     * @return array<int, string>
     */
    public function toJsonArray(): array
    {
        $result = [];
        foreach ($this->items as $item) {
            $result[] = $item->toString();
        }

        return $result;
    }

    /**
     * @return class-string<TaskId>
     */
    protected static function getAllowedItemClass(): string
    {
        return TaskId::class;
    }

    /**
     * @return string
     */
    protected static function getCollectionName(): string
    {
        return 'TaskIdCollectionDto';
    }
}
