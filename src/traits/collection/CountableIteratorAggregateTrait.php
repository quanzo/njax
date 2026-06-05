<?php

declare(strict_types=1);

namespace app\njax\traits\collection;

/**
 * Общая реализация Countable и IteratorAggregate для DTO-коллекций.
 *
 * Требует наличия protected-свойства $items в использующем классе.
 */
trait CountableIteratorAggregateTrait
{
    /**
     * @return \Traversable<int, mixed>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
