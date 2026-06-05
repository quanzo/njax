<?php

declare(strict_types=1);

namespace app\njax\classes\dto\task\response;

use app\njax\interfaces\serialization\IJsonArrayable;
use app\njax\traits\serialization\ArrayableFromJsonTrait;
use app\njax\traits\serialization\JsonArrayableCollectionTrait;
use app\njax\traits\collection\CountableIteratorAggregateTrait;
use app\njax\traits\collection\ImmutableAppendableCollectionTrait;
use app\njax\traits\collection\TypedItemsCollectionTrait;

/**
 * DTO коллекции завершённых задач.
 */
final class CompletedTaskCollectionDto implements \Countable, \IteratorAggregate, IJsonArrayable
{
    use CountableIteratorAggregateTrait;
    use TypedItemsCollectionTrait;
    use ImmutableAppendableCollectionTrait;
    use JsonArrayableCollectionTrait;
    use ArrayableFromJsonTrait;

    /**
     * @param array<int, CompletedTaskDto> $items Завершённые задачи.
     */
    public function __construct(array $items = [])
    {
        $this->initializeItems($items);
    }

    /**
     * @param CompletedTaskDto $item Завершённая задача.
     *
     * @return self
     */
    public function withItem(CompletedTaskDto $item): self
    {
        return $this->appendItem($item);
    }

    /**
     * @return class-string<CompletedTaskDto>
     */
    protected static function getAllowedItemClass(): string
    {
        return CompletedTaskDto::class;
    }

    /**
     * @return string
     */
    protected static function getCollectionName(): string
    {
        return 'CompletedTaskCollectionDto';
    }
}
