<?php

declare(strict_types=1);

namespace app\njax\helpers;

/**
 * Хелпер каноникализации пейлоада.
 * Формирует детерминированные снимки пейлоада для хеширования и проверки подписи.
 * Пример:
 * $normalized = PayloadCanonicalizerHelper::canonicalize(['b' => 2, 'a' => 1]);
 */
final class PayloadCanonicalizerHelper
{
    /**
     * Каноникализация значения.
     * Возвращает рекурсивно отсортированную по ключам версию JSON-совместимых данных.
     *
     * @param mixed $payload Произвольное JSON-совместимое значение.
     *
     * @return mixed
     */
    public static function canonicalize(mixed $payload): mixed
    {
        if (is_array($payload) === false) {
            return $payload;
        }

        if (self::isList($payload)) {
            $normalizedList = [];
            foreach ($payload as $value) {
                $normalizedList[] = self::canonicalize($value);
            }

            return $normalizedList;
        }

        ksort($payload);
        $normalizedMap = [];
        foreach ($payload as $key => $value) {
            $normalizedMap[(string) $key] = self::canonicalize($value);
        }

        return $normalizedMap;
    }

    /**
     * Сформировать канонический JSON.
     * Преобразует пейлоад в стабильную JSON-форму.
     *
     * @param mixed $payload Произвольное JSON-совместимое значение.
     *
     * @return string
     */
    public static function toCanonicalJson(mixed $payload): string
    {
        $canonicalValue = self::canonicalize($payload);

        return (string) json_encode(
            $canonicalValue,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * Создать глубокую копию.
     * Создает изолированный снимок пейлоада, чтобы избежать случайного совместного изменения.
     *
     * @param mixed $payload Произвольное JSON-совместимое значение.
     *
     * @return mixed
     */
    public static function deepCopy(mixed $payload): mixed
    {
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedPayload === false) {
            throw new \InvalidArgumentException('Payload cannot be encoded into JSON.');
        }

        return json_decode($encodedPayload, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Проверить список.
     * Определяет, образуют ли ключи массива последовательный список с нуля.
     *
     * @param array<mixed> $value Массив для проверки.
     *
     * @return bool
     */
    private static function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
