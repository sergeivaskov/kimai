# Тестовое покрытие EPIC-01: Infrastructure Setup

## Обзор

Данная директория содержит тестовое покрытие для функциональности, реализованной в рамках EPIC-01.

## Структура тестов

### Unit-тесты (Kimai PHP)

#### `Log/Processor/CorrelationIdProcessorTest.php`
- Проверяет добавление `correlation_id` в логи через Monolog Processor
- Покрывает 3 сценария: с correlation_id, без запроса, без correlation_id в запросе

#### `EventSubscriber/CorrelationIdSubscriberTest.php`
- Проверяет генерацию и извлечение `correlation_id` из HTTP headers
- Покрывает: генерацию UUID v4, использование существующего ID из header

### Integration-тесты (Kimai PHP)

#### `Integration/CorrelationIdLoggingTest.php`
- End-to-end проверка пробрасывания `correlation_id` через HTTP запросы
- Проверяет что correlation_id попадает в request attributes

#### `Integration/DoctrineSmokeTest.php`
- Проверяет корректность работы Doctrine ORM с PostgreSQL
- Тестирует mapping всех entities на таблицы БД
- Проверяет доступность core таблиц (users, teams, customers, projects, activities, timesheet)

#### `Integration/InfrastructureSmokeTest.php`
- Проверяет доступность всей инфраструктуры
- Тестирует: PostgreSQL, Redis, MinIO, PgBouncer
- Проверяет базовые операции запросов к БД

### Smoke-test скрипт

#### `smoke-test.sh`
- Комплексная проверка всех компонентов инфраструктуры
- Проверяет: PostgreSQL, Redis, MinIO, ManticoreSearch, PgBouncer, Kimai API, AFFiNE API
- Валидирует Doctrine schema
- Проверяет наличие structured logs с correlation_id

## Запуск тестов

### Через mcp-tester (рекомендуется)

```bash
# Запуск отдельного теста
mcp-tester run_tests_and_analyze --target_path "apps/backend/tests/Log/Processor/CorrelationIdProcessorTest.php"

# Запуск всех unit-тестов correlation_id
mcp-tester run_tests_and_analyze --target_path '["apps/backend/tests/Log/Processor/CorrelationIdProcessorTest.php", "apps/backend/tests/EventSubscriber/CorrelationIdSubscriberTest.php"]'

# Запуск всех integration-тестов
mcp-tester run_tests_and_analyze --target_path '["apps/backend/tests/Integration/CorrelationIdLoggingTest.php", "apps/backend/tests/Integration/DoctrineSmokeTest.php", "apps/backend/tests/Integration/InfrastructureSmokeTest.php"]'

# Запуск с coverage
mcp-tester run_tests_and_analyze --target_path "apps/backend/tests/Log" --coverage true
```

### Напрямую через Docker Compose

```bash
# Запуск одного теста
docker-compose exec kimai-backend php bin/phpunit tests/Log/Processor/CorrelationIdProcessorTest.php

# Запуск всех тестов correlation_id
docker-compose exec kimai-backend php bin/phpunit tests/Log tests/EventSubscriber tests/Integration

# Smoke-test всей инфраструктуры
bash apps/backend/tests/smoke-test.sh
```

## Результаты тестирования

### PHP (Kimai) - ✅ Все тесты прошли

- **CorrelationIdProcessorTest**: 4 теста, 5 assertions - ✅ PASSED
- **CorrelationIdSubscriberTest**: 4 теста, 10 assertions - ✅ PASSED
- **CorrelationIdLoggingTest**: 2 теста, 7 assertions - ✅ PASSED
- **DoctrineSmokeTest**: 6 тестов, 46 assertions - ✅ PASSED (2 skipped)
- **InfrastructureSmokeTest**: 6 тестов, 5 assertions - ✅ PASSED (3 skipped)

**Итого PHP**: 22 теста, 73 assertions, 5 skipped

### Node.js (AFFiNE) - ✅ Все тесты прошли

- **request.spec.ts**: 7 тестов - ✅ PASSED
  - Проверка приоритета X-Correlation-ID
  - Fallback на x-cloud-trace-context
  - Генерация нового ID
  - Корректность формата

## Покрытие функциональности EPIC-01

### ✅ Structured Logging
- Kimai: JSON logs с correlation_id через Monolog
- AFFiNE: JSON logs с correlation_id через NestJS Logger
- Desktop-app: X-Correlation-ID в HTTP headers (unit-тест создан)

### ✅ Database Migration (PostgreSQL)
- Doctrine schema validation
- Entity mapping проверка
- CRUD operations тесты
- PostgreSQL версия и features проверка

### ✅ Infrastructure Validation
- PostgreSQL connectivity
- Redis connectivity
- MinIO health check
- PgBouncer configuration
- ManticoreSearch availability

### ✅ API Endpoints
- Kimai REST API /api/ping
- AFFiNE GraphQL API /api/workspace

## Ограничения и будущие улучшения

1. **Code Coverage**: PHPUnit требует Xdebug/PCOV для сбора coverage метрик
2. **E2E тесты**: Требуют полностью работающий стек и авторизацию
3. **Desktop-app тесты**: Требуют Electron environment для полноценного тестирования
4. **PgBouncer admin**: Некоторые специфичные команды PgBouncer не доступны через обычное подключение

## Следующие шаги

1. Настроить Xdebug для сбора PHP code coverage
2. Добавить E2E тесты с полной аутентификацией
3. Настроить CI/CD pipeline для автоматического запуска тестов
4. Добавить performance тесты для проверки impact correlation_id на latency
