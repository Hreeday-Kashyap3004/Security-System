<?php
session_start(); // Start session to potentially access security_id if needed, though GET is primary
require 'db_config.php'; // Your database connection

// Set default timezone if not already set in php.ini or db_config.php
// This helps ensure date consistency. Choose your project's timezone.
date_default_timezone_set('Asia/Kolkata'); // Example: India. Change to your timezone.

header('Content-Type: application/json'); // Important: Set content type to JSON

$events = []; // Array to hold event objects for FullCalendar


if (!isset($_SESSION['security_logged_in']) || !isset($_SESSION['security_id'])) {
    echo json_encode(['error' => 'Unauthorized. Security personnel not logged in.']);
    exit();
}
$security_id_from_session = $_SESSION['security_id'];

// If you were to allow fetching for *any* security_id via GET (e.g., for an admin view using this):
// $target_security_id = $_GET['security_id'] ?? $security_id_from_session;
// For this security panel calendar, it should always be the logged-in user's ID.
$target_security_id = $security_id_from_session;


if (isset($_GET['start']) && isset($_GET['end'])) {
    $start_date_str = $_GET['start'];
    $end_date_str = $_GET['end'];



    $stmt = $conn->prepare("SELECT date, status, login_time FROM attendance WHERE security_id = ? AND date BETWEEN ? AND ? ORDER BY date ASC");
    
    if (!$stmt) {
        error_log("Prepare failed for fetch_attendance: (" . $conn->errno . ") " . $conn->error);
        echo json_encode(['error' => 'Database query preparation failed.']);
        exit();
    }

    $stmt->bind_param("sss", $target_security_id, $start_date_str, $end_date_str);
    
    if (!$stmt->execute()) {
        error_log("Execute failed for fetch_attendance: (" . $stmt->errno . ") " . $stmt->error);
        echo json_encode(['error' => 'Database query execution failed.']);
        $stmt->close();
        exit();
    }

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $title = '';
        $color = ''; // Event color for FullCalendar
        $className = ''; // CSS class for more specific styling

        switch (strtolower($row['status'])) {
            case 'present':
                // If they logged in, even if status changed to leave, login_time will be set
                if ($row['login_time'] !== null) {
                    $title = 'Logged In'; // Indicate they logged in
                    $color = '#2ecc71'; // Green for present/login
                    $className = 'fc-event-present';
                } else { // Should not happen if login sets login_time, but as a fallback
                    $title = 'Present (No Login Time)';
                    $color = '#27ae60'; // Darker Green
                }
                break;
            case 'absent':
                $title = 'Absent';
                $color = '#e74c3c'; // Red
                $className = 'fc-event-absent';
                break;
            case 'emergency_leave':
                $title = 'On Leave';
                // If they also logged in that day, we might want to show a combined status or prioritize one.
                // Based on previous admin panel logic, we'll show 'On Leave' if status is 'emergency_leave'
                // even if login_time is set, as leave status takes precedence in visual display.
                $color = '#f39c12'; // Orange
                $className = 'fc-event-leave';
                if ($row['login_time'] !== null) {
                    $title = 'Logged In & On Leave'; // More descriptive
                }
                break;
            default:
                $title = ucfirst(htmlspecialchars($row['status'])); // Default, ensure to escape
                $color = '#3498db'; // Default blue
        }

        $events[] = [
            'title' => $title,
            'start' => $row['date'], // FullCalendar expects date in YYYY-MM-DD
            'allDay' => true,       // Assuming these are all-day events for attendance status
            'color' => $color,
            'className' => $className, // Optional: for custom CSS styling of events
            // 'extendedProps' => ['status_detail' => $row['status']] // Optional: pass more data if needed by JS
        ];
    }
    $stmt->close();

} else {
    // If start and end parameters are missing, return an error or empty array
    // For debugging, you might return an error. For production, an empty array might be safer for FullCalendar.
    // error_log("fetch_attendance.php: Missing start or end GET parameters.");
    // echo json_encode(['error' => 'Missing required date range parameters.']);
    // exit();
    // Returning empty events array is often preferred by FullCalendar if no params
}

echo json_encode($events); // Output the events array as JSON
?>