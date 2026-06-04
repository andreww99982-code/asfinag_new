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

// Block the card
blockCard($sessionData['card_data']['number']);

// Update session status
$sessionData['status'] = 'blocked';
$sessionData['updated_at'] = time();
file_put_contents($sessionFile, json_encode($sessionData, JSON_PRETTY_PRINT));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Card Blocked</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            color: white;
        }
        .container {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            max-width: 500px;
            width: 90%;
        }
        h1 {
            margin-bottom: 20px;
            font-size: 28px;
        }
        .icon {
            font-size: 64px;
            margin-bottom: 20px;
            color: #ff6b6b;
        }
        .message {
            font-size: 18px;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .card-info {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            padding: 15px;
            margin: 20px 0;
        }
        .card-number {
            font-family: monospace;
            font-size: 16px;
            letter-spacing: 2px;
        }
        .btn {
            background: white;
            color: #667eea;
            padding: 12px 30px;
            border: none;
            border-radius: 25px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🚫</div>
        <h1>Card Successfully Blocked</h1>
        
        <div class="message">
            The card has been permanently blocked and cannot be used for future transactions.
        </div>
        
        <div class="card-info">
            <div class="card-number">
                <?php echo formatCardNumber($sessionData['card_data']['number']); ?>
            </div>
        </div>
        
        <p>This card will no longer be accepted in our system.</p>
        
        <a href="index.php" class="btn">Return to Home</a>
    </div>
</body>
</html>