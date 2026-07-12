<?php
/**
 * Utility script to recreate the application database user.
 * Run via CLI: php ocabis/create_ocabis_user.php
 * Removes itself once finished if needed.
 */

$rootUser = 'root';
$rootPass = '';
$host = 'localhost';

$mysqli = @new mysqli($host, $rootUser, $rootPass);
if ($mysqli->connect_error) {
    fwrite(STDERR, "Failed to connect as root: {$mysqli->connect_error}\n");
    exit(1);
}

$commands = [
    "CREATE USER IF NOT EXISTS 'ocabisuser'@'localhost' IDENTIFIED BY 'ocabis123'",
    "GRANT ALL PRIVILEGES ON ocabis.* TO 'ocabisuser'@'localhost'",
    "FLUSH PRIVILEGES"
];

foreach ($commands as $sql) {
    if (!$mysqli->query($sql)) {
        fwrite(STDERR, "Error running '{$sql}': {$mysqli->error}\n");
        exit(1);
    }
}

echo "ocabisuser recreated and granted privileges.\n";

