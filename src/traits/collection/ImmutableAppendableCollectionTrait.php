<?php

declare(strict_types=1);

namespace app\njax\traits\collection;

/**
 * Неизменяемое добавление элемента в коллекцию через клонирование.
 *
 * Требует protected-свойства $items в использующем классе.
 */
trait ImmutableAppendableCollectionTrait
{
    /**
     * Возвращает новую коллекцию с добавленным элементом.
     *
     * @param mixed $item Элемент для добавления.
     *
     * @return static
     */
    protected function appendItem(mixed $item): static
    {
        $clone = clone $this;
        $clone->items[] = $item;

        return $clone;
    }
}
