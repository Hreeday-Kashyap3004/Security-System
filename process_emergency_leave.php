<?php
session_start();
require 'db_config.php'; // Your database connection

// Ensure a security guard is logged in and their ID is in the session
if (!isset($_SESSION['security_logged_in']) || !isset($_SESSION['security_id'])) {
    header('Location: index.php?error=unauthorized_access');
    exit();
}

// Start output buffering for header redirect
ob_start();

$security_id_from_session = $_SESSION['security_id'];
$notification_message = ''; // For user feedback

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reason'])) {
        $reason_for_leave = trim($_POST['reason']);
        $today_date = date('Y-m-d'); // The leave is for today

        if (empty($reason_for_leave)) {
            $notification_message = "Error: Reason for emergency leave cannot be empty.";
        } else {
            // Begin transaction if your MySQL version/engine supports it and you want atomicity
            // $conn->begin_transaction();
            // For simplicity, we'll proceed with separate queries.

            $leave_inserted = false;
            $attendance_updated = false;

            // 1. Insert into emergency_leaves table
            $stmt_insert_leave = $conn->prepare("INSERT INTO emergency_leaves (security_id, reason, leave_date, status, requested_at) VALUES (?, ?, ?, 'approved', NOW())");
            // Assuming emergency leaves are auto-approved in this system for status.
            // If they need admin approval, status should be 'pending'.
            
            if (!$stmt_insert_leave) {
                error_log("Prepare failed for stmt_insert_leave: " . $conn->error);
                $notification_message = "Error: Could not process leave request (DBP_L). Please try again.";
            } else {
                $stmt_insert_leave->bind_param("sss", $security_id_from_session, $reason_for_leave, $today_date);
                if ($stmt_insert_leave->execute()) {
                    $leave_inserted = true;
                } else {
                    error_log("Execute failed for stmt_insert_leave: " . $stmt_insert_leave->error);
                    $notification_message = "Error: Failed to record emergency leave (DBE_L). Please try again.";
                }
                $stmt_insert_leave->close();
            }

            // 2. Update/Insert into attendance table
            if ($leave_inserted) { // Only proceed if leave was successfully recorded
                // The login_time should persist if it was already set by a login on the same day.
                // If no record exists for today, login_time will be NULL.
                // If they logged in, then requested leave, status becomes 'emergency_leave' but login_time remains.
                $stmt_update_attendance = $conn->prepare(
                    "INSERT INTO attendance (security_id, date, status, login_time) 
                     VALUES (?, ?, 'emergency_leave', NULL) 
                     ON DUPLICATE KEY UPDATE status = 'emergency_leave', login_time = COALESCE(attendance.login_time, NULL)"
                );
                // COALESCE(attendance.login_time, NULL) ensures that if login_time was already set, it's kept.
                // If it was NULL, it remains NULL (or if you want to explicitly set it from VALUES(login_time) if inserting,
                // but since we insert NULL for login_time here, it's fine).
                // A simpler alternative if you don't care about inserting NULL for login_time explicitly when it's a new row:
                // "INSERT INTO attendance (security_id, date, status) VALUES (?, ?, 'emergency_leave') 
                //  ON DUPLICATE KEY UPDATE status = 'emergency_leave'" 
                // This would keep existing login_time implicitly.

                if (!$stmt_update_attendance) {
                    error_log("Prepare failed for stmt_update_attendance: " . $conn->error);
                    $notification_message = "Emergency leave recorded, but failed to update attendance status (DBP_A). Please contact admin.";
                    // Potentially rollback transaction here if using transactions
                } else {
                    $stmt_update_attendance->bind_param("ss", $security_id_from_session, $today_date);
                    if ($stmt_update_attendance->execute()) {
                        $attendance_updated = true;
                        $notification_message = "Emergency Leave Request Submitted Successfully for today. Your attendance has been updated.";
                    } else {
                        error_log("Execute failed for stmt_update_attendance: " . $stmt_update_attendance->error);
                        $notification_message = "Emergency leave recorded, but failed to update attendance status (DBE_A). Please contact admin.";
                        // Potentially rollback transaction here
                    }
                    $stmt_update_attendance->close();
                }
            }

            // if ($leave_inserted && $attendance_updated) {
            //    $conn->commit(); // Commit transaction
            // } else {
            //    $conn->rollback(); // Rollback transaction
            // }

        }
    } else {
        $notification_message = "Error: Reason for leave is missing.";
    }
} else {
    // If not a POST request, redirect
    $notification_message = "Invalid request method.";
    header('Location: security_panel.php');
    exit();
}

$_SESSION['security_panel_notification'] = $notification_message;
header('Location: security_panel.php');
exit();

// Ensure buffer is flushed
if(ob_get_level() > 0) {
    ob_end_flush();
}
?>