<?php
// Database configuration
$servername = "localhost";       // Usually 'localhost' for XAMPP
$username = "root";             // Default username for XAMPP MySQL
$password = "";                 // Default password for XAMPP MySQL is empty
$dbname = "security_system";    // The name of your database

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    // For a real application, you might log this error instead of die()
    // or show a more user-friendly error page.
    die("Connection failed: " . $conn->connect_error);
}

// Optional: Set character set to utf8mb4 for broader character support
if (!$conn->set_charset("utf8mb4")) {
    // printf("Error loading character set utf8mb4: %s\n", $conn->error);
    // For a real app, handle this error. For now, we'll proceed.
}
?>