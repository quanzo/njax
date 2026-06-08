<?php

declare(strict_types=1);

namespace app\modules\njax\classes\commands;

use app\modules\njax\exceptions\task\ValidationException;
use app\modules\njax\interfaces\task\command\TaskCommandInterface;
use app\modules\njax\interfaces\task\command\TaskCommandRegistryInterface;

/**
 * Реестр доступных команд task-endpoint.
 *
 * Хранит соответствие "имя команды -> экземпляр/класс", поддерживает
 * регистрацию в fluent-стиле и централизованную валидацию входа.
 */
final class TaskCommandRegistry implements TaskCommandRegistryInterface
{
    /**
     * @var array<string, TaskCommandInterface>
     */
    private array $commandsByName = [];

    /**
     * @var array<string, class-string<TaskCommandInterface>>
     */
    private array $commandClassByName = [];

    /**
     * Регистрирует класс команды и мгновенно делает ее доступной в реестре.
     *
     * @param class-string<TaskCommandInterface> $commandClass Полное имя класса команды.
     *
     * @return self
     */
    public function registerCommandClass(string $commandClass): self
    {
        if (is_subclass_of($commandClass, TaskCommandInterface::class) === false) {
            throw new \InvalidArgumentException(
                'Класс "' . $commandClass . '" должен реализовывать TaskCommandInterface.'
            );
        }

        /** @var TaskCommandInterface $command */
        $command = new $commandClass();

        return $this->registerCommand($command);
    }

    /**
     * Регистрирует экземпляр команды.
     *
     * Если команда с таким именем уже существовала, она будет заменена новым
     * экземпляром. Такой подход позволяет переопределять поведение для тестов.
     *
     * @param TaskCommandInterface $command Экземпляр команды.
     *
     * @return self
     */
    public function registerCommand(TaskCommandInterface $command): self
    {
        $commandName = trim($command->getCommandName());
        if ($commandName === '') {
            throw new \InvalidArgumentException('Имя команды не может быть пустым.');
        }

        $this->commandsByName[$commandName] = $command;
        /** @var class-string<TaskCommandInterface> $commandClass */
        $commandClass = $command::class;
        $this->commandClassByName[$commandName] = $commandClass;

        return $this;
    }

    /**
     * Проверяет наличие команды в реестре.
     *
     * @param string $commandName Имя команды.
     *
     * @return bool
     */
    public function hasCommand(string $commandName): bool
    {
        return array_key_exists($commandName, $this->commandsByName);
    }

    /**
     * Выполняет статическую валидацию входа для указанной команды.
     *
     * @param string $commandName Имя команды.
     * @param mixed $payload Пейлоад команды.
     *
     * @return void
     */
    public function validateCommandInput(string $commandName, mixed $payload): void
    {
        $this->assertCommandRegistered($commandName);
        $commandClass = $this->commandClassByName[$commandName];
        $commandClass::validateInput($payload);
    }

    /**
     * Исполняет команду через зарегистрированный экземпляр.
     *
     * @param string $commandName Имя команды.
     * @param mixed $payload Пейлоад команды.
     *
     * @return mixed
     */
    public function execute(string $commandName, mixed $payload): mixed
    {
        $this->assertCommandRegistered($commandName);

        return $this->commandsByName[$commandName]->execute($payload);
    }

    /**
     * Проверяет, что команда зарегистрирована, иначе выбрасывает ошибку валидации.
     *
     * @param string $commandName Имя команды.
     *
     * @return void
     */
    private function assertCommandRegistered(string $commandName): void
    {
        if ($this->hasCommand($commandName)) {
            return;
        }

        throw new ValidationException('Команда "' . $commandName . '" не зарегистрирована.');
    }
}
