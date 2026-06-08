<?php

declare(strict_types=1);

namespace app\modules\njax\traits\serialization;

use app\modules\njax\helpers\JsonArrayableHelper;

/**
 * JSON-сериализация коллекции DTO с рекурсивной обработкой IJsonArrayable.
 *
 * Требует protected-свойства $items в использующем классе.
 */
trait JsonArrayableCollectionTrait
{
    /**
     * @return array<int, mixed>
     */
    public function toJsonArray(): array
    {
        $serialized = [];
        foreach ($this->items as $item) {
            $serialized[] = JsonArrayableHelper::toJsonArray($item);
        }

        return $serialized;
    }
}
