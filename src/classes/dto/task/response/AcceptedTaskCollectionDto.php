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
 * DTO коллекции принятых задач.
 *
 * Хранит принятые задачи, назначенные сервером, с поддержкой
 * неизменяемого добавления элементов и JSON-сериализации.
 */
final class AcceptedTaskCollectionDto implements \Countable, \IteratorAggregate, IJsonArrayable
{
    use CountableIteratorAggregateTrait;
    use TypedItemsCollectionTrait;
    use ImmutableAppendableCollectionTrait;
    use JsonArrayableCollectionTrait;
    use ArrayableFromJsonTrait;

    /**
     * @param array<int, AcceptedTaskDto> $items Принятые задачи.
     */
    public function __construct(array $items = [])
    {
        $this->initializeItems($items);
    }

    /**
     * @param AcceptedTaskDto $item Принятая задача для добавления.
     *
     * @return self
     */
    public function withItem(AcceptedTaskDto $item): self
    {
        return $this->appendItem($item);
    }

    /**
     * @return class-string<AcceptedTaskDto>
     */
    protected static function getAllowedItemClass(): string
    {
        return AcceptedTaskDto::class;
    }

    /**
     * @return string
     */
    protected static function getCollectionName(): string
    {
        return 'AcceptedTaskCollectionDto';
    }
}
