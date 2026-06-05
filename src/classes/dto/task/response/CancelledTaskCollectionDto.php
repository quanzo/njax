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
 * DTO коллекции отменённых задач.
 *
 * Хранит задачи, успешно отменённые сервером в рамках batch-запроса.
 */
final class CancelledTaskCollectionDto implements \Countable, \IteratorAggregate, IJsonArrayable
{
    use CountableIteratorAggregateTrait;
    use TypedItemsCollectionTrait;
    use ImmutableAppendableCollectionTrait;
    use JsonArrayableCollectionTrait;
    use ArrayableFromJsonTrait;

    /**
     * @param array<int, CancelledTaskDto> $items Отменённые задачи.
     */
    public function __construct(array $items = [])
    {
        $this->initializeItems($items);
    }

    /**
     * @param CancelledTaskDto $item Отменённая задача для добавления.
     *
     * @return self
     */
    public function withItem(CancelledTaskDto $item): self
    {
        return $this->appendItem($item);
    }

    /**
     * @return class-string<CancelledTaskDto>
     */
    protected static function getAllowedItemClass(): string
    {
        return CancelledTaskDto::class;
    }

    /**
     * @return string
     */
    protected static function getCollectionName(): string
    {
        return 'CancelledTaskCollectionDto';
    }
}
