<?php
require_once 'config.php';

header('Content-Type: application/json');

// Online/offline presence removed - page is tracked via check_status polling only
echo json_encode(['ok' => true]);
?>
