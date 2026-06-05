/**
 * Тесты HTTP-транспорта TaskManager (#sendRequest).
 */
import { describe, it, afterEach } from "node:test";
import assert from "node:assert/strict";
import { TaskManagerTestContext } from "./helpers/TaskManagerTestContext.mjs";
import { MockFetchFactory } from "./helpers/MockFetchFactory.mjs";

describe("TaskManagerTransport", () => {
    afterEach(async () => {
        await TaskManagerTestContext.disposeAll();
    });
    // POST, headers, url.
    it("отправляет POST на endpointUrl с Content-Type application/json", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            endpointUrl: "/custom-batch-endpoint",
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);

        assert.equal(state.requests[0].url, "/custom-batch-endpoint");
        assert.equal(state.requests[0].method, "POST");
        assert.equal(state.requests[0].headers["Content-Type"], "application/json");
    });

    // HTTP error с message — ретрай.
    it("инициирует ретрай при HTTP ошибке с message в теле ответа", async () => {
        let attempts = 0;
        const { manager, state } = TaskManagerTestContext.createManager({
            retryDelayMs: 35,
            mock: {
                handler: async () => {
                    attempts += 1;
                    if (attempts === 1) {
                        return {
                            ok: false,
                            status: 422,
                            async json() {
                                return { message: "Malformed batch" };
                            },
                        };
                    }
                    return {
                        ok: true,
                        async json() {
                            return MockFetchFactory.createEmptyResponse();
                        },
                    };
                },
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);
        await TaskManagerTestContext.sleep(50);

        assert.equal(attempts, 2);
        assert.equal(state.requestCount, 2);
    });

    // HTTP error без message — ретрай (дефолтное сообщение внутри throw).
    it("планирует ретрай при HTTP ошибке без message в теле ответа", async () => {
        let attempts = 0;
        const { manager, state } = TaskManagerTestContext.createManager({
            retryDelayMs: 35,
            mock: {
                handler: async () => {
                    attempts += 1;
                    if (attempts === 1) {
                        return {
                            ok: false,
                            status: 500,
                            async json() {
                                return {};
                            },
                        };
                    }
                    return {
                        ok: true,
                        async json() {
                            return MockFetchFactory.createEmptyResponse();
                        },
                    };
                },
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);
        await TaskManagerTestContext.sleep(50);

        assert.equal(attempts, 2);
        assert.equal(state.requestCount, 2);
    });

    // response без ok: boolean — invalid fetch response.
    it("обрабатывает невалидный объект ответа fetch как транспортную ошибку", async () => {
        let attempts = 0;
        const { manager, state } = TaskManagerTestContext.createManager({
            retryDelayMs: 35,
            mock: {
                handler: async () => {
                    attempts += 1;
                    if (attempts === 1) {
                        return { status: 200 };
                    }
                    return {
                        ok: true,
                        async json() {
                            return MockFetchFactory.createEmptyResponse();
                        },
                    };
                },
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);
        await TaskManagerTestContext.sleep(50);

        assert.equal(state.requestCount, 2);
    });
});
