<?php

declare(strict_types=1);

namespace Tests\Dto;

use app\njax\classes\dto\task\response\AcceptedTaskCollectionDto;
use app\njax\classes\dto\task\response\AcceptedTaskDto;
use app\njax\classes\task\TaskId;
use PHPUnit\Framework\TestCase;

/**
 * Тесты поведения traits коллекций DTO на эталонной AcceptedTaskCollectionDto.
 */
final class DtoCollectionTraitTest extends TestCase
{
    /**
     * count() возвращает число элементов коллекции.
     */
    public function testCountReturnsItemTotal(): void
    {
        $collection = new AcceptedTaskCollectionDto([
            new AcceptedTaskDto(0, new TaskId('task-1')),
            new AcceptedTaskDto(1, new TaskId('task-2')),
        ]);

        $this->assertSame(2, $collection->count());
    }

    /**
     * isEmpty() истинно для пустой коллекции.
     */
    public function testIsEmptyForEmptyCollection(): void
    {
        $collection = new AcceptedTaskCollectionDto();

        $this->assertTrue($collection->isEmpty());
    }

    /**
     * isEmpty() ложно, если есть хотя бы один элемент.
     */
    public function testIsEmptyFalseWhenItemsPresent(): void
    {
        $collection = new AcceptedTaskCollectionDto([
            new AcceptedTaskDto(0, new TaskId('task-1')),
        ]);

        $this->assertFalse($collection->isEmpty());
    }

    /**
     * Итератор обходит все элементы в порядке добавления.
     */
    public function testIteratorTraversesAllItems(): void
    {
        $first = new AcceptedTaskDto(0, new TaskId('task-1'));
        $second = new AcceptedTaskDto(1, new TaskId('task-2'));
        $collection = new AcceptedTaskCollectionDto([$first, $second]);

        $iterated = [];
        foreach ($collection as $item) {
            $iterated[] = $item;
        }

        $this->assertSame([$first, $second], $iterated);
    }

    /**
     * withItem() возвращает новую коллекцию, не изменяя исходную.
     */
    public function testWithItemReturnsImmutableCopy(): void
    {
        $original = new AcceptedTaskCollectionDto();
        $item = new AcceptedTaskDto(0, new TaskId('task-new'));

        $extended = $original->withItem($item);

        $this->assertCount(0, $original);
        $this->assertCount(1, $extended);
        $this->assertSame($item, iterator_to_array($extended)[0]);
    }

    /**
     * toJsonArray() сериализует элементы коллекции.
     */
    public function testToJsonArraySerializesItems(): void
    {
        $collection = new AcceptedTaskCollectionDto([
            new AcceptedTaskDto(3, new TaskId('task-x')),
        ]);

        $this->assertSame(
            [['requestTaskIndex' => 3, 'taskId' => 'task-x']],
            $collection->toJsonArray()
        );
    }

    /**
     * toArray() коллекции ответа совпадает с toJsonArray() через ArrayableFromJsonTrait.
     */
    public function testToArrayEqualsToJsonArrayForResponseCollection(): void
    {
        $collection = new AcceptedTaskCollectionDto([
            new AcceptedTaskDto(0, new TaskId('task-1')),
        ]);

        $this->assertSame($collection->toJsonArray(), $collection->toArray());
    }

    /**
     * Конструктор отклоняет элементы неверного типа.
     */
    public function testConstructorRejectsInvalidItemType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AcceptedTaskCollectionDto([new \stdClass()]);
    }

    /**
     * withItem() отклоняет добавление элемента неверного типа на уровне сигнатуры PHP.
     * Проверяем валидный сценарий цепочки двух вызовов withItem.
     */
    public function testChainedWithItemBuildsCollection(): void
    {
        $collection = (new AcceptedTaskCollectionDto())
            ->withItem(new AcceptedTaskDto(0, new TaskId('task-1')))
            ->withItem(new AcceptedTaskDto(1, new TaskId('task-2')));

        $this->assertCount(2, $collection);
    }

    /**
     * count() для пустой коллекции равен нулю.
     */
    public function testCountIsZeroForEmptyCollection(): void
    {
        $this->assertSame(0, (new AcceptedTaskCollectionDto())->count());
    }

    /**
     * Итератор пустой коллекции не возвращает элементов.
     */
    public function testEmptyCollectionIteratorHasNoElements(): void
    {
        $this->assertSame([], iterator_to_array(new AcceptedTaskCollectionDto()));
    }
}
