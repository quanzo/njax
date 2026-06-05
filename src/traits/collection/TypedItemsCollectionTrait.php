<?php

declare(strict_types=1);

namespace app\njax\traits\collection;

/**
 * Хранение и валидация типизированного списка элементов коллекции DTO.
 *
 * Использующий класс должен объявить protected array $items и реализовать
 * getAllowedItemClass() и getCollectionName().
 */
trait TypedItemsCollectionTrait
{
    /**
     * @var array<int, mixed>
     */
    protected array $items = [];

    /**
     * @return class-string
     */
    abstract protected static function getAllowedItemClass(): string;

    /**
     * @return string
     */
    abstract protected static function getCollectionName(): string;

    /**
     * Инициализирует коллекцию с проверкой типа каждого элемента.
     *
     * @param array<int, mixed> $items Элементы коллекции.
     *
     * @return void
     */
    protected function initializeItems(array $items): void
    {
        $allowedClass = static::getAllowedItemClass();
        foreach ($items as $item) {
            if ($item instanceof $allowedClass === false) {
                throw new \InvalidArgumentException(
                    static::getCollectionName() . ' принимает только экземпляры ' . $allowedClass . '.'
                );
            }
        }

        $this->items = array_values($items);
    }

    /**
     * @return array<int, mixed>
     */
    protected function getItems(): array
    {
        return $this->items;
    }
}
