<?php

declare(strict_types=1);

namespace Tests\Dto;

use app\njax\classes\dto\task\request\TaskDefinitionDto;
use app\njax\classes\dto\task\response\AcceptedTaskCollectionDto;
use app\njax\classes\dto\task\response\AcceptedTaskDto;
use app\njax\classes\task\TaskId;
use app\njax\classes\dto\task\queue\TaskIdCollectionDto;
use app\njax\helpers\JsonArrayableHelper;
use app\njax\interfaces\serialization\IJsonArrayable;
use app\njax\traits\serialization\ArrayableFromJsonTrait;
use PHPUnit\Framework\TestCase;

/**
 * Тесты рекурсивной JSON-сериализации {@see JsonArrayableHelper}.
 */
final class JsonArrayableHelperTest extends TestCase
{
    /**
     * Сериализует объект, реализующий IJsonArrayable.
     */
    public function testToJsonArrayWithJsonArrayableObject(): void
    {
        $dto = new TaskDefinitionDto('echo', ['value' => 1]);

        $result = JsonArrayableHelper::toJsonArray($dto);

        $this->assertSame(['method' => 'echo', 'payload' => ['value' => 1]], $result);
    }

    /**
     * Возвращает скаляр без изменений.
     */
    public function testToJsonArrayWithScalarString(): void
    {
        $this->assertSame('text', JsonArrayableHelper::toJsonArray('text'));
    }

    /**
     * Возвращает целое число без изменений.
     */
    public function testToJsonArrayWithScalarInt(): void
    {
        $this->assertSame(42, JsonArrayableHelper::toJsonArray(42));
    }

    /**
     * Возвращает null без изменений.
     */
    public function testToJsonArrayWithNull(): void
    {
        $this->assertNull(JsonArrayableHelper::toJsonArray(null));
    }

    /**
     * Возвращает булево значение без изменений.
     */
    public function testToJsonArrayWithBoolean(): void
    {
        $this->assertFalse(JsonArrayableHelper::toJsonArray(false));
    }

    /**
     * Рекурсивно обрабатывает простой ассоциативный массив.
     */
    public function testToJsonArrayWithFlatArray(): void
    {
        $input = ['a' => 1, 'b' => 'two'];

        $this->assertSame($input, JsonArrayableHelper::toJsonArray($input));
    }

    /**
     * Рекурсивно сериализует вложенные IJsonArrayable внутри массива.
     */
    public function testToJsonArrayWithNestedJsonArrayableInArray(): void
    {
        $dto = new AcceptedTaskDto(0, new TaskId('task-1'));
        $input = ['items' => [$dto]];

        $result = JsonArrayableHelper::toJsonArray($input);

        $this->assertSame(
            ['items' => [['requestTaskIndex' => 0, 'taskId' => 'task-1']]],
            $result
        );
    }

    /**
     * Сериализует коллекцию IJsonArrayable-элементов через вложенный вызов toJsonArray.
     */
    public function testToJsonArrayWithJsonArrayableCollection(): void
    {
        $collection = new AcceptedTaskCollectionDto([
            new AcceptedTaskDto(0, new TaskId('task-a')),
            new AcceptedTaskDto(1, new TaskId('task-b')),
        ]);

        $result = JsonArrayableHelper::toJsonArray($collection);

        $this->assertCount(2, $result);
        $this->assertSame('task-a', $result[0]['taskId']);
        $this->assertSame(1, $result[1]['requestTaskIndex']);
    }

    /**
     * Обрабатывает смешанный массив из скаляров и IJsonArrayable.
     */
    public function testToJsonArrayWithMixedArray(): void
    {
        $dto = new TaskDefinitionDto('sum', ['numbers' => [1, 2]]);
        $input = [
            'meta' => 'ok',
            'count' => 2,
            'task' => $dto,
            'flags' => [true, null],
        ];

        $result = JsonArrayableHelper::toJsonArray($input);

        $this->assertSame('ok', $result['meta']);
        $this->assertSame(2, $result['count']);
        $this->assertSame('sum', $result['task']['method']);
        $this->assertSame([true, null], $result['flags']);
    }

    /**
     * Обрабатывает пустой массив.
     */
    public function testToJsonArrayWithEmptyArray(): void
    {
        $this->assertSame([], JsonArrayableHelper::toJsonArray([]));
    }

    /**
     * Обрабатывает списочный массив с числовыми ключами.
     */
    public function testToJsonArrayWithListArray(): void
    {
        $input = [1, 2, 3];

        $this->assertSame($input, JsonArrayableHelper::toJsonArray($input));
    }

    /**
     * Сериализует анонимный IJsonArrayable с произвольной структурой.
     */
    public function testToJsonArrayWithAnonymousJsonArrayable(): void
    {
        $arrayable = new class () implements IJsonArrayable {
            use ArrayableFromJsonTrait;

            public function toJsonArray(): array
            {
                return ['nested' => ['x' => 1]];
            }
        };

        $result = JsonArrayableHelper::toJsonArray($arrayable);

        $this->assertSame(['nested' => ['x' => 1]], $result);
    }

    /**
     * Сериализует TaskIdCollectionDto в массив строковых идентификаторов.
     */
    public function testToJsonArrayWithTaskIdCollection(): void
    {
        $collection = new TaskIdCollectionDto([
            new TaskId('task-a'),
            new TaskId('task-b'),
        ]);

        $this->assertSame(['task-a', 'task-b'], JsonArrayableHelper::toJsonArray($collection));
    }
}
