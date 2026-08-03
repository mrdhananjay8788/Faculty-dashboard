<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once(__DIR__ . '/../config/db.php');

// AUTO-PATCH: Safely ensures parent_name and parent_email columns exist in access_requests
function patchAccessRequestsTable($conn) {
    // Ensure table exists first
    $createTableSQL = "CREATE TABLE IF NOT EXISTS `access_requests` (
        `request_id` INT AUTO_INCREMENT PRIMARY KEY,
        `prn_number` VARCHAR(50) NOT NULL UNIQUE,
        `full_name` VARCHAR(100) NOT NULL,
        `email` VARCHAR(150) NOT NULL UNIQUE,
        `department` VARCHAR(100) NOT NULL,
        `academic_year` VARCHAR(50) NOT NULL DEFAULT 'FY',
        `division` VARCHAR(50) NOT NULL DEFAULT 'A',
        `parent_name` VARCHAR(100) NOT NULL,
        `parent_email` VARCHAR(150) NOT NULL,
        `status` VARCHAR(20) DEFAULT 'PENDING',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    @mysqli_query($conn, $createTableSQL);

    // Patch columns if table was created previously without them
    $parentCols = [
        "parent_name" => "VARCHAR(100) NOT NULL DEFAULT ''",
        "parent_email" => "VARCHAR(150) NOT NULL DEFAULT ''",
        "academic_year" => "VARCHAR(50) NOT NULL DEFAULT 'FY'",
        "division" => "VARCHAR(50) NOT NULL DEFAULT 'A'"
    ];

    foreach ($parentCols as $col => $definition) {
        try {
            $checkCol = @mysqli_query($conn, "SHOW COLUMNS FROM `access_requests` LIKE '$col'");
            if ($checkCol && mysqli_num_rows($checkCol) === 0) {
                @mysqli_query($conn, "ALTER TABLE `access_requests` ADD COLUMN `$col` $definition");
            }
        } catch (Exception $e) {
            // Swallowed gracefully
        }
    }
}

patchAccessRequestsTable($conn);

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name    = trim($_POST['full_name']);
    $prn_number   = strtoupper(trim($_POST['prn_number']));
    $email        = strtolower(trim($_POST['email']));
    $department   = trim($_POST['department']);
    $academic_year= trim($_POST['academic_year']);
    $division     = strtoupper(trim($_POST['division']));
    $parent_name  = trim($_POST['parent_name']);
    $parent_email = strtolower(trim($_POST['parent_email']));

    if (!empty($full_name) && !empty($prn_number) && !empty($email) && !empty($department) && !empty($academic_year) && !empty($division) && !empty($parent_name) && !empty($parent_email)) {
        
        // 1. Check if PRN or Student Email already active in users table
            $checkUserPrn = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
            $checkUserPrn->bind_param("s", $prn_number);
            $checkUserPrn->execute();
            $userPrnExists = $checkUserPrn->get_result()->num_rows > 0;
            $checkUserPrn->close();

            $checkUserEmail = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $checkUserEmail->bind_param("s", $email);
            $checkUserEmail->execute();
            $userEmailExists = $checkUserEmail->get_result()->num_rows > 0;
            $checkUserEmail->close();

            if ($userPrnExists) {
                $error = "An IDP account has already been issued for PRN: $prn_number.";
            } elseif ($userEmailExists) {
                $error = "An IDP account has already been issued for Student Email: $email.";
            } else {
                // 2. Check if a PENDING access request already exists for this PRN or Email
                $checkReqPrn = $conn->prepare("SELECT request_id FROM access_requests WHERE prn_number = ? AND status = 'PENDING'");
                $checkReqPrn->bind_param("s", $prn_number);
                $checkReqPrn->execute();
                $pendingPrnExists = $checkReqPrn->get_result()->num_rows > 0;
                $checkReqPrn->close();

                $checkReqEmail = $conn->prepare("SELECT request_id FROM access_requests WHERE email = ? AND status = 'PENDING'");
                $checkReqEmail->bind_param("s", $email);
                $checkReqEmail->execute();
                $pendingEmailExists = $checkReqEmail->get_result()->num_rows > 0;
                $checkReqEmail->close();

                if ($pendingPrnExists) {
                    $error = "A pending access request for PRN ($prn_number) is already under Admin review.";
                } elseif ($pendingEmailExists) {
                    $error = "A pending access request for Student Email ($email) is already under Admin review.";
                } else {
                    // 3. Check if email is already tied to a different PRN
                    $checkDiffPrn = $conn->prepare("SELECT prn_number FROM access_requests WHERE email = ? AND prn_number != ?");
                    $checkDiffPrn->bind_param("ss", $email, $prn_number);
                    $checkDiffPrn->execute();
                    $diffPrnRes = $checkDiffPrn->get_result();
                    $emailTiedToOtherPrn = ($diffPrnRes->num_rows > 0) ? $diffPrnRes->fetch_assoc()['prn_number'] : null;
                    $checkDiffPrn->close();

                    if ($emailTiedToOtherPrn) {
                        $error = "The student email ($email) is already associated with PRN: $emailTiedToOtherPrn.";
                    } else {
                        // 4. Upsert: Check if an existing request row exists for this PRN to update or insert new row
                        $checkExisting = $conn->prepare("SELECT request_id FROM access_requests WHERE prn_number = ?");
                        $checkExisting->bind_param("s", $prn_number);
                        $checkExisting->execute();
                        $existRes = $checkExisting->get_result();

                        if ($existRes->num_rows > 0) {
                            $row = $existRes->fetch_assoc();
                            $reqId = $row['request_id'];
                            $stmt = $conn->prepare("UPDATE access_requests SET prn_number = ?, full_name = ?, email = ?, department = ?, academic_year = ?, division = ?, parent_name = ?, parent_email = ?, status = 'PENDING', created_at = CURRENT_TIMESTAMP WHERE request_id = ?");
                            $stmt->bind_param("ssssssssi", $prn_number, $full_name, $email, $department, $academic_year, $division, $parent_name, $parent_email, $reqId);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO access_requests (prn_number, full_name, email, department, academic_year, division, parent_name, parent_email, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING')");
                            $stmt->bind_param("ssssssss", $prn_number, $full_name, $email, $department, $academic_year, $division, $parent_name, $parent_email);
                        }
                        $checkExisting->close();

                        if ($stmt->execute()) {
                            $success = "Access request logged! Student and Parent IDP accounts will be provisioned simultaneously upon Admin approval.";
                        } else {
                            $error = "System write fault submitting request: " . $conn->error;
                        }
                        $stmt->close();
                    }
                }
            }
    } else {
        $error = "Please fill in all mandatory student and parent parameters.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Access | SAAES</title>
    
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
            
            --card-bg: rgba(255, 255, 255, 0.95);
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
            /* College Building Background with Deep Blue Overlay */
            background-image: 
                linear-gradient(135deg, rgba(15, 23, 42, 0.9) 0%, rgba(37, 99, 235, 0.75) 100%), 
                url('../assets/images/college_building.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            padding: 2rem;
            -webkit-font-smoothing: antialiased;
        }

        /* ================= WIDE CENTERED ISLAND ================= */
        .island-wrapper {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 1100px;
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
            background: radial-gradient(800px circle at var(--mouse-x, 50%) var(--mouse-y, 50%), rgba(255, 255, 255, 0.6), transparent 40%);
            z-index: 100;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.5s ease;
            mix-blend-mode: overlay;
        }
        .glass-island:hover::after { opacity: 1; }

        /* ================= LEFT PANE: 2D REGISTRATION WORKFLOW ================= */
        .workflow-pane {
            flex: 0.8;
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
            color: #ffffff;
            margin-bottom: 0.3rem;
        }

        .workflow-header p {
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.7);
            font-weight: 500;
        }

        /* Sleek 2D Vertical Timeline */
        .timeline-container {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 2.2rem;
        }

        .timeline-line {
            position: absolute;
            left: 23px; 
            top: 20px;
            bottom: 20px;
            width: 2px;
            background: rgba(255, 255, 255, 0.15);
            z-index: 1;
            overflow: hidden;
            border-radius: 2px;
        }

        /* Glowing Data Pulse */
        .timeline-pulse {
            position: absolute;
            top: -50px;
            left: 0;
            width: 100%;
            height: 60px;
            background: linear-gradient(to bottom, transparent, var(--cyan-glow), transparent);
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

        /* ================= RIGHT PANE: REGISTRATION FORM ================= */
        .form-pane {
            flex: 1.2;
            padding: 3.5rem 3rem;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            z-index: 2;
        }

        .card-header {
            text-align: center;
            margin-bottom: 2rem;
            opacity: 0;
            transform: translateY(15px);
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards 0.2s;
        }

        .sys-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 65px; height: 65px;
            background: var(--blue-light);
            color: var(--blue-primary);
            border-radius: 18px;
            font-size: 1.8rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }

        .form-pane:hover .sys-icon {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.2);
            background: var(--blue-primary);
            color: #ffffff;
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

        /* Form Sections */
        .section-title {
            font-family: var(--font-head);
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--blue-deep);
            margin: 1.5rem 0 1rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border-bottom: 2px solid var(--blue-light);
            padding-bottom: 0.5rem;
            opacity: 0;
            animation: slideRight 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .section-title i { color: var(--blue-primary); }
        .section-title:first-of-type { margin-top: 0; }

        /* Multi-column Grid */
        .form-row {
            display: flex;
            gap: 1.25rem;
        }
        .form-row .form-group { flex: 1; }

        /* ================= INPUTS ================= */
        .form-group {
            margin-bottom: 1.25rem;
            position: relative;
            opacity: 0;
            transform: translateX(-15px);
            animation: slideRight 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        /* Staggered form rows */
        .stagger-1 { animation-delay: 0.3s; }
        .stagger-2 { animation-delay: 0.4s; }
        .stagger-3 { animation-delay: 0.5s; }
        .stagger-4 { animation-delay: 0.6s; }
        .stagger-5 { animation-delay: 0.7s; }

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
            padding: 1.1rem 1rem 1.1rem 3.2rem;
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
            font-size: 0.9rem;
            pointer-events: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            transform-origin: left top;
        }

        /* Logic for inputs to float */
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
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards 0.8s;
            margin-top: 1rem;
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
            text-align: center;
            margin-top: 1.5rem;
            opacity: 0;
            animation: fadeUp 0.6s forwards 0.9s;
        }

        .helper-links a {
            font-size: 0.95rem;
            color: var(--text-muted);
            font-weight: 500;
            transition: color 0.3s ease;
            text-decoration: none;
        }
        .helper-links a:hover { color: var(--blue-deep); }
        .helper-links a strong { color: var(--blue-primary); font-weight: 600; }
        .helper-links a:hover strong { text-decoration: underline; }

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
            border-left: 4px solid;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
            transform: scale(0.8) translateY(-30px);
            opacity: 0;
            transform-origin: center top;
            animation: bouncyPop 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55) forwards;
        }

        .toast-error { border-color: #ef4444; box-shadow: 0 15px 35px rgba(239, 68, 68, 0.15); }
        .toast-error i { color: #ef4444; }
        
        .toast-success { border-color: #10b981; box-shadow: 0 15px 35px rgba(16, 185, 129, 0.15); }
        .toast-success i { color: #10b981; }

        .super-toast i { font-size: 1.4rem; }
        .super-toast span { font-weight: 600; color: var(--blue-deep); font-size: 0.9rem; }

        @keyframes bouncyPop {
            0% { transform: scale(0.8) translateY(-30px); opacity: 0; }
            100% { transform: scale(1) translateY(0); opacity: 1; }
        }

        @keyframes slideUp { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes slideRight { from { opacity: 0; transform: translateX(-15px); } to { opacity: 1; transform: translateX(0); } }
        @keyframes fadeUp { from { opacity: 0; } to { opacity: 1; } }

        /* Responsive Breakpoints */
        @media (max-width: 900px) {
            .glass-island { flex-direction: column; max-width: 600px; margin: 0 auto;}
            .workflow-pane { display: none; } /* Hide workflow on mobile to keep form accessible */
            .form-pane { padding: 3rem 2.5rem; border-radius: 24px; }
        }
        @media (max-width: 600px) {
            .form-row { flex-direction: column; gap: 0; }
            .form-pane { padding: 2rem 1.5rem; }
            .card-title { font-size: 1.6rem; }
        }
    </style>
</head>
<body>

    <!-- BOUNCY POPUP CONTAINER -->
    <div id="popup-container"></div>

    <div class="island-wrapper" id="cardWrapper">
        <div class="glass-island" id="cardElement">
            
            <!-- LEFT PANE: 2D REGISTRATION WORKFLOW -->
            <div class="workflow-pane">
                <div class="workflow-header">
                    <h4>Registration Flow</h4>
                    <p>Account creation & approval process</p>
                </div>

                <div class="timeline-container">
                    <div class="timeline-line">
                        <div class="timeline-pulse"></div>
                    </div>
                    
                    <div class="timeline-step">
                        <div class="step-icon"><i class="fa-solid fa-file-pen"></i></div>
                        <div class="step-content">
                            <h5>Submit Details</h5>
                            <p>Provide student and parent info</p>
                        </div>
                    </div>

                    <div class="timeline-step">
                        <div class="step-icon"><i class="fa-solid fa-user-shield"></i></div>
                        <div class="step-content">
                            <h5>Admin Verification</h5>
                            <p>Data authenticated by college</p>
                        </div>
                    </div>

                    <div class="timeline-step">
                        <div class="step-icon"><i class="fa-solid fa-server"></i></div>
                        <div class="step-content">
                            <h5>Account Provisioning</h5>
                            <p>Dual IDP accounts generated</p>
                        </div>
                    </div>

                    <div class="timeline-step">
                        <div class="step-icon"><i class="fa-solid fa-laptop-code"></i></div>
                        <div class="step-content">
                            <h5>System Access</h5>
                            <p>Ready to login to dashboard</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT PANE: REGISTRATION FORM -->
            <div class="form-pane">
                <div class="card-header">
                    <div class="sys-icon"><i class="fa-solid fa-user-plus"></i></div>
                    <h3 class="card-title">Request Access</h3>
                    <p class="card-subtitle">Submit details to request an account</p>
                </div>

                <form method="POST" action="register.php" id="registerForm">
                    
                    <!-- STUDENT DETAILS SECTION -->
                    <div class="section-title stagger-1"><i class="fa-solid fa-user-graduate"></i> Student Details</div>
                    
                    <div class="form-row stagger-1">
                        <div class="form-group">
                            <div class="input-wrapper">
                                <i class="fa-solid fa-id-card input-icon"></i>
                                <input type="text" name="prn_number" class="form-control-custom" placeholder="PRN Number" required autocomplete="off">
                                <label class="floating-label">PRN Number</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="input-wrapper">
                                <i class="fa-solid fa-building-columns input-icon"></i>
                                <select name="department" class="form-select-custom" required>
                                    <option value="" disabled selected></option>
                                    <option value="AI and Machine Learning">AI & Machine Learning</option>
                                    <option value="AI and Data Science">AI & Data Science</option>
                                    <option value="Computer Engineering">Computer Engineering</option>
                                    <option value="ENTC">E&TC</option>
                                    <option value="Mechanical Engineering">Mechanical Engineering</option>
                                    <option value="Electrical Engineering">Electrical Engineering</option>
                                    <option value="Electronics and Computer Engineering">Electronics & Computer</option>
                                    <option value="Information Technology">Information Technology</option>
                                    <option value="Civil Engineering">Civil Engineering</option>
                                </select>
                                <label class="floating-label">Department</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-row stagger-2">
                        <div class="form-group">
                            <div class="input-wrapper">
                                <i class="fa-regular fa-calendar-days input-icon"></i>
                                <select name="academic_year" class="form-select-custom" required>
                                    <option value="" disabled selected></option>
                                    <option value="FY">First Year (FY)</option>
                                    <option value="SY">Second Year (SY)</option>
                                    <option value="TY">Third Year (TY)</option>
                                    <option value="Final Year">Final Year (B.Tech)</option>
                                </select>
                                <label class="floating-label">Academic Year</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="input-wrapper">
                                <i class="fa-solid fa-layer-group input-icon"></i>
                                <select name="division" class="form-select-custom" required>
                                    <option value="" disabled selected></option>
                                    <option value="A">Division A</option>
                                    <option value="B">Division B</option>
                                    <option value="C">Division C</option>
                                    <option value="D">Division D</option>
                                </select>
                                <label class="floating-label">Class / Division</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group stagger-3">
                        <div class="input-wrapper">
                            <i class="fa-regular fa-user input-icon"></i>
                            <input type="text" name="full_name" class="form-control-custom" placeholder="Student Full Name" required autocomplete="off">
                            <label class="floating-label">Student Full Name</label>
                        </div>
                    </div>

                    <div class="form-group stagger-3">
                        <div class="input-wrapper">
                            <i class="fa-regular fa-envelope input-icon"></i>
                            <input type="email" name="email" class="form-control-custom" placeholder="Student Email" required autocomplete="off">
                            <label class="floating-label">Student Email Address</label>
                        </div>
                    </div>

                    <!-- PARENT DETAILS SECTION -->
                    <div class="section-title stagger-4"><i class="fa-solid fa-user-shield"></i> Parent / Guardian Details</div>

                    <div class="form-group stagger-4">
                        <div class="input-wrapper">
                            <i class="fa-solid fa-user-tie input-icon"></i>
                            <input type="text" name="parent_name" class="form-control-custom" placeholder="Parent Name" required autocomplete="off">
                            <label class="floating-label">Parent / Guardian Name</label>
                        </div>
                    </div>

                    <div class="form-group stagger-4">
                        <div class="input-wrapper">
                            <i class="fa-solid fa-envelope-open-text input-icon"></i>
                            <input type="email" name="parent_email" class="form-control-custom" placeholder="Parent Email" required autocomplete="off">
                            <label class="floating-label">Parent Email Address</label>
                        </div>
                    </div>

                    <div class="btn-wrapper">
                        <button type="submit" class="btn-primary" id="submitBtn">
                            Submit Request <i class="fa-solid fa-arrow-right"></i>
                        </button>
                    </div>

                    <div class="helper-links">
                        <a href="login.php">Already have an account? <strong>Login here</strong></a>
                    </div>
                </form>
            </div>

        </div>
    </div>

    <!-- Pass PHP Errors/Success to JS -->
    <?php if (!empty($error)): ?>
        <div id="phpError" style="display:none;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div id="phpSuccess" style="display:none;"><?php echo htmlspecialchars($success); ?></div>
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

        // 2. BOUNCY POPUPS FOR ERRORS & SUCCESS
        const phpError = document.getElementById('phpError');
        const phpSuccess = document.getElementById('phpSuccess');

        if (phpError) triggerPopup(phpError.innerText, 'error');
        if (phpSuccess) triggerPopup(phpSuccess.innerText, 'success');

        function triggerPopup(message, type) {
            const container = document.getElementById('popup-container');
            const toast = document.createElement('div');
            toast.className = `super-toast ${type === 'error' ? 'toast-error' : 'toast-success'}`;
            
            const iconClass = type === 'error' ? 'fa-triangle-exclamation fa-beat-fade' : 'fa-circle-check fa-bounce';
            toast.innerHTML = `<i class="fa-solid ${iconClass}"></i> <span>${message}</span>`;
            
            container.appendChild(toast);
            
            setTimeout(() => {
                toast.style.transition = 'all 0.4s ease';
                toast.style.transform = 'scale(0.8) translateY(-30px)';
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 400);
            }, type === 'error' ? 5000 : 7000); // Success message stays a bit longer
        }

        // 3. MAGNETIC BUTTON WITH RIPPLE EXPLOSION
        const btn = document.getElementById('submitBtn');
        
        btn.addEventListener('mousemove', (e) => {
            const rect = btn.getBoundingClientRect();
            const x = e.clientX - rect.left - rect.width / 2;
            const y = e.clientY - rect.top - rect.height / 2;
            btn.style.transform = `translate(${x * 0.08}px, ${y * 0.08}px)`; // Slightly less magnetic pull due to wide form
        });

        btn.addEventListener('mouseleave', () => {
            btn.style.transform = `translate(0px, 0px)`;
        });

        btn.addEventListener('click', function(e) {
            // Only trigger loading animation if the form is valid to prevent trapping the user
            if (document.getElementById('registerForm').checkValidity()) {
                const circle = document.createElement('span');
                circle.classList.add('ripple');
                
                const rect = btn.getBoundingClientRect();
                const diameter = Math.max(rect.width, rect.height);
                circle.style.width = circle.style.height = `${diameter}px`;
                circle.style.left = `${e.clientX - rect.left - diameter / 2}px`;
                circle.style.top = `${e.clientY - rect.top - diameter / 2}px`;
                
                btn.appendChild(circle);
                setTimeout(() => circle.remove(), 500);

                setTimeout(() => {
                    btn.style.pointerEvents = 'none';
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="font-size: 1.2rem;"></i>';
                    btn.style.background = 'var(--blue-deep)';
                    btn.style.boxShadow = 'none';
                }, 100);
            }
        });
    });
    </script>
</body>
</html>