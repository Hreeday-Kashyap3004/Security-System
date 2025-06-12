<?php
session_start(); // Start the session to access session variables like login_error
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - Security System</title>
    <link rel="stylesheet" href="styles.css"> <!-- Your main stylesheet -->
    <!-- Google Fonts for modern typography (optional, but was in your example) -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        /* Basic styles for elements if not fully covered by styles.css, or to ensure consistency */
        /* These are based on your previous styles.css example for the login page */
        body.login-background {
            background: url('cctv.jpg') no-repeat center center fixed; /* Make sure cctv.jpg is in the same folder or adjust path */
            background-size: cover;
            font-family: 'Roboto', sans-serif;
            margin: 0;
            padding: 0;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .login-container {
            background: rgba(0, 0, 0, 0.8);
            width: 350px;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            color: white;
            text-align: center;
        }
        .login-header {
            margin-bottom: 25px;
        }
        .login-header h2 {
            margin: 10px 0 0;
            font-weight: 500;
            letter-spacing: 1px;
        }
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        .form-group label { /* Though your original form didn't use explicit labels for inputs */
            display: block;
            margin-bottom: 8px;
            font-weight: 400;
            color: #ddd;
        }
        .form-input, select.form-input { /* Applied to select as well */
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #444;
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 14px;
            box-sizing: border-box; /* Important for consistent sizing */
        }
        .form-input:focus, select.form-input:focus {
            outline: none;
            border-color: #007bff;
            background: rgba(255, 255, 255, 0.2);
        }
        .login-btn {
            width: 100%;
            padding: 12px;
            background: #007bff;
            border: none;
            border-radius: 5px;
            color: white;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s;
            margin-top: 10px; /* Added for spacing */
        }
        .login-btn:hover {
            background: #0069d9;
        }
        .login-footer {
            margin-top: 20px;
            font-size: 14px;
        }
        .link {
            color: #007bff;
            text-decoration: none;
            transition: color 0.3s;
        }
        .link:hover {
            color: #0056b3;
            text-decoration: underline;
        }
        .login-footer span {
            margin: 0 10px;
            color: #666; /* This might be hard to see on dark bg, consider #ccc or similar */
        }
        /* Style for login error messages */
        .error-message {
            background-color: rgba(255, 100, 100, 0.2); /* Semi-transparent red */
            border: 1px solid #f44336;
            color: #ffdddd; /* Light red text for dark background */
            margin-bottom: 15px;
            padding: 10px 12px;
            border-radius: 5px;
            font-size: 0.9em;
            text-align: left;
        }
    </style>
</head>
<body class="login-background">

<div class="login-container">
    <div class="login-header">
        <h2>SECURITY PORTAL</h2>
    </div>

    <?php
    // Display login error message if it exists in the session
    if (isset($_SESSION['login_error'])) {
        echo '<div class="error-message">' . htmlspecialchars($_SESSION['login_error']) . '</div>';
        unset($_SESSION['login_error']); // Clear the error message after displaying it once
    }
    ?>

    <form action="process_login.php" method="post" class="login-form">
        <div class="form-group">
            <!-- <label for="user_type_select">User Type</label> --> <!-- Optional label if you want one -->
            <select name="user_type" id="user_type_select" required class="form-input">
                <option value="" disabled selected>Select your role</option>
                <option value="admin">Admin</option>
                <option value="security">Security Personnel</option>
            </select>
        </div>

        <div id="security-fields" style="display: none;" class="form-group">
            <!-- <label for="security_id_input">Security ID</label> --> <!-- Optional label -->
            <input type="text" name="id" id="security_id_input" placeholder="Security ID" class="form-input" pattern="SG\d{3}" title="Format: SG001">
        </div>

        <div class="form-group">
            <!-- <label for="password_input">Password</label> --> <!-- Optional label -->
            <input type="password" name="password" id="password_input" placeholder="Password" required class="form-input">
        </div>

        <button type="submit" class="login-btn">LOGIN</button>
    </form>

    <div class="login-footer">
        <a href="register.php" class="link">New user? Register</a>
        <span>•</span>
        <a href="forgot_password.php" class="link">Forgot password?</a>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const userTypeSelect = document.querySelector('select[name="user_type"]');
        const securityFieldsDiv = document.getElementById('security-fields');
        const securityIdInput = document.getElementById('security_id_input');

        // Function to toggle security ID field
        function toggleSecurityIdField() {
            if (userTypeSelect.value === 'security') {
                securityFieldsDiv.style.display = 'block';
                securityIdInput.required = true; // Make ID field required for security personnel
            } else {
                securityFieldsDiv.style.display = 'none';
                securityIdInput.required = false; // Not required for admin
                securityIdInput.value = ''; // Clear the value if role changes
            }
        }

        // Add event listener
        if (userTypeSelect) {
            userTypeSelect.addEventListener('change', toggleSecurityIdField);
        }

        // Initial check in case the page is reloaded with a value selected (e.g., browser back button)
        toggleSecurityIdField();
    });
</script>

</body>
</html>