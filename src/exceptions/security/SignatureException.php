<?php

declare(strict_types=1);

namespace app\modules\njax\exceptions\security;

use app\modules\njax\exceptions\task\TaskSystemException;

/**
 * Исключение подписи.
 * Сигнализирует об ошибке проверки подписи запроса.
 * Пример:
 * throw new SignatureException('Invalid request signature.');
 */
final class SignatureException extends TaskSystemException
{
}
