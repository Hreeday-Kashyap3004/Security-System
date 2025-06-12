<?php
session_start();
require 'db_config.php'; // Your database connection

// Ensure a security guard is logged in
if (!isset($_SESSION['security_logged_in']) || !isset($_SESSION['security_id'])) {
    header('Location: index.php');
    exit();
}

$security_id = $_SESSION['security_id']; // Get the logged-in guard's ID

// --- Handle POST requests for this page (e.g., clearing messages) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start();
    if (isset($_POST['clear_messages'])) {
        $stmt_clear = $conn->prepare("DELETE FROM notifications WHERE security_id = ?");
        if ($stmt_clear) {
            $stmt_clear->bind_param("s", $security_id);
            if ($stmt_clear->execute()) {
                $_SESSION['security_panel_notification'] = "All messages cleared successfully.";
            } else {
                $_SESSION['security_panel_notification'] = "Error clearing messages: " . htmlspecialchars($stmt_clear->error);
            }
            $stmt_clear->close();
        } else {
            $_SESSION['security_panel_notification'] = "Error preparing to clear messages: " . htmlspecialchars($conn->error);
        }
        header("Location: security_panel.php"); // Redirect to refresh and show notification
        exit();
    }
    // Add other POST handling for this page if needed in the future
    ob_end_flush();
}
// --- End POST handling ---


// Fetch the guard's current details from the database
// This ensures the displayed info is always up-to-date, especially shift
$stmt_guard = $conn->prepare("SELECT name, duty_area, shift FROM users WHERE id = ?");
if (!$stmt_guard) {
    // Handle error - perhaps redirect or show a generic error
    die("Error preparing to fetch guard details: " . $conn->error);
}
$stmt_guard->bind_param("s", $security_id);
$stmt_guard->execute();
$result_guard = $stmt_guard->get_result();
if ($result_guard->num_rows > 0) {
    $guard_details = $result_guard->fetch_assoc();
} else {
    // Should not happen if session is valid, but handle gracefully
    // Potentially log out user or show error
    unset($_SESSION['security_logged_in']);
    unset($_SESSION['security_id']);
    header('Location: index.php?error=user_not_found');
    exit();
}
$stmt_guard->close();

// Fetch all notifications for this guard
$stmt_notifications = $conn->prepare("SELECT id, message, created_at FROM notifications WHERE security_id = ? ORDER BY created_at DESC");
if (!$stmt_notifications) {
    die("Error preparing to fetch notifications: " . $conn->error);
}
$stmt_notifications->bind_param("s", $security_id);
$stmt_notifications->execute();
$notifications_result = $stmt_notifications->get_result();
$notifications_list = [];
while ($note_row = $notifications_result->fetch_assoc()) {
    $notifications_list[] = $note_row;
}
$stmt_notifications->close();

// Fetch attendance data for the current month for the calendar
// This part is similar to what fetch_attendance.php did, but directly for initial calendar load
// You might also use an AJAX call to fetch_attendance.php for dynamic calendar updates if preferred
$current_month_start_date = date('Y-m-01');
$current_month_end_date = date('Y-m-t');
$stmt_attendance_cal = $conn->prepare("SELECT date, status, login_time FROM attendance WHERE security_id = ? AND date BETWEEN ? AND ?");
$calendar_events_js_array = []; // To build JS array for FullCalendar
if ($stmt_attendance_cal) {
    $stmt_attendance_cal->bind_param("sss", $security_id, $current_month_start_date, $current_month_end_date);
    $stmt_attendance_cal->execute();
    $attendance_cal_result = $stmt_attendance_cal->get_result();
    while ($att_row = $attendance_cal_result->fetch_assoc()) {
        $title = '';
        $color = '';
        $event_status = strtolower($att_row['status']);

        if ($att_row['login_time'] !== null) { // If logged in, always mark as such, even if leave was later
            $title = 'Logged In';
            $color = '#2ecc71'; // Green for present/login
            if ($event_status == 'emergency_leave') {
                $title .= ' (On Leave)'; // Append leave status if also on leave
                $color = '#e67e22'; // Orange for on leave after login
            }
        } elseif ($event_status == 'present') { // Should ideally always have login_time
            $title = 'Present';
            $color = '#2ecc71'; 
        } elseif ($event_status == 'absent') {
            $title = 'Absent';
            $color = '#e74c3c'; // Red for absent
        } elseif ($event_status == 'emergency_leave') {
            $title = 'Emergency Leave';
            $color = '#f39c12'; // Orange for emergency leave
        } else {
            $title = ucfirst($event_status); // Fallback
            $color = '#bdc3c7'; // Grey for unknown
        }
        
        if (!empty($title)) {
            $calendar_events_js_array[] = [
                'title' => $title,
                'start' => $att_row['date'],
                'allDay' => true,
                'color' => $color,
                'borderColor' => $color // Optional: make border same as background
            ];
        }
    }
    $stmt_attendance_cal->close();
} else {
    // Handle error preparing statement for calendar data
    error_log("Error preparing calendar attendance statement: " . $conn->error);
}

$security_panel_notification_message = $_SESSION['security_panel_notification'] ?? null;
if (isset($_SESSION['security_panel_notification'])) unset($_SESSION['security_panel_notification']);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Security Dashboard - <?= htmlspecialchars($guard_details['name']) ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    <style>
        /* Your existing styles.css should cover most, but here are some specific to this panel */
        :root {
            --primary-sec: #2c3e50; /* Dark blue/grey for security panel theme */
            --secondary-sec: #3498db; /* Brighter blue for accents */
            --light-sec: #ecf0f1;
            --dark-sec: #2c3e50;
            --success-sec: #2ecc71; /* Green */
            --warning-sec: #f39c12; /* Orange */
            --danger-sec: #e74c3c; /* Red */
            --info-sec: #3498db; /* Blue for info */
        }
        body { font-family: 'Roboto', sans-serif; margin: 0; padding: 0; background-color: #f4f6f9; color: var(--dark-sec); }
        .dashboard-container-sec { display: grid; grid-template-columns: 260px 1fr; min-height: 100vh; }
        .sidebar-sec { background: var(--primary-sec); color: white; padding: 25px; position: fixed; width: 260px; height: 100%; overflow-y: auto; }
        .sidebar-sec h2 { margin-top: 0; margin-bottom: 20px; font-size: 1.6em; border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 15px; }
        .profile-info-sec { margin-bottom: 30px; }
        .profile-info-sec h3 { font-size: 1.2em; margin-bottom: 5px; }
        .profile-info-sec p { font-size: 0.9em; margin-bottom: 8px; opacity: 0.9; }
        .profile-info-sec p strong { font-weight: 500; }
        #current-shift-display { font-weight: bold; color: var(--success-sec); }
        .main-content-sec { grid-column: 2; padding: 30px; }
        .main-content-sec h1 { font-size: 2em; margin-bottom: 25px; color: var(--primary-sec); }
        .card-sec { background: white; border-radius: 8px; padding: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 30px; }
        .card-sec h2 { font-size: 1.4em; margin-top: 0; margin-bottom: 20px; color: var(--secondary-sec); border-bottom: 1px solid #eee; padding-bottom: 10px;}
        .form-group-sec { margin-bottom: 20px; }
        .form-group-sec label { display: block; margin-bottom: 8px; font-weight: 500; color: var(--dark-sec); }
        .form-control-sec, select.form-control-sec, textarea.form-control-sec {
            width: 100%; padding: 12px 15px; border: 1px solid #ccc; border-radius: 5px;
            font-family: inherit; font-size: 0.95em; box-sizing: border-box;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-control-sec:focus { outline: none; border-color: var(--secondary-sec); box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2); }
        textarea.form-control-sec { min-height: 100px; resize: vertical; }
        .btn-sec {
            background: var(--secondary-sec); color: white; border: none; padding: 12px 25px;
            border-radius: 5px; cursor: pointer; font-weight: 500; font-size: 0.95em;
            transition: background-color 0.2s, transform 0.1s; text-transform: uppercase;
        }
        .btn-sec:hover { background-color: #2980b9; transform: translateY(-1px); }
        .btn-sec-danger { background: var(--danger-sec); }
        .btn-sec-danger:hover { background: #c0392b; }
        .logout-btn-sec {
            display: block; width: 100%; text-align: center; margin-top: 30px;
            color: white; background-color: rgba(255,255,255,0.15);
            padding: 10px; text-decoration: none; font-weight: 500; border-radius: 5px;
            transition: background-color 0.2s;
        }
        .logout-btn-sec:hover { background-color: rgba(255,255,255,0.25); }
        .notifications-list-sec { max-height: 300px; overflow-y: auto; padding-right: 10px; }
        .notification-item-sec { background-color: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 10px; border-left: 4px solid var(--info-sec); }
        .notification-item-sec p { margin: 0 0 5px 0; }
        .notification-time-sec { font-size: 0.8em; color: #777; text-align: right; }
        .panel-notification {
            padding: 15px; margin-bottom: 20px; border-radius: 5px; border-left: 5px solid;
        }
        .panel-notification.success { border-left-color: var(--success-sec); background-color: #e8f5e9; color: #2e7d32; }
        .panel-notification.error { border-left-color: var(--danger-sec); background-color: #ffebee; color: #c62828; }
        #attendance-calendar { max-width: 100%; margin-top: 20px; }
        .fc-event { cursor: default !important; } /* Make calendar events not look clickable */
        .shift-update-animation { animation: pulseColor 1.5s infinite alternate; }
        @keyframes pulseColor { 0% { color: var(--success-sec); } 100% { color: var(--warning-sec); } }
    </style>
</head>
<body>
    <div class="dashboard-container-sec">
        <div class="sidebar-sec">
            <h2>Security Portal</h2>
            <div class="profile-info-sec">
                <h3><?= htmlspecialchars($guard_details['name']) ?></h3>
                <p><strong>ID:</strong> <?= htmlspecialchars($security_id) ?></p>
                <p><strong>Duty Area:</strong> <?= htmlspecialchars($guard_details['duty_area']) ?></p>
                <p><strong>Current Shift:</strong> <span id="current-shift-display"><?= htmlspecialchars($guard_details['shift']) ?></span></p>
            </div>
            <a href="index.php?logout=true" class="logout-btn-sec">Logout</a>
        </div>
        
        <div class="main-content-sec">
            <h1>Your Dashboard</h1>

            <?php if ($security_panel_notification_message): ?>
                <div class="panel-notification <?= (strpos(strtolower($security_panel_notification_message), 'error') !== false) ? 'error' : 'success' ?>">
                    <?= htmlspecialchars($security_panel_notification_message) ?>
                </div>
            <?php endif; ?>

            <div class="card-sec">
                <h2>Admin Notifications</h2>
                <?php if (!empty($notifications_list)): ?>
                    <div class="notifications-list-sec">
                        <?php foreach ($notifications_list as $note): ?>
                            <div class="notification-item-sec">
                                <p><?= nl2br(htmlspecialchars($note['message'])) ?></p>
                                <div class="notification-time-sec">
                                    Received: <?= date('M j, Y, g:i a', strtotime($note['created_at'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <form method="POST" action="security_panel.php" style="margin-top: 20px; text-align:right;">
                        <button type="submit" name="clear_messages" class="btn-sec btn-sec-danger">Clear All Messages</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted">No new messages from admin.</p>
                <?php endif; ?>
            </div>

            <div class="card-sec">
                <h2>Your Attendance Calendar</h2>
                <div id="attendance-calendar"></div>
                <div style="margin-top: 15px; display: flex; justify-content: space-around; font-size: 0.9em;">
                    <span><span style="display:inline-block; width:12px; height:12px; background-color:#2ecc71; margin-right:5px; border-radius:3px;"></span> Logged In</span>
                    <span><span style="display:inline-block; width:12px; height:12px; background-color:#e74c3c; margin-right:5px; border-radius:3px;"></span> Absent</span>
                    <span><span style="display:inline-block; width:12px; height:12px; background-color:#f39c12; margin-right:5px; border-radius:3px;"></span> Emergency Leave</span>
                    <span><span style="display:inline-block; width:12px; height:12px; background-color:#e67e22; margin-right:5px; border-radius:3px;"></span> Logged In (then Leave)</span>
                </div>
            </div>

            <div class="card-sec">
                <h2>Request Shift Change</h2>
                <form action="process_shift_request.php" method="post">
                    <div class="form-group-sec">
                        <label for="current_shift_form">Your Current Shift (Read-only)</label>
                        <input type="text" id="current_shift_form" name="current_shift" class="form-control-sec" 
                               value="<?= htmlspecialchars($guard_details['shift']) ?>" readonly>
                    </div>
                    <div class="form-group-sec">
                        <label for="desired_shift_form">Desired New Shift</label>
                        <select id="desired_shift_form" name="desired_shift" class="form-control-sec" required>
                            <option value="" disabled selected>Select desired shift</option>
                            <option value="5 am to 1 pm">5 am - 1 pm</option>
                            <option value="1 pm to 9 pm">1 pm - 9 pm</option>
                            <option value="9 pm to 5 am">9 pm - 5 am</option>
                        </select>
                    </div>
                    <div class="form-group-sec">
                        <label for="reason_shift_form">Reason for Change</label>
                        <textarea id="reason_shift_form" name="reason" class="form-control-sec" rows="4" required placeholder="Please provide a brief reason for your request..."></textarea>
                    </div>
                    <button type="submit" class="btn-sec">Submit Shift Request</button>
                </form>
            </div>

            <div class="card-sec">
                <h2>Request Emergency Leave (for Today)</h2>
                <form action="process_emergency_leave.php" method="post">
                    <div class="form-group-sec">
                        <label for="reason_leave_form">Reason for Emergency Leave</label>
                        <textarea id="reason_leave_form" name="reason" class="form-control-sec" rows="4" required placeholder="Please provide details for your emergency leave request..."></textarea>
                    </div>
                    <button type="submit" class="btn-sec">Submit Leave Request</button>
                </form>
            </div>

        </div> <!-- .main-content-sec -->
    </div> <!-- .dashboard-container-sec -->

    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize FullCalendar
            const calendarEl = document.getElementById('attendance-calendar');
            if (calendarEl) {
                const calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth' // Removed week view for simplicity unless needed
                    },
                    events: <?php echo json_encode($calendar_events_js_array); ?>,
                    eventDisplay: 'block', // How events are displayed
                    // Optional: Custom event rendering if needed
                    // eventContent: function(arg) { 
                    //     return { html: `<div style="padding:2px; font-size:0.85em;">${arg.event.title}</div>` };
                    // }
                });
                calendar.render();
            }

            // Function to update shift display in real-time (or periodically)
            const shiftDisplayElement = document.getElementById('current-shift-display');
            const currentShiftFormElement = document.getElementById('current_shift_form');

            function updateShiftDisplay() {
                if (!shiftDisplayElement) return; // Guard against element not found

                fetch('get_current_shift.php') // Assumes get_current_shift.php is in the same directory
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok for get_current_shift.php');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data && data.shift) {
                            if (shiftDisplayElement.textContent !== data.shift) {
                                shiftDisplayElement.classList.add('shift-update-animation');
                                setTimeout(() => {
                                    shiftDisplayElement.classList.remove('shift-update-animation');
                                }, 3000); // Animation duration
                            }
                            shiftDisplayElement.textContent = data.shift;
                            if (currentShiftFormElement) {
                                currentShiftFormElement.value = data.shift;
                            }
                        } else if (data && data.error) {
                            console.error('Error from get_current_shift.php:', data.error);
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching current shift:', error);
                        // Optionally display a static message or hide element if fetch fails
                    });
            }

            // Update shift every 30 seconds and immediately on load/focus
            if (shiftDisplayElement) {
                setInterval(updateShiftDisplay, 30000); // 30 seconds
                updateShiftDisplay(); // Initial call
                window.addEventListener('focus', updateShiftDisplay); // Update when window gains focus
            }
        });
    </script>
</body>
</html>