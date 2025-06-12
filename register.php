<!DOCTYPE html>
<html>
<head>
    <title>Register - Security System</title>
    <link rel="stylesheet" href="styles.css"> <!-- Your main stylesheet -->
    <!-- Google Fonts for modern typography (optional) -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        /* Embedded styles for consistency, ideally move to styles.css */
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
        .login-container { /* Re-using login-container styling for registration form */
            background: rgba(0, 0, 0, 0.8);
            width: 380px; /* Slightly wider for the extra field/label */
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            color: white;
            text-align: center;
        }
        .login-header { /* Can be reused if you have a header here */
            margin-bottom: 25px;
        }
        .login-header h2 {
            margin: 10px 0 0;
            font-weight: 500;
            letter-spacing: 1px;
        }
        .form-group {
            margin-bottom: 15px; 
            text-align: left;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 400;
            color: #ddd; 
        }
        .form-input, input[type="text"], input[type="password"], input[type="file"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #444;
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 14px;
            box-sizing: border-box; 
        }
        input[type="file"].form-input { /* Specific styling for file input if needed */
            padding: 8px 10px; /* File inputs often look better with slightly less padding */
            color: #ccc; /* Placeholder text color for file input can be tricky */
        }
        .form-input:focus, input[type="text"]:focus, input[type="password"]:focus, input[type="file"]:focus {
            outline: none;
            border-color: #007bff;
            background: rgba(255, 255, 255, 0.2);
        }
        .login-btn { /* Re-using login-btn style for the register button */
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
            margin-top: 10px; 
        }
        .login-btn:hover {
            background: #0069d9;
        }
        .link { /* For the "Back to login" link */
            color: #007bff;
            text-decoration: none;
            transition: color 0.3s;
            display: inline-block; /* For margin-top to work */
            margin-top: 20px;
        }
        .link:hover {
            color: #0056b3;
            text-decoration: underline;
        }
    </style>
</head>
<body class="login-background">

<div class="login-container">
    <div class="login-header"> <!-- Optional: you can remove if not needed for register page -->
        <h2>New Security Personnel Registration</h2>
    </div>

    <!-- IMPORTANT: enctype="multipart/form-data" is required for file uploads -->
    <form action="process_register.php" method="post" enctype="multipart/form-data">
        <div class="form-group">
            <label for="reg_name">Full Name</label>
            <input type="text" name="name" id="reg_name" placeholder="Enter your full name" required class="form-input">
        </div>

        <div class="form-group">
            <label for="reg_password">Choose a Password</label>
            <input type="password" name="password" id="reg_password" placeholder="Create a password" required class="form-input">
        </div>

        <div class="form-group">
            <label for="id_photo">Upload ID Photo (JPG, PNG, max 2MB)</label>
            <input type="file" name="id_photo" id="id_photo" accept=".jpg,.jpeg,.png" required class="form-input">
            <!-- The 'accept' attribute is a client-side hint, server-side validation is crucial -->
        </div>

        <button type="submit" class="login-btn">Register</button>
    </form>

    <a href="index.php" class="link">Back to Login</a>
</div>

</body>
</html>