<?php

declare(strict_types=1);

namespace app\njax\commands;

use app\njax\classes\commands\AbstractTaskCommand;

/**
 * Команда, возвращающая входные параметры без модификаций.
 *
 * Используется как простой сценарий проверки транспорта и цепочки исполнения.
 */
final class EchoTaskCommand extends AbstractTaskCommand
{
    /**
     * Возвращает имя команды, доступное клиенту в поле task.method.
     *
     * @return string
     */
    public function getCommandName(): string
    {
        return 'echo';
    }

    /**
     * Для echo-команды допустим любой JSON-совместимый пейлоад.
     *
     * @param mixed $payload Параметры команды.
     *
     * @return void
     */
    public static function validateInput(mixed $payload): void
    {
    }

    /**
     * Возвращает исходный пейлоад как результат.
     *
     * @param mixed $payload Валидированный пейлоад.
     *
     * @return mixed
     */
    protected function executeValidated(mixed $payload): mixed
    {
        return $payload;
    }
}
