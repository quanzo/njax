<?php

declare(strict_types=1);

namespace app\modules\njax\classes\task;

/**
 * Value-object идентификатора задачи.
 * Хранит и валидирует идентификатор задачи, используемый в операциях очереди.
 * Пример:
 * $taskId = new TaskId('task-0001');
 */
final class TaskId
{
    /**
     * @var string
     */
    private string $value;

    /**
     * Конструктор.
     * Создает валидированный неизменяемый идентификатор задачи.
     *
     * @param string $value Исходное значение идентификатора.
     */
    public function __construct(string $value)
    {
        $trimmedValue = trim($value);
        if ($trimmedValue === '') {
            throw new \InvalidArgumentException('Task id cannot be empty.');
        }

        if (!preg_match('/^[a-zA-Z0-9\-_:.]+$/', $trimmedValue)) {
            throw new \InvalidArgumentException('Task id contains unsupported characters.');
        }

        $this->value = $trimmedValue;
    }

    /**
     * Получить скалярное значение.
     * Возвращает идентификатор в виде строки.
     *
     * @return string
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Сравнить идентификаторы.
     * Проверяет, представляют ли два идентификатора задач одно и то же значение.
     *
     * @param TaskId $other Другой идентификатор задачи.
     *
     * @return bool
     */
    public function equals(TaskId $other): bool
    {
        return $this->value === $other->value;
    }
}
