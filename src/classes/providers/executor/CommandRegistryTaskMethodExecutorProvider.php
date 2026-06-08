<?php

declare(strict_types=1);

namespace app\modules\njax\classes\providers\executor;

use app\modules\njax\interfaces\task\command\TaskCommandRegistryInterface;
use app\modules\njax\interfaces\task\executor\TaskMethodExecutorProviderInterface;

/**
 * Адаптер исполнения задач через реестр команд.
 *
 * Класс сохраняет существующий контракт TaskMethodExecutorProviderInterface,
 * но вместо ручного if/else-диспетчинга делегирует выбор команды реестру.
 */
final class CommandRegistryTaskMethodExecutorProvider implements TaskMethodExecutorProviderInterface
{
    /**
     * @var TaskCommandRegistryInterface
     */
    private TaskCommandRegistryInterface $taskCommandRegistry;

    /**
     * @param TaskCommandRegistryInterface $taskCommandRegistry Реестр зарегистрированных команд.
     */
    public function __construct(TaskCommandRegistryInterface $taskCommandRegistry)
    {
        $this->taskCommandRegistry = $taskCommandRegistry;
    }

    /**
     * Исполняет команду по имени через реестр.
     *
     * @param string $methodName Имя команды.
     * @param mixed $payload Параметры команды.
     *
     * @return mixed
     */
    public function execute(string $methodName, mixed $payload): mixed
    {
        return $this->taskCommandRegistry->execute($methodName, $payload);
    }
}
