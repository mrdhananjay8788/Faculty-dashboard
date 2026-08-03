<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-caching headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

require_once(__DIR__ . '/../config/db.php');

// Ensure audit_logs table exists
$conn->query("CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'System',
    action VARCHAR(255) NOT NULL,
    details TEXT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// AUTO-HEAL: Sync missing emails and details
function healUserEmails($conn) {
    @mysqli_query($conn, "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `email` VARCHAR(150) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `department` VARCHAR(150) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `academic_year` VARCHAR(50) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `division` VARCHAR(50) DEFAULT NULL");
    @mysqli_query($conn, "UPDATE `users` SET `email` = `username` WHERE (`email` IS NULL OR `email` = '') AND `username` LIKE '%@%'");

    $syncParents = "UPDATE `users` u 
                    JOIN `access_requests` ar ON u.`linked_student_prn` = ar.`prn_number` 
                    SET u.`email` = ar.`parent_email` 
                    WHERE u.`role` = 'Parent' AND (u.`email` IS NULL OR u.`email` = '')";
    @mysqli_query($conn, $syncParents);
}
healUserEmails($conn);

$flashMessage = '';
$flashClass = 'alert-info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // DUAL IDP APPROVAL
    if ($action === 'approve_request') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        
        $reqStmt = $conn->prepare("SELECT * FROM access_requests WHERE request_id = ? AND status = 'PENDING'");
        $reqStmt->bind_param("i", $requestId);
        $reqStmt->execute();
        $reqData = $reqStmt->get_result()->fetch_assoc();
        $reqStmt->close();

        if ($reqData) {
            $prn          = strtoupper(trim($reqData['prn_number']));
            $student_name = trim($reqData['full_name']);
            $student_email= strtolower(trim($reqData['email']));
            $parent_name  = trim($reqData['parent_name'] ?? ($student_name . " Guardian"));
            $parent_email = strtolower(trim($reqData['parent_email'] ?? ''));
            $dept_name    = trim($reqData['department'] ?? '');
            $academic_year= trim($reqData['academic_year'] ?? 'FY');
            $division     = trim($reqData['division'] ?? 'A');
            $defaultPass  = password_hash('Zeal@2026', PASSWORD_BCRYPT);

            // 1. Create Student Account
            $createStudent = $conn->prepare("INSERT IGNORE INTO users (name, username, email, department, academic_year, division, password, role, is_first_login) VALUES (?, ?, ?, ?, ?, ?, ?, 'Student', 1)");
            $createStudent->bind_param("sssssss", $student_name, $prn, $student_email, $dept_name, $academic_year, $division, $defaultPass);
            $createStudent->execute();
            $createStudent->close();

            // 2. Create Parent Account
            $createParent = $conn->prepare("INSERT IGNORE INTO users (name, username, email, password, role, linked_student_prn, is_first_login) VALUES (?, ?, ?, ?, 'Parent', ?, 1)");
            $createParent->bind_param("sssss", $parent_name, $parent_email, $parent_email, $defaultPass, $prn);
            $createParent->execute();
            $createParent->close();

            // 3. Update Request
            $upReq = $conn->prepare("UPDATE access_requests SET status = 'APPROVED' WHERE request_id = ?");
            $upReq->bind_param("i", $requestId);
            $upReq->execute();
            $upReq->close();

            // Log action to audit logs
            $logAction = "Approved dual registration request for PRN: $prn";
            $auditStmt = $conn->prepare("INSERT INTO audit_logs (user_id, role, action, details) VALUES (?, 'Admin', 'User Registration Approval', ?)");
            $adminUid = (int)$_SESSION['user_id'];
            $auditStmt->bind_param("is", $adminUid, $logAction);
            $auditStmt->execute();
            $auditStmt->close();

            $flashMessage = "Dual IDPs issued! Student PRN: $prn | Parent Email: $parent_email (Default Passkey: Zeal@2026)";
            $flashClass = "alert-success";
        }
    }

    // MANUAL STAFF IDP PROVISIONING
    if ($action === 'create_staff_idp') {
        $staff_name = trim($_POST['staff_name'] ?? '');
        $username   = strtolower(trim($_POST['staff_username'] ?? ''));
        $staff_role = trim($_POST['staff_role'] ?? '');
        $staff_dept = trim($_POST['staff_department'] ?? '');
        $defaultPass = password_hash('Zeal@2026', PASSWORD_BCRYPT);
        $allowedStaffRoles = ['Faculty', 'HOD', 'GFM', 'Admin'];

        if (!empty($staff_name) && !empty($username) && in_array($staff_role, $allowedStaffRoles)) {
            $checkU = $conn->prepare("SELECT user_id FROM users WHERE LOWER(username) = ?");
            $checkU->bind_param("s", $username);
            $checkU->execute();
            if ($checkU->get_result()->num_rows > 0) {
                $flashMessage = "Account creation aborted: Username/Email '$username' is already registered.";
                $flashClass = "alert-danger";
            } else {
                $createStaff = $conn->prepare("INSERT INTO users (name, username, email, department, password, role, is_first_login) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $createStaff->bind_param("ssssss", $staff_name, $username, $username, $staff_dept, $defaultPass, $staff_role);
                if ($createStaff->execute()) {
                    $deptStr = !empty($staff_dept) ? " | Branch: $staff_dept" : "";
                    
                    // Log action to audit logs
                    $logAction = "Manually created $staff_role account: $username ($staff_name)";
                    $auditStmt = $conn->prepare("INSERT INTO audit_logs (user_id, role, action, details) VALUES (?, 'Admin', 'Create Staff Account', ?)");
                    $adminUid = (int)$_SESSION['user_id'];
                    $auditStmt->bind_param("is", $adminUid, $logAction);
                    $auditStmt->execute();
                    $auditStmt->close();

                    $flashMessage = "Staff account created successfully! Role: $staff_role$deptStr | Login: $username | Default Passkey: Zeal@2026";
                    $flashClass = "alert-success";
                } else {
                    $flashMessage = "Error writing staff user record.";
                    $flashClass = "alert-danger";
                }
                $createStaff->close();
            }
            $checkU->close();
        } else {
            $flashMessage = "Please select a valid staff role and complete all fields.";
            $flashClass = "alert-warning";
        }
    }

    // REJECT REQUEST
    if ($action === 'reject_request') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $rejStmt = $conn->prepare("UPDATE access_requests SET status = 'REJECTED' WHERE request_id = ?");
        $rejStmt->bind_param("i", $requestId);
        $rejStmt->execute();
        $rejStmt->close();
        $flashMessage = "Request rejected.";
        $flashClass = "alert-warning";
    }

    // DELETE USER
    if ($action === 'delete_user') {
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        if ($targetUserId > 0 && $targetUserId !== (int)$_SESSION['user_id']) {
            // Get user details first for logging
            $usrStmt = $conn->prepare("SELECT username, role, name FROM users WHERE user_id = ?");
            $usrStmt->bind_param("i", $targetUserId);
            $usrStmt->execute();
            $usrObj = $usrStmt->get_result()->fetch_assoc();
            $usrStmt->close();

            $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $targetUserId);
            if ($stmt->execute()) {
                if ($usrObj) {
                    $logAction = "Deleted {$usrObj['role']} account: {$usrObj['username']} ({$usrObj['name']})";
                    $auditStmt = $conn->prepare("INSERT INTO audit_logs (user_id, role, action, details) VALUES (?, 'Admin', 'Delete User Account', ?)");
                    $adminUid = (int)$_SESSION['user_id'];
                    $auditStmt->bind_param("is", $adminUid, $logAction);
                    $auditStmt->execute();
                    $auditStmt->close();
                }
                $flashMessage = "User account deleted.";
                $flashClass = "alert-success";
            } else {
                $flashMessage = "Error deleting user account.";
                $flashClass = "alert-danger";
            }
            $stmt->close();
        }
    }
}

function getCount($conn, $query) {
    $res = @mysqli_query($conn, $query);
    if ($res) { 
        $row = mysqli_fetch_assoc($res); 
        return (int)($row['c'] ?? 0); 
    }
    return 0;
}

$totalUsers      = getCount($conn, "SELECT COUNT(*) AS c FROM users");
$totalStudents   = getCount($conn, "SELECT COUNT(*) AS c FROM users WHERE role = 'Student'");
$totalParents    = getCount($conn, "SELECT COUNT(*) AS c FROM users WHERE role = 'Parent'");
$pendingRequests = getCount($conn, "SELECT COUNT(*) AS c FROM access_requests WHERE status = 'PENDING'");

$requestsList = [];
$reqQ = @mysqli_query($conn, "SELECT * FROM access_requests WHERE status = 'PENDING' ORDER BY request_id DESC");
if ($reqQ && mysqli_num_rows($reqQ) > 0) {
    while ($r = mysqli_fetch_assoc($reqQ)) { 
        $requestsList[] = $r; 
    }
}

$userList = [];
$userQ = @mysqli_query($conn, "SELECT user_id, name, username, email, department, role, is_first_login FROM users ORDER BY user_id DESC LIMIT 50");
if ($userQ && mysqli_num_rows($userQ) > 0) {
    while ($r = mysqli_fetch_assoc($userQ)) { 
        $userList[] = $r; 
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Registry | SAAES Admin</title>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* Premium Hero Banner (Matching the screenshot theme) */
        .premium-hero {
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.92), rgba(30, 41, 59, 0.85)), url('../assets/images/college_building.jpg');
            background-size: cover;
            background-position: center;
            border-radius: var(--radius-lg);
            padding: 2.25rem 2.5rem;
            color: #ffffff;
            margin-bottom: 2rem;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.05);
            position: relative;
            overflow: hidden;
        }
        .hero-dept,
        .hero-title,
        .hero-desc {
            position: relative;
            z-index: 5;
        }
        .hero-dept {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: #38bdf8; /* Light cyan text glow */
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 10px rgba(56, 189, 248, 0.3);
        }
        .hero-title {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.65rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }
        .hero-desc {
            font-size: 0.9rem;
            color: #cbd5e1;
            max-width: 650px;
        }

        /* Telemetry summary blocks */
        .telemetry-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .tel-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .tel-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        .tel-icon.blue { background: #eef2ff; color: #3b82f6; }
        .tel-icon.green { background: #ecfdf5; color: #10b981; }
        .tel-icon.purple { background: #f5f3ff; color: #8b5cf6; }
        .tel-icon.orange { background: #fffbeb; color: #f59e0b; }
        .tel-info {
            display: flex;
            flex-direction: column;
        }
        .tel-val {
            font-family: var(--font-head);
            font-size: 1.75rem;
            font-weight: 800;
            line-height: 1.2;
            color: var(--text-dark);
        }
        .tel-label {
            font-size: 0.825rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Module card design */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 2rem;
        }
        .card-header-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 12px;
        }
        .card-title {
            font-family: var(--font-head);
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card-title i {
            color: var(--primary-blue);
        }
        .card-subtitle {
            font-size: 0.88rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
        }

        /* Forms styling */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            align-items: flex-end;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-label {
            font-size: 0.825rem;
            font-weight: 600;
            color: var(--text-dark);
        }
        .form-input, .form-select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            font-family: var(--font-body);
            font-size: 0.9rem;
            outline: none;
            transition: all 0.2s ease;
            background: var(--bg-body);
            color: var(--text-dark);
        }
        .form-input:focus, .form-select:focus {
            border-color: var(--primary-blue);
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
        }

        /* Buttons styles */
        .btn {
            font-family: var(--font-head);
            font-weight: 600;
            font-size: 0.875rem;
            padding: 10px 20px;
            border-radius: var(--radius-md);
            border: 1px solid transparent;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--primary-hover) 100%);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.2);
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(2, 132, 199, 0.3);
        }
        .btn-outline-danger {
            background: transparent;
            border-color: #fecaca;
            color: var(--danger);
        }
        .btn-outline-danger:hover {
            background: #fef2f2;
            border-color: var(--danger);
        }

        /* Tables layout */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        .data-table th, .data-table td {
            padding: 12px 18px;
            border-bottom: 1px solid var(--border-light);
            font-size: 0.9rem;
            vertical-align: middle;
        }
        .data-table th {
            background: #f8fafc;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .data-table tbody tr:last-child td {
            border-bottom: none;
        }
        .data-table tbody tr:hover {
            background: rgba(15, 23, 42, 0.01);
        }

        /* Tags and badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.775rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .badge.warning { background: #fffbeb; color: #d97706; border: 1px solid #fef3c7; }
        .badge.success { background: #ecfdf5; color: #059669; border: 1px solid #d1fae5; }
        .badge.info { background: #eff6ff; color: #2563eb; border: 1px solid #dbeafe; }
        .badge.dark { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

        /* Alerts banner styling */
        .alert-banner {
            padding: 14px 20px;
            border-radius: var(--radius-md);
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            animation: slideDown 0.3s ease;
        }
        .alert-banner.alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-banner.alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-banner.alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .alert-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: inherit;
            cursor: pointer;
            opacity: 0.7;
        }
        .alert-close:hover { opacity: 1; }

        @keyframes slideDown {
            from { transform: translateY(-10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    </style>
</head>
<body>

<div class="admin-layout">
    <?php 
    $active_page = 'users'; 
    include_once(__DIR__ . '/../includes/sidebar.php'); 
    ?>

    <main class="admin-main">
        <!-- Pretty College Hero Banner (Matching the screenshot theme) -->
        <div class="premium-hero">
            <!-- Glowing background atmosphere & scans -->
            <div class="dreamy-glow-blob blob-1" style="top: -20px; left: 20%; width: 220px; height: 220px;"></div>
            <div class="dreamy-glow-blob blob-2" style="bottom: -20px; right: 20%; width: 220px; height: 220px;"></div>
            
            <div class="circuit-gate">
                <div class="gate-neon-pulse" style="animation-duration: 5s;"></div>
            </div>
            
            <!-- Floating Glowing Icons behind layout -->
            <div class="shape shape-1" style="top: 15%; right: 15%; font-size: 1.3rem; animation-duration: 7s;"><div class="glow"></div><i class="fa-solid fa-graduation-cap"></i></div>
            <div class="shape shape-2" style="bottom: 20%; left: 15%; font-size: 1.1rem; animation-duration: 9s; animation-delay: 1.5s;"><div class="glow"></div><i class="fa-solid fa-book"></i></div>
            <div class="shape shape-3" style="top: 40%; right: 5%; font-size: 1.2rem; animation-duration: 8s; animation-delay: 3s;"><div class="glow"></div><i class="fa-solid fa-award"></i></div>

            <div class="hero-dept">Department of Electronics &amp; Computer Engineering</div>
            <h1 class="hero-title">User Accounts &amp; IDP Provisioning</h1>
            <p class="hero-desc">Manage registered users, issue staff login credentials, and approve student registration requests.</p>
        </div>

        <!-- Flash banner alerts -->
        <?php if ($flashMessage): ?>
            <div class="alert-banner <?php echo $flashClass; ?>" id="flashBanner">
                <span><i class="fa-solid fa-circle-info style="margin-right: 8px;"></i> <?php echo htmlspecialchars($flashMessage); ?></span>
                <button type="button" class="alert-close" onclick="document.getElementById('flashBanner').style.display='none'">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Telemetry Summary Cards -->
        <div class="telemetry-grid">
            <div class="tel-card">
                <div class="tel-icon orange"><i class="fa-solid fa-clock"></i></div>
                <div class="tel-info">
                    <span class="tel-val"><?php echo $pendingRequests; ?></span>
                    <span class="tel-label">Pending Approvals</span>
                </div>
            </div>
            <div class="tel-card">
                <div class="tel-icon blue"><i class="fa-solid fa-user-graduate"></i></div>
                <div class="tel-info">
                    <span class="tel-val"><?php echo $totalStudents; ?></span>
                    <span class="tel-label">Active Students</span>
                </div>
            </div>
            <div class="tel-card">
                <div class="tel-icon purple"><i class="fa-solid fa-users"></i></div>
                <div class="tel-info">
                    <span class="tel-val"><?php echo $totalParents; ?></span>
                    <span class="tel-label">Active Parents</span>
                </div>
            </div>
            <div class="tel-card">
                <div class="tel-icon green"><i class="fa-solid fa-server"></i></div>
                <div class="tel-info">
                    <span class="tel-val"><?php echo $totalUsers; ?></span>
                    <span class="tel-label">System Registry</span>
                </div>
            </div>
        </div>

        <!-- IDP Access Requests Queue -->
        <div class="card">
            <div class="card-header-flex">
                <h3 class="card-title"><i class="fa-solid fa-list-check"></i> Registration Approvals Queue</h3>
                <span class="badge <?php echo ($pendingRequests > 0) ? 'warning' : 'dark'; ?>"><?php echo count($requestsList); ?> pending approvals</span>
            </div>
            <p class="card-subtitle">Verify student details to issue dual credentials for the student and their parent.</p>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>PRN</th>
                            <th>Student & Email</th>
                            <th>Parent & Email</th>
                            <th>Department / Year / Div</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($requestsList) === 0): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                                    <i class="fa-regular fa-folder-open" style="font-size: 2rem; display: block; margin-bottom: 10px; opacity: 0.5;"></i>
                                    No pending student registration approvals.
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($requestsList as $req): ?>
                            <tr>
                                <td style="font-weight: 700; color: var(--primary-blue);"><?php echo htmlspecialchars($req['prn_number']); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($req['full_name']); ?></strong>
                                    <div style="font-size: 0.775rem; color: var(--text-muted);"><?php echo htmlspecialchars($req['email']); ?></div>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($req['parent_name'] ?? 'Parent'); ?></strong>
                                    <div style="font-size: 0.775rem; color: var(--primary-blue);"><?php echo htmlspecialchars($req['parent_email'] ?? '-'); ?></div>
                                </td>
                                <td>
                                    <span class="badge dark" style="font-size:0.75rem; font-weight:700;"><?php echo htmlspecialchars($req['department']); ?></span>
                                    <div style="margin-top: 4px; font-size: 0.775rem; color: var(--text-muted);">
                                        Year: <strong><?php echo htmlspecialchars($req['academic_year'] ?? 'FY'); ?></strong> &middot; Div: <strong><?php echo htmlspecialchars($req['division'] ?? 'A'); ?></strong>
                                    </div>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 8px;">
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="approve_request">
                                            <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                                            <button type="submit" class="btn btn-primary" style="padding: 6px 12px; font-size:0.8rem;"><i class="fa-solid fa-check"></i> Issue IDP</button>
                                        </form>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Reject registration request?');">
                                            <input type="hidden" name="action" value="reject_request">
                                            <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger" style="padding: 6px 12px; font-size:0.8rem;" title="Reject"><i class="fa-solid fa-xmark"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- MANUAL STAFF ACCOUNT PROVISIONING -->
        <div class="card">
            <h3 class="card-title" style="margin-bottom: 0.5rem;"><i class="fa-solid fa-user-shield"></i> Create Staff Credentials</h3>
            <p class="card-subtitle">Generate accounts for Faculty, GFM, HOD, and Administrators. Default passkey: <strong>Zeal@2026</strong></p>

            <form method="POST">
                <input type="hidden" name="action" value="create_staff_idp">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="staff_name" class="form-input" placeholder="e.g. Dr. Ramesh Patil" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email Address (Username)</label>
                        <input type="email" name="staff_username" class="form-input" placeholder="e.g. rpatil@zealeducation.com" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role Designation</label>
                        <select name="staff_role" class="form-select" required>
                            <option value="" disabled selected>-- Select Role --</option>
                            <option value="Faculty">Faculty</option>
                            <option value="HOD">HOD</option>
                            <option value="GFM">GFM</option>
                            <option value="Admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Branch / Department</label>
                        <select name="staff_department" class="form-select" required>
                            <option value="" disabled selected>-- Select Dept --</option>
                            <option value="AI and Machine Learning">AI and Machine Learning</option>
                            <option value="AI and Data Science">AI and Data Science</option>
                            <option value="Computer Engineering">Computer Engineering</option>
                            <option value="ENTC">ENTC</option>
                            <option value="Mechanical Engineering">Mechanical Engineering</option>
                            <option value="Electrical Engineering">Electrical Engineering</option>
                            <option value="Electronics and Computer Engineering">Electronics and Computer Engineering</option>
                            <option value="Information Technology">Information Technology</option>
                            <option value="Civil Engineering">Civil Engineering</option>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary" style="width: 100%; height: 42px;"><i class="fa-solid fa-plus"></i> Create Staff</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- ISSUED USER REGISTRY -->
        <div class="card">
            <h3 class="card-title" style="margin-bottom: 0.5rem;"><i class="fa-solid fa-users-gear"></i> System Registry Records</h3>
            <p class="card-subtitle">Active accounts registered in the database. Deleting an account is permanent.</p>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>UID</th>
                            <th>Name</th>
                            <th>Username / PRN</th>
                            <th>Email Address</th>
                            <th>Role</th>
                            <th>Department</th>
                            <th>Status</th>
                            <th style="text-align: right;">Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($userList as $u): ?>
                            <tr>
                                <td style="color: var(--text-muted); font-weight:700;">#<?php echo $u['user_id']; ?></td>
                                <td style="font-weight: 700; color: var(--text-dark);"><?php echo htmlspecialchars($u['name']); ?></td>
                                <td><?php echo htmlspecialchars($u['username']); ?></td>
                                <td style="font-size: 0.85rem; color: var(--text-muted);"><?php echo htmlspecialchars($u['email'] ?? $u['username']); ?></td>
                                <td>
                                    <?php
                                    $roleBadge = 'dark';
                                    $rLower = strtolower($u['role']);
                                    if ($rLower === 'admin') $roleBadge = 'danger';
                                    elseif ($rLower === 'faculty') $roleBadge = 'info';
                                    elseif ($rLower === 'parent') $roleBadge = 'purple';
                                    elseif ($rLower === 'student') $roleBadge = 'success';
                                    ?>
                                    <span class="badge <?php echo $roleBadge; ?>"><?php echo htmlspecialchars($u['role']); ?></span>
                                </td>
                                <td><span class="badge dark" style="font-size:0.75rem;"><?php echo htmlspecialchars($u['department'] ?: 'Electronics & Computer Engineering'); ?></span></td>
                                <td>
                                    <?php if ((int)$u['is_first_login'] === 1): ?>
                                        <span class="badge warning">Pending Login</span>
                                    <?php else: ?>
                                        <span class="badge success">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ((int)$u['user_id'] !== (int)$_SESSION['user_id']): ?>
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to permanently delete this user account?');">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="target_user_id" value="<?php echo $u['user_id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger" style="padding: 6px 10px; font-size: 0.8rem;"><i class="fa-solid fa-trash-can"></i></button>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size: 0.8rem; color: var(--text-muted); font-style:italic;">Logged In</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<?php 
$modalPath = __DIR__ . '/../includes/end_session_modal.php';
if (file_exists($modalPath)) {
    include_once $modalPath;
}
?>
</body>
</html>
