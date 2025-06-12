<?php
session_start();
require 'db_config.php'; // Your database connection

// Ensure a security guard is logged in and their ID is in the session
if (!isset($_SESSION['security_logged_in']) || !isset($_SESSION['security_id'])) {
    // If not logged in, redirect to login page.
    // Optionally, you could set an error message.
    header('Location: index.php?error=unauthorized_access');
    exit();
}

// Start output buffering for header redirect
ob_start();

$security_id_from_session = $_SESSION['security_id'];
$notification_message = ''; // For user feedback

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['current_shift'], $_POST['desired_shift'], $_POST['reason'])) {
        
        $current_shift_from_form = trim($_POST['current_shift']);
        $desired_shift_from_form = trim($_POST['desired_shift']);
        $reason_from_form = trim($_POST['reason']);

        // Basic validation for inputs
        if (empty($desired_shift_from_form)) {
            $notification_message = "Error: Desired shift cannot be empty.";
        } elseif (empty($reason_from_form)) {
            $notification_message = "Error: Reason for shift change cannot be empty.";
        } else {
            // Fetch the user's actual current shift from the database for verification
            $stmt_check_shift = $conn->prepare("SELECT shift FROM users WHERE id = ?");
            if (!$stmt_check_shift) {
                error_log("Prepare failed for stmt_check_shift: " . $conn->error);
                $notification_message = "Error: Could not verify your current shift. Please try again later.";
            } else {
                $stmt_check_shift->bind_param("s", $security_id_from_session);
                $stmt_check_shift->execute();
                $result_check_shift = $stmt_check_shift->get_result();

                if ($result_check_shift->num_rows > 0) {
                    $user_data = $result_check_shift->fetch_assoc();
                    $actual_current_shift_from_db = $user_data['shift'];

                    // Verify that the current_shift from the form matches the one in the DB
                    if ($current_shift_from_form !== $actual_current_shift_from_db) {
                        $notification_message = "Error: Your submitted current shift ('" . htmlspecialchars($current_shift_from_form) . "') does not match our records ('" . htmlspecialchars($actual_current_shift_from_db) . "'). Please refresh your dashboard and try again.";
                    } elseif ($desired_shift_from_form === $actual_current_shift_from_db) {
                        $notification_message = "Notice: Your desired shift is the same as your current shift. No request submitted.";
                    } else {
                        // All checks passed, proceed to insert the shift request
                        $stmt_insert_request = $conn->prepare("INSERT INTO shift_requests (security_id, current_shift, desired_shift, reason, status) VALUES (?, ?, ?, ?, 'pending')");
                        if (!$stmt_insert_request) {
                            error_log("Prepare failed for stmt_insert_request: " . $conn->error);
                            $notification_message = "Error: Failed to submit shift request due to a server issue. Please try again.";
                        } else {
                            $stmt_insert_request->bind_param("ssss", $security_id_from_session, $actual_current_shift_from_db, $desired_shift_from_form, $reason_from_form);
                            
                            if ($stmt_insert_request->execute()) {
                                $notification_message = "Shift Change Request Submitted Successfully! It is now pending admin approval.";
                            } else {
                                error_log("Execute failed for stmt_insert_request: " . $stmt_insert_request->error);
                                $notification_message = "Error: Failed to submit shift request (DB Error). Please try again.";
                            }
                            $stmt_insert_request->close();
                        }
                    }
                } else {
                    // This case should ideally not happen if the user is logged in and their ID is valid
                    $notification_message = "Error: Could not find your user record to verify current shift.";
                    error_log("User record not found for ID: " . $security_id_from_session . " in process_shift_request.php");
                }
                $stmt_check_shift->close();
            }
        }
    } else {
        $notification_message = "Error: Required fields for shift request are missing.";
    }
} else {
    // If not a POST request, redirect (prevents direct script access)
    $notification_message = "Invalid request method."; // Should not be seen if redirect works
    header('Location: security_panel.php');
    exit();
}

// Set session notification and redirect back to the security panel
$_SESSION['security_panel_notification'] = $notification_message;
header('Location: security_panel.php');
exit();

// Ensure buffer is flushed if script somehow reaches here without exiting
if(ob_get_level() > 0) {
    ob_end_flush();
}
?>