<?php
$host = "localhost";
$username = "root";
$password = "";
$dbname = "saaes_db";
// Create connection
$conn = new mysqli($host, $username, $password, $dbname);
// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
// Set character set
$conn->set_charset("utf8mb4");
?>