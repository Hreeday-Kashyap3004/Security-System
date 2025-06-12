<?php
session_start(); // Start session to access the logged-in security guard's ID
require 'db_config.php'; // Your database connection

header('Content-Type: application/json'); // Set the content type to JSON for the response

// Check if a security guard is logged in and their ID is in the session
if (!isset($_SESSION['security_logged_in']) || !isset($_SESSION['security_id'])) {
    // Not authorized or session expired
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Unauthorized: Security personnel not logged in.']);
    exit();
}

$security_id_from_session = $_SESSION['security_id'];
$current_shift = null; // Default value
$user_name = null; // Optional: also fetch name if needed by JS

// Prepare statement to fetch the current shift (and optionally name) for the logged-in user
$stmt = $conn->prepare("SELECT shift, name FROM users WHERE id = ?");
if (!$stmt) {
    http_response_code(500); // Internal Server Error
    error_log("Prepare failed for get_current_shift: (" . $conn->errno . ") " . $conn->error);
    echo json_encode(['error' => 'Database query preparation failed.']);
    exit();
}

$stmt->bind_param("s", $security_id_from_session);

if (!$stmt->execute()) {
    http_response_code(500); // Internal Server Error
    error_log("Execute failed for get_current_shift: (" . $stmt->errno . ") " . $stmt->error);
    echo json_encode(['error' => 'Database query execution failed.']);
    $stmt->close();
    exit();
}

$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user_data = $result->fetch_assoc();
    $current_shift = $user_data['shift'];
    $user_name = $user_data['name']; // If you want to send the name back too
} else {
    // This case should ideally not happen if the session security_id is valid
    // and the user exists in the database. Could indicate a data integrity issue
    // or the user was deleted after login but before this AJAX call.
    http_response_code(404); // Not Found
    error_log("User not found in get_current_shift for ID: " . $security_id_from_session);
    echo json_encode(['error' => 'User data not found.']);
    $stmt->close();
    exit();
}

$stmt->close();

// Send the response as JSON
echo json_encode([
    'shift' => $current_shift,
    'name' => $user_name, // Optional: if your JS needs it
    'last_updated_timestamp' => time() // Unix timestamp of when this data was fetched
]);
?>