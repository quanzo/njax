<?php

declare(strict_types=1);

namespace app\modules\njax\classes\providers\executor;

use app\modules\njax\interfaces\task\executor\TaskMethodExecutorProviderInterface;

/**
 * Stub провайдера выполнения методов задач по умолчанию.
 * Предоставляет простые примеры выполнения методов для демонстрации обработки очереди.
 * Пример:
 * $executor = new DefaultTaskMethodExecutorProviderStub();
 */
final class DefaultTaskMethodExecutorProviderStub implements TaskMethodExecutorProviderInterface
{
    /**
     * Выполнить метод.
     * Выполняет предопределенные демонстрационные методы и возвращает нормализованный результат.
     *
     * @param string $methodName Имя запрошенного метода.
     * @param mixed $payload Пейлоад задачи.
     *
     * @return mixed
     */
    public function execute(string $methodName, mixed $payload): mixed
    {
        if ($methodName === 'echo') {
            return $payload;
        }

        if ($methodName === 'sum') {
            $sum = 0;
            $numbers = is_array($payload) && isset($payload['numbers']) && is_array($payload['numbers'])
                ? $payload['numbers']
                : [];

            foreach ($numbers as $number) {
                $sum += (float) $number;
            }

            return [
                'sum' => $sum,
                'count' => count($numbers),
            ];
        }

        return [
            'method' => $methodName,
            'payload' => $payload,
            'note' => 'No specific handler configured, returning payload snapshot.',
        ];
    }
}
