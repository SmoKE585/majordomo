<?php
/** 2026-10-25 — схема долговечного журнала заданий для изолированных проверок SQLite. */
function createRuntimeQueueFixture(PDO $pdo): void
{
    if (getenv('APP_ENV') !== 'testing' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') throw new RuntimeException('Изоляция SQLite не подтверждена');
    $pdo->exec("CREATE TABLE IF NOT EXISTS core_runtime_queue (
        ID INTEGER PRIMARY KEY AUTOINCREMENT, DATANAME TEXT NOT NULL, DATAVALUE TEXT NOT NULL,
        LANE TEXT NOT NULL, STATUS TEXT DEFAULT 'pending', SOURCE_KEY TEXT UNIQUE,
        CREATED TEXT, EXPIRE TEXT, AVAILABLE_AT TEXT, CLAIM_TOKEN TEXT DEFAULT '', CLAIM_UNTIL TEXT,
        ATTEMPTS INTEGER DEFAULT 0, LAST_ERROR TEXT, FINISHED TEXT, DISPATCH_GENERATION TEXT DEFAULT '')");
}
