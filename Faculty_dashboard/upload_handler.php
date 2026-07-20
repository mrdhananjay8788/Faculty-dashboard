<?php
session_start();
// Enable error reporting for debugging if needed
ini_set('display_errors', 1);
error_reporting(E_ALL);
// Ensure the uploads directory exists
$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = htmlspecialchars($_POST['title']);
    $description = htmlspecialchars($_POST['description']);
    $subject = htmlspecialchars($_POST['subject']);
    $marks = intval($_POST['marks']);
    $dueDate = htmlspecialchars($_POST['due_date']);
    $dueTime = htmlspecialchars($_POST['due_time']);
    
    // File upload handling
    if (isset($_FILES['resource_pdf']) && $_FILES['resource_pdf']['error'] === UPLOAD_ERR_OK) {
        
        $fileTmpPath = $_FILES['resource_pdf']['tmp_name'];
        $fileName = $_FILES['resource_pdf']['name'];
        $fileSize = $_FILES['resource_pdf']['size'];
        $fileType = $_FILES['resource_pdf']['type'];
        
        // Basic validation
  $allowedType = 'application/pdf';
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        if ($fileType !== $allowedType) {
            $_SESSION['message'] = "Invalid file format. Only PDFs are allowed.";
            $_SESSION['msg_type'] = "error";
            header("Location: index.php");
            exit;
        }
        
        if ($fileSize > $maxSize) {
            $_SESSION['message'] = "File is too large. Maximum size is 5MB.";
            $_SESSION['msg_type'] = "error";
            header("Location: index.php");
            exit;
        }
        
        // Sanitize file name and create a unique name
        $fileNameCmps = explode(".", $fileName);
        $fileExtension = strtolower(end($fileNameCmps));
         $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
        $destPath = $uploadDir . $newFileName;
        
        if(move_uploaded_file($fileTmpPath, $destPath)) {
            // Success!
            // In a real application, you would save $title, $description, $destPath, etc. to a database here.
            
            $_SESSION['message'] = "Activity '{$title}' created successfully!";
            $_SESSION['msg_type'] = "success";
            header("Location: index.php");
            exit;
        } else {
            $_SESSION['message'] = "There was an error moving the uploaded file.";
            $_SESSION['msg_type'] = "error";
            header("Location: index.php");
            exit;
        }
    } else {
        $_SESSION['message'] = "Please upload a valid PDF document.";
        $_SESSION['msg_type'] = "error";
        header("Location: index.php");
        exit;
    }
} else {
    // Not a POST request
    header("Location: index.php");
    exit;
}
?>