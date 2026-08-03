<?php
$conn = new mysqli('localhost', 'root', '', 'saaes_db');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$username = 'admin';
$email = 'admin@zeal.in';
$pass = password_hash('Zeal@2026', PASSWORD_BCRYPT);

// Check if admin already exists
$result = $conn->query("SELECT user_id FROM users WHERE username = 'admin'");
if ($result->num_rows > 0) {
    // Update password if it already exists
    $conn->query("UPDATE users SET password = '$pass', role = 'Admin' WHERE username = 'admin'");
    echo "Admin account already existed, password reset to Zeal@2026.";
} else {
    // Insert new admin
    $sql = "INSERT INTO users (name, username, email, department, password, role, is_first_login) VALUES ('Super Admin', '$username', '$email', 'Management', '$pass', 'Admin', 0)";
    if ($conn->query($sql) === TRUE) {
        echo "Admin account created successfully! Username: admin, Password: Zeal@2026";
    } else {
        echo "Error: " . $conn->error;
    }
}
$conn->close();
