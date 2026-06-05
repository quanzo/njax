<?php

declare(strict_types=1);

namespace Tests\Task;

use app\njax\interfaces\task\executor\TaskMethodExecutorProviderInterface;

/**
 * Test-double провайдера исполнения, всегда выбрасывающий исключение.
 *
 * Используется для проверки безопасной обработки ошибок в {@see \app\njax\classes\task\TaskBatchHandler}.
 * Пример:
 * $executor = new FailingTaskMethodExecutorProviderStub();
 */
final class FailingTaskMethodExecutorProviderStub implements TaskMethodExecutorProviderInterface
{
    /**
     * Имитирует сбой исполнения команды.
     *
     * @param string $methodName Имя метода задачи.
     * @param mixed $payload Пейлоад задачи.
     *
     * @return mixed
     */
    public function execute(string $methodName, mixed $payload): mixed
    {
        throw new \RuntimeException('Искусственный сбой исполнения для теста.');
    }
}
