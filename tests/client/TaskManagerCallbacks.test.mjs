/**
 * Тесты обработки ответов сервера и колбэков TaskManager.
 */
import { describe, it, afterEach } from "node:test";
import assert from "node:assert/strict";
import { TaskManagerTestContext } from "./helpers/TaskManagerTestContext.mjs";

describe("TaskManagerCallbacks", () => {
    afterEach(async () => {
        await TaskManagerTestContext.disposeAll();
    });

    // validationErrors — callbackOnFail с validation_error.
    it("вызывает callbackOnFail с reason validation_error при validationErrors", async () => {
        const { manager } = TaskManagerTestContext.createManager({
            mock: {
                responses: [{
                    acceptedTasks: [],
                    completedTasks: [],
                    validationErrors: [{
                        requestTaskIndex: 0,
                        method: "sum",
                        message: "numbers required",
                    }],
                    cancelledTasks: [],
                    unknownTasks: [],
                }],
            },
        });

        const errors = [];
        manager.submitTask("sum", { numbers: [] }, () => {
            errors.push("success");
        }, (error) => {
            errors.push(error.reason);
        });

        await TaskManagerTestContext.syncFlush(manager);

        assert.deepEqual(errors, ["validation_error"]);
    });

    // enqueue_rejected — задача не принята без явной ошибки.
    it("вызывает callbackOnFail с enqueue_rejected если задача не в acceptedTasks", async () => {
        const { manager } = TaskManagerTestContext.createManager({
            mock: { responses: [mockEmptyResponse()] },
        });

        const reasons = [];
        manager.submitTask("echo", { value: 1 }, () => {}, (error) => {
            reasons.push(error.reason);
        });

        await TaskManagerTestContext.syncFlush(manager);

        assert.deepEqual(reasons, ["enqueue_rejected"]);
    });

    // completedTasks — success с meta.
    it("вызывает success callback с meta.taskId и meta.status", async () => {
        const { manager } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    TaskManagerTestContext.completeResponse("task-000001", { value: 42 }),
                ],
            },
        });

        const metas = [];
        manager.submitTask("echo", { value: 1 }, (result, meta) => {
            metas.push({ result, meta });
        });

        await TaskManagerTestContext.syncAcceptAndComplete(manager);

        assert.equal(metas.length, 1);
        assert.equal(metas[0].meta.taskId, "task-000001");
        assert.equal(metas[0].meta.status, "completed");
        assert.deepEqual(metas[0].result, { value: 42 });
    });

    // completedAt отсутствует — null.
    it("передаёт meta.completedAt = null если поле отсутствует в ответе", async () => {
        const { manager } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    {
                        acceptedTasks: [],
                        completedTasks: [{
                            taskId: "task-000001",
                            result: { ok: true },
                        }],
                        validationErrors: [],
                        cancelledTasks: [],
                        unknownTasks: [],
                    },
                ],
            },
        });

        let completedAt;
        manager.submitTask("echo", { value: 1 }, (_r, meta) => {
            completedAt = meta.completedAt;
        });

        await TaskManagerTestContext.syncAcceptAndComplete(manager);

        assert.equal(completedAt, null);
    });

    // status отсутствует — default completed.
    it("использует status completed по умолчанию", async () => {
        const { manager } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    {
                        acceptedTasks: [],
                        completedTasks: [{
                            taskId: "task-000001",
                            result: {},
                        }],
                        validationErrors: [],
                        cancelledTasks: [],
                        unknownTasks: [],
                    },
                ],
            },
        });

        let status;
        manager.submitTask("echo", { value: 1 }, (_r, meta) => {
            status = meta.status;
        });

        await TaskManagerTestContext.syncAcceptAndComplete(manager);

        assert.equal(status, "completed");
    });

    // Throw в success-callback — второй всё равно вызывается.
    it("не прерывает fan-out если success-callback бросает ошибку", async () => {
        const { manager } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse(),
                    TaskManagerTestContext.completeResponse("task-000001"),
                ],
            },
        });

        const calls = [];
        manager.submitTask("echo", { value: 1 }, () => {
            throw new Error("boom");
        });
        manager.submitTask("echo", { value: 1 }, () => {
            calls.push("ok");
        });

        await TaskManagerTestContext.syncAcceptAndComplete(manager);

        assert.deepEqual(calls, ["ok"]);
    });

    // Throw в fail-callback — остальные не прерываются.
    it("не прерывает fan-out fail-колбэков при throw в одном из них", async () => {
        const { manager } = TaskManagerTestContext.createManager({
            mock: {
                responses: [{
                    acceptedTasks: [],
                    completedTasks: [],
                    validationErrors: [{ requestTaskIndex: 0, method: "sum", message: "bad" }],
                    cancelledTasks: [],
                    unknownTasks: [],
                }],
            },
        });

        const calls = [];
        manager.submitTask("sum", { n: 1 }, () => {}, () => {
            throw new Error("fail-boom");
        });
        manager.submitTask("sum", { n: 1 }, () => {}, () => {
            calls.push("fail-ok");
        });

        await TaskManagerTestContext.syncFlush(manager);

        assert.deepEqual(calls, ["fail-ok"]);
    });

    // Без callbackOnFail — pending удалён тихо.
    it("удаляет pending без вызова колбэков если callbackOnFail не передан", async () => {
        const { manager } = TaskManagerTestContext.createManager({
            mock: {
                responses: [{
                    acceptedTasks: [],
                    completedTasks: [],
                    validationErrors: [{ requestTaskIndex: 0, method: "sum", message: "bad" }],
                    cancelledTasks: [],
                    unknownTasks: [],
                }],
            },
        });

        let successCalled = false;
        manager.submitTask("sum", { n: 1 }, () => {
            successCalled = true;
        });

        await TaskManagerTestContext.syncFlush(manager);

        assert.equal(successCalled, false);
    });

    // Partial accept: 1 accepted + 1 validationError.
    it("обрабатывает partial accept: валидная ждёт, невалидная получает fail", async () => {
        const { manager } = TaskManagerTestContext.createManager({
            mock: {
                responses: [{
                    acceptedTasks: [{ requestTaskIndex: 0, taskId: "task-ok" }],
                    completedTasks: [],
                    validationErrors: [{
                        requestTaskIndex: 1,
                        method: "sum",
                        message: "invalid",
                    }],
                    cancelledTasks: [],
                    unknownTasks: [],
                }],
            },
        });

        const outcomes = [];
        manager.submitTask("echo", { value: 1 }, () => {
            outcomes.push("echo-ok");
        });
        manager.submitTask("sum", { bad: true }, () => {
            outcomes.push("sum-ok");
        }, (err) => {
            outcomes.push(`sum-fail:${err.reason}`);
        });

        await TaskManagerTestContext.syncFlush(manager);

        assert.deepEqual(outcomes, ["sum-fail:validation_error"]);
    });

    // cancelledTasks от сервера — тихая очистка.
    it("тихо очищает состояние при cancelledTasks без вызова колбэков", async () => {
        const { manager, state } = TaskManagerTestContext.createManager({
            mock: {
                responses: [
                    TaskManagerTestContext.acceptResponse("task-000001"),
                    {
                        acceptedTasks: [],
                        completedTasks: [],
                        validationErrors: [],
                        cancelledTasks: [{ taskId: "task-000001" }],
                        unknownTasks: [],
                    },
                ],
            },
        });

        let called = false;
        manager.submitTask("echo", { value: 1 }, () => {
            called = true;
        });

        await TaskManagerTestContext.syncAcceptAndComplete(manager);

        assert.equal(called, false);
        assert.equal(state.requestCount, 2);
    });

    // completedTasks для неизвестного taskId — no-op.
    it("игнорирует completedTasks для неизвестного taskId без throw", async () => {
        const { manager } = TaskManagerTestContext.createManager({
            mock: {
                responses: [{
                    acceptedTasks: [],
                    completedTasks: [{ taskId: "unknown-id", result: { x: 1 } }],
                    validationErrors: [],
                    cancelledTasks: [],
                    unknownTasks: [],
                }],
            },
        });

        manager.submitTask("echo", { value: 1 }, () => {});

        await assert.doesNotReject(async () => {
            await TaskManagerTestContext.syncFlush(manager);
        });
    });
});

/**
 * Пустой ответ сервера для тестов enqueue_rejected.
 *
 * @returns {Object}
 */
function mockEmptyResponse() {
    return {
        acceptedTasks: [],
        completedTasks: [],
        validationErrors: [],
        cancelledTasks: [],
        unknownTasks: [],
    };
}
