<?php
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

session_start();

// Redirect to appropriate dashboard if already logged in
if (isset($_SESSION['user_id']))  {
    switch ($_SESSION['user_type']) {
        case 'admin':
            header('Location: FSM.ESM.2.html');
            exit;
        case 'employee':
            header('Location: FSM.ESM.EMPLOYEE.dashboard.html');
            exit;
        case 'cso':
            header('Location: CSO-dashboard.html');
        case 'hr':
            header('Location: HR_Management_system.html');
        case 'Dept Manager':
            header('Location: FSM.ESM.DEPT_MANAGER.html');
            exit;
    }
}


// Handle login if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'], $_POST['password'])) {
    require_once 'db_connect.php';
    
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format";
    } else {
        try {
            $db = new Database();
            $conn = $db->connect();
            
            // Check all user tables in one query (from auth.php)
            $stmt = $conn->prepare("
                SELECT 'admin' as role, admin_id as id, email, password_hash FROM admins WHERE email = :email
                UNION ALL
                SELECT 'employee' as role, employee_id as id, email, password_hash FROM employees WHERE email = :email
                UNION ALL
                SELECT 'cso' as role, cso_id as id, email, password_hash FROM csos WHERE email = :email
                LIMIT 1
            ");
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (password_verify($password, $user['password_hash'])) {
                    // Set session data
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_type'] = $user['role'];
                    $_SESSION['email'] = $user['email'];
                    
                    // Update last login time
                    $updateStmt = $conn->prepare("UPDATE {$user['role']}s SET last_login = NOW() WHERE {$user['role']}_id = :id");
                    $updateStmt->bindParam(':id', $user['id']);
                    $updateStmt->execute();
                    
                    // Redirect to appropriate dashboard
                    $redirect = $user['role'] === 'admin' ? 'FSM.ESM.2.html' : 
                                ($user['role'] === 'employee' ? 'FSM.ESM.EMPLOYEE.dashboard.html' : 'CSO-dashboard.html');
                                echo json_encode([
                                'success' => true,
                                'redirect' => $redirect
                                ]);
                    header("Location: $redirect");
                    exit;
                } else {
                    $error = "Invalid password";
                }
            } else {
                $error = "User not found";
            }
        } catch (PDOException $e) {
            error_log("Database Error: " . $e->getMessage());
            $error = "Database error occurred";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Employee Management System</title>
  <style>
  body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    margin: 0;
    padding: 0;
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    background-image: url('654efd408a002efdfdfba23cc63b1780.jpg');
    background-size: cover;         /* Shows full image without cropping */
    background-repeat: no-repeat;   /* Prevents tiling */
    background-position: center;    /* Centers the image */
    background-attachment: fixed;   /* Optional: Fixed position */
    background-color: #f5f7fa;      /* Fallback color matching your design */
    position: relative;
  }

    body::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background-color: rgba(255, 255, 255, 0.2);
      z-index: 0;
    }

    .ems-container {
      background-color: white;
      padding: 30px;
      border-radius: 10px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.15);
      width: 90%;
      max-width: 500px;
      text-align: center;
      position: relative;
      z-index: 1;
    }

    .logo-container {
      margin-bottom: 20px;
    }

    .logo {
      height: 100px;
      width: 100px;
      border-radius: 50%;
      object-fit: cover;
      border: 3px solid #3498db;
      box-shadow: 0 4px 8px rgba(0,0,0,0.1);
      margin-bottom: 15px;
    }

    h1 {
      color: #2c3e50;
      margin: 10px 0 25px;
      font-size: 20px;
      font-weight: 600;
    }

    .login-controls {
      display: flex;
      flex-direction: column;
      gap: 15px;
      margin: 25px 0;
    }

    .role-selector {
      position: relative;
      width: 100%;
    }

    .role-selector select {
      width: 100%;
      padding: 12px 15px;
      border: 1px solid #ddd;
      border-radius: 6px;
      font-size: 15px;
      appearance: none;
      background-color: white;
      background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
      background-repeat: no-repeat;
      background-position: right 10px center;
      background-size: 1em;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .role-selector select:focus {
      border-color: #3498db;
      outline: none;
      box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
    }
    
    .input-wrapper {
      position: relative;
    }
    
    .password-toggle {
      position: absolute;
      top: 50%;
      right: 12px;
      transform: translateY(-50%);
      cursor: pointer;
      width: 24px;
      height: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 10;
      pointer-events: auto;
    }
    
    .password-toggle svg {
      width: 20px;
      height: 20px;
      display: block;
    }
    
    /* Input field styling */
    .input-wrapper input[type="email"],
    .input-wrapper input[type="text"],
    .input-wrapper input[type="password"] {
      width: 100%;
      box-sizing: border-box;
      font-size: 16px;
      padding: 10px;
    }

    .login-btn {
      background-color: #3498db;
      color: white;
      border: none;
      padding: 12px 25px;
      border-radius: 6px;
      cursor: pointer;
      font-size: 15px;
      font-weight: 500;
      transition: all 0.3s ease;
      width: 100%;
    }

    .login-btn:hover,
    .login-btn:focus {
      background-color: #2980b9;
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
      outline: none;
    }

    .login-btn:disabled {
      background-color: #95a5a6;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }

    .footer {
      margin-top: 30px;
      color: #7f8c8d;
      font-size: 12px;
      border-top: 1px solid #ecf0f1;
      padding-top: 15px;
    }

    .subscribe-form {
      margin-top: 20px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 15px;
    }

    .form-group {
      width: 100%;
      max-width: 400px;
      text-align: left;
    }

    .form-group label {
      display: block;
      margin-bottom: 5px;
      font-weight: 500;
      color: #2c3e50;
      font-size: 14px;
    }

    .form-group input {
      width: 100%;
      padding: 10px 15px;
      border: 1px solid #ddd;
      border-radius: 6px;
      font-size: 14px;
      transition: border-color 0.3s;
    }

    .form-group input:focus {
      border-color: #3498db;
      outline: none;
      box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
    }

    .form-group .error {
      color: #e74c3c;
      font-size: 12px;
      margin-top: 5px;
      display: none;
    }

    .subscribe-btn {
      background-color: #2ecc71;
      color: white;
      border: none;
      padding: 12px 25px;
      border-radius: 6px;
      cursor: pointer;
      font-size: 15px;
      font-weight: 500;
      transition: all 0.3s ease;
      width: 100%;
      max-width: 400px;
    }

    .subscribe-btn:hover,
    .subscribe-btn:focus {
      background-color: #27ae60;
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
      outline: none;
    }

    .subscribe-btn:disabled {
      background-color: #95a5a6;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }

    .success-message {
      color: #2ecc71;
      font-weight: 500;
      margin-top: 15px;
      font-size: 14px;
      display: none;
    }

    @media (max-width: 480px) {
      .ems-container {
        padding: 20px;
      }
      
      .logo {
        height: 80px;
        width: 80px;
      }
      
      h1 {
        font-size: 18px;
      }
    }
  </style>
</head>
<body>
  <div class="ems-container">
    <div class="logo-container">
      <img src="FSM.LOGO.JPG" alt="Company Logo" class="logo" />
      <h1>Employee Management System</h1>
    </div>

    <div class="login-controls">
      <div class="role-selector">
        <label for="roleSelect" style="display:block; text-align:left; margin-bottom:5px; font-weight:500; color:#2c3e50; font-size:14px;">
          Select your role
        </label>
        <select id="roleSelect">
          <option value="" selected disabled>Select your role</option>
          <option value="admin">Admin</option>
          <option value="employee">Employee</option>
          <option value="cso">CSO</option>
          <option value="hr">HR</option>
          <option value="Dept Manager">Dept Manager</option>
        </select>
      </div>
      
      <button id="loginBtn" class="login-btn" disabled>
        <i class="fas fa-sign-in-alt" aria-hidden="true"></i> LOGIN
      </button>
    </div>

    <form id="subscribeForm" class="subscribe-form" novalidate>
      <div class="form-group">
        <label for="email">Email for Newsletter</label>
        <div class="input-wrapper">
          <input type="email" id="email" name="email" placeholder="***************" required />
          <span class="password-toggle" onclick="toggleVisibility('email')">
            <!-- Show icon (blue eye) -->
            <svg class="show-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <circle cx="12" cy="12" r="10" fill="#3498db"/>
              <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z" fill="black"/>
            </svg>
            <!-- Hide icon (blue slashed eye) -->
            <svg class="hide-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display: none;">
              <circle cx="12" cy="12" r="10" fill="#3498db"/>
              <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z" fill="black"/>
              <line x1="1" y1="23" x2="23" y2="1" stroke="black" stroke-width="2"/>
            </svg>
          </span>
        </div>
        <div class="error" id="emailError">Please enter a valid email address</div>
      </div>

      <div class="form-group">
        <label for="name">Your Name </label>
        <div class="input-wrapper">
          <input type="text" id="name" name="name" placeholder="****" />
          <span class="password-toggle" onclick="toggleVisibility('name')">
            <!-- Show icon (blue eye) -->
            <svg class="show-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <circle cx="12" cy="12" r="10" fill="#3498db"/>
              <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z" fill="black"/>
            </svg>
            <!-- Hide icon (blue slashed eye) -->
            <svg class="hide-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display: none;">
              <circle cx="12" cy="12" r="10" fill="#3498db"/>
              <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z" fill="black"/>
              <line x1="1" y1="23" x2="23" y2="1" stroke="black" stroke-width="2"/>
            </svg>
          </span>
        </div>
      </div>

      <button type="submit" class="subscribe-btn" id="subscribeBtn" disabled>
        <i class="fas fa-envelope" aria-hidden="true"></i> SUBSCRIBE
      </button>

      <div class="success-message" id="successMessage">
        Thank you for subscribing! You'll receive our newsletter soon.
      </div>
    </form>

    <div class="footer">
      <p>© <span id="currentYear"></span> All Rights Reserved.</p>
      <p>Powered by: Fortishield-Matrix</p>
    </div>
  </div>

  <!-- Font Awesome for icons -->
  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css"
  />

  <script>
    document.getElementById('currentYear').textContent = new Date().getFullYear();
    
    // Global function for visibility toggle
    function toggleVisibility(fieldId) {
      const input = document.getElementById(fieldId);
      const originalType = input.getAttribute('data-original-type') || input.type;
      
      if (!input.getAttribute('data-original-type')) {
        input.setAttribute('data-original-type', input.type);
      }
      
      const isVisible = input.type === 'password';
      
      if (isVisible) {
        // Show the text (restore original type)
        input.type = originalType;
      } else {
        // Hide the text (change to password type)
        input.type = 'password';
      }
      
      const toggleSpan = input.parentElement.querySelector('.password-toggle');
      const showIcon = toggleSpan.querySelector('.show-icon');
      const hideIcon = toggleSpan.querySelector('.hide-icon');
      
      showIcon.style.display = isVisible ? 'none' : '';
      hideIcon.style.display = isVisible ? '' : 'none';
    }

    const subscribeForm = document.getElementById('subscribeForm');
    const emailInput = document.getElementById('email');
    const nameInput = document.getElementById('name');
    const emailError = document.getElementById('emailError');
    const subscribeBtn = document.getElementById('subscribeBtn');
    const successMessage = document.getElementById('successMessage');
    const roleSelect = document.getElementById('roleSelect');
    const loginBtn = document.getElementById('loginBtn');

    // Role to panel mapping
    const rolePanels = {
      'admin': 'FSM.ESM.ADMIN LOGIN PANEL.html',
      'employee': 'FSM.ESM.EMPLOYEE.html',
      'cso': 'FSM.ESM.CSO.html',
      'hr': 'FSM.ESM.HR.html',
      'Dept Manager': 'FSM.ESM.DEPT_MANAGER.html' 
    };

    // Initially disable login button - requires subscription first
    loginBtn.disabled = true;
    loginBtn.textContent = 'SUBSCRIBE FIRST TO LOGIN';

    // Enable login button when role is selected AND subscription is complete
    roleSelect.addEventListener('change', function() {
      // Only enable if subscription was successful
      if (this.value && window.subscriptionCompleted) {
        loginBtn.disabled = false;
        loginBtn.textContent = 'LOGIN';
      }
    });

    // Handle login button click
    loginBtn.addEventListener('click', function() {
      const selectedRole = roleSelect.value;
      if (selectedRole && rolePanels[selectedRole] && window.subscriptionCompleted) {
        window.location.href = rolePanels[selectedRole];
      } else if (!window.subscriptionCompleted) {
        alert('Please complete your subscription first before logging in.');
      }
    });

    // Allow pressing Enter on role select to trigger login
    roleSelect.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' && roleSelect.value && window.subscriptionCompleted) {
        loginBtn.click();
      }
    });

    // Email validation and subscription logic
    emailInput.addEventListener('input', toggleSubscribeButton);
    nameInput.addEventListener('input', toggleSubscribeButton);

    function validateEmail(email) {
      const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      return regex.test(email);
    }

    function toggleSubscribeButton() {
      const email = emailInput.value.trim();
      const name = nameInput.value.trim();
      if (validateEmail(email) && name.length > 1) {
        subscribeBtn.disabled = false;
        emailError.style.display = 'none';
      } else {
        subscribeBtn.disabled = true;
        emailError.style.display = 'block';
      }
    }

    subscribeForm.addEventListener('submit', async function(e) {
      e.preventDefault();

      const email = emailInput.value.trim();
      const name = nameInput.value.trim();
      const role = roleSelect.value;

      if (!validateEmail(email)) {
        emailError.textContent = "Please enter a valid email address";
        emailError.style.display = 'block';
        return;
      }

      subscribeBtn.disabled = true;
      subscribeBtn.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Subscribing...';

      // Send subscription data to server
      try {
        const response = await fetch("send_material.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ email, name, role })
        });

        const result = await response.json();

        if (result.success) {
          successMessage.style.display = "block";
          subscribeBtn.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i> SUBSCRIBED';
          subscribeBtn.style.backgroundColor = "#95a5a6";
          subscribeForm.reset();
          
          // Mark subscription as completed
          window.subscriptionCompleted = true;
          
          // Enable login if role is selected
          if (roleSelect.value) {
            loginBtn.disabled = false;
            loginBtn.textContent = 'LOGIN';
          }
          
          // Show success message
          successMessage.textContent = "Subscription successful! You can now login.";
          successMessage.style.display = "block";
          
          setTimeout(() => {
            successMessage.style.display = "none";
          }, 5000);
        } else {
          alert("Subscription failed: " + result.message);
          subscribeBtn.disabled = false;
          subscribeBtn.innerHTML = '<i class="fas fa-envelope" aria-hidden="true"></i> SUBSCRIBE';
        }
      } catch (error) {
        alert("Server error: " + error.message);
        subscribeBtn.disabled = false;
        subscribeBtn.innerHTML = '<i class="fas fa-envelope" aria-hidden="true"></i> SUBSCRIBE';
      }
    });

    // Accessibility - keyboard navigation
    document.querySelectorAll('button, select').forEach(element => {
      element.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          this.click();
        }
      });
    });
  </script>
</body>
</html>