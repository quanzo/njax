<?php

declare(strict_types=1);

namespace app\modules\njax\classes\providers\taskid;

use app\modules\njax\classes\task\TaskId;
use app\modules\njax\interfaces\task\taskid\TaskIdGeneratorInterface;

/**
 * Stub инкрементального генератора id задач.
 * Генерирует предсказуемые id задач для разработки и тестов.
 * Пример:
 * $generator = new IncrementalTaskIdGeneratorStub();
 */
final class IncrementalTaskIdGeneratorStub implements TaskIdGeneratorInterface
{
    /**
     * @var int
     */
    private int $counter;

    /**
     * Конструктор.
     * Инициализирует счетчик генератора.
     *
     * @param int $initialCounter Начальное значение счетчика.
     */
    public function __construct(int $initialCounter = 0)
    {
        $this->counter = $initialCounter;
    }

    /**
     * Сгенерировать id.
     * Формирует следующий инкрементальный id задачи.
     *
     * @return TaskId
     */
    public function generate(): TaskId
    {
        $this->counter++;

        return new TaskId('task-' . str_pad((string) $this->counter, 6, '0', STR_PAD_LEFT));
    }
}
