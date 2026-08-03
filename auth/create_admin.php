<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-caching headers to prevent browser back-button access after logout
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit();
}

require_once(__DIR__ . '/../config/db.php');

// AUTO-HEAL: Sync missing emails in users table for seamless recovery
function healUserEmails($conn) {
    // 1. Ensure email and department columns exist
    @mysqli_query($conn, "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `email` VARCHAR(150) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `department` VARCHAR(150) DEFAULT NULL");

    // 2. Fill empty user emails with username if username is formatted like an email
    @mysqli_query($conn, "UPDATE `users` SET `email` = `username` WHERE (`email` IS NULL OR `email` = '') AND `username` LIKE '%@%'");

    // 3. For parents, sync parent_email from access_requests if still blank
    $syncParents = "UPDATE `users` u 
                    JOIN `access_requests` ar ON u.`linked_student_prn` = ar.`prn_number` 
                    SET u.`email` = ar.`parent_email` 
                    WHERE u.`role` = 'Parent' AND (u.`email` IS NULL OR u.`email` = '')";
    @mysqli_query($conn, $syncParents);
}
healUserEmails($conn);

$flashMessage = '';
$flashClass = 'alert-dark text-white border-secondary';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // DUAL IDP APPROVAL (Student via PRN + Parent via Email)
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
            $dept_name    = trim($reqData['department'] ?? '');
            $defaultPass  = password_hash('Zeal@2026', PASSWORD_BCRYPT);

            // 1. Create Student Account (Username = PRN, Email = student_email)
            $createStudent = $conn->prepare("INSERT INTO users (name, username, email, department, password, role, is_first_login) VALUES (?, ?, ?, ?, ?, 'Student', 1)");
            $createStudent->bind_param("sssss", $student_name, $prn, $student_email, $dept_name, $defaultPass);
            $createStudent->execute();
            $createStudent->close();

            // 2. Create Linked Parent Account (Username = Parent Email, Email = Parent Email)
            $createParent = $conn->prepare("INSERT INTO users (name, username, email, password, role, linked_student_prn, is_first_login) VALUES (?, ?, ?, ?, 'Parent', ?, 1)");
            $createParent->bind_param("sssss", $parent_name, $parent_email, $parent_email, $defaultPass, $prn);
            $createParent->execute();
            $createParent->close();

            // 3. Update Request Status
            $upReq = $conn->prepare("UPDATE access_requests SET status = 'APPROVED' WHERE request_id = ?");
            $upReq->bind_param("i", $requestId);
            $upReq->execute();
            $upReq->close();

            $flashMessage = "Dual IDPs issued! Student PRN: $prn | Parent Email: $parent_email (Default Passkey: Zeal@2026)";
            $flashClass = "alert-success bg-dark text-success border-success";
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
                $flashClass = "alert-danger bg-dark text-danger border-danger";
            } else {
                $createStaff = $conn->prepare("INSERT INTO users (name, username, email, department, password, role, is_first_login) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $createStaff->bind_param("ssssss", $staff_name, $username, $username, $staff_dept, $defaultPass, $staff_role);
                if ($createStaff->execute()) {
                    $deptStr = !empty($staff_dept) ? " | Branch: $staff_dept" : "";
                    $flashMessage = "Staff IDP provisioned successfully! Role: $staff_role$deptStr | Login: $username | Default Passkey: Zeal@2026";
                    $flashClass = "alert-success bg-dark text-success border-success";
                } else {
                    $flashMessage = "Error writing staff IDP user record.";
                    $flashClass = "alert-danger bg-dark text-danger border-danger";
                }
                $createStaff->close();
            }
            $checkU->close();
        } else {
            $flashMessage = "Please select a valid staff role and complete all fields.";
            $flashClass = "alert-warning bg-dark text-warning border-warning";
        }
    }

    if ($action === 'reject_request') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $rejStmt = $conn->prepare("UPDATE access_requests SET status = 'REJECTED' WHERE request_id = ?");
        $rejStmt->bind_param("i", $requestId);
        $rejStmt->execute();
        $rejStmt->close();
        $flashMessage = "Request rejected.";
        $flashClass = "alert-warning bg-dark text-warning border-warning";
    }

    if ($action === 'delete_user') {
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        if ($targetUserId > 0 && $targetUserId !== (int)$_SESSION['user_id']) {
            $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $targetUserId);
            $stmt->execute();
            $stmt->close();
            $flashMessage = "User account purged.";
            $flashClass = "alert-success bg-dark text-success border-success";
        }
    }
}

function getCount($conn, $query) {
    $res = @mysqli_query($conn, $query);
    if ($res) { $row = mysqli_fetch_assoc($res); return (int)($row['c'] ?? 0); }
    return 0;
}

$totalUsers      = getCount($conn, "SELECT COUNT(*) AS c FROM users");
$totalStudents   = getCount($conn, "SELECT COUNT(*) AS c FROM users WHERE role = 'Student'");
$totalParents    = getCount($conn, "SELECT COUNT(*) AS c FROM users WHERE role = 'Parent'");
$pendingRequests = getCount($conn, "SELECT COUNT(*) AS c FROM access_requests WHERE status = 'PENDING'");

$requestsList = [];
$reqQ = @mysqli_query($conn, "SELECT * FROM access_requests WHERE status = 'PENDING' ORDER BY request_id DESC");
if ($reqQ && mysqli_num_rows($reqQ) > 0) {
    while ($r = mysqli_fetch_assoc($reqQ)) { $requestsList[] = $r; }
}

// Fetch System Users List with explicit Email and Department fields
$userList = [];
$userQ = @mysqli_query($conn, "SELECT user_id, name, username, email, department, role, is_first_login FROM users ORDER BY user_id DESC LIMIT 30");
if ($userQ && mysqli_num_rows($userQ) > 0) {
    while ($r = mysqli_fetch_assoc($userQ)) { $userList[] = $r; }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZCOER // SAAES — Admin Command</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

    <style>
        :root { --bg-base: #f4f6f9; --panel-bg: rgba(8, 8, 11, 0.88); --input-bg: rgba(16, 18, 23, 0.75); --silver-border: rgba(255, 255, 255, 0.1); --silver-text: #94a3b8; }
        * { font-family: 'Plus Jakarta Sans', sans-serif; letter-spacing: -0.01em; box-sizing: border-box; }
        body { background-color: var(--bg-base); color: #fff; min-height: 100vh; margin: 0; position: relative; overflow-x: hidden; }
        #cometField { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; pointer-events: none; }
        .dashboard-container { position: relative; z-index: 5; padding: 40px; }
        .glass-card { background: var(--panel-bg); border: 1px solid var(--silver-border); border-radius: 4px; backdrop-filter: blur(24px); box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px; padding: 24px; }
        .eyebrow { font-family: 'JetBrains Mono', monospace; font-size: 0.68rem; letter-spacing: 0.14em; color: var(--silver-text); }
        .form-control-custom, .form-select-custom { background-color: var(--input-bg); border: 1px solid var(--silver-border); color: #f1f5f9; border-radius: 4px; padding: 10px 14px; font-size: 0.85rem; width: 100%; }
        .form-control-custom:focus, .form-select-custom:focus { border-color: rgba(255, 255, 255, 0.35); outline: none; background-color: var(--input-bg); color: #fff; }
        .form-select-custom option { background-color: #0b0c0e; color: #f1f5f9; }
        .btn-action-silver { background: linear-gradient(180deg, #ffffff 0%, #cbd5e1 100%); color: #050508; border: none; border-radius: 4px; padding: 8px 14px; font-weight: 700; font-size: 0.8rem; text-decoration: none; }
        .btn-action-silver:hover { background: #ffffff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .btn-outline-silver { background: transparent; border: 1px solid var(--silver-border); color: #fff; border-radius: 4px; padding: 8px 14px; font-weight: 600; font-size: 0.8rem; }
        .table-dark-custom { --bs-table-bg: transparent; color: #fff; }
        .table-dark-custom th { border-bottom: 1px solid var(--silver-border); color: var(--silver-text); font-family: 'JetBrains Mono', monospace; font-size: 0.7rem; }
        .table-dark-custom td { border-bottom: 1px solid rgba(255,255,255,0.04); vertical-align: middle; font-size: 0.85rem; }
    </style>
</head>
<body>

    <canvas id="cometField"></canvas>

    <div class="dashboard-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <div class="eyebrow mb-1">ZCOER // SYSTEM ROOT</div>
                <h2 class="fw-bold m-0" style="font-family: 'Space Grotesk', sans-serif;">Admin IDP Command Terminal</h2>
            </div>
            <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-3"><i class="fa-solid fa-power-off me-1"></i> Logout</a>
        </div>

        <?php if ($flashMessage): ?>
            <div class="alert <?php echo $flashClass; ?> alert-dismissible fade show mb-4"><?php echo htmlspecialchars($flashMessage); ?><button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <!-- Summary Strip -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="glass-card m-0">
                    <div class="eyebrow">Pending Requests</div>
                    <h3 class="fw-bold mt-1 text-warning"><?php echo $pendingRequests; ?></h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="glass-card m-0">
                    <div class="eyebrow">Active Students</div>
                    <h3 class="fw-bold mt-1"><?php echo $totalStudents; ?></h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="glass-card m-0">
                    <div class="eyebrow">Active Parents</div>
                    <h3 class="fw-bold mt-1 text-info"><?php echo $totalParents; ?></h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="glass-card m-0">
                    <div class="eyebrow">Total Users</div>
                    <h3 class="fw-bold mt-1"><?php echo $totalUsers; ?></h3>
                </div>
            </div>
        </div>

        <!-- MANUAL STAFF IDP ASSIGNMENT PANEL -->
        <div class="glass-card mb-4">
            <div class="d-flex align-items-center mb-3">
                <i class="fa-solid fa-user-plus me-2 text-white"></i>
                <h5 class="fw-bold m-0">Manual Staff IDP Provisioning</h5>
            </div>
            <p class="text-secondary small mb-3">Manually assign login credentials for staff roles (Faculty, HOD, GFM, Admin). Default passkey will be <code>Zeal@2026</code>.</p>

            <form method="POST" action="admin_users.php">
                <input type="hidden" name="action" value="create_staff_idp">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="eyebrow mb-1 d-block">Staff Member Name</label>
                        <input type="text" name="staff_name" class="form-control-custom" placeholder="e.g. Dr. Alan Smith" required autocomplete="off">
                    </div>
                    <div class="col-md-3">
                        <label class="eyebrow mb-1 d-block">Official Username / Email</label>
                        <input type="text" name="staff_username" class="form-control-custom" placeholder="e.g. alansmith@zeal.in" required autocomplete="off">
                    </div>
                    <div class="col-md-2">
                        <label class="eyebrow mb-1 d-block">Assign Staff Role</label>
                        <select name="staff_role" class="form-select-custom" required>
                            <option value="" disabled selected>Select Role</option>
                            <option value="Faculty">Faculty</option>
                            <option value="HOD">HOD (Head of Dept)</option>
                            <option value="GFM">GFM (Guardian Faculty Member)</option>
                            <option value="Admin">System Administrator</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="eyebrow mb-1 d-block">Assign Branch / Dept</label>
                        <select name="staff_department" class="form-select-custom" required>
                            <option value="" disabled selected>-- Select --</option>
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
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-action-silver w-100"><i class="fa-solid fa-key me-1"></i> Issue IDP</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Student & Parent Approval Queue -->
        <div class="glass-card mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold m-0">Pending Student & Parent Requests Queue</h5>
                <span class="badge bg-warning text-dark font-monospace"><?php echo count($requestsList); ?> PENDING</span>
            </div>

            <div class="table-responsive">
                <table class="table table-dark-custom align-middle">
                    <thead>
                        <tr>
                            <th>PRN</th>
                            <th>Student & Email</th>
                            <th>Parent & Login Email</th>
                            <th>Department</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($requestsList) === 0): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No pending student/parent requests in queue.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($requestsList as $req): ?>
                            <tr>
                                <td class="font-monospace fw-bold text-white"><?php echo htmlspecialchars($req['prn_number']); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($req['full_name']); ?></strong>
                                    <div class="text-secondary small"><?php echo htmlspecialchars($req['email']); ?></div>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($req['parent_name'] ?? 'Parent'); ?></strong>
                                    <div class="text-info small"><i class="fa-regular fa-envelope me-1"></i><?php echo htmlspecialchars($req['parent_email'] ?? '-'); ?></div>
                                </td>
                                <td><span class="badge border border-secondary text-white-50"><?php echo htmlspecialchars($req['department']); ?></span></td>
                                <td class="text-end">
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="approve_request">
                                        <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                                        <button type="submit" class="btn btn-action-silver me-1"><i class="fa-solid fa-check me-1"></i> Approve Dual IDP</button>
                                    </form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Reject request?');">
                                        <input type="hidden" name="action" value="reject_request">
                                        <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                                        <button type="submit" class="btn btn-outline-silver text-danger border-danger"><i class="fa-solid fa-xmark"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- UPDATED: ISSUED SYSTEM USERS REGISTRY WITH EXPLICIT PRN & EMAIL -->
        <div class="glass-card">
            <h5 class="fw-bold mb-3">Issued Account Registry</h5>
            <div class="table-responsive">
                <table class="table table-dark-custom align-middle">
                    <thead>
                        <tr>
                            <th>UID</th>
                            <th>Name</th>
                            <th>Login Username / PRN</th>
                            <th>Email Address</th>
                            <th>Role</th>
                            <th>Branch / Dept</th>
                            <th>Onboarding Status</th>
                            <th class="text-end">Manage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($userList as $u): ?>
                            <tr>
                                <td class="font-monospace text-secondary">#<?php echo $u['user_id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($u['name']); ?></strong></td>
                                <td class="font-monospace text-white fw-semibold"><?php echo htmlspecialchars($u['username']); ?></td>
                                <td class="text-info small font-monospace"><?php echo htmlspecialchars($u['email'] ?? $u['username']); ?></td>
                                <td><span class="badge border border-secondary"><?php echo htmlspecialchars($u['role']); ?></span></td>
                                <td><span class="badge border border-info text-info"><?php echo htmlspecialchars($u['department'] ?: 'General'); ?></span></td>
                                <td>
                                    <?php if ((int)$u['is_first_login'] === 1): ?>
                                        <span class="text-warning small"><i class="fa-solid fa-hourglass me-1"></i> Pending Setup</span>
                                    <?php else: ?>
                                        <span class="text-success small"><i class="fa-solid fa-circle-check me-1"></i> Completed</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ((int)$u['user_id'] !== (int)$_SESSION['user_id']): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Purge account?');">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="target_user_id" value="<?php echo $u['user_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash-can"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const canvas = document.getElementById('cometField'); const ctx = canvas.getContext('2d');
        let stars = []; const numStars = 150; function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
        window.addEventListener('resize', resize); resize();
        class Star { constructor() { this.x = Math.random() * canvas.width; this.y = Math.random() * canvas.height; this.size = Math.random() * 1.2 + 0.3; this.speed = Math.random() * 0.4 + 0.1; this.alpha = Math.random() * 0.5 + 0.1; } update() { this.y -= this.speed; if (this.y < 0) { this.y = canvas.height; this.x = Math.random() * canvas.width; } } draw() { ctx.fillStyle = `rgba(255, 255, 255, ${this.alpha})`; ctx.beginPath(); ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2); ctx.fill(); } }
        for (let i = 0; i < numStars; i++) stars.push(new Star());
        function loop() { ctx.fillStyle = '#010103'; ctx.fillRect(0, 0, canvas.width, canvas.height); stars.forEach(s => { s.update(); s.draw(); }); requestAnimationFrame(loop); } loop();
    </script>
</body>
</html>