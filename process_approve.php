<?php
session_start();
require 'db_config.php'; // Your database connection

// Ensure an admin is logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

// Start output buffering for header redirects
// Only start if not already started (though multiple starts are usually harmless)
if (ob_get_level() == 0) {
    ob_start(); 
}


$upload_dir = 'uploads/'; // Define your upload directory, same as in process_register.php

// This script primarily handles user approval/rejection.
// The shift change logic that was in your very first version of this file
// is assumed to be handled directly within admin_panel.php's POST block now.

if (isset($_POST['pending_id']) && isset($_POST['action'])) {
    $pending_id = (int)$_POST['pending_id']; // Cast to integer for security/type safety
    $action = $_POST['action'];

    // Fetch pending user data first, including id_photo_path, regardless of action
    // This ensures we have the photo path if needed for deletion.
    $pending_user_stmt = $conn->prepare("SELECT id, name, password, id_photo_path FROM pending_users WHERE id = ?");
    if (!$pending_user_stmt) {
        $_SESSION['admin_notification'] = "Error preparing statement (PU_Fetch): " . htmlspecialchars($conn->error);
        error_log("Prepare failed for pending_user_stmt (approve/reject): " . $conn->error);
        header("Location: admin_panel.php");
        exit();
    }
    $pending_user_stmt->bind_param("i", $pending_id);
    $pending_user_stmt->execute();
    $pending_user_result = $pending_user_stmt->get_result();

    if ($pending_user_result->num_rows == 0) {
        $_SESSION['admin_notification'] = "Invalid pending user request. User with ID " . $pending_id . " not found.";
        $pending_user_stmt->close();
        header("Location: admin_panel.php");
        exit();
    }
    $pending_user_data = $pending_user_result->fetch_assoc();
    $pending_user_stmt->close();

    $id_photo_path_from_pending = $pending_user_data['id_photo_path']; // Store for potential file deletion


    if ($action == "approve") {
        // Check if all required fields for approval are set
        if (isset($_POST['assign_id'], $_POST['shift'], $_POST['duty_area'])) {
            $assign_id = trim($_POST['assign_id']);
            $shift = trim($_POST['shift']);
            $duty_area = trim($_POST['duty_area']);

            // Validate assigned ID format
            if (!preg_match('/^SG\d{3}$/', $assign_id)) {
                $_SESSION['admin_notification'] = "Invalid Security ID format. Must be SG followed by 3 digits (e.g., SG001).";
                header("Location: admin_panel.php");
                exit();
            }

            // Check if assigned ID already exists in users table
            $id_check_stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
            if (!$id_check_stmt) {
                $_SESSION['admin_notification'] = "Error preparing statement (ID_Check): " . htmlspecialchars($conn->error);
                error_log("Prepare failed for id_check_stmt (approve): " . $conn->error);
                header("Location: admin_panel.php");
                exit();
            }
            $id_check_stmt->bind_param("s", $assign_id);
            $id_check_stmt->execute();
            $id_check_result = $id_check_stmt->get_result();
            if ($id_check_result->num_rows > 0) {
                $_SESSION['admin_notification'] = "Security ID " . htmlspecialchars($assign_id) . " already exists. Please choose a different ID.";
                $id_check_stmt->close();
                header("Location: admin_panel.php");
                exit();
            }
            $id_check_stmt->close();

            // Get details from $pending_user_data (fetched earlier)
            $name = $pending_user_data['name'];
            $password = $pending_user_data['password']; // This is plaintext

            // Insert into users table WITHOUT id_photo_path
            $insert_user_stmt = $conn->prepare("INSERT INTO users (id, name, password, duty_area, shift) VALUES (?, ?, ?, ?, ?)");
            if (!$insert_user_stmt) {
                 $_SESSION['admin_notification'] = "Error preparing statement (User_Insert): " . htmlspecialchars($conn->error);
                 error_log("Prepare failed for insert_user_stmt (approve): " . $conn->error);
            } else {
                $insert_user_stmt->bind_param("sssss", $assign_id, $name, $password, $duty_area, $shift);
                
                if ($insert_user_stmt->execute()) {
                    $_SESSION['admin_notification'] = "User '" . htmlspecialchars($name) . "' Approved! Assigned Security ID: " . htmlspecialchars($assign_id) . ".";
                    
                    // Delete the physical ID photo file from uploads/ as it's no longer needed
                    if (!empty($id_photo_path_from_pending)) {
                        $file_to_delete_after_approval = $upload_dir . $id_photo_path_from_pending;
                        if (file_exists($file_to_delete_after_approval)) {
                            if (unlink($file_to_delete_after_approval)) {
                                $_SESSION['admin_notification'] .= " Temporary ID photo deleted.";
                                error_log("Deleted approved user's ID photo: " . $file_to_delete_after_approval);
                            } else {
                                $_SESSION['admin_notification'] .= " (Warning: Could not delete temporary ID photo file '" . htmlspecialchars($id_photo_path_from_pending) . "'. Please check server logs/permissions.)";
                                error_log("ERROR: Failed to delete approved user's ID photo: " . $file_to_delete_after_approval);
                            }
                        } else {
                             $_SESSION['admin_notification'] .= " (Notice: Temporary ID photo file not found on server at '" . htmlspecialchars($id_photo_path_from_pending) . "' to delete.)";
                             error_log("NOTICE: Approved user's ID photo file not found to delete: " . $file_to_delete_after_approval);
                        }
                    } else {
                        // No photo path was recorded, so nothing to append to the message here.
                        // $_SESSION['admin_notification'] .= " (No temporary ID photo was associated with this request.)"; // Optional detail
                    }

                    // Delete from pending_users table
                    $delete_pending_stmt = $conn->prepare("DELETE FROM pending_users WHERE id = ?");
                    $delete_pending_stmt->bind_param("i", $pending_id);
                    $delete_pending_stmt->execute();
                    $delete_pending_stmt->close();
                    
                    // Send welcome notification
                    $message_content = "Welcome! Your registration has been approved. Your Security ID: " . htmlspecialchars($assign_id) . ", Shift: " . htmlspecialchars($shift) . ", Duty Area: " . htmlspecialchars($duty_area) . ".";
                    $notif_stmt = $conn->prepare("INSERT INTO notifications (security_id, message) VALUES (?, ?)");
                    $notif_stmt->bind_param("ss", $assign_id, $message_content);
                    $notif_stmt->execute();
                    $notif_stmt->close();

                } else {
                    $_SESSION['admin_notification'] = "Error approving user (DB Insert Failed): " . htmlspecialchars($insert_user_stmt->error);
                    error_log("Execute failed for insert_user_stmt (approve): " . $insert_user_stmt->error);
                }
                $insert_user_stmt->close();
            }
        } else {
            $_SESSION['admin_notification'] = "Missing required fields for approval (Assigned ID, Shift, or Duty Area).";
        }

    } elseif ($action == "reject") {
        // Action is 'reject'
        // $id_photo_path_from_pending was already fetched

        $notification_part_photo = ''; // To build message about photo deletion
        $notification_part_db = '';    // To build message about DB record deletion

        // Delete the physical photo file if it exists
        if (!empty($id_photo_path_from_pending)) {
            $file_path_on_server = $upload_dir . $id_photo_path_from_pending;
            if (file_exists($file_path_on_server)) {
                if (unlink($file_path_on_server)) {
                    $notification_part_photo = "Associated ID photo '" . htmlspecialchars($id_photo_path_from_pending) . "' has been deleted.";
                    error_log("Deleted rejected user's ID photo: " . $file_path_on_server);
                } else {
                    $notification_part_photo = "Could not delete associated ID photo file '" . htmlspecialchars($id_photo_path_from_pending) . "'. Check permissions.";
                    error_log("Failed to delete ID photo on rejection: " . $file_path_on_server);
                }
            } else {
                 $notification_part_photo = "No physical ID photo file found at specified path ('".htmlspecialchars($id_photo_path_from_pending)."') to delete.";
                 error_log("NOTICE: Rejected user's ID photo file not found to delete: " . $file_path_on_server);
            }
        } else {
            $notification_part_photo = "No ID photo path was recorded with the request.";
        }

        // Now delete the record from pending_users
        $delete_pending_stmt = $conn->prepare("DELETE FROM pending_users WHERE id = ?");
        $delete_pending_stmt->bind_param("i", $pending_id);
        if ($delete_pending_stmt->execute()) {
            $notification_part_db = "Pending request removed from database.";
        } else {
            $notification_part_db = "Error removing pending request from database: " . htmlspecialchars($delete_pending_stmt->error);
            error_log("Failed to delete pending user record (ID: $pending_id) on rejection: " . $delete_pending_stmt->error);
        }
        $delete_pending_stmt->close();

        $_SESSION['admin_notification'] = "User Rejected. " . $notification_part_photo . " " . $notification_part_db;

    } else {
        $_SESSION['admin_notification'] = "Invalid action specified for user processing.";
    }
} else {
    // If required POST variables (pending_id, action) are not set
    $_SESSION['admin_notification'] = "Invalid request. Missing parameters for user approval/rejection.";
}

header("Location: admin_panel.php");
exit();

// Ensure output buffer is flushed if it was started (though exit() usually handles this)
if (ob_get_level() > 0) {
    ob_end_flush();
}
?>