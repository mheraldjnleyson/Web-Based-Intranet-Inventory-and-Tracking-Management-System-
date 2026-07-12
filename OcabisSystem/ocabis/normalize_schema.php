<?php
// Safe, idempotent schema normalization without breaking existing code
// - Adds FK id columns alongside existing text columns
// - Backfills data where possible
// - Adds indexes and foreign keys with ON DELETE SET NULL

error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: text/plain; charset=utf-8');

// Reuse existing DB connection
require_once __DIR__ . '/../db_connect.php';

if (!isset($conn) || !$conn instanceof mysqli) {
    http_response_code(500);
    echo "Database connection not available.\n";
    exit;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $tableEsc = $conn->real_escape_string($table);
    $columnEsc = $conn->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

function tableExists(mysqli $conn, string $table): bool {
    $tableEsc = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$tableEsc}'");
    return $res && $res->num_rows > 0;
}

function indexExists(mysqli $conn, string $table, string $indexName): bool {
    $tableEsc = $conn->real_escape_string($table);
    $indexEsc = $conn->real_escape_string($indexName);
    $res = $conn->query("SHOW INDEX FROM `{$tableEsc}` WHERE Key_name = '{$indexEsc}'");
    return $res && $res->num_rows > 0;
}

function constraintExists(mysqli $conn, string $table, string $constraintName): bool {
    $dbRes = $conn->query('SELECT DATABASE() as db');
    $dbRow = $dbRes ? $dbRes->fetch_assoc() : null;
    $db = $dbRow ? $dbRow['db'] : '';
    if ($db === '') return false;
    $tableEsc = $conn->real_escape_string($table);
    $constraintEsc = $conn->real_escape_string($constraintName);
    $dbEsc = $conn->real_escape_string($db);
    $sql = "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS 
            WHERE CONSTRAINT_SCHEMA = '{$dbEsc}' 
              AND TABLE_NAME = '{$tableEsc}' 
              AND CONSTRAINT_NAME = '{$constraintEsc}' 
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

function run(mysqli $conn, string $sql, string $label): void {
    if ($conn->query($sql)) {
        echo "OK: {$label}\n";
    } else {
        echo "SKIP/ERR: {$label} -> " . $conn->error . "\n";
    }
}

echo "Starting schema normalization...\n\n";

// 1) borrow_history: add department_id (nullable), backfill from departments.name, index, FK
if (tableExists($conn, 'borrow_history')) {
    if (!columnExists($conn, 'borrow_history', 'department_id')) {
        run($conn, "ALTER TABLE borrow_history ADD COLUMN department_id INT NULL AFTER department_name", "borrow_history.add department_id");
    } else {
        echo "OK: borrow_history.department_id already exists\n";
    }

    // Backfill via exact name match
    $updateSql = "UPDATE borrow_history bh 
                  LEFT JOIN departments d ON d.name = bh.department_name 
                  SET bh.department_id = d.id 
                  WHERE bh.department_id IS NULL";
    run($conn, $updateSql, 'borrow_history.backfill department_id from departments.name');

    // Index for FK
    if (!indexExists($conn, 'borrow_history', 'idx_borrow_history_department_id')) {
        run($conn, "CREATE INDEX idx_borrow_history_department_id ON borrow_history(department_id)", 'borrow_history.create index department_id');
    } else {
        echo "OK: borrow_history index exists\n";
    }

    // Add FK (SET NULL to avoid breaking deletes)
    $fkName = 'fk_borrow_history_department_id';
    if (!constraintExists($conn, 'borrow_history', $fkName)) {
        run($conn, "ALTER TABLE borrow_history 
                    ADD CONSTRAINT {$fkName} FOREIGN KEY (department_id) 
                    REFERENCES departments(id) ON DELETE SET NULL ON UPDATE CASCADE", 'borrow_history.add FK department_id');
    } else {
        echo "OK: borrow_history FK already exists\n";
    }
}

// 2) item_requests: add user_id and department_id (nullable), backfill, indexes, FKs
if (tableExists($conn, 'item_requests')) {
    if (!columnExists($conn, 'item_requests', 'user_id')) {
        run($conn, "ALTER TABLE item_requests ADD COLUMN user_id INT NULL AFTER requested_by", "item_requests.add user_id");
    } else {
        echo "OK: item_requests.user_id already exists\n";
    }

    if (!columnExists($conn, 'item_requests', 'department_id')) {
        run($conn, "ALTER TABLE item_requests ADD COLUMN department_id INT NULL AFTER department_name", "item_requests.add department_id");
    } else {
        echo "OK: item_requests.department_id already exists\n";
    }

    // Backfill department_id from departments.name
    $updDept = "UPDATE item_requests r 
                LEFT JOIN departments d ON d.name = r.department_name 
                SET r.department_id = d.id 
                WHERE r.department_id IS NULL";
    run($conn, $updDept, 'item_requests.backfill department_id from departments.name');

    // Backfill user_id via username, then email heuristic
    // 2.a match username
    $updUser1 = "UPDATE item_requests r 
                 LEFT JOIN users u ON u.username = r.requested_by 
                 SET r.user_id = u.id 
                 WHERE r.user_id IS NULL";
    run($conn, $updUser1, 'item_requests.backfill user_id from users.username');

    // 2.b if requested_by looks like email, match by email
    $updUser2 = "UPDATE item_requests r 
                 LEFT JOIN users u ON u.email = r.requested_by 
                 SET r.user_id = u.id 
                 WHERE r.user_id IS NULL AND r.requested_by LIKE '%@%'";
    run($conn, $updUser2, 'item_requests.backfill user_id from users.email');

    if (!indexExists($conn, 'item_requests', 'idx_item_requests_user_id')) {
        run($conn, "CREATE INDEX idx_item_requests_user_id ON item_requests(user_id)", 'item_requests.create index user_id');
    } else {
        echo "OK: item_requests user_id index exists\n";
    }

    if (!indexExists($conn, 'item_requests', 'idx_item_requests_department_id')) {
        run($conn, "CREATE INDEX idx_item_requests_department_id ON item_requests(department_id)", 'item_requests.create index department_id');
    } else {
        echo "OK: item_requests department_id index exists\n";
    }

    $fkUser = 'fk_item_requests_user_id';
    if (!constraintExists($conn, 'item_requests', $fkUser)) {
        run($conn, "ALTER TABLE item_requests 
                    ADD CONSTRAINT {$fkUser} FOREIGN KEY (user_id) 
                    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE", 'item_requests.add FK user_id');
    } else {
        echo "OK: item_requests user FK exists\n";
    }

    $fkDept = 'fk_item_requests_department_id';
    if (!constraintExists($conn, 'item_requests', $fkDept)) {
        run($conn, "ALTER TABLE item_requests 
                    ADD CONSTRAINT {$fkDept} FOREIGN KEY (department_id) 
                    REFERENCES departments(id) ON DELETE SET NULL ON UPDATE CASCADE", 'item_requests.add FK department_id');
    } else {
        echo "OK: item_requests department FK exists\n";
    }
}

echo "\nDone. No breaking changes applied. You can gradually migrate code to use the new *_id columns.\n";

?>


