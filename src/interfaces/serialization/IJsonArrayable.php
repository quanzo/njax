<?php

declare(strict_types=1);

namespace app\modules\njax\interfaces\serialization;

/**
 * Контракт объектов, которые могут быть преобразованы в массив для JSON-передачи.
 *
 * Наследует {@see IArrayable}: любой JSON-DTO также поддерживает toArray().
 * Сериализация вложенных структур — через {@see \app\njax\helpers\JsonArrayableHelper::toJsonArray()}.
 */
interface IJsonArrayable extends IArrayable
{
    /**
     * Преобразует объект в массив, пригодный для json_encode.
     *
     * @return array<string, mixed>|array<int, mixed>
     */
    public function toJsonArray(): array;
}
