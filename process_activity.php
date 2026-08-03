$storedFileName = $uniqueHash . '.' . $fileExtension;
$uploadFilePath = $uploadDir . $storedFileName;
$dbFilePath = 'uploads/' . $storedFileName;
// Move the file securely to the final destination
if (move_uploaded_file($file['tmp_name'], $uploadFilePath)) {
    
    // Prepare SQL statement to prevent SQL Injection
    $stmt = $conn->prepare("INSERT INTO activities (title, description, original_file_name, stored_file_name, file_path, file_type, file_size) VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    if ($stmt) {
        // Bind parameters securely
        $stmt->bind_param("ssssssi", $title, $description, $originalFileName, $storedFileName, $dbFilePath, $fileMimeType, $file['size']);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Activity '{$title}' has been successfully deployed.";
        } else {
            // Revert file upload if database insert fails
            if (file_exists($uploadFilePath)) {
                unlink($uploadFilePath);
            }
            $_SESSION['error'] = "Database Error: Could not save the activity details.";
        }
        $stmt->close();
    } else {
        if (file_exists($uploadFilePath)) {
            unlink($uploadFilePath);
        }
        $_SESSION['error'] = "Database Error: Failed to prepare statement.";
    }
} else {
    $_SESSION['error'] = "Server Error: Failed to safely store the uploaded file.";
}
// Close connection and redirect
$conn->close();
header("Location: deploy_activity.php");
exit;
