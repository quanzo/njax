#!/usr/bin/env bash
# Запуск client-тестов TaskManager (node:test).
# Glob **/*.test.mjs в Node 20 нестабилен — перечисляем файлы явно.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

node --test \
  tests/client/TaskManagerConstructor.test.mjs \
  tests/client/TaskManagerSubmitTask.test.mjs \
  tests/client/TaskManagerBatching.test.mjs \
  tests/client/TaskManagerCallbacks.test.mjs \
  tests/client/TaskManagerPolling.test.mjs \
  tests/client/TaskManagerRetry.test.mjs \
  tests/client/TaskManagerCancel.test.mjs \
  tests/client/TaskManagerSigning.test.mjs \
  tests/client/TaskManagerTransport.test.mjs \
  tests/client/TaskManagerIntegration.test.mjs
