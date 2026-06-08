<?php

declare(strict_types=1);

namespace app\modules\njax\interfaces\security;

use app\modules\njax\classes\dto\security\AuthorizationRequestDto;
use app\modules\njax\classes\dto\security\AuthorizationResultDto;

/**
 * Интерфейс провайдера авторизации.
 * Определяет абстракцию стратегии авторизации на уровне endpoint.
 * Пример:
 * $result = $authorizationProvider->authorize($authorizationRequest);
 */
interface AuthorizationProviderInterface
{
    /**
     * Авторизовать запрос.
     * Оценивает доступ к endpoint согласно переданному контексту и политике.
     *
     * @param AuthorizationRequestDto $request Дескриптор запроса авторизации.
     *
     * @return AuthorizationResultDto
     */
    public function authorize(AuthorizationRequestDto $request): AuthorizationResultDto;
}
