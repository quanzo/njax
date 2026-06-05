<?php

declare(strict_types=1);

namespace app\njax\classes\providers\authorization;

use app\njax\classes\dto\security\AuthorizationRequestDto;
use app\njax\classes\dto\security\AuthorizationResultDto;
use app\njax\interfaces\security\AuthorizationProviderInterface;

/**
 * Stub провайдера авторизации с разрешением для всех.
 * Авторизует любой запрос и работает как no-op адаптер авторизации.
 * Пример:
 * $provider = new AllowAllAuthorizationProviderStub();
 */
final class AllowAllAuthorizationProviderStub implements AuthorizationProviderInterface
{
    /**
     * Авторизовать запрос.
     * Возвращает успешный результат авторизации для любого запроса.
     *
     * @param AuthorizationRequestDto $request Дескриптор запроса авторизации.
     *
     * @return AuthorizationResultDto
     */
    public function authorize(AuthorizationRequestDto $request): AuthorizationResultDto
    {
        return new AuthorizationResultDto(true, null, null);
    }
}
