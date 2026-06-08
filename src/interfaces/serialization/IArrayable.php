<?php

declare(strict_types=1);

namespace app\modules\njax\interfaces\serialization;

/**
 * Базовый контракт DTO, которые могут быть преобразованы в PHP-массив.
 *
 * Элементы результирующего массива могут быть скалярами или другими объектами.
 * JSON-типы расширяют контракт через {@see IJsonArrayable} и {@see toJsonArray()}.
 */
interface IArrayable
{
    /**
     * Преобразует DTO в массив для доменной обработки.
     *
     * @return array<string, mixed>|array<int, mixed>
     */
    public function toArray(): array;
}
