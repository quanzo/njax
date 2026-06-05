<?php

declare(strict_types=1);

namespace app\njax\classes\providers\signature;

use app\njax\classes\dto\task\request\ClientTaskBatchRequestDto;
use app\njax\classes\dto\http\RequestContextDto;
use app\njax\classes\dto\security\SignatureVerificationResultDto;
use app\njax\helpers\PayloadCanonicalizerHelper;
use app\njax\interfaces\security\RequestSignatureProviderInterface;

/**
 * Stub провайдера подписи HMAC SHA256.
 * Проверяет подписи запросов по key id и хешу HMAC-SHA256.
 * Пример:
 * $provider = new HmacSha256SignatureProviderStub(['demo-key' => 'secret'], false);
 */
final class HmacSha256SignatureProviderStub implements RequestSignatureProviderInterface
{
    /**
     * @var array<string, string>
     */
    private array $secretByKeyId;

    /**
     * @var bool
     */
    private bool $allowMissingSignature;

    /**
     * Конструктор.
     * Настраивает HMAC-секреты по key id.
     *
     * @param array<string, string> $secretByKeyId HMAC-секреты, сгруппированные по key id.
     * @param bool $allowMissingSignature Разрешены ли неподписанные запросы.
     */
    public function __construct(array $secretByKeyId, bool $allowMissingSignature = true)
    {
        $this->secretByKeyId = $secretByKeyId;
        $this->allowMissingSignature = $allowMissingSignature;
    }

    /**
     * Проверить подпись.
     * Валидирует подпись запроса с использованием настроенного HMAC-секрета.
     *
     * @param ClientTaskBatchRequestDto $request Распарсенный DTO запроса.
     * @param RequestContextDto $context Метаданные контекста запроса.
     *
     * @return SignatureVerificationResultDto
     */
    public function verify(ClientTaskBatchRequestDto $request, RequestContextDto $context): SignatureVerificationResultDto
    {
        $signature = $request->getSignature();
        if ($signature === null) {
            if ($this->allowMissingSignature === true) {
                return new SignatureVerificationResultDto(true, null);
            }

            return new SignatureVerificationResultDto(false, 'Request signature is required.');
        }

        $keyId = $signature->getKeyId();
        if (array_key_exists($keyId, $this->secretByKeyId) === false) {
            return new SignatureVerificationResultDto(false, 'Unknown signature key id.');
        }

        $payloadForSigning = $request->toJsonArray();
        unset($payloadForSigning['signature']);
        $canonicalPayload = PayloadCanonicalizerHelper::toCanonicalJson($payloadForSigning);
        $expectedHash = base64_encode(hash_hmac('sha256', $canonicalPayload, $this->secretByKeyId[$keyId], true));

        if (hash_equals($expectedHash, $signature->getHash()) === false) {
            return new SignatureVerificationResultDto(false, 'Invalid request signature.');
        }

        return new SignatureVerificationResultDto(true, null);
    }
}
