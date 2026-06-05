/**
 * Фабрика mock fetch для client-тестов TaskManager.
 *
 * Единая точка для эмуляции ответов сервера, подсчёта параллельных HTTP
 * и сохранения тел batch-запросов для assert.
 *
 * @example
 * const { fetchFn, state } = MockFetchFactory.createSequentialResponder([
 *   { acceptedTasks: [{ requestTaskIndex: 0, taskId: "task-000001" }], ... },
 * ]);
 */
export class MockFetchFactory {
    /**
     * Пустой успешный ответ batch-endpoint.
     *
     * @returns {Object} JSON-ответ сервера без изменений состояния.
     */
    static createEmptyResponse() {
        return {
            acceptedTasks: [],
            completedTasks: [],
            validationErrors: [],
            cancelledTasks: [],
            unknownTasks: [],
        };
    }

    /**
     * Создать mock `fetchFn` с настраиваемым поведением.
     *
     * @param {Object} [options] Параметры mock.
     * @param {Object[]} [options.responses] Очередь JSON-ответов (последний повторяется).
     * @param {Function} [options.handler] Кастомный обработчик `(context) => response`.
     * @param {number} [options.delayMs] Задержка перед ответом.
     * @param {boolean} [options.ok=true] HTTP-успех (`response.ok`).
     * @param {number} [options.status=500] HTTP-статус при `ok: false`.
     * @param {boolean|string} [options.throwNetworkError] Бросить до формирования ответа.
     * @param {number} [options.failFirstRequests=0] Число первых HTTP-запросов, которые бросают сетевую ошибку.
     * @param {boolean} [options.invalidResponseObject] Вернуть объект без `ok: boolean`.
     * @param {Function} [options.jsonThrows] Если задан — `response.json()` бросает ошибку.
     *
     * @returns {{ fetchFn: Function, state: Object }} Пара fetch и мутабельное состояние.
     */
    static create(options = {}) {
        const state = {
            parallelInFlight: 0,
            maxParallelInFlight: 0,
            requests: [],
            requestCount: 0,
        };

        const responses = options.responses ?? [MockFetchFactory.createEmptyResponse()];
        let responseIndex = 0;

        const fetchFn = async (url, init) => {
            state.parallelInFlight += 1;
            state.maxParallelInFlight = Math.max(state.maxParallelInFlight, state.parallelInFlight);
            state.requestCount += 1;

            const requestIndex = state.requestCount - 1;
            const body = JSON.parse(init.body);
            state.requests.push({
                url,
                method: init.method,
                headers: init.headers,
                body,
            });

            try {
                if (options.delayMs) {
                    await new Promise((resolve) => setTimeout(resolve, options.delayMs));
                }

                if (options.throwNetworkError) {
                    const message = options.throwNetworkError === true
                        ? "Network error"
                        : String(options.throwNetworkError);
                    throw new Error(message);
                }

                const failFirstRequests = Number(options.failFirstRequests ?? 0);
                if (failFirstRequests > 0 && state.requestCount <= failFirstRequests) {
                    throw new Error("Network error");
                }

                if (typeof options.handler === "function") {
                    return await options.handler({
                        url,
                        init,
                        body,
                        state,
                        requestIndex,
                    });
                }

                const responseBody = responses[Math.min(responseIndex, responses.length - 1)];
                if (responseIndex < responses.length - 1) {
                    responseIndex += 1;
                }

                if (options.invalidResponseObject) {
                    return { status: 200 };
                }

                const ok = options.ok !== false;

                return {
                    ok,
                    status: ok ? 200 : (options.status ?? 500),
                    async json() {
                        if (typeof options.jsonThrows === "function") {
                            throw options.jsonThrows();
                        }

                        return responseBody;
                    },
                };
            } finally {
                state.parallelInFlight -= 1;
            }
        };

        return { fetchFn, state };
    }

    /**
     * Создать mock с очередью последовательных ответов.
     *
     * @param {Object[]} responses Массив JSON-ответов сервера.
     *
     * @returns {{ fetchFn: Function, state: Object }}
     */
    static createSequentialResponder(responses) {
        return MockFetchFactory.create({ responses });
    }

    /**
     * Создать mock с кастомным обработчиком каждого запроса.
     *
     * @param {Function} handler Обработчик запроса.
     *
     * @returns {{ fetchFn: Function, state: Object }}
     */
    static createHandler(handler) {
        return MockFetchFactory.create({ handler });
    }
}
