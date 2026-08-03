<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-caching headers to prevent browser back-button access after logout
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Steps out of 'auth' folder to find 'config/db.php'
require_once(__DIR__ . '/../config/db.php');

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $input_identity = strtolower(trim($_POST['username'] ?? ''));
    $password       = trim($_POST['password'] ?? '');
    $selected_role  = strtolower(trim($_POST['role'] ?? ''));

    if (!empty($input_identity) && !empty($password) && !empty($selected_role)) {
        
        // Checks BOTH username and email columns with case-insensitive matching
        $stmt = $conn->prepare("SELECT user_id, name, password, role, is_first_login, department FROM users WHERE (LOWER(TRIM(username)) = ? OR LOWER(TRIM(email)) = ?) AND LOWER(role) = ?");
        $stmt->bind_param("sss", $input_identity, $input_identity, $selected_role);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if (password_verify($password, $user['password'])) {
                $normalized_role = strtolower($user['role']);
                
                $_SESSION['user_id']    = (int) $user['user_id'];
                $_SESSION['user_name']  = $user['name'];
                $_SESSION['full_name']  = $user['name']; // Required by student dashboard
                $_SESSION['role']       = $normalized_role; // Required in lowercase ('student')
                if (!empty($user['department'])) {
                    $_SESSION['department'] = $user['department'];
                }

                // If student, pre-populate student_id & session data
                if ($normalized_role === 'student') {
                    $stuStmt = $conn->prepare("SELECT student_id, roll_no, division, department FROM students WHERE user_id = ? LIMIT 1");
                    if ($stuStmt) {
                        $stuStmt->bind_param("i", $user['user_id']);
                        $stuStmt->execute();
                        $stuRes = $stuStmt->get_result();
                        if ($stuRes && $stuRow = $stuRes->fetch_assoc()) {
                            $_SESSION['student_id'] = (int) $stuRow['student_id'];
                            $_SESSION['roll_no']    = $stuRow['roll_no'];
                            $_SESSION['division']   = $stuRow['division'];
                            $_SESSION['department'] = $stuRow['department'];
                        }
                        $stuStmt->close();
                    }
                }

                // Write audit log entry (log before first login interceptor to capture all successful logins)
                try {
                    $logAction = "Logged in successfully";
                    $logDetails = "User role: " . $user['role'];
                    $logStmt = $conn->prepare("INSERT INTO audit_logs (user_id, role, action, details) VALUES (?, ?, ?, ?)");
                    $logStmt->bind_param("isss", $user['user_id'], $user['role'], $logAction, $logDetails);
                    $logStmt->execute();
                    $logStmt->close();
                } catch (Exception $e) {
                    // Swallowed safely
                }

                // FIRST LOGIN INTERCEPTOR GATE
                if ((int)($user['is_first_login'] ?? 0) === 1) {
                    header("Location: setup_profile.php");
                    exit();
                }

                // Dynamic relative routing depending on role
                switch ($normalized_role) {
                    case 'admin': 
                        header("Location: admin_users.php"); 
                        exit();
                    case 'faculty': 
                        header("Location: ../faculty_dashboard.php"); 
                        exit();
                    case 'hod': 
                        header("Location: ../hod_dashboard.php"); 
                        exit();
                    case 'gfm': 
                        header("Location: ../gfm_dashboard.php"); 
                        exit();
                    case 'student': 
                        header("Location: ../student_dashboard.php"); 
                        exit();
                    case 'parent': 
                        header("Location: ../parent_dashboard.php"); 
                        exit();
                    default: 
                        $error = "No dashboard routing defined for role: " . htmlspecialchars($user['role']);
                        break;
                }
            } else {
                $error = "Invalid password credentials provided.";
            }
        } else {
            $error = "No active account found matching identity '" . htmlspecialchars($input_identity) . "' for " . htmlspecialchars($selected_role) . ".";
        }
        $stmt->close();
    } else {
        $error = "Please fill in all login fields including role selection.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>College Login | SAAES</title>
    
    <!-- Premium Minimalist Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        :root {
            /* Clean White & Blue 2D Palette */
            --bg-canvas: #f8fafc;
            --blue-deep: #0f172a;
            --blue-primary: #2563eb;
            --blue-hover: #1d4ed8;
            --blue-light: #eff6ff;
            --cyan-glow: #00e5ff;
            
            --card-bg: rgba(255, 255, 255, 0.92);
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --border-soft: rgba(226, 232, 240, 0.8);
            
            --font-head: 'Outfit', sans-serif;
            --font-body: 'Inter', sans-serif;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--font-body);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow-x: hidden;
            background-color: var(--blue-deep);
            /* Static, non-moving College Building Background with Deep Blue Overlay */
            background-image: 
                linear-gradient(135deg, rgba(15, 23, 42, 0.9) 0%, rgba(37, 99, 235, 0.75) 100%), 
                url('../assets/images/college_building.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            padding: 2rem;
            -webkit-font-smoothing: antialiased;
        }

        /* ================= WIDE CENTERED LOGIN ISLAND ================= */
        .island-wrapper {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 1000px;
        }

        .glass-island {
            position: relative;
            display: flex;
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            overflow: hidden;
            
            /* Clean Drop-in Animation */
            opacity: 0;
            transform: translateY(30px);
            animation: assembleCard 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes assembleCard {
            to { opacity: 1; transform: translateY(0); }
        }

        /* WOW FACTOR: INTERACTIVE SPOTLIGHT */
        .glass-island::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: radial-gradient(800px circle at var(--mouse-x, 50%) var(--mouse-y, 50%), rgba(255, 255, 255, 0.5), transparent 40%);
            z-index: 100;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.5s ease;
            mix-blend-mode: overlay;
        }
        .glass-island:hover::after {
            opacity: 1;
        }

        /* ================= LEFT PANE: 2D WORKFLOW TIMELINE ================= */
        .workflow-pane {
            flex: 1.1;
            /* Updated to match the blue hue */
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.95) 0%, rgba(37, 99, 235, 0.9) 100%);
            padding: 4rem 3.5rem;
            border-right: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            z-index: 2;
        }

        .workflow-header {
            margin-bottom: 3rem;
        }

        .workflow-header h4 {
            font-family: var(--font-head);
            font-size: 1.6rem;
            font-weight: 800;
            color: #ffffff; /* White text for dark bg */
            margin-bottom: 0.3rem;
        }

        .workflow-header p {
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.7); /* Light text for dark bg */
            font-weight: 500;
        }

        /* Sleek 2D Vertical Timeline */
        .timeline-container {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 2.2rem;
        }

        /* Vertical Connector Line */
        .timeline-line {
            position: absolute;
            left: 23px; /* Centers exactly behind the 48px icons */
            top: 20px;
            bottom: 20px;
            width: 2px;
            background: rgba(255, 255, 255, 0.15); /* Lighter line for dark bg */
            z-index: 1;
            overflow: hidden;
            border-radius: 2px;
        }

        /* WOW FACTOR: Syncronized Glowing Data Pulse */
        .timeline-pulse {
            position: absolute;
            top: -50px;
            left: 0;
            width: 100%;
            height: 60px;
            background: linear-gradient(to bottom, transparent, var(--cyan-glow), transparent); /* Cyan glow */
            animation: pulseDown 6s infinite linear;
        }

        @keyframes pulseDown {
            0% { top: -60px; }
            100% { top: 100%; }
        }

        .timeline-step {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            position: relative;
            z-index: 2;
            
            /* Staggered Slide In */
            opacity: 0;
            transform: translateX(-20px);
            animation: slideRight 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        .timeline-step:nth-child(2) { animation-delay: 0.2s; }
        .timeline-step:nth-child(3) { animation-delay: 0.4s; }
        .timeline-step:nth-child(4) { animation-delay: 0.6s; }
        .timeline-step:nth-child(5) { animation-delay: 0.8s; }

        .step-icon {
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.2rem;
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            flex-shrink: 0;
        }

        /* Synced Icon Pulses matching the data pulse animation (6s total) */
        .timeline-step:nth-child(2) .step-icon { animation: syncGlow 6s infinite linear; animation-delay: 0.2s; }
        .timeline-step:nth-child(3) .step-icon { animation: syncGlow 6s infinite linear; animation-delay: 1.8s; }
        .timeline-step:nth-child(4) .step-icon { animation: syncGlow 6s infinite linear; animation-delay: 3.4s; }
        .timeline-step:nth-child(5) .step-icon { animation: syncGlow 6s infinite linear; animation-delay: 5s; }

        @keyframes syncGlow {
            0%, 20%, 100% { 
                transform: scale(1); 
                background: rgba(255, 255, 255, 0.1); 
                color: #ffffff; 
                box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2); 
                border-color: rgba(255, 255, 255, 0.3); 
            }
            10% { 
                transform: scale(1.15); 
                background: var(--cyan-glow); 
                color: var(--blue-deep); 
                box-shadow: 0 8px 20px rgba(0, 229, 255, 0.5); 
                border-color: var(--cyan-glow); 
            }
        }

        .step-content h5 {
            font-family: var(--font-head);
            font-size: 1.1rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.15rem;
        }

        .step-content p {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.7);
            margin: 0;
        }

        /* ================= RIGHT PANE: LOGIN FORM ================= */
        .form-pane {
            flex: 1;
            padding: 4rem 3.5rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #ffffff;
            z-index: 2;
        }

        .card-header {
            text-align: center;
            margin-bottom: 2.5rem;
            opacity: 0;
            transform: translateY(15px);
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards 0.2s;
        }

        .sys-logo {
            width: 80px;
            height: auto;
            margin-bottom: 1rem;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .form-pane:hover .sys-logo {
            transform: translateY(-4px);
            filter: drop-shadow(0 10px 15px rgba(37, 99, 235, 0.2));
        }

        .card-title {
            font-family: var(--font-head);
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--blue-deep);
            margin-bottom: 0.2rem;
        }

        .card-subtitle {
            font-size: 0.95rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* ================= INPUTS ================= */
        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
            opacity: 0;
            transform: translateX(-15px);
            animation: slideRight 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .form-group:nth-child(1) { animation-delay: 0.3s; }
        .form-group:nth-child(2) { animation-delay: 0.4s; }
        .form-group:nth-child(3) { animation-delay: 0.5s; }

        .input-wrapper {
            position: relative;
            background: var(--bg-canvas);
            border: 1px solid var(--border-soft);
            border-radius: 12px;
            transition: all 0.3s ease;
            overflow: hidden;
        }

        /* Smooth blue fill on focus */
        .input-wrapper::before {
            content: ''; position: absolute; top: 0; left: 50%; width: 0%; height: 100%;
            background: rgba(37, 99, 235, 0.04);
            transform: translateX(-50%);
            transition: width 0.4s ease;
            z-index: 0;
        }
        .input-wrapper:focus-within::before { width: 100%; }
        .input-wrapper:focus-within { 
            border-color: var(--blue-primary); 
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1); 
            background: #ffffff;
        }

        .input-icon {
            position: absolute;
            left: 1.2rem; top: 50%; transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1.1rem;
            z-index: 2;
            transition: all 0.3s ease;
        }
        .input-wrapper:focus-within .input-icon {
            color: var(--blue-primary);
            transform: translateY(-50%) scale(1.1);
        }

        .form-control-custom, .form-select-custom {
            width: 100%;
            background: transparent;
            border: none;
            color: var(--blue-deep);
            padding: 1.2rem 1rem 1.2rem 3.2rem;
            font-family: var(--font-body);
            font-size: 0.95rem;
            font-weight: 600;
            position: relative;
            z-index: 1;
            outline: none;
            -webkit-appearance: none;
        }

        .form-select-custom {
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%232563eb' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1.2rem center;
            background-size: 1.2em;
        }

        .form-control-custom::placeholder { color: transparent; }
        
        /* Floating labels */
        .floating-label {
            position: absolute;
            left: 3.2rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 0.95rem;
            pointer-events: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            transform-origin: left top;
        }

        .form-control-custom:focus ~ .floating-label,
        .form-control-custom:not(:placeholder-shown) ~ .floating-label,
        .form-select-custom:focus ~ .floating-label,
        .form-select-custom:valid ~ .floating-label {
            transform: translateY(-130%) scale(0.8);
            color: var(--blue-primary);
            font-weight: 600;
        }

        /* ================= BUTTON & LINKS ================= */
        .btn-wrapper {
            opacity: 0; transform: translateY(15px);
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards 0.6s;
            margin-top: 2rem;
            position: relative;
        }

        .btn-primary {
            width: 100%;
            padding: 1.1rem;
            background: var(--blue-primary);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-family: var(--font-head);
            font-size: 1.05rem;
            font-weight: 600;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.3);
            background: var(--blue-hover);
        }

        .btn-primary:active { transform: translateY(0); box-shadow: none; }

        /* Ripple effect on click */
        .ripple {
            position: absolute;
            background: rgba(255, 255, 255, 0.4);
            border-radius: 50%;
            transform: scale(0);
            animation: rippleAnim 0.5s ease-out;
            pointer-events: none;
        }
        @keyframes rippleAnim { to { transform: scale(4); opacity: 0; } }

        .helper-links {
            display: flex;
            justify-content: space-between;
            margin-top: 1.5rem;
            padding: 0 0.2rem;
            opacity: 0;
            animation: fadeUp 0.6s forwards 0.7s;
        }

        .helper-links a {
            font-size: 0.9rem;
            color: var(--text-muted);
            font-weight: 500;
            transition: color 0.3s ease;
            text-decoration: none;
        }
        .helper-links a:hover { color: var(--blue-deep); }
        .helper-links a.primary-link { color: var(--blue-primary); font-weight: 600; }
        .helper-links a.primary-link:hover { text-decoration: underline; }

        /* ================= BOUNCY POPUPS ================= */
        #popup-container {
            position: fixed;
            top: 30px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 15px;
            pointer-events: none;
        }

        .super-toast {
            background: #ffffff;
            border-left: 4px solid #ef4444;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            box-shadow: 0 15px 35px rgba(239, 68, 68, 0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            transform: scale(0.8) translateY(-30px);
            opacity: 0;
            transform-origin: center top;
            animation: bouncyPop 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55) forwards;
        }

        .super-toast i { font-size: 1.4rem; color: #ef4444; }
        .super-toast span { font-weight: 600; color: var(--blue-deep); font-size: 0.9rem; }

        @keyframes bouncyPop {
            0% { transform: scale(0.8) translateY(-30px); opacity: 0; }
            100% { transform: scale(1) translateY(0); opacity: 1; }
        }

        @keyframes slideUp { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideRight { from { opacity: 0; transform: translateX(-20px); } to { opacity: 1; transform: translateX(0); } }
        @keyframes fadeUp { from { opacity: 0; } to { opacity: 1; } }

        /* ================= BACK TO HOME BUTTON ================= */
        .back-home-btn {
            position: fixed;
            top: 30px;
            left: 30px;
            z-index: 100;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0.6rem 1.2rem;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 30px;
            color: #ffffff;
            font-family: var(--font-body);
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            opacity: 0;
            animation: fadeUp 0.6s forwards 0.3s;
        }

        .back-home-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateX(-3px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
            color: #ffffff;
        }

        /* Responsive Breakpoints */
        @media (max-width: 900px) {
            .glass-island { flex-direction: column; max-width: 480px; margin: 0 auto;}
            .workflow-pane { border-right: none; border-bottom: 1px solid var(--border-soft); padding: 2.5rem; display: none; } /* Hide workflow on mobile to keep login fast/clean */
            .form-pane { padding: 3rem 2.5rem; border-radius: 24px; }
        }
        @media (max-width: 480px) {
            .form-pane { padding: 2.5rem 1.5rem; }
            .card-title { font-size: 1.6rem; }
            .back-home-btn { top: 15px; left: 15px; padding: 0.5rem 1rem; }
        }
    </style>
</head>
<body>

    <!-- BACK TO HOME BUTTON -->
    <a href="../index.php" class="back-home-btn">
        <i class="fa-solid fa-arrow-left"></i> Home
    </a>

    <!-- BOUNCY POPUP CONTAINER -->
    <div id="popup-container"></div>

    <div class="island-wrapper" id="cardWrapper">
        <div class="glass-island" id="cardElement">
            
            <!-- LEFT PANE: 2D MINIMALIST WORKFLOW TIMELINE -->
            <div class="workflow-pane">
                <div class="workflow-header">
                    <h4>System Workflow</h4>
                    <p>Automated Evaluation Process</p>
                </div>

                <div class="timeline-container">
                    <!-- The connecting line with the traveling glowing pulse -->
                    <div class="timeline-line">
                        <div class="timeline-pulse"></div>
                    </div>
                    
                    <div class="timeline-step">
                        <div class="step-icon"><i class="fa-solid fa-chalkboard-user"></i></div>
                        <div class="step-content">
                            <h5>Faculty Creates</h5>
                            <p>Assigns activity with deadline</p>
                        </div>
                    </div>

                    <div class="timeline-step">
                        <div class="step-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                        <div class="step-content">
                            <h5>Student Submits</h5>
                            <p>Secure file upload</p>
                        </div>
                    </div>

                    <div class="timeline-step">
                        <div class="step-icon"><i class="fa-solid fa-check-double"></i></div>
                        <div class="step-content">
                            <h5>Faculty Evaluates</h5>
                            <p>Review and mark allocation</p>
                        </div>
                    </div>

                    <div class="timeline-step">
                        <div class="step-icon"><i class="fa-solid fa-chart-pie"></i></div>
                        <div class="step-content">
                            <h5>Realtime Analysis</h5>
                            <p>Instant performance metrics</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT PANE: LOGIN FORM -->
            <div class="form-pane">
                <div class="card-header">
                    <img src="../assets/images/zeallogo.jpg" alt="Zeal College Logo" class="sys-logo">
                    <h3 class="card-title">College Login</h3>
                    <p class="card-subtitle">Please sign in to continue</p>
                </div>

                <form method="POST" action="login.php" id="loginForm">
                    
                    <div class="form-group">
                        <div class="input-wrapper">
                            <i class="fa-solid fa-user-shield input-icon"></i>
                            <?php $getRole = strtolower($_GET['role'] ?? ''); ?>
                            <select name="role" class="form-select-custom" required>
                                <option value="" disabled <?php echo empty($getRole) ? 'selected' : ''; ?>></option>
                                <option value="student" <?php echo $getRole === 'student' ? 'selected' : ''; ?>>Student</option>
                                <option value="parent" <?php echo $getRole === 'parent' ? 'selected' : ''; ?>>Parent</option>
                                <option value="faculty" <?php echo $getRole === 'faculty' ? 'selected' : ''; ?>>Faculty</option>
                                <option value="hod" <?php echo $getRole === 'hod' ? 'selected' : ''; ?>>HOD</option>
                                <option value="gfm" <?php echo $getRole === 'gfm' ? 'selected' : ''; ?>>GFM</option>
                                <option value="admin" <?php echo $getRole === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                            <label class="floating-label">Select Role</label>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="input-wrapper">
                            <i class="fa-regular fa-envelope input-icon"></i>
                            <input type="text" name="username" class="form-control-custom" placeholder="PRN or Email" required autocomplete="username">
                            <label class="floating-label">PRN or Email</label>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="input-wrapper">
                            <i class="fa-solid fa-lock input-icon"></i>
                            <input type="password" name="password" id="password" class="form-control-custom" style="padding-right: 3rem;" placeholder="Password" required autocomplete="current-password">
                            <label class="floating-label">Password</label>
                            <i class="fa-regular fa-eye toggle-password" data-target="password" style="position: absolute; right: 1.2rem; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-muted); font-size: 1.1rem; z-index: 5; transition: color 0.3s ease;"></i>
                        </div>
                    </div>

                    <div class="btn-wrapper">
                        <button type="submit" class="btn-primary" id="submitBtn">
                            Login <i class="fa-solid fa-arrow-right"></i>
                        </button>
                    </div>

                    <div class="helper-links">
                        <a href="forgot_password.php">Forgot Password?</a>
                        <a href="register.php" class="primary-link">Register</a>
                    </div>
                </form>
            </div>

        </div>
    </div>

    <!-- Pass PHP Errors to JS -->
    <?php if (!empty($error)): ?>
        <div id="phpError" style="display:none;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <script>
    document.addEventListener("DOMContentLoaded", () => {
        
        // 1. WOW FACTOR: INTERACTIVE GLASS SPOTLIGHT
        const island = document.getElementById('cardElement');
        island.addEventListener('mousemove', (e) => {
            const rect = island.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            // Set CSS variables for the spotlight position
            island.style.setProperty('--mouse-x', `${x}px`);
            island.style.setProperty('--mouse-y', `${y}px`);
        });

        // 2. BOUNCY POPUPS FOR ERRORS
        const phpError = document.getElementById('phpError');
        if (phpError) {
            triggerPopup(phpError.innerText);
        }

        function triggerPopup(message) {
            const container = document.getElementById('popup-container');
            const toast = document.createElement('div');
            toast.className = 'super-toast';
            toast.innerHTML = `<i class="fa-solid fa-triangle-exclamation fa-beat-fade"></i> <span>${message}</span>`;
            
            container.appendChild(toast);
            
            // Remove after delay with fade out
            setTimeout(() => {
                toast.style.transition = 'all 0.4s ease';
                toast.style.transform = 'scale(0.8) translateY(-30px)';
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 400);
            }, 5000);
        }

        // 3. MAGNETIC BUTTON WITH RIPPLE EXPLOSION
        const btn = document.getElementById('submitBtn');
        
        btn.addEventListener('mousemove', (e) => {
            const rect = btn.getBoundingClientRect();
            const x = e.clientX - rect.left - rect.width / 2;
            const y = e.clientY - rect.top - rect.height / 2;
            // Subtle 2D translation
            btn.style.transform = `translate(${x * 0.1}px, ${y * 0.1}px)`;
        });

        btn.addEventListener('mouseleave', () => {
            btn.style.transform = `translate(0px, 0px)`;
        });

        btn.addEventListener('click', function(e) {
            // Ripple Effect
            const circle = document.createElement('span');
            circle.classList.add('ripple');
            
            const rect = btn.getBoundingClientRect();
            const diameter = Math.max(rect.width, rect.height);
            circle.style.width = circle.style.height = `${diameter}px`;
            circle.style.left = `${e.clientX - rect.left - diameter / 2}px`;
            circle.style.top = `${e.clientY - rect.top - diameter / 2}px`;
            
            btn.appendChild(circle);
            setTimeout(() => circle.remove(), 500);

            // Loading State
            setTimeout(() => {
                btn.style.pointerEvents = 'none';
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="font-size: 1.2rem;"></i>';
                btn.style.background = 'var(--blue-deep)';
                btn.style.boxShadow = 'none';
            }, 100);
        });

        // 4. TOGGLE PASSWORD VISIBILITY
        document.querySelectorAll('.toggle-password').forEach(function(icon) {
            icon.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const input = document.getElementById(targetId);
                if (input.type === 'password') {
                    input.type = 'text';
                    this.classList.remove('fa-eye');
                    this.classList.add('fa-eye-slash');
                    this.style.color = 'var(--blue-primary)';
                } else {
                    input.type = 'password';
                    this.classList.remove('fa-eye-slash');
                    this.classList.add('fa-eye');
                    this.style.color = 'var(--text-muted)';
                }
            });
        });
    });
    </script>
</body>
</html>