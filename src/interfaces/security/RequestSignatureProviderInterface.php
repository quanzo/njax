<?php

declare(strict_types=1);

namespace app\modules\njax\interfaces\security;

use app\modules\njax\classes\dto\task\request\ClientTaskBatchRequestDto;
use app\modules\njax\classes\dto\http\RequestContextDto;
use app\modules\njax\classes\dto\security\SignatureVerificationResultDto;

/**
 * Интерфейс провайдера подписи запроса.
 * Определяет стратегию проверки подписи для запросов пакетной обработки задач.
 * Пример:
 * $result = $signatureProvider->verify($request, $context);
 */
interface RequestSignatureProviderInterface
{
    /**
     * Проверить подпись.
     * Валидирует подпись запроса и возвращает результат проверки.
     *
     * @param ClientTaskBatchRequestDto $request Распарсенный DTO запроса.
     * @param RequestContextDto $context Метаданные контекста запроса.
     *
     * @return SignatureVerificationResultDto
     */
    public function verify(ClientTaskBatchRequestDto $request, RequestContextDto $context): SignatureVerificationResultDto;
}
