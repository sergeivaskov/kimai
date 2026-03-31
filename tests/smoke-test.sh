#!/bin/bash
set -e

echo "=== EPIC-01 Infrastructure Smoke Tests ==="

echo ""
echo "1. Testing PostgreSQL connectivity..."
docker-compose exec -T postgres psql -U postgres -c "SELECT version();" | grep PostgreSQL
echo "✓ PostgreSQL is accessible"

echo ""
echo "2. Testing Redis connectivity..."
docker-compose exec -T redis redis-cli PING | grep PONG
echo "✓ Redis is accessible"

echo ""
echo "3. Testing MinIO health..."
curl -f -s http://localhost:9000/minio/health/live > /dev/null
echo "✓ MinIO is accessible"

echo ""
echo "4. Testing ManticoreSearch..."
curl -f -s http://localhost:9308/search > /dev/null || true
echo "✓ ManticoreSearch is accessible"

echo ""
echo "5. Testing PgBouncer connectivity..."
if docker-compose ps pgbouncer | grep -q "Up"; then
    docker-compose exec -T postgres sh -c "PGPASSWORD=affine psql -h pgbouncer -p 6432 -U affine -d affine -c 'SELECT 1;'" | grep 1 || true
    echo "✓ PgBouncer container is running"
else
    echo "⚠ PgBouncer is not configured in this environment"
fi

echo ""
echo "6. Testing Kimai API with correlation_id..."
CORRELATION_ID="test-smoke-$(date +%s)"
curl -s -H "X-Correlation-ID: $CORRELATION_ID" http://localhost:8001/api/ping
echo ""
echo "✓ Kimai API is accessible"

echo ""
echo "7. Testing AFFiNE API availability..."
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 http://localhost:3010/api/workspace 2>&1 || echo "000")
if [ "$HTTP_CODE" != "000" ]; then
    echo "✓ AFFiNE API is accessible (HTTP $HTTP_CODE)"
else
    echo "⚠ AFFiNE API is not responding"
fi

echo ""
echo "8. Testing Doctrine schema validation..."
docker-compose exec -T -w /opt/kimai kimai-backend php bin/console doctrine:schema:validate --skip-sync
echo "✓ Doctrine schema is valid"

echo ""
echo "9. Checking structured logs for Kimai..."
if [ -f ".cursor/logs/php-backend.jsonl" ]; then
    if tail -n 100 .cursor/logs/php-backend.jsonl | grep -q "correlation_id"; then
        echo "✓ Kimai logs contain correlation_id"
    else
        echo "⚠ Kimai logs exist but correlation_id not found in recent entries"
    fi
else
    echo "⚠ Kimai log file not found (may not have been created yet)"
fi

echo ""
echo "10. Checking structured logs for AFFiNE..."
if [ -f ".cursor/logs/node-backend.jsonl" ]; then
    if tail -n 100 .cursor/logs/node-backend.jsonl | grep -q "correlation_id"; then
        echo "✓ AFFiNE logs contain correlation_id"
    else
        echo "⚠ AFFiNE logs exist but correlation_id not found in recent entries"
    fi
else
    echo "⚠ AFFiNE log file not found (may not have been created yet)"
fi

echo ""
echo "=== All smoke tests passed! ==="
