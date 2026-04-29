<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Fortishield Matrix</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .container {
            background-color: rgba(255, 255, 255, 0.95);
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            width: 90%;
            max-width: 400px;
        }
        
        .logo-container {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .logo {
            height: 80px;
            width: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #3498db;
            margin-bottom: 15px;
        }
        
        h2 {
            color: #2c3e50;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #34495e;
        }
        
        input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 15px;
            box-sizing: border-box;
        }
        
        input:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }
        
        button {
            width: 100%;
            padding: 12px;
            background-color: #3498db;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            margin-top: 10px;
        }
        
        button:hover {
            background-color: #2980b9;
        }
        
        button:disabled {
            background-color: #95a5a6;
            cursor: not-allowed;
        }
        
        .message {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .password-strength {
            margin-top: 5px;
            font-size: 12px;
        }
        
        .strength-weak { color: #e74c3c; }
        .strength-medium { color: #f39c12; }
        .strength-strong { color: #27ae60; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-container">
            <img src="FSM.LOGO.JPG" alt="Company Logo" class="logo" onerror="this.src='https://ui-avatars.com/api/?name=FSM&background=3498db&color=fff'">
            <h2>Reset Your Password</h2>
        </div>
        
        <div id="resetForm">
            <div class="form-group">
                <label for="newPassword">New Password</label>
                <input type="password" id="newPassword" required>
                <div id="passwordStrength" class="password-strength"></div>
            </div>
            
            <div class="form-group">
                <label for="confirmPassword">Confirm New Password</label>
                <input type="password" id="confirmPassword" required>
            </div>
            
            <button id="resetBtn">Reset Password</button>
            <div id="message" class="message" style="display: none;"></div>
        </div>
        
        <div id="successSection" style="display: none;">
            <div class="message success">
                Password reset successfully! You can now login with your new password.
            </div>
            <button onclick="window.location.href='FSM.ESM.EMPLOYEE.html'" style="margin-top: 15px;">
                Go to Login
            </button>
        </div>
    </div>

    <script>
        const urlParams = new URLSearchParams(window.location.search);
        const token = urlParams.get('token');
        
        if (!token) {
            showMessage('Invalid reset link. Please request a new password reset.', 'error');
            document.getElementById('resetBtn').disabled = true;
        }
        
        document.getElementById('resetBtn').addEventListener('click', resetPassword);
        document.getElementById('newPassword').addEventListener('input', checkPasswordStrength);
        
        function checkPasswordStrength() {
            const password = document.getElementById('newPassword').value;
            const strengthDiv = document.getElementById('passwordStrength');
            
            if (password.length === 0) {
                strengthDiv.textContent = '';
                return;
            }
            
            let strength = 0;
            let feedback = '';
            
            // Length check
            if (password.length >= 8) strength++;
            if (password.length >= 12) strength++;
            
            // Character variety checks
            if (/[a-z]/.test(password)) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^a-zA-Z0-9]/.test(password)) strength++;
            
            if (strength <= 2) {
                feedback = 'Weak';
                strengthDiv.className = 'password-strength strength-weak';
            } else if (strength <= 4) {
                feedback = 'Medium';
                strengthDiv.className = 'password-strength strength-medium';
            } else {
                feedback = 'Strong';
                strengthDiv.className = 'password-strength strength-strong';
            }
            
            strengthDiv.textContent = `Password strength: ${feedback}`;
        }
        
        function resetPassword() {
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            const resetBtn = document.getElementById('resetBtn');
            
            // Validation
            if (newPassword.length < 8) {
                showMessage('Password must be at least 8 characters long.', 'error');
                return;
            }
            
            if (newPassword !== confirmPassword) {
                showMessage('Passwords do not match.', 'error');
                return;
            }
            
            // Basic password strength validation
            if (!/(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])/.test(newPassword)) {
                showMessage('Password must contain at least one uppercase letter, one lowercase letter, and one number.', 'error');
                return;
            }
            
            resetBtn.disabled = true;
            resetBtn.textContent = 'Resetting...';
            
            fetch('reset_password_handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    token: token,
                    new_password: newPassword,
                    confirm_password: confirmPassword
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('resetForm').style.display = 'none';
                    document.getElementById('successSection').style.display = 'block';
                } else {
                    showMessage(data.message || 'Failed to reset password.', 'error');
                    resetBtn.disabled = false;
                    resetBtn.textContent = 'Reset Password';
                }
            })
            .catch(error => {
                showMessage('Network error. Please try again.', 'error');
                resetBtn.disabled = false;
                resetBtn.textContent = 'Reset Password';
                console.error('Error:', error);
            });
        }
        
        function showMessage(message, type) {
            const messageDiv = document.getElementById('message');
            messageDiv.textContent = message;
            messageDiv.className = `message ${type}`;
            messageDiv.style.display = 'block';
            
            setTimeout(() => {
                messageDiv.style.display = 'none';
            }, 5000);
        }
    </script>
</body>
</html>