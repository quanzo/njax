<?php

declare(strict_types=1);

namespace app\njax\interfaces\task\command;

/**
 * Контракт реестра команд.
 *
 * Реестр отвечает за регистрацию доступных команд, поиск команды по имени,
 * предварительную валидацию входных данных и делегирование исполнения.
 */
interface TaskCommandRegistryInterface
{
    /**
     * Регистрирует класс команды и делает его доступным для вызова.
     *
     * @param class-string<TaskCommandInterface> $commandClass Полное имя класса команды.
     *
     * @return self
     */
    public function registerCommandClass(string $commandClass): self;

    /**
     * Регистрирует экземпляр команды.
     *
     * @param TaskCommandInterface $command Экземпляр команды.
     *
     * @return self
     */
    public function registerCommand(TaskCommandInterface $command): self;

    /**
     * Проверяет, зарегистрирована ли команда с заданным именем.
     *
     * @param string $commandName Имя команды.
     *
     * @return bool
     */
    public function hasCommand(string $commandName): bool;

    /**
     * Выполняет статическую валидацию входных параметров команды.
     *
     * @param string $commandName Имя команды.
     * @param mixed $payload Параметры команды.
     *
     * @return void
     */
    public function validateCommandInput(string $commandName, mixed $payload): void;

    /**
     * Исполняет команду и возвращает результат.
     *
     * @param string $commandName Имя команды.
     * @param mixed $payload Параметры команды.
     *
     * @return mixed
     */
    public function execute(string $commandName, mixed $payload): mixed;
}
