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
 * Неизменяемая коллекция ошибок валидации задач (режим partial accept).
 */
final class ValidationErrorCollectionDto implements \Countable, \IteratorAggregate, IJsonArrayable
{
    use CountableIteratorAggregateTrait;
    use TypedItemsCollectionTrait;
    use ImmutableAppendableCollectionTrait;
    use JsonArrayableCollectionTrait;
    use ArrayableFromJsonTrait;

    /**
     * @param array<int, ValidationErrorDto> $items Исходный список ошибок.
     */
    public function __construct(array $items = [])
    {
        $this->initializeItems($items);
    }

    /**
     * @param ValidationErrorDto $item Ошибка валидации.
     *
     * @return self
     */
    public function withItem(ValidationErrorDto $item): self
    {
        return $this->appendItem($item);
    }

    /**
     * @return class-string<ValidationErrorDto>
     */
    protected static function getAllowedItemClass(): string
    {
        return ValidationErrorDto::class;
    }

    /**
     * @return string
     */
    protected static function getCollectionName(): string
    {
        return 'ValidationErrorCollectionDto';
    }
}
