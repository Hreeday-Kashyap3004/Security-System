<?php
session_start();
require 'db_config.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

// --- MODIFICATION: Initialize variables for Attendance Tracker (NEW) ---
$attendance_security_id_input = '';
$attendance_month_input = date('m'); // Default to current month, will be overridden by POST if form submitted
$attendance_year_input = date('Y');  // Default to current year, will be overridden by POST if form submitted
$attendance_records = [];
$attendance_message = ''; // For messages related to attendance fetching
$user_name_for_attendance_display = ''; // To store the name of the user whose attendance is being viewed

// Preserve general admin notification if it's not an attendance form submission that sets its own message
$page_load_admin_notification = $_SESSION['admin_notification'] ?? null;
if (isset($_SESSION['admin_notification']) && !isset($_POST['fetch_attendance'])) {
    unset($_SESSION['admin_notification']);
}
// --- END MODIFICATION ---


// Handle all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- MODIFICATION: Start output buffering for header redirects that might occur in POST handling ---
    if(!ob_start("ob_gzhandler")) ob_start(); // Start output buffering

    // --- MODIFICATION: Add logic for Attendance Tracker Form Submission (NEW) ---
    // This should be the first 'if' or 'elseif' in your POST handling block
    if (isset($_POST['fetch_attendance'])) {
        $attendance_security_id_input = trim($_POST['attendance_security_id']);
        $attendance_month_input = trim($_POST['attendance_month']); // Will be like "01", "02", ..., "12"
        $attendance_year_input = trim($_POST['attendance_year']);

        // Basic validation
        if (empty($attendance_security_id_input) || !preg_match('/^SG\d{3}$/', $attendance_security_id_input)) {
            $attendance_message = "Error: Please enter a valid Security ID (e.g., SG001).";
        } elseif (empty($attendance_month_input) || !is_numeric($attendance_month_input) || (int)$attendance_month_input < 1 || (int)$attendance_month_input > 12) {
            $attendance_message = "Error: Please select a valid month.";
        } elseif (empty($attendance_year_input) || !is_numeric($attendance_year_input) || strlen($attendance_year_input) != 4) {
            $attendance_message = "Error: Please select a valid year.";
        } else {
            // Check if security ID exists
            $check_user_stmt = $conn->prepare("SELECT id, name FROM users WHERE id = ?");
            if (!$check_user_stmt) {
                $attendance_message = "Error preparing user check: " . htmlspecialchars($conn->error);
                 error_log("Prepare failed for check_user_stmt: " . $conn->error);
            } else {
                $check_user_stmt->bind_param("s", $attendance_security_id_input);
                $check_user_stmt->execute();
                $check_user_result = $check_user_stmt->get_result();

                if ($check_user_result->num_rows == 0) {
                    $attendance_message = "Error: Security ID " . htmlspecialchars($attendance_security_id_input) . " not found in the system.";
                } else {
                    $user_data_for_att = $check_user_result->fetch_assoc();
                    $user_name_for_attendance_display = $user_data_for_att['name']; // Store name for display

                    $month = str_pad($attendance_month_input, 2, '0', STR_PAD_LEFT); // Ensure two digits for month
                    $year = $attendance_year_input;

                    $start_date = "$year-$month-01";
                    $end_date = date("Y-m-t", strtotime($start_date)); // Get the last day of the selected month

                    // Fetch date, status, and login_time
                    $stmt_att = $conn->prepare("SELECT date, status, login_time FROM attendance WHERE security_id = ? AND date BETWEEN ? AND ? ORDER BY date ASC");
                    if (!$stmt_att) {
                        $attendance_message = "Error preparing attendance query: " . htmlspecialchars($conn->error);
                        error_log("Prepare failed for stmt_att: " . $conn->error);
                    } else {
                        $stmt_att->bind_param("sss", $attendance_security_id_input, $start_date, $end_date);
                        $stmt_att->execute();
                        $result_att = $stmt_att->get_result();

                        if ($result_att->num_rows > 0) {
                            while ($row_att = $result_att->fetch_assoc()) {
                                // Check for actual login based on login_time being set
                                if ($row_att['login_time'] !== null) { 
                                     $attendance_records[] = date("F j, Y (l)", strtotime($row_att['date'])); 
                                }
                            }
                            if (empty($attendance_records)) {
                                 $attendance_message = "No confirmed login records (based on login time having a value) found for " . htmlspecialchars($user_name_for_attendance_display) . " (ID: " . htmlspecialchars($attendance_security_id_input) . ") in " . date("F Y", strtotime($start_date)) . ". The guard may have been on leave or absent without a prior login on these days.";
                            }
                        } else {
                            $attendance_message = "No attendance records of any kind found for " . htmlspecialchars($user_name_for_attendance_display) . " (ID: " . htmlspecialchars($attendance_security_id_input) . ") in " . date("F Y", strtotime($start_date)) . ".";
                        }
                        $stmt_att->close();
                    }
                }
                $check_user_stmt->close();
            }
        }
        // IMPORTANT: No redirect or exit() here for attendance form, as we want to display results on the same page.
    }
    // --- End Attendance Tracker Form Submission ---

    // Handle shift change responses (YOUR EXISTING LOGIC - from the 598 line version)
    elseif (isset($_POST['respond_shift'])) {
        $request_id = $_POST['request_id'];
        $security_id = $_POST['security_id'];
        $new_shift = $_POST['new_shift'];
        
        if (isset($_POST['approve'])) {
            $stmt = $conn->prepare("UPDATE users SET shift=? WHERE id=?");
            $stmt->bind_param("ss", $new_shift, $security_id);
            if ($stmt->execute()) {
                $_SESSION['admin_notification'] = "Shift change APPROVED for ID: " . htmlspecialchars($security_id) . " (New shift: " . htmlspecialchars($new_shift) . ")";
                $message = "Your shift change request has been APPROVED. New shift: " . htmlspecialchars($new_shift);
                $notif_stmt = $conn->prepare("INSERT INTO notifications (security_id, message) VALUES (?, ?)");
                $notif_stmt->bind_param("ss", $security_id, $message);
                $notif_stmt->execute();
                $notif_stmt->close();
            } else {
                $_SESSION['admin_notification'] = "Error: Failed to approve shift change - " . $conn->error;
            }
            $stmt->close();
        } 
        elseif (isset($_POST['reject'])) {
            $_SESSION['admin_notification'] = "Shift change REJECTED for ID: " . htmlspecialchars($security_id);
            $message = "Your shift change request has been REJECTED.";
            $notif_stmt = $conn->prepare("INSERT INTO notifications (security_id, message) VALUES (?, ?)");
            $notif_stmt->bind_param("ss", $security_id, $message);
            $notif_stmt->execute();
            $notif_stmt->close();
        }
        $del_stmt = $conn->prepare("DELETE FROM shift_requests WHERE id=?");
        $del_stmt->bind_param("i", $request_id); 
        $del_stmt->execute();
        $del_stmt->close();
        header("Location: admin_panel.php");
        exit();
    }
    // Handle clear pending requests (YOUR EXISTING LOGIC)
    elseif (isset($_POST['clear_pending'])) {
        // Fetch all id_photo_path before truncating to delete files
        $photo_paths_to_delete = [];
        $result_photos = $conn->query("SELECT id_photo_path FROM pending_users WHERE id_photo_path IS NOT NULL");
        if($result_photos) {
            while($photo_row = $result_photos->fetch_assoc()) {
                $photo_paths_to_delete[] = $photo_row['id_photo_path'];
            }
        }

        $conn->query("TRUNCATE TABLE pending_users"); 
        $_SESSION['admin_notification'] = "Pending requests cleared from database.";

        $deleted_files_count = 0;
        foreach($photo_paths_to_delete as $photo_file) {
            $file_to_delete = 'uploads/' . $photo_file;
            if(file_exists($file_to_delete)) {
                if(unlink($file_to_delete)) {
                    $deleted_files_count++;
                } else {
                    error_log("Failed to delete photo during clear_pending: " . $file_to_delete);
                }
            }
        }
        if ($deleted_files_count > 0) {
             $_SESSION['admin_notification'] .= " $deleted_files_count associated ID photo file(s) deleted.";
        } else if (!empty($photo_paths_to_delete) && $deleted_files_count == 0) {
            $_SESSION['admin_notification'] .= " Attempted to delete photos, but none were removed (check permissions or paths).";
        }

        header("Location: admin_panel.php");
        exit();
    }
    // Handle clear shift requests (YOUR EXISTING LOGIC)
    elseif (isset($_POST['clear_shifts'])) {
        $conn->query("TRUNCATE TABLE shift_requests"); 
        $_SESSION['admin_notification'] = "Shift requests cleared";
        header("Location: admin_panel.php");
        exit();
    }
    // Handle sending notifications (YOUR EXISTING LOGIC, including "send to all" enhancement)
    elseif (isset($_POST['send_notification'])) {
        $security_id_for_notif = trim($_POST['security_id']); 
        $message_content = trim($_POST['notification_message']);

        if(empty($message_content)){
            $_SESSION['admin_notification'] = "Error: Notification message cannot be empty.";
        } elseif (empty($security_id_for_notif)) { 
            $users_result = $conn->query("SELECT id FROM users"); 
            if ($users_result && $users_result->num_rows > 0) {
                $stmt_all = $conn->prepare("INSERT INTO notifications (security_id, message) VALUES (?, ?)");
                if(!$stmt_all) {
                     $_SESSION['admin_notification'] = "Error preparing mass notification: " . $conn->error;
                } else {
                    $success_count = 0;
                    while($user_row = $users_result->fetch_assoc()){
                        $stmt_all->bind_param("ss", $user_row['id'], $message_content);
                        if($stmt_all->execute()) $success_count++;
                    }
                    $stmt_all->close();
                    $_SESSION['admin_notification'] = "Notification sent to ALL ($success_count) active security personnel.";
                }
            } else {
                $_SESSION['admin_notification'] = "No active security personnel found to send mass notification.";
            }
        } else { 
             if (!preg_match('/^SG\d{3}$/', $security_id_for_notif)) { 
                 $_SESSION['admin_notification'] = "Error: Invalid Security ID format for notification ('" . htmlspecialchars($security_id_for_notif) . "').";
             } else {
                $stmt_notif = $conn->prepare("INSERT INTO notifications (security_id, message) VALUES (?, ?)");
                 if(!$stmt_notif){
                     $_SESSION['admin_notification'] = "Error preparing notification: " . $conn->error;
                 } else {
                    $stmt_notif->bind_param("ss", $security_id_for_notif, $message_content);
                    if ($stmt_notif->execute()) {
                        $_SESSION['admin_notification'] = "Notification sent to Security ID: " . htmlspecialchars($security_id_for_notif);
                    } else {
                        $_SESSION['admin_notification'] = "Error sending notification: " . $stmt_notif->error;
                    }
                    $stmt_notif->close();
                 }
             }
        }
        header("Location: admin_panel.php");
        exit();
    }
    
    // --- MODIFICATION: End output buffering if it was started and a redirect happened ---
    // This general flush is better at the script's very end.
    // The main purpose of ob_start here is to catch output before header() calls in POST actions.
    // If we reach here after a POST and haven't exited, it's likely the attendance form, which doesn't redirect.

} // END OF if ($_SERVER['REQUEST_METHOD'] === 'POST')


// Fetch data (YOUR EXISTING QUERIES - now ensuring id_photo_path and necessary fields are fetched)
// Also ensuring `requested_at` exists or using `id` for ordering.
// Assuming `pending_users` has `requested_at` and `shift_requests` has `requested_at`.
// If not, change `ORDER BY requested_at DESC` to `ORDER BY id DESC`.
$pendingUsers = $conn->query("SELECT id, name, password, id_photo_path, requested_at FROM pending_users ORDER BY requested_at DESC");
$shiftRequests = $conn->query("SELECT sr.id, sr.security_id, sr.current_shift, sr.desired_shift, sr.reason, sr.requested_at, u.name as user_name 
                                FROM shift_requests sr 
                                LEFT JOIN users u ON sr.security_id = u.id 
                                ORDER BY sr.requested_at DESC");

// --- MODIFICATION: Restore general admin notification if it was set by a redirecting POST action ---
// This is done at the top now before the POST block might unset it.
if (isset($page_load_admin_notification) && !isset($_POST['fetch_attendance'])) { // if it's not an attendance postback
    $_SESSION['admin_notification'] = $page_load_admin_notification;
}
// --- END MODIFICATION ---
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* YOUR EXISTING CSS FROM THE 598-LINE FILE - UNCHANGED */
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --danger: #f72585;
            --warning: #f8961e;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --white: #ffffff;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background-color: #f5f7fb; color: var(--dark); line-height: 1.6; }
        .dashboard-container { display: grid; grid-template-columns: 240px 1fr; min-height: 100vh; }
        .sidebar { background: var(--primary); color: var(--white); padding: 1.5rem; box-shadow: 2px 0 10px rgba(0,0,0,0.1); position: fixed; width: 240px; height: 100%; overflow-y: auto; }
        .sidebar-header { padding-bottom: 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 1.5rem; }
        .sidebar-header h2 { font-weight: 600; font-size: 1.25rem; }
        .profile-info { margin-bottom: 2rem; }
        .profile-info h3 { font-size: 1rem; font-weight: 500; margin-bottom: 0.25rem; }
        .profile-info p { font-size: 0.875rem; opacity: 0.8; }
        .logout-btn { display: inline-flex; align-items: center; color: var(--white); text-decoration: none; font-weight: 500; padding: 0.5rem 0; transition: opacity 0.3s; }
        .logout-btn:hover { opacity: 0.8; }
        .main-content { grid-column: 2; padding: 2rem; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .header h1 { font-size: 1.75rem; font-weight: 600; color: var(--dark); }
        .card { background: var(--white); border-radius: 0.5rem; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 1.5rem; overflow: hidden; }
        .card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; }
        .card-header h2 { font-size: 1.25rem; font-weight: 500; color: var(--dark); }
        .card-body { padding: 1.5rem; }
        .notification-card { border-left: 4px solid var(--warning); }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; color: var(--dark); }
        .form-control, select.form-control { width: 100%; padding: 0.75rem 1rem; font-size: 0.875rem; border: 1px solid #e2e8f0; border-radius: 0.375rem; transition: border-color 0.3s, box-shadow 0.3s; background-color: var(--white); }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1); }
        textarea.form-control { min-height: 120px; resize: vertical; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 0.75rem 1.5rem; font-size: 0.875rem; font-weight: 500; border-radius: 0.375rem; cursor: pointer; transition: all 0.3s; border: none; }
        .btn-primary { background-color: var(--primary); color: var(--white); }
        .btn-primary:hover { background-color: var(--primary-dark); }
        .btn-success { background-color: var(--success); color: var(--white);  }
        .btn-danger { background-color: var(--danger); color: var(--white); }
        .btn-warning { background-color: var(--warning); color: var(--white); }
        .btn-sm { padding: 0.5rem 1rem; font-size: 0.8125rem; }
        .request-item { padding: 1.25rem; border-bottom: 1px solid rgba(0,0,0,0.05); }
        .request-item:last-child { border-bottom: none; }
        .request-item h3 { font-size: 1rem; font-weight: 500; margin-bottom: 0.75rem; }
        .id-photo-container img { max-width: 200px; max-height: 180px; border: 1px solid #ddd; border-radius: 0.375rem; padding: 5px; margin-top: 5px; display: block; }
        .shift-response { margin-top: 1rem; padding-top: 1rem; border-top: 1px dashed #e2e8f0; }
        .alert { padding: 1rem; border-radius: 0.375rem; margin-bottom: 1.5rem; font-size: 0.875rem; }
        .alert-success { background-color: rgba(76, 201, 240, 0.1);  border-left: 4px solid var(--success); color: var(--dark);  }
        .alert-error { background-color: rgba(247, 37, 133, 0.1);  border-left: 4px solid var(--danger); color: var(--dark);  }
        .mt-3 { margin-top: 1rem; }
        .text-muted { color: var(--gray); }
        .d-flex { display: flex; }
        .gap-2 { gap: 0.5rem; }

        /* --- MODIFICATION: CSS for Attendance Results (NEW) --- */
        .attendance-results ul { list-style-type: none; padding-left: 0; margin-top: 0.5rem; }
        .attendance-results li { background-color: #e9ecef; color: var(--dark); padding: 8px 12px; margin-bottom: 6px; border-radius: 0.25rem; border: 1px solid #dee2e6; font-size: 0.9rem; }
        .attendance-results h4 { margin-bottom: 0.75rem; color: var(--primary); font-size: 1.1rem;}
        .attendance-results .text-muted { font-size: 0.9rem; }
        /* --- END MODIFICATION --- */
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar (YOUR EXISTING HTML - UNCHANGED) -->
        <div class="sidebar">
            <div class="sidebar-header"><h2>Security Portal</h2></div>
            <div class="profile-info"><h3>Administrator</h3><p class="text-muted">Full System Access</p></div>
            <a href="index.php?logout=true" class="logout-btn"> 
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" style="margin-right: 8px;"><path fill-rule="evenodd" d="M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 9.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0v2z"/><path fill-rule="evenodd" d="M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708l3-3z"/></svg>
                Logout
            </a>
        </div>
        
        <!-- Main Content (YOUR EXISTING HTML STRUCTURE - UNCHANGED except for new card) -->
        <div class="main-content">
            <div class="header"><h1>Admin Dashboard</h1></div>
            
            <?php if (isset($_SESSION['admin_notification'])): ?>
                <div class="alert <?= (strpos(strtolower($_SESSION['admin_notification']), 'error') !== false || strpos(strtolower($_SESSION['admin_notification']), 'failed') !== false) ? 'alert-error' : 'alert-success' ?>">
                    <?= htmlspecialchars($_SESSION['admin_notification']) ?>
                </div>
                <?php unset($_SESSION['admin_notification']); // Unset after displaying ?>
            <?php endif; ?>
            
            <!-- Notification Card (YOUR EXISTING HTML - UNCHANGED) -->
            <div class="card notification-card">
                <div class="card-header"><h2>Send Notification</h2></div>
                <div class="card-body">
                    <form method="POST" action="admin_panel.php">
                        <div class="form-group">
                            <label for="security_id_notif">Security ID (Leave blank to send to all)</label> 
                            <input type="text" id="security_id_notif" name="security_id" class="form-control" placeholder="e.g. SG001 or leave blank" pattern="(SG\d{3})?" title="Format: SG001 or blank">
                        </div>
                        <div class="form-group">
                            <label for="notification_message">Message Content</label>
                            <textarea id="notification_message" name="notification_message" class="form-control" required rows="4" placeholder="Type your notification message here..."></textarea>
                        </div>
                        <button type="submit" name="send_notification" class="btn btn-primary">Send Notification</button>
                    </form>
                </div>
            </div>

            <!-- --- MODIFICATION: Add HTML for Attendance Tracker Card (NEW) --- -->
            <div class="card">
                <div class="card-header"><h2>Security Guard Attendance Tracker</h2></div>
                <div class="card-body">
                    <form method="POST" action="admin_panel.php">
                        <div class="form-group">
                            <label for="attendance_security_id_form">Security Guard ID</label>
                            <input type="text" id="attendance_security_id_form" name="attendance_security_id" class="form-control" 
                                   value="<?= htmlspecialchars($attendance_security_id_input) ?>" required pattern="SG\d{3}" title="Format: SG001">
                        </div>
                        <div class="form-group">
                            <label for="attendance_month_form">Month</label>
                            <select id="attendance_month_form" name="attendance_month" class="form-control" required>
                                <?php 
                                $selected_month_att = isset($_POST['fetch_attendance']) ? $attendance_month_input : date('m');
                                for ($m=1; $m<=12; ++$m): 
                                    $month_value = sprintf("%02d", $m); 
                                ?>
                                    <option value="<?= $month_value ?>" <?= ($selected_month_att == $month_value) ? 'selected' : '' ?>>
                                        <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="attendance_year_form">Year</label>
                            <select id="attendance_year_form" name="attendance_year" class="form-control" required>
                                <?php 
                                $currentYear = date('Y');
                                $selected_year_att = isset($_POST['fetch_attendance']) ? $attendance_year_input : $currentYear;
                                for ($y = $currentYear - 5; $y <= $currentYear + 1; ++$y): ?>
                                    <option value="<?= $y ?>" <?= ($selected_year_att == $y) ? 'selected' : '' ?>>
                                        <?= $y ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <button type="submit" name="fetch_attendance" class="btn btn-primary">Fetch Attendance</button>
                    </form>

                    <?php if (isset($_POST['fetch_attendance'])): // Only show results section if attendance form was submitted ?>
                        <div class="attendance-results mt-3">
                            <?php 
                            $display_month_name = "Invalid Month";
                            if(is_numeric($attendance_month_input) && (int)$attendance_month_input >= 1 && (int)$attendance_month_input <= 12){
                                $display_month_name = date('F', mktime(0, 0, 0, (int)$attendance_month_input, 1));
                            }
                            $display_year = is_numeric($attendance_year_input) ? htmlspecialchars($attendance_year_input) : "Invalid Year";
                            $display_user_name = htmlspecialchars($user_name_for_attendance_display ?: $attendance_security_id_input);
                            ?>
                            <h4>Login Dates for <?= $display_user_name ?> in <?= $display_month_name . " " . $display_year ?>:</h4>
                            
                            <?php if (!empty($attendance_message) && empty($attendance_records)): ?>
                                <p class="text-muted"><?= htmlspecialchars($attendance_message) ?></p>
                            <?php elseif (!empty($attendance_records)): ?>
                                <ul>
                                    <?php foreach ($attendance_records as $date_present): ?>
                                        <li><?= htmlspecialchars($date_present) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php if(!empty($attendance_message)): ?>
                                    <p class="text-muted mt-3"><em>Note: <?= htmlspecialchars($attendance_message) ?></em></p>
                                <?php endif; ?>
                            <?php elseif (empty($attendance_message)): ?>
                                <p class="text-muted">No 'present' (login) records found for this period.</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <!-- --- END Attendance Tracker Card --- -->
            
            <!-- New Entry Requests (YOUR EXISTING HTML with ID photo display - UNCHANGED) -->
            <div class="card">
                <div class="card-header"><h2>New Entry Requests</h2></div>
                <div class="card-body">
                    <?php if ($pendingUsers && $pendingUsers->num_rows > 0): ?>
                        <?php while($row = $pendingUsers->fetch_assoc()): ?>
                            <div class="request-item">
                                <form action="process_approve.php" method="post">
                                    <h3><?= htmlspecialchars($row['name']) ?></h3>
                                    <?php if (!empty($row['id_photo_path'])): ?>
                                        <div class="form-group id-photo-container">
                                            <label>Uploaded ID Photo:</label>
                                            <a href="uploads/<?= htmlspecialchars($row['id_photo_path']) ?>" target="_blank" title="View full image">
                                                <img src="uploads/<?= htmlspecialchars($row['id_photo_path']) ?>" alt="ID Photo for <?= htmlspecialchars($row['name']) ?>">
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted">No ID photo was uploaded for this request.</p>
                                    <?php endif; ?>
                                    <div class="form-group">
                                        <label>Assign ID</label>
                                        <input type="text" name="assign_id" class="form-control" required pattern="SG\d{3}" title="Format: SG001">
                                    </div>
                                    <div class="form-group">
                                        <label>Duty Area</label>
                                        <select name="duty_area" class="form-control" required>
                                            <option value="">Select duty area</option>
                                            <option value="Main Entrance">Main Entrance</option>
                                            <option value="Academic Building">Academic Building</option>
                                            <option value="Hostels">Hostels</option>
                                            <option value="Health Centre">Health Centre</option>
                                            <option value="Parking Lot">Parking Lot</option>
                                            <option value="Botanical Garden">Botanical Garden</option>
                                            <option value="Workshop">Workshop</option>
                                            <option value="Dhaba">Dhaba</option>
                                            <option value="Engineering Dept">Engineering Dept</option>
                                            <option value="Maths Dept">Maths Dept</option>
                                            <option value="Microbiology Department">Microbiology Department</option>
                                            <option value="School of Engineering">School of Engineering</option>
                                            <option value="Niribili">Niribili</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Shift</label>
                                        <select name="shift" class="form-control" required>
                                            <option value="">Select shift</option>
                                            <option value="5 am to 1 pm">5 am - 1 pm</option>
                                            <option value="1 pm to 9 pm">1 pm - 9 pm</option>
                                            <option value="9 pm to 5 am">9 pm - 5 am</option>
                                        </select>
                                    </div>
                                    <input type="hidden" name="pending_id" value="<?= $row['id'] ?>">
                                    <div class="d-flex gap-2">
                                        <button type="submit" name="action" value="approve" class="btn btn-success">Approve</button>
                                        <button type="submit" name="action" value="reject" class="btn btn-danger">Reject</button>
                                    </div>
                                </form>
                            </div>
                        <?php endwhile; ?>
                        <form method="POST" action="admin_panel.php" class="mt-3">
                            <button type="submit" name="clear_pending" class="btn btn-warning" onclick="return confirm('Are you sure you want to clear ALL pending requests? This will also attempt to delete associated ID photos.');">Clear All Pending Requests</button>
                        </form>
                    <?php else: ?>
                        <p class="text-muted">No pending registration requests.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Shift Change Requests (YOUR EXISTING HTML - UNCHANGED) -->
            <div class="card">
                <div class="card-header"><h2>Shift Change Requests</h2></div>
                <div class="card-body">
                     <?php if ($shiftRequests && $shiftRequests->num_rows > 0): ?>
                        <?php while($row = $shiftRequests->fetch_assoc()): ?>
                            <div class="request-item">
                                <h3>Security ID: <?= htmlspecialchars($row['security_id']) ?> <?php if(isset($row['user_name'])) echo "(".htmlspecialchars($row['user_name']).")"; ?></h3>
                                <p><strong>Current Shift:</strong> <?= htmlspecialchars($row['current_shift']) ?></p>
                                <p><strong>Requested Shift:</strong> <?= htmlspecialchars($row['desired_shift']) ?></p>
                                <p><strong>Reason:</strong> <?= nl2br(htmlspecialchars($row['reason'])) ?></p>
                                <?php if(isset($row['requested_at'])): ?>
                                    <p class="text-muted"><small>Requested on: <?= date('M j, Y g:i a', strtotime($row['requested_at'])) ?></small></p>
                                <?php endif; ?>
                                <form method="POST" action="admin_panel.php" class="shift-response">
                                    <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="security_id" value="<?= htmlspecialchars($row['security_id']) ?>"> 
                                    <input type="hidden" name="new_shift" value="<?= htmlspecialchars($row['desired_shift']) ?>"> 
                                    <input type="hidden" name="respond_shift" value="1">
                                    <div class="d-flex gap-2">
                                        <button type="submit" name="approve" value="1" class="btn btn-success btn-sm">Approve Shift Change</button>
                                        <button type="submit" name="reject" value="1" class="btn btn-danger btn-sm">Reject Request</button>
                                    </div>
                                </form>
                            </div>
                        <?php endwhile; ?>
                        <form method="POST" action="admin_panel.php" class="mt-3">
                            <button type="submit" name="clear_shifts" class="btn btn-warning" onclick="return confirm('Are you sure you want to clear all shift requests?');">Clear All Shift Requests</button>
                        </form>
                    <?php else: ?>
                        <p class="text-muted">No shift change requests.</p>
                    <?php endif; ?>
                </div>
            </div>

        </div> <!-- .main-content -->
    </div> <!-- .dashboard-container -->
    <?php 
    // --- MODIFICATION: Ensure output buffer is flushed if content exists ---
    // This should be the very last PHP thing before </body>
    if(ob_get_level() > 0 && ob_get_length() > 0) { // Check if buffer is active and has content
        ob_end_flush(); 
    } elseif (ob_get_level() > 0) { // If buffer is active but empty, just end it
        ob_end_clean();
    }
    ?>
</body>
</html>