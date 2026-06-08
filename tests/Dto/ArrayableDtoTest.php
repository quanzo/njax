<?php

declare(strict_types=1);

namespace Tests\Dto;

use app\modules\njax\classes\dto\task\queue\TaskIdCollectionDto;
use app\modules\njax\classes\dto\task\request\TaskDefinitionCollectionDto;
use app\modules\njax\classes\dto\task\request\TaskDefinitionDto;
use app\modules\njax\classes\task\TaskId;
use app\modules\njax\interfaces\serialization\IArrayable;
use app\modules\njax\interfaces\serialization\IJsonArrayable;
use PHPUnit\Framework\TestCase;

/**
 * Тесты доменного контракта {@see IArrayable} для коллекций DTO.
 */
final class ArrayableDtoTest extends TestCase
{
    /**
     * TaskIdCollectionDto реализует IJsonArrayable и через него IArrayable.
     */
    public function testTaskIdCollectionDtoImplementsBothInterfaces(): void
    {
        $taskId = new TaskId('task-1');
        $collection = new TaskIdCollectionDto([$taskId]);

        $this->assertInstanceOf(IJsonArrayable::class, $collection);
        $this->assertInstanceOf(IArrayable::class, $collection);
        $this->assertSame([$taskId], $collection->toArray());
    }

    /**
     * TaskDefinitionCollectionDto реализует только IArrayable и возвращает объекты TaskDefinitionDto.
     */
    public function testTaskDefinitionCollectionDtoImplementsIArrayable(): void
    {
        $definition = new TaskDefinitionDto('echo', ['value' => 1]);
        $collection = new TaskDefinitionCollectionDto([$definition]);

        $this->assertInstanceOf(IArrayable::class, $collection);
        $this->assertNotInstanceOf(IJsonArrayable::class, $collection);
        $this->assertSame([$definition], $collection->toArray());
    }

    /**
     * toArray() у TaskIdCollectionDto возвращает объекты, toJsonArray() — строки.
     */
    public function testTaskIdCollectionDomainAndJsonRepresentationsDiffer(): void
    {
        $collection = new TaskIdCollectionDto([new TaskId('task-x')]);

        $domainItems = $collection->toArray();
        $jsonItems = $collection->toJsonArray();

        $this->assertCount(1, $domainItems);
        $this->assertInstanceOf(TaskId::class, $domainItems[0]);
        $this->assertSame(['task-x'], $jsonItems);
    }
}
