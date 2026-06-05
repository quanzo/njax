<?php

declare(strict_types=1);

namespace app\njax\interfaces\security;

use app\njax\classes\dto\task\request\ClientTaskBatchRequestDto;
use app\njax\classes\dto\http\RequestContextDto;
use app\njax\classes\dto\security\SignatureVerificationResultDto;

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
