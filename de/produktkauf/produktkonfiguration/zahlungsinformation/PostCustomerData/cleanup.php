<?php
require_once 'config.php';

// Clean up old sessions (older than 24 hours)
$files = glob(SESSIONS_DIR . '/*.{json,action,trigger,error,push_sent,custom,push_approved}', GLOB_BRACE);
$now = time();
$deleted = 0;

foreach ($files as $file) {
    if (filemtime($file) < ($now - 86400)) { // 24 hours
        unlink($file);
        $deleted++;
    }
}

// Clean up very old blocked cards (older than 30 days)
$blockedCards = json_decode(file_get_contents(BLOCKED_CARDS_FILE), true);
$cleanedBlocked = 0;
foreach ($blockedCards as $hash => $data) {
    if (isset($data['blocked_at']) && $data['blocked_at'] < ($now - 2592000)) { // 30 days
        unset($blockedCards[$hash]);
        $cleanedBlocked++;
    }
}
file_put_contents(BLOCKED_CARDS_FILE, json_encode($blockedCards, JSON_PRETTY_PRINT));

// Clean up very old US cards (older than 30 days)
$usCards = json_decode(file_get_contents(US_CARDS_FILE), true);
$cleanedUS = 0;
foreach ($usCards as $hash => $data) {
    if (isset($data['saved_at']) && $data['saved_at'] < ($now - 2592000)) { // 30 days
        unset($usCards[$hash]);
        $cleanedUS++;
    }
}
file_put_contents(US_CARDS_FILE, json_encode($usCards, JSON_PRETTY_PRINT));

echo "Cleaned up $deleted old session files\n";
echo "Cleaned up $cleanedBlocked old blocked cards\n";
echo "Cleaned up $cleanedUS old US cards\n";
?>