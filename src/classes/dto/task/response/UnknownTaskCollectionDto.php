<?php

declare(strict_types=1);

namespace app\modules\njax\classes\dto\task\response;

use app\modules\njax\interfaces\serialization\IJsonArrayable;
use app\modules\njax\traits\serialization\ArrayableFromJsonTrait;
use app\modules\njax\traits\serialization\JsonArrayableCollectionTrait;
use app\modules\njax\traits\collection\CountableIteratorAggregateTrait;
use app\modules\njax\traits\collection\ImmutableAppendableCollectionTrait;
use app\modules\njax\traits\collection\TypedItemsCollectionTrait;

/**
 * DTO коллекции неизвестных задач.
 */
final class UnknownTaskCollectionDto implements \Countable, \IteratorAggregate, IJsonArrayable
{
    use CountableIteratorAggregateTrait;
    use TypedItemsCollectionTrait;
    use ImmutableAppendableCollectionTrait;
    use JsonArrayableCollectionTrait;
    use ArrayableFromJsonTrait;

    /**
     * @param array<int, UnknownTaskDto> $items Неизвестные задачи.
     */
    public function __construct(array $items = [])
    {
        $this->initializeItems($items);
    }

    /**
     * @param UnknownTaskDto $item Неизвестная задача.
     *
     * @return self
     */
    public function withItem(UnknownTaskDto $item): self
    {
        return $this->appendItem($item);
    }

    /**
     * @return class-string<UnknownTaskDto>
     */
    protected static function getAllowedItemClass(): string
    {
        return UnknownTaskDto::class;
    }

    /**
     * @return string
     */
    protected static function getCollectionName(): string
    {
        return 'UnknownTaskCollectionDto';
    }
}
