<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once(__DIR__ . '/../config/db.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'Student';

$checkStmt = $conn->prepare("SELECT is_first_login, name, username, role FROM users WHERE user_id = ?");
$checkStmt->bind_param("i", $user_id);
$checkStmt->execute();
$userData = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

$actual_role = $userData['role'] ?? $user_role;

// Route if setup already completed
if ((int)($userData['is_first_login'] ?? 0) === 0) {
    switch (strtolower($actual_role)) {
        case 'admin': header("Location: admin_users.php"); break;
        case 'faculty': header("Location: ../faculty_dashboard.php"); break;
        case 'hod': header("Location: ../hod_dashboard.php"); break;
        case 'gfm': header("Location: ../gfm_dashboard.php"); break;
        case 'parent': header("Location: ../parent_dashboard.php"); break;
        default: header("Location: ../student_dashboard.php"); break;
    }
    exit();
}

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $new_password     = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);
    $phone            = trim($_POST['phone'] ?? '');

    $roll_no  = (strtolower($actual_role) === 'student') ? trim($_POST['roll_no'] ?? '') : NULL;
    
    // Faculty assignments
    $fac_dept = (strtolower($actual_role) === 'faculty') ? trim($_POST['fac_department'] ?? '') : NULL;
    $fac_year = (strtolower($actual_role) === 'faculty') ? trim($_POST['fac_academic_year'] ?? '') : NULL;
    $fac_subj = (strtolower($actual_role) === 'faculty') ? trim($_POST['fac_subject'] ?? '') : NULL;

    $isValid = !empty($new_password) && !empty($confirm_password) && !empty($phone);
    if (strtolower($actual_role) === 'student') {
        if (empty($roll_no)) {
            $isValid = false;
        }
    }
    if (strtolower($actual_role) === 'faculty') {
        if (empty($fac_dept) || empty($fac_year) || empty($fac_subj)) {
            $isValid = false;
        }
    }

    if ($isValid) {
        if ($new_password === $confirm_password) {
            if (strlen($new_password) >= 6 && preg_match('/[A-Z]/', $new_password) && preg_match('/[a-z]/', $new_password) && preg_match('/[0-9]/', $new_password) && preg_match('/[^a-zA-Z0-9]/', $new_password)) {
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

                $updateStmt = $conn->prepare("UPDATE users SET password = ?, roll_no = ?, phone = ?, is_first_login = 0 WHERE user_id = ?");
                $updateStmt->bind_param("sssi", $hashed_password, $roll_no, $phone, $user_id);

                if ($updateStmt->execute()) {
                    // If Faculty, auto-seed the standard 4 teaching assignments (Divisions A, B, C, D)
                    if (strtolower($actual_role) === 'faculty') {
                        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS faculty_classes (
                            class_id INT AUTO_INCREMENT PRIMARY KEY,
                            faculty_id INT NOT NULL,
                            class_name VARCHAR(150) NOT NULL,
                            subject_code VARCHAR(100),
                            academic_year VARCHAR(50) DEFAULT 'FY',
                            department VARCHAR(100) DEFAULT '',
                            division VARCHAR(50) DEFAULT '',
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        )");
                        
                        // Patch columns just in case table exists but missing these new global fields
                        @mysqli_query($conn, "ALTER TABLE faculty_classes ADD COLUMN IF NOT EXISTS department VARCHAR(100) DEFAULT ''");
                        @mysqli_query($conn, "ALTER TABLE faculty_classes ADD COLUMN IF NOT EXISTS division VARCHAR(50) DEFAULT ''");

                        $divisions = ['A', 'B', 'C', 'D'];
                        $facStmt = $conn->prepare("INSERT INTO faculty_classes (faculty_id, class_name, subject_code, academic_year, department, division) VALUES (?, ?, ?, ?, ?, ?)");
                        
                        foreach ($divisions as $fac_div) {
                            $className = "{$fac_dept} - {$fac_year} - Div {$fac_div}";
                            $facStmt->bind_param("isssss", $user_id, $className, $fac_subj, $fac_year, $fac_dept, $fac_div);
                            $facStmt->execute();
                        }
                        $facStmt->close();
                    }

                    $success = "Account setup successfully completed. Redirecting...";
                    
                    $roleLow = strtolower($actual_role);
                    $targetUrl = "../student_dashboard.php";
                    if ($roleLow === 'admin') $targetUrl = "admin_users.php";
                    elseif ($roleLow === 'faculty') $targetUrl = "../faculty_dashboard.php";
                    elseif ($roleLow === 'hod') $targetUrl = "../hod_dashboard.php";
                    elseif ($roleLow === 'gfm') $targetUrl = "../gfm_dashboard.php";
                    elseif ($roleLow === 'parent') $targetUrl = "../parent_dashboard.php";

                    echo "<script>
                            setTimeout(function(){
                                window.location.href = '$targetUrl';
                            }, 1800);
                          </script>";
                } else {
                    $error = "System write error updating profile.";
                }
                $updateStmt->close();
            } else {
                $error = "Password must be at least 6 characters and contain an uppercase letter, a lowercase letter, a number, and a special character.";
            }
        } else {
            $error = "Password confirmation mismatch.";
        }
    } else {
        $error = "Please fill in all required setup parameters.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Setup | SAAES</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Clean Academic Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            /* Traditional Academic Color Palette */
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --navy-primary: #0f172a;
            --blue-accent: #2563eb;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            
            --radius-md: 8px;
            --radius-lg: 12px;
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            
            --font-main: 'Inter', system-ui, -apple-system, sans-serif;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: var(--font-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            -webkit-font-smoothing: antialiased;
        }

        a { text-decoration: none; color: inherit; }

        /* ================= SETUP CARD ================= */
        .setup-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            width: 100%;
            max-width: 650px;
            border-radius: var(--radius-lg);
            padding: 3rem;
            box-shadow: var(--shadow-md);
        }

        .card-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .sys-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            background: #eff6ff;
            color: var(--blue-accent);
            border-radius: 50%;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .card-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--navy-primary);
            margin-bottom: 0.5rem;
        }

        .card-subtitle {
            color: var(--text-muted);
            font-size: 0.95rem;
            line-height: 1.5;
        }

        /* ================= FORM ELEMENTS ================= */
        .section-divider {
            font-size: 1rem;
            font-weight: 600;
            color: var(--navy-primary);
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 8px;
            margin-bottom: 20px;
            margin-top: 1.5rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-divider i { color: var(--blue-accent); }

        .form-group { margin-bottom: 1.25rem; }
        
        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 0.4rem;
            display: block;
        }

        .form-control-custom, .form-select-custom {
            background-color: var(--bg-body);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 0.75rem 1rem;
            font-family: var(--font-main);
            font-size: 0.95rem;
            width: 100%;
            transition: all 0.2s ease;
            border-radius: var(--radius-md);
            -webkit-appearance: none;
        }
        
        .form-select-custom {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
            padding-right: 2.5rem;
        }
        
        .form-control-custom:focus, .form-select-custom:focus {
            border-color: var(--blue-accent);
            background-color: var(--bg-card);
            outline: none;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .form-control-custom::placeholder { color: #94a3b8; font-size: 0.9rem; }

        /* ================= BUTTON ================= */
        .btn-primary {
            font-family: var(--font-main);
            font-weight: 600;
            font-size: 1rem;
            padding: 0.85rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: var(--blue-accent);
            color: #fff;
            border: none;
            border-radius: var(--radius-md);
            width: 100%;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.1s ease;
            margin-top: 2rem;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .btn-primary:active {
            transform: scale(0.98);
        }

        /* ================= ALERTS ================= */
        .alert { 
            font-size: 0.9rem; 
            font-weight: 500; 
            border-radius: var(--radius-md); 
            padding: 1rem 1.25rem; 
            margin-bottom: 2rem; 
            display: flex; 
            align-items: center; 
            gap: 0.75rem;
        }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }

        @media (max-width: 600px) {
            .setup-card { padding: 2rem 1.5rem; }
            .card-title { font-size: 1.5rem; }
        }
    </style>
</head>
<body>

    <div class="setup-card">
        <div class="card-header">
            <div class="sys-icon"><i class="fa-solid fa-user-shield"></i></div>
            <h3 class="card-title"><?php echo htmlspecialchars($actual_role); ?> Setup</h3>
            <p class="card-subtitle">Welcome, <strong><?php echo htmlspecialchars($userData['name']); ?></strong>. Please complete your profile to continue.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" action="setup_profile.php" id="setupForm">
            
            <!-- Section 1: Security Passkey -->
            <div class="section-divider"><i class="fa-solid fa-key"></i> 1. Security Credentials</div>
            
            <div class="row">
                <div class="col-md-6 form-group">
                    <label class="form-label">New Password <span style="color: #ef4444;">*</span></label>
                    <div class="position-relative">
                        <input type="password" name="new_password" id="new_password" class="form-control-custom" style="padding-right: 2.5rem;" placeholder="Min 6 chars, uppercase, lowercase, number, symbol" required>
                        <i class="fa-regular fa-eye toggle-password" data-target="new_password" style="position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); cursor: pointer; color: #64748b;"></i>
                    </div>
                </div>
                <div class="col-md-6 form-group">
                    <label class="form-label">Confirm Password <span style="color: #ef4444;">*</span></label>
                    <div class="position-relative">
                        <input type="password" name="confirm_password" id="confirm_password" class="form-control-custom" style="padding-right: 2.5rem;" placeholder="Retype password" required>
                        <i class="fa-regular fa-eye toggle-password" data-target="confirm_password" style="position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); cursor: pointer; color: #64748b;"></i>
                    </div>
                </div>
            </div>

            <!-- Section 2: Account Recovery Setup Removed (Now uses Email OTP) -->

            <!-- Section 3: Role-Adaptive Metadata -->
            <?php if ($actual_role === 'Student'): ?>
                <div class="section-divider"><i class="fa-solid fa-graduation-cap"></i> 3. Academic Details</div>
                
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label class="form-label">Roll No <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="roll_no" class="form-control-custom" placeholder="e.g. 45" required autocomplete="off">
                    </div>
                    <div class="col-md-6 form-group">
                        <label class="form-label">Phone No <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="phone" class="form-control-custom" placeholder="Required" required autocomplete="off">
                    </div>
                </div>
            <?php elseif ($actual_role === 'Faculty'): ?>
                <div class="section-divider"><i class="fa-solid fa-chalkboard-user"></i> 3. Primary Teaching Assignment</div>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem;">Select your primary global cohort. You can add more later in your dashboard.</p>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label class="form-label">Department / Branch <span style="color: #ef4444;">*</span></label>
                        <select name="fac_department" class="form-select-custom" required>
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
                    <div class="col-md-6 form-group">
                        <label class="form-label">Academic Year <span style="color: #ef4444;">*</span></label>
                        <select name="fac_academic_year" class="form-select-custom" required>
                            <option value="" disabled selected>-- Select Year --</option>
                            <option value="FY">First Year (FY)</option>
                            <option value="SY">Second Year (SY)</option>
                            <option value="TY">Third Year (TY)</option>
                            <option value="Final Year">Final Year (B.Tech)</option>
                        </select>
                    </div>
                    <div class="col-md-6 form-group">
                        <label class="form-label">Subject / Course Code <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="fac_subject" class="form-control-custom" placeholder="e.g. BEE or CS101" required autocomplete="off">
                    </div>
                    <div class="col-12 form-group">
                        <label class="form-label">Phone Number <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="phone" class="form-control-custom" placeholder="Required contact" required autocomplete="off">
                    </div>
                </div>
            <?php else: ?>
                <div class="section-divider"><i class="fa-solid fa-address-book"></i> 3. Contact Details</div>
                
                <div class="form-group">
                    <label class="form-label">Phone Number <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="phone" class="form-control-custom" placeholder="Required primary contact" required autocomplete="off">
                </div>
            <?php endif; ?>

            <button type="submit" class="btn-primary" id="submitBtn">
                Complete Setup <i class="fa-solid fa-arrow-right"></i>
            </button>
        </form>
    </div>

    <!-- Vanilla JS for Form Processing -->
    <script>
    document.addEventListener("DOMContentLoaded", () => {
        // Submit Button Feedback
        document.getElementById('setupForm').addEventListener('submit', function() {
            const btn = document.getElementById('submitBtn');
            btn.style.pointerEvents = 'none';
            btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin me-2"></i> Processing...';
        });

        // Toggle Password Visibility
        document.querySelectorAll('.toggle-password').forEach(function(icon) {
            icon.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const input = document.getElementById(targetId);
                if (input.type === 'password') {
                    input.type = 'text';
                    this.classList.remove('fa-eye');
                    this.classList.add('fa-eye-slash');
                } else {
                    input.type = 'password';
                    this.classList.remove('fa-eye-slash');
                    this.classList.add('fa-eye');
                }
            });
        });
    });
    </script>
</body>
</html>