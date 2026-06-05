<?php

declare(strict_types=1);

namespace app\njax\interfaces\task\executor;

/**
 * Интерфейс провайдера выполнения методов задач.
 * Определяет контракт выполнения обработчиков задач на основе метода.
 * Пример:
 * $result = $executor->execute('getData', ['id' => 1]);
 */
interface TaskMethodExecutorProviderInterface
{
    /**
     * Выполнить метод.
     * Запускает метод задачи с пейлоадом и возвращает результат метода.
     *
     * @param string $methodName Имя запрошенного метода.
     * @param mixed $payload Пейлоад задачи.
     *
     * @return mixed
     */
    public function execute(string $methodName, mixed $payload): mixed;
}
