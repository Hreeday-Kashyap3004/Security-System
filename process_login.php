<?php
session_start(); // Start the session to set/access session variables
require 'db_config.php'; // Your database connection

// Ensure no prior output before header() calls, good practice
ob_start();

$login_error_message_for_session = ''; // Variable to hold any login error before setting to session

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if essential POST variables are set
    if (isset($_POST['user_type']) && isset($_POST['password'])) {
        $user_type = $_POST['user_type'];
        $password_from_form = $_POST['password']; // Password entered by the user

        if ($user_type == "admin") {
            // Admin login logic (hardcoded password)
            if ($password_from_form == "admin123") { // Your admin password
                $_SESSION['admin_logged_in'] = true;
                header('Location: admin_panel.php');
                exit();
            } else {
                $login_error_message_for_session = "Wrong Admin password!";
            }
        } elseif ($user_type == "security") {
            // Security Personnel login logic
            if (isset($_POST['id']) && !empty(trim($_POST['id']))) {
                $security_id_from_form = trim($_POST['id']);

                // Validate Security ID format (optional but good)
                if (!preg_match('/^SG\d{3}$/', $security_id_from_form)) {
                    $login_error_message_for_session = "Invalid Security ID format. Must be SG followed by 3 digits.";
                } else {
                    // Prepare statement to prevent SQL injection, even with plaintext password comparison for ID
                    $stmt = $conn->prepare("SELECT id, password FROM users WHERE id = ?");
                    if (!$stmt) {
                        error_log("Prepare failed for user select: (" . $conn->errno . ") " . $conn->error);
                        $login_error_message_for_session = "An internal error occurred (DBP_U). Please try again later.";
                    } else {
                        $stmt->bind_param("s", $security_id_from_form);
                        $stmt->execute();
                        $result = $stmt->get_result();

                        if ($result->num_rows == 1) {
                            $user = $result->fetch_assoc();
                            $password_from_db = $user['password']; // Plaintext password from DB

                            // Direct comparison for plaintext passwords
                            if ($password_from_form === $password_from_db) {
                                // Password is correct
                                $_SESSION['security_logged_in'] = true;
                                $_SESSION['security_id'] = $user['id'];

                                // Record attendance
                                $today = date('Y-m-d');
                                $login_time_now = date('H:i:s'); // Get current time for login_time field

                                // Check if already marked present today (or any other status)
                                // If an emergency leave was processed, it might have already set status.
                                // The login should mark as 'present' and record login_time.
                                // ON DUPLICATE KEY UPDATE ensures we handle existing rows for the day.
                                $attendance_stmt = $conn->prepare(
                                    "INSERT INTO attendance (security_id, date, status, login_time) 
                                     VALUES (?, ?, 'present', ?) 
                                     ON DUPLICATE KEY UPDATE status = IF(status = 'emergency_leave', 'emergency_leave', 'present'), login_time = IF(login_time IS NULL, VALUES(login_time), login_time)"
                                );
                                 // The IF(status...) part ensures that if they were already on emergency leave, it stays that way.
                                 // The IF(login_time...) part ensures login_time is only set once per day.

                                if (!$attendance_stmt) {
                                    error_log("Prepare failed for attendance insert/update: (" . $conn->errno . ") " . $conn->error);
                                    // Decide if login should still proceed if attendance logging fails. For now, it does.
                                    // You might want to add a notification to admin or user.
                                } else {
                                    $attendance_stmt->bind_param("sss", $user['id'], $today, $login_time_now);
                                    if (!$attendance_stmt->execute()) {
                                        error_log("Execute failed for attendance insert/update: (" . $attendance_stmt->errno . ") " . $attendance_stmt->error);
                                    }
                                    $attendance_stmt->close();
                                }
                                
                                header('Location: security_panel.php');
                                exit();
                            } else {
                                // Wrong password
                                $login_error_message_for_session = "Invalid Security ID or Password.";
                            }
                        } else {
                            // User ID not found
                            $login_error_message_for_session = "Invalid Security ID or Password.";
                        }
                        $stmt->close();
                    }
                }
            } else {
                $login_error_message_for_session = "Security ID is required for Security Personnel.";
            }
        } else {
            $login_error_message_for_session = "Invalid user type selected.";
        }
    } else {
        $login_error_message_for_session = "Required login fields are missing.";
    }

    // If login failed for any reason and an error message was set, store it in session and redirect
    if (!empty($login_error_message_for_session)) {
        $_SESSION['login_error'] = $login_error_message_for_session;
        header('Location: index.php');
        exit();
    }

} else {
    // If not a POST request, redirect to login page (prevents direct access to this script)
    header('Location: index.php');
    exit();
}

ob_end_flush(); // Send output buffer if no redirect happened (shouldn't occur if logic is tight)
?>