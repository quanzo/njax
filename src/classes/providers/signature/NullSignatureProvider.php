<?php

declare(strict_types=1);

namespace app\modules\njax\classes\providers\signature;

use app\modules\njax\classes\dto\task\request\ClientTaskBatchRequestDto;
use app\modules\njax\classes\dto\http\RequestContextDto;
use app\modules\njax\classes\dto\security\SignatureVerificationResultDto;
use app\modules\njax\interfaces\security\RequestSignatureProviderInterface;

/**
 * Null-провайдер подписи.
 * Принимает любой запрос без проверки подписи.
 * Пример:
 * $provider = new NullSignatureProvider();
 */
final class NullSignatureProvider implements RequestSignatureProviderInterface
{
    /**
     * Проверить подпись.
     * Возвращает успешный результат проверки подписи для любого запроса.
     *
     * @param ClientTaskBatchRequestDto $request Распарсенный DTO запроса.
     * @param RequestContextDto $context Метаданные контекста запроса.
     *
     * @return SignatureVerificationResultDto
     */
    public function verify(ClientTaskBatchRequestDto $request, RequestContextDto $context): SignatureVerificationResultDto
    {
        return new SignatureVerificationResultDto(true, null);
    }
}
