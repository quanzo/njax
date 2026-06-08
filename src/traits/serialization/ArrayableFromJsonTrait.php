<?php

declare(strict_types=1);

namespace app\modules\njax\traits\serialization;

/**
 * Делегирует доменный toArray() в JSON-представление toJsonArray().
 *
 * Используется в DTO, где PHP-массив для домена совпадает с JSON-структурой.
 * Требует реализации toJsonArray() в использующем классе.
 */
trait ArrayableFromJsonTrait
{
    /**
     * @return array<string, mixed>|array<int, mixed>
     */
    public function toArray(): array
    {
        return $this->toJsonArray();
    }
}
