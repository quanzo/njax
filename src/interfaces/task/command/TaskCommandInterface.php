<?php

declare(strict_types=1);

namespace app\njax\interfaces\task\command;

/**
 * Контракт прикладной команды, доступной для постановки и исполнения в task-endpoint.
 *
 * Команда должна:
 * - объявлять стабильное имя, по которому она вызывается клиентом;
 * - уметь статически валидировать входной пейлоад;
 * - исполнять бизнес-логику и возвращать результат.
 */
interface TaskCommandInterface
{
    /**
     * Возвращает имя команды, с которым клиент обращается в поле task.method.
     *
     * @return string
     */
    public function getCommandName(): string;

    /**
     * Выполняет команду с переданным пейлоадом.
     *
     * @param mixed $payload Параметры команды.
     *
     * @return mixed
     */
    public function execute(mixed $payload): mixed;

    /**
     * Выполняет статическую валидацию параметров команды.
     *
     * Метод обязан выбрасывать доменную ошибку валидации, если входные данные
     * не подходят для безопасного исполнения команды.
     *
     * @param mixed $payload Параметры команды.
     *
     * @return void
     */
    public static function validateInput(mixed $payload): void;
}
