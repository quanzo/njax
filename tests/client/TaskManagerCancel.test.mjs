/**
 * Тесты отмены задач и dispose TaskManager.
 */
import { describe, it, afterEach } from "node:test";
import assert from "node:assert/strict";
import { TaskManagerTestContext } from "./helpers/TaskManagerTestContext.mjs";
import { MockFetchFactory } from "./helpers/MockFetchFactory.mjs";

describe("TaskManagerCancel", () => {
    afterEach(async () => {
        await TaskManagerTestContext.disposeAll();
    });

    // cancelTask до accept — задача не в batch.
    it("не отправляет отменённую до accept задачу на сервер", async () => {
        const { manager, state } = TaskManagerTestContext.createManager();

        let called = false;
        const fp = manager.submitTask("echo", { value: 1 }, () => {
            called = true;
        });
        manager.cancelTask(fp);

        await TaskManagerTestContext.flushMicrotasks();

        assert.equal(state.requestCount, 0);
        assert.equal(called, false);
    });

    // cancelTask после accept — cancelledTaskIds.
    it("добавляет cancelledTaskIds после cancelTask с известным taskId", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    MockFetchFactory.createEmptyResponse(),
                ],
            },
        });

        const fp = manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);

        manager.cancelTask(fp);
        await TaskManagerTestContext.syncFlush(manager);

        const cancelReq = state.requests.find((r) => r.body.cancelledTaskIds?.includes("task-000001"));
        assert.ok(cancelReq);
    });

    // cancelTask unknown fingerprint — no-op.
    it("безопасно игнорирует cancelTask с неизвестным fingerprint", () => {
        const { manager } = TaskManagerTestContext.createManager();

        assert.doesNotThrow(() => {
            manager.cancelTask("unknown:fingerprint");
        });
    });

    // cancelTasksByIds — валидные id.
    it("очищает локальное состояние и ставит cancelledTaskIds при cancelTasksByIds", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    MockFetchFactory.createEmptyResponse(),
                ],
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);

        manager.cancelTasksByIds(["task-000001"]);
        await TaskManagerTestContext.syncFlush(manager);

        const req = state.requests.find((r) => r.body.cancelledTaskIds?.includes("task-000001"));
        assert.ok(req);
    });

    // cancelTasksByIds — не массив.
    it("игнорирует cancelTasksByIds если аргумент не массив", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            statusCheckIntervalMs: 5000,
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    TaskManagerTestContext.completeResponse("task-000001"),
                ],
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncAcceptAndComplete(manager);

        const before = state.requestCount;
        manager.cancelTasksByIds("not-array");
        await TaskManagerTestContext.flushMicrotasks();

        assert.equal(state.requestCount, before);
    });

    // cancelTasksByIds — невалидные id пропускаются.
    it("пропускает пустые и невалидные элементы в cancelTasksByIds", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    MockFetchFactory.createEmptyResponse(),
                ],
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);

        manager.cancelTasksByIds(["", "   ", 123, "task-000001"]);
        await TaskManagerTestContext.syncFlush(manager);

        const req = state.requests.find((r) => r.body.cancelledTaskIds?.includes("task-000001"));
        assert.ok(req);
        assert.equal(req.body.cancelledTaskIds.includes(""), false);
    });

    // cancelTasksByIds — unknown taskId всё равно в cancelledTaskIds.
    it("добавляет unknown taskId в cancelledTaskIds даже без локального fingerprint", async () => {
        const { manager, state } = TaskManagerTestContext.createManager();

        manager.cancelTasksByIds(["task-external"]);
        await TaskManagerTestContext.syncFlush(manager);

        assert.deepEqual(state.requests[0].body.cancelledTaskIds, ["task-external"]);
    });

    // dispose — финальный batch с отменой.
    it("отправляет финальный batch отмены при dispose для waiting задач", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    MockFetchFactory.createEmptyResponse(),
                ],
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);

        await manager.dispose();

        const disposeReq = state.requests.find(
            (r) => r.body.cancelledTaskIds?.includes("task-000001")
        );
        assert.ok(disposeReq);
    });

    // dispose — flush бросает ошибку, локальная очистка завершается.
    it("завершает локальную очистку dispose даже если финальный flush падает", async () => {
        const { manager } = TaskManagerTestContext.createManager({
            mock: {
                responses: [TaskManagerTestContext.acceptResponse("task-000001")],
                throwNetworkError: "dispose-fail",
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);

        await assert.doesNotReject(async () => {
            await manager.dispose();
        });
    });

    // dispose — останавливает poll и retry таймеры.
    it("останавливает poll и retry таймеры после dispose", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            statusCheckIntervalMs: 80,
            retryDelayMs: 80,
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    MockFetchFactory.createEmptyResponse(),
                ],
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});
        await TaskManagerTestContext.syncFlush(manager);

        await manager.dispose();

        const countAfterDispose = state.requestCount;
        await TaskManagerTestContext.sleep(200);
        await TaskManagerTestContext.flushMicrotasks();

        assert.equal(state.requestCount, countAfterDispose);
    });
});
