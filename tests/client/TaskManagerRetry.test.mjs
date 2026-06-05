/**
 * Тесты ретраев транспорта и unknownTasks TaskManager.
 */
import { describe, it, afterEach } from "node:test";
import assert from "node:assert/strict";
import { TaskManagerTestContext } from "./helpers/TaskManagerTestContext.mjs";
import { MockFetchFactory } from "./helpers/MockFetchFactory.mjs";

describe("TaskManagerRetry", () => {
    afterEach(async () => {
        await TaskManagerTestContext.disposeAll();
    });

    // fetch бросает Error — ретрай → успех.
    it("повторяет отправку после сетевой ошибки и затем принимает задачу", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            retryDelayMs: 40,
            mock: {
                failFirstRequests: 1,
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    TaskManagerTestContext.completeResponse("task-000001"),
                ],
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);
        await TaskManagerTestContext.sleep(60);

        assert.ok(state.requestCount >= 2);
    });

    // HTTP ok: false — ретрай с удвоением.
    it("планирует ретрай с увеличенной задержкой при HTTP ok false", async () => {
        let attempts = 0;
        const { manager } = TaskManagerTestContext.createManager({
            retryDelayMs: 40,
            maxRetryDelayMs: 500,
            mock: {
                handler: async () => {
                    attempts += 1;
                    if (attempts < 3) {
                        return { ok: false, status: 500, async json() { return { message: "err" }; } };
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
        await TaskManagerTestContext.sleep(90);

        assert.equal(attempts, 3);
    });

    // maxRetryDelayMs cap.
    it("ограничивает задержку ретрая значением maxRetryDelayMs", async () => {
        const timestamps = [];
        const { manager } = TaskManagerTestContext.createManager({
            retryDelayMs: 40,
            maxRetryDelayMs: 60,
            mock: {
                handler: async () => {
                    timestamps.push(Date.now());
                    if (timestamps.length <= 3) {
                        return { ok: false, status: 500, async json() { return {}; } };
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
        await TaskManagerTestContext.sleep(250);

        assert.ok(timestamps.length >= 3);
        if (timestamps.length >= 3) {
            const gap2 = timestamps[2] - timestamps[1];
            assert.ok(gap2 <= 120, `ожидался cap задержки, gap=${gap2}`);
        }
    });

    // Успешный ответ сбрасывает nextRetryDelayMs.
    it("сбрасывает задержку ретрая после успешного ответа", async () => {
        const timestamps = [];
        const { manager, state } = TaskManagerTestContext.createManager({
            retryDelayMs: 40,
            mock: {
                handler: async () => {
                    timestamps.push(Date.now());
                    const attempt = timestamps.length;
                    if (attempt === 1 || attempt === 3) {
                        return { ok: false, status: 500, async json() { return {}; } };
                    }
                    if (attempt === 2) {
                        return {
                            ok: true,
                            async json() {
                                return TaskManagerTestContext.acceptResponse("task-000001");
                            },
                        };
                    }
                    return {
                        ok: true,
                        async json() {
                            return TaskManagerTestContext.completeResponse("task-000001");
                        },
                    };
                },
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);
        await TaskManagerTestContext.sleep(50);
        await TaskManagerTestContext.sleep(90);

        assert.ok(state.requestCount >= 4);
        if (timestamps.length >= 4) {
            const gapAfterReset = timestamps[3] - timestamps[2];
            assert.ok(gapAfterReset < 70, `ожидался сброс задержки, gap=${gapAfterReset}`);
        }
    });

    // unknownTasks — requeue.
    it("повторно ставит задачу в batch при unknownTasks сохраняя колбэки", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    {
                        acceptedTasks: [],
                        completedTasks: [],
                        validationErrors: [],
                        cancelledTasks: [],
                        unknownTasks: [{ taskId: "task-000001", reason: "task_not_found" }],
                    },
                    TaskManagerTestContext.acceptResponse("task-000002"),
                    MockFetchFactory.createEmptyResponse(),
                ],
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);
        await TaskManagerTestContext.syncFlush(manager);

        const requeued = state.requests.find(
            (req, index) => index > 0 && req.body.tasks?.length === 1
                && req.body.tasks[0].method === "echo"
        );
        assert.ok(requeued);
    });

    // Ошибка транспорта снимает inFlight.
    it("повторно включает задачу в batch после снятия inFlight при ошибке", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            retryDelayMs: 30,
            mock: {
                failFirstRequests: 1,
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    TaskManagerTestContext.completeResponse("task-000001"),
                ],
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);
        await TaskManagerTestContext.sleep(50);

        const retryRequest = state.requests.find((req) => req.body.tasks?.length === 1);
        assert.ok(retryRequest);
    });

    // Невалидный объект ответа fetch — ретрай.
    it("планирует ретрай при невалидном объекте ответа fetch", async () => {
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

        assert.equal(attempts, 2);
        assert.equal(state.requestCount, 2);
    });

    // Подтверждение cancelledTaskIds — id удалён из буфера.
    it("удаляет cancelledTaskIds из буфера после успешного ответа", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    MockFetchFactory.createEmptyResponse(),
                    MockFetchFactory.createEmptyResponse(),
                ],
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);

        manager.cancelTasksByIds(["task-000001"]);
        await TaskManagerTestContext.syncFlush(manager);

        const cancelRequest = state.requests.find(
            (req) => req.body.cancelledTaskIds?.includes("task-000001")
        );
        assert.ok(cancelRequest);

        const cancelIndex = state.requests.indexOf(cancelRequest);
        await TaskManagerTestContext.syncFlush(manager);

        const duplicateCancel = state.requests
            .slice(cancelIndex + 1)
            .some((req) => req.body.cancelledTaskIds?.includes("task-000001"));
        assert.equal(duplicateCancel, false);
    });
});
