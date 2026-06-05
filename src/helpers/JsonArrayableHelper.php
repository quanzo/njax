<?php

declare(strict_types=1);

namespace app\njax\helpers;

use app\njax\interfaces\serialization\IJsonArrayable;

/**
 * Хелпер рекурсивной сериализации значений в JSON-совместимую структуру.
 *
 * При обходе вложенных структур проверяет каждый элемент на реализацию
 * {@see IJsonArrayable} и вызывает toJsonArray() для таких объектов.
 */
final class JsonArrayableHelper
{
    /**
     * Преобразует значение в структуру, пригодную для json_encode.
     *
     * @param mixed $value Скаляр, массив или объект с поддержкой IJsonArrayable.
     *
     * @return mixed
     */
    public static function toJsonArray(mixed $value): mixed
    {
        if ($value instanceof IJsonArrayable) {
            return $value->toJsonArray();
        }

        if (is_array($value) === false) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = self::toJsonArray($item);
        }

        return $normalized;
    }
}
