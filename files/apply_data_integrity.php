<?php
/**
 * One-time script: add foreign key constraints for data integrity.
 * Prevents hard-deleting barbers/services that are used in sales.
 * Run from browser once: http://localhost/boy-barbershop/files/apply_data_integrity.php
 * Or from project root: php files/apply_data_integrity.php
 */

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    echo "<pre>";
}

require dirname(__DIR__) . '/connection.php';

$dbname = 'boy_barbershop';

function constraintExists(PDO $pdo, string $table, string $name): bool {
    $stmt = $pdo->prepare("
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ");
    $stmt->execute([$GLOBALS['dbname'], $table, $name]);
    return (bool) $stmt->fetch();
}

$done = [];
$errors = [];

try {
    // Ensure InnoDB for FK support
    $pdo->exec('ALTER TABLE barbers ENGINE=InnoDB');
    $pdo->exec('ALTER TABLE services ENGINE=InnoDB');
    $pdo->exec('ALTER TABLE sales ENGINE=InnoDB');
    $done[] = 'Tables set to InnoDB (if needed).';
} catch (Throwable $e) {
    $errors[] = 'InnoDB: ' . $e->getMessage();
}

if (!constraintExists($pdo, 'sales', 'fk_sales_barber')) {
    try {
        $pdo->exec('ALTER TABLE sales ADD CONSTRAINT fk_sales_barber FOREIGN KEY (barber_id) REFERENCES barbers(id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $done[] = 'Added FK: sales.barber_id → barbers.id (ON DELETE RESTRICT).';
    } catch (Throwable $e) {
        $errors[] = 'fk_sales_barber: ' . $e->getMessage();
    }
} else {
    $done[] = 'FK fk_sales_barber already exists.';
}

if (!constraintExists($pdo, 'sales', 'fk_sales_service')) {
    try {
        $pdo->exec('ALTER TABLE sales ADD CONSTRAINT fk_sales_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT ON UPDATE CASCADE');
        $done[] = 'Added FK: sales.service_id → services.id (ON DELETE RESTRICT).';
    } catch (Throwable $e) {
        $errors[] = 'fk_sales_service: ' . $e->getMessage();
    }
} else {
    $done[] = 'FK fk_sales_service already exists.';
}

foreach ($done as $msg) {
    echo "[OK] $msg\n";
}
foreach ($errors as $msg) {
    echo "[ERROR] $msg\n";
}

echo "\nDone. Barbers and services that have sales can no longer be hard-deleted (only soft-deactivated).\n";

if (!$isCli) {
    echo "</pre>";
}
