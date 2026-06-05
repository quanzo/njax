/**
 * Тесты опроса waitingTaskIds и single-flight TaskManager.
 */
import { describe, it, afterEach } from "node:test";
import assert from "node:assert/strict";
import { TaskManagerTestContext } from "./helpers/TaskManagerTestContext.mjs";
import { MockFetchFactory } from "./helpers/MockFetchFactory.mjs";

describe("TaskManagerPolling", () => {
    afterEach(async () => {
        await TaskManagerTestContext.disposeAll();
    });

    // submit → accept → poll → completed (миграция legacy).
    it("выполняет цикл submit accept poll complete и вызывает success callback", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            statusCheckIntervalMs: 80,
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    TaskManagerTestContext.completeResponse("task-000001", { ok: true }),
                ],
            },
        });

        let resolved = false;
        manager.submitTask("echo", { value: 1 }, () => {
            resolved = true;
        });

        await TaskManagerTestContext.syncFlush(manager);
        await TaskManagerTestContext.sleep(120);

        assert.ok(state.requestCount >= 2);
        assert.equal(resolved, true);
    });

    // Долгий fetch + poll timer — single-flight.
    it("не допускает параллельный HTTP при долгом fetch и срабатывании poll timer", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            statusCheckIntervalMs: 40,
            mock: {
                delayMs: 60,
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    TaskManagerTestContext.completeResponse("task-000001"),
                ],
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        const flushPromise = TaskManagerTestContext.syncFlush(manager);
        await TaskManagerTestContext.sleep(50);
        await flushPromise;
        await TaskManagerTestContext.sleep(120);

        assert.ok(state.maxParallelInFlight <= 1);
    });

    // Второй poll не раньше statusCheckIntervalMs.
    it("не выполняет второй poll раньше statusCheckIntervalMs", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            statusCheckIntervalMs: 300,
            mock: {
                delayMs: 200,
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    TaskManagerTestContext.completeResponse("task-000001"),
                ],
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.sleep(30);
        assert.equal(state.requestCount, 1);

        await TaskManagerTestContext.sleep(120);
        assert.equal(state.requestCount, 1);

        await TaskManagerTestContext.sleep(200);
        assert.ok(state.requestCount >= 2);
    });

    // Все задачи завершены — нет лишних HTTP.
    it("не отправляет лишние HTTP после завершения всех задач", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            statusCheckIntervalMs: 60,
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    TaskManagerTestContext.completeResponse("task-000001"),
                ],
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncAcceptAndComplete(manager);

        const countAfterComplete = state.requestCount;
        await TaskManagerTestContext.sleep(200);

        assert.equal(state.requestCount, countAfterComplete);
    });

    // Параллельный flush при isRequestInFlight — no-op.
    it("игнорирует параллельный forceFlush пока запрос в полёте", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            mock: {
                delayMs: 50,
                responses: [
                    TaskManagerTestContext.acceptResponse(),
                    TaskManagerTestContext.completeResponse("task-000001"),
                ],
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        const flush1 = manager.forceFlush();
        const flush2 = manager.forceFlush();
        await Promise.all([flush1, flush2]);

        assert.equal(state.maxParallelInFlight, 1);
        assert.ok(state.requestCount >= 1);
    });

    // waitingTaskIds пуст — poll timer не планирует HTTP.
    it("не планирует poll HTTP когда waitingTaskIds пуст", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            statusCheckIntervalMs: 60,
            mock: { responses: [MockFetchFactory.createEmptyResponse()] },
        });

        manager.submitTask("echo", { value: 1 }, () => {}, () => {});
        await TaskManagerTestContext.syncFlush(manager);

        const countAfterReject = state.requestCount;
        await TaskManagerTestContext.sleep(150);

        assert.equal(state.requestCount, countAfterReject);
    });
});
