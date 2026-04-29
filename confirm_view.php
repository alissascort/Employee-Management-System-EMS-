<?php
require_once 'db_connect.php';

$email = $_GET['email'] ?? '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = "Invalid confirmation link.";
} else {
    try {
        $db = new Database();
        $conn = $db->connect();

        // Check if email exists in subscriptions
        $stmt = $conn->prepare("SELECT id FROM subscriptions WHERE email = :email LIMIT 1");
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        if ($stmt->rowCount() === 1) {
            // Update last_confirmed_at to now
            $update = $conn->prepare("UPDATE subscriptions SET last_confirmed_at = NOW() WHERE email = :email");
            $update->bindParam(':email', $email);
            $update->execute();

            $success = "Thank you! Your subscription has been confirmed. You can now access all portals.";
        } else {
            $error = "Email not found. Please subscribe first.";
        }
    } catch (PDOException $e) {
        error_log("DB Error in confirm_view.php: " . $e->getMessage());
        $error = "A server error occurred. Please try again later.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Subscription Confirmation</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; height: 100vh; }
        .container { background: #fff; padding: 2em; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); max-width: 400px; text-align: center; }
        .success { color: #2c662d; }
        .error { color: #a94442; }
        a.button { margin-top: 1.5em; display: inline-block; padding: 0.5em 1.2em; background: #007bff; color: white; text-decoration: none; border-radius: 4px; }
        a.button:hover { background: #0056b3; }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!empty($success)) : ?>
            <h2 class="success"><?= htmlspecialchars($success) ?></h2>
            <p><a href="FSM.ESM.FRONT.1.html" class="button">Go to Home</a></p>
        <?php else : ?>
            <h2 class="error"><?= htmlspecialchars($error ?? "Unknown error.") ?></h2>
            <p><a href="FSM.ESM.FRONT.1.html" class="button">Return to Subscription</a></p>
        <?php endif; ?>
    </div>
</body>
</html>

