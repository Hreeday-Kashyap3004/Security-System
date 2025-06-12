<?php
require 'db_config.php'; // Your database connection

ob_start(); // Start output buffering

$message_to_user = ''; // To store user feedback
$upload_dir = 'uploads/'; // Define your upload directory (relative to this script's location)
                          // Make sure this directory exists and is writable by the web server.
$final_id_photo_filename = null; // Variable to hold the final filename of the uploaded photo if successful

// Check if the form was submitted via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Check if all expected fields are present (name, password, and the id_photo file array)
    if (isset($_POST['name'], $_POST['password'], $_FILES['id_photo'])) {
        
        $name = trim($_POST['name']);
        $password = $_POST['password']; // Storing plaintext password as per our specific project requirement

        // --- File Upload Handling ---
        $id_photo_file_info = $_FILES['id_photo']; // Array containing file details

        $original_file_name = $id_photo_file_info['name'];
        $file_temporary_path = $id_photo_file_info['tmp_name']; // Temporary path on server after upload
        $file_size_bytes = $id_photo_file_info['size'];
        $upload_error_code = $id_photo_file_info['error']; // PHP's error code for the upload

        // Define allowed extensions and max file size
        $allowed_mime_types = ['image/jpeg', 'image/png']; // More reliable than just extension
        $allowed_extensions_for_feedback = ['jpg', 'jpeg', 'png']; // For user messages
        $max_file_size_bytes = 2 * 1024 * 1024; // 2MB

        if ($upload_error_code === UPLOAD_ERR_OK) { // Check if PHP reported any upload error
            
            // Get file extension and MIME type
            $file_extension = strtolower(pathinfo($original_file_name, PATHINFO_EXTENSION));
            $file_mime_type = mime_content_type($file_temporary_path); // Get actual MIME type

            if (in_array($file_mime_type, $allowed_mime_types) && in_array($file_extension, $allowed_extensions_for_feedback)) {
                if ($file_size_bytes <= $max_file_size_bytes) {
                    // Create a unique filename to prevent overwrites and for security
                    $final_id_photo_filename = "idimg_" . time() . "_" . uniqid('', true) . "." . $file_extension;
                    $destination_path_on_server = $upload_dir . $final_id_photo_filename;

                    // Attempt to move the uploaded file from its temporary location to the final destination
                    if (move_uploaded_file($file_temporary_path, $destination_path_on_server)) {
                        // File moved successfully. $final_id_photo_filename is now set.
                        // The overall $message_to_user will be set later based on DB operation.
                    } else {
                        $message_to_user = "Error: Could not move uploaded ID photo. Please check server permissions for the '{$upload_dir}' directory or contact support.";
                        $final_id_photo_filename = null; // Reset filename on failure
                        error_log("File upload move_uploaded_file() error for destination: " . $destination_path_on_server . ". Check permissions and path.");
                    }
                } else {
                    $message_to_user = "Error: ID photo file is too large. Maximum allowed size is 2MB.";
                    $final_id_photo_filename = null;
                }
            } else {
                $message_to_user = "Error: Invalid ID photo file type. Only JPG, JPEG, and PNG images are allowed.";
                $final_id_photo_filename = null;
            }
        } elseif ($upload_error_code === UPLOAD_ERR_NO_FILE) {
            $message_to_user = "Error: No ID photo was uploaded. This field is required.";
            $final_id_photo_filename = null;
        } else {
            // Handle other PHP upload errors (e.g., UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, etc.)
            $message_to_user = "Error during file upload (Code: {$upload_error_code}). Please try again or contact support if the issue persists.";
            $final_id_photo_filename = null;
            error_log("PHP file upload error code: " . $upload_error_code . " for file: " . $original_file_name);
        }
        // --- End File Upload Handling ---

        // Proceed to database insertion ONLY if name, password are provided AND file upload was successful
        // (i.e., $final_id_photo_filename is not null) AND no prior critical error message was set for file handling.
        if (!empty($name) && !empty($password) && $final_id_photo_filename !== null && empty($message_to_user)) {

            $sql = "INSERT INTO pending_users (name, password, id_photo_path) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($sql);

            if ($stmt) {
                // Bind parameters: name (string), password (string), id_photo_filename (string)
                $stmt->bind_param("sss", $name, $password, $final_id_photo_filename);

                if ($stmt->execute()) {
                    $message_to_user = "Registration successful! Your ID photo has been uploaded. Please wait for Administrator approval.";
                } else {
                    $message_to_user = "Error: Database insertion failed after file upload - " . htmlspecialchars($stmt->error);
                    // If DB insert fails, it's good practice to delete the just-uploaded file to prevent orphans
                    if (isset($destination_path_on_server) && file_exists($destination_path_on_server)) {
                        unlink($destination_path_on_server);
                        error_log("Deleted orphaned uploaded file after DB insertion error: " . $destination_path_on_server);
                    }
                }
                $stmt->close();
            } else {
                $message_to_user = "Error: Database statement preparation failed - " . htmlspecialchars($conn->error);
                 // If DB prepare fails, also delete the just-uploaded file
                if (isset($destination_path_on_server) && file_exists($destination_path_on_server)) {
                    unlink($destination_path_on_server);
                    error_log("Deleted orphaned uploaded file after DB prepare error: " . $destination_path_on_server);
                }
            }
        } elseif (empty($message_to_user)) { 
            // This condition means file upload might have failed silently or name/password were empty,
            // and no specific error message was set by the file upload block.
            if (empty($name) || empty($password)) {
                 $message_to_user = "Error: Name and Password are required fields.";
            } elseif ($final_id_photo_filename === null && $upload_error_code !== UPLOAD_ERR_NO_FILE) {
                 // This implies a file was selected but an error occurred during processing not covered above
                 $message_to_user = "Error: There was an issue processing the uploaded ID photo. Please try again.";
            } else if ($upload_error_code === UPLOAD_ERR_NO_FILE && $final_id_photo_filename === null){
                 // This should have been caught earlier, but as a fallback.
                 $message_to_user = "Error: ID photo is required and was not uploaded.";
            }
        }
        // If $message_to_user was already set by a file validation error, that message will persist.

    } else {
        $message_to_user = "Error: Required registration form data is missing. Please fill out all fields and upload an ID photo.";
    }
} else {
    // If the page is accessed directly via GET or without a POST request
    $message_to_user = "Please register through the form provided.";
    // Optionally, redirect to register.php if accessed directly:
    // header('Location: register.php');
    // exit();
}

ob_end_flush(); // Send the output buffer (which will be the HTML below)
?>

<!DOCTYPE html>
<html>
<head>
    <title>Registration Status - Security System</title>
    <link rel="stylesheet" href="styles.css"> <!-- Your main stylesheet -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        /* Consistent styling for the status message page */
        body.login-background {
            background: url('cctv.jpg') no-repeat center center fixed;
            background-size: cover;
            font-family: 'Roboto', sans-serif;
            margin: 0; padding: 0; height: 100vh;
            display: flex; justify-content: center; align-items: center; text-align: center;
        }
        .message-container {
            background: rgba(0, 0, 0, 0.8);
            width: 400px; /* Slightly wider for potentially longer messages */
            padding: 30px; border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            color: white;
        }
        .message-container p {
            margin-bottom: 25px; font-size: 1.1em; line-height: 1.6;
        }
        .message-container a.link {
            color: #007bff; text-decoration: none;
            transition: color 0.3s; margin: 0 10px;
            padding: 8px 15px; border: 1px solid #007bff; border-radius: 5px;
        }
        .message-container a.link:hover {
            color: white; background-color: #0056b3; border-color: #0056b3;
        }
        .status-message.error { /* Class for error messages */
            color: #ffdddd; /* Light red for dark background */
            font-weight: bold;
        }
        .status-message.success { /* Class for success messages */
            color: #ddffdd; /* Light green for dark background */
            font-weight: bold;
        }
    </style>
</head>
<body class="login-background">
    <div class="message-container">
        <?php 
        $status_class = 'success'; // Default to success
        if (strpos(strtolower($message_to_user), 'error') !== false || 
            strpos(strtolower($message_to_user), 'failed') !== false ||
            strpos(strtolower($message_to_user), 'missing') !== false ||
            strpos(strtolower($message_to_user), 'invalid') !== false ||
            strpos(strtolower($message_to_user), 'issue') !== false ||
            strpos(strtolower($message_to_user), 'could not') !== false) {
            $status_class = 'error';
        }
        ?>
        <p class="status-message <?php echo $status_class; ?>">
            <?php echo htmlspecialchars($message_to_user); // Always escape user-facing output ?>
        </p>
        <div> <!-- Wrapper for links for better layout if needed -->
            <a href="register.php" class="link">Back to Registration</a>
            <a href="index.php" class="link">Back to Login</a>
        </div>
    </div>
</body>
</html>