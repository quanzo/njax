<?php

declare(strict_types=1);

namespace app\njax\classes\providers\authorization;

use app\njax\classes\dto\security\AuthorizationRequestDto;
use app\njax\classes\dto\security\AuthorizationResultDto;
use app\njax\interfaces\security\AuthorizationProviderInterface;

/**
 * Stub провайдера авторизации по пользователю из контекста.
 * Разрешает публичные endpoint и требует аутентифицированного пользователя для защищенных endpoint.
 * Пример:
 * $provider = new ContextUserAuthorizationProviderStub();
 */
final class ContextUserAuthorizationProviderStub implements AuthorizationProviderInterface
{
    /**
     * Авторизовать запрос.
     * Валидирует авторизацию согласно политике endpoint и id пользователя из контекста.
     *
     * @param AuthorizationRequestDto $request Дескриптор запроса авторизации.
     *
     * @return AuthorizationResultDto
     */
    public function authorize(AuthorizationRequestDto $request): AuthorizationResultDto
    {
        if ($request->requiresAuthorization() === false) {
            return new AuthorizationResultDto(true, null, null);
        }

        if ($request->getContext()->getAuthenticatedUserId() === null) {
            return new AuthorizationResultDto(false, 401, 'Authentication required.');
        }

        return new AuthorizationResultDto(true, null, null);
    }
}
