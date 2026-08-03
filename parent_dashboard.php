<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-caching headers to prevent browser back-button access after logout
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/config/db.php';

// Check authorization
$role = strtolower($_SESSION['role'] ?? '');
if (empty($_SESSION['user_id']) || !in_array($role, ['parent', 'admin'])) {
    header('Location: auth/login.php');
    exit;
}

$parent_user_id = (int)$_SESSION['user_id'];

// 1. Fetch Parent Account Info
$stmtP = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmtP->execute([$parent_user_id]);
$parentUser = $stmtP->fetch(PDO::FETCH_ASSOC);

$parentName = $parentUser['name'] ?? $_SESSION['full_name'] ?? 'Parent / Guardian';
$parentEmail = $parentUser['email'] ?? '';
$ward_prn = trim($parentUser['linked_student_prn'] ?? '');

// Fallback lookup via access_requests if linked_student_prn is empty
if (empty($ward_prn) && !empty($parentEmail)) {
    $stmtReq = $pdo->prepare("SELECT prn_number FROM access_requests WHERE LOWER(parent_email) = ? AND status = 'APPROVED' ORDER BY request_id DESC LIMIT 1");
    $stmtReq->execute([strtolower($parentEmail)]);
    $ward_prn = $stmtReq->fetchColumn() ?: '';
}

// 2. Fetch Ward (Student) Details
$wardStudent = null;
$wardSubmissions = [];
$wardActivities = [];

if (!empty($ward_prn)) {
    $stmtWard = $pdo->prepare("
        SELECT u.user_id, u.name AS student_name, u.email AS student_email, u.username AS prn, u.department, u.academic_year, u.division,
               st.student_id, COALESCE(NULLIF(st.roll_no, ''), NULLIF(u.roll_no, ''), 'N/A') AS roll_no
        FROM users u
        LEFT JOIN students st ON st.user_id = u.user_id
        WHERE (UPPER(u.username) = UPPER(?) OR UPPER(u.linked_student_prn) = UPPER(?)) AND LOWER(u.role) = 'student'
        LIMIT 1
    ");
    $stmtWard->execute([$ward_prn, $ward_prn]);
    $wardStudent = $stmtWard->fetch(PDO::FETCH_ASSOC);
}

if ($wardStudent) {
    $student_user_id = (int)$wardStudent['user_id'];
    $student_table_id = (int)($wardStudent['student_id'] ?? 0);

    // Fetch Ward's Submissions & Scores
    $stmtSub = $pdo->prepare("
        SELECT s.*, a.title AS activity_title, a.type AS activity_type, a.max_marks, a.due_date,
               u_fac.name AS faculty_name
        FROM submissions s
        JOIN activities a ON s.activity_id = a.activity_id
        LEFT JOIN users u_fac ON a.faculty_id = u_fac.user_id
        WHERE s.student_id = ? OR s.student_id = ?
        ORDER BY s.submission_date DESC
    ");
    $stmtSub->execute([$student_user_id, $student_table_id]);
    $wardSubmissions = $stmtSub->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Fetch Ward's Classes
    $stmtClassIds = $pdo->prepare("
        SELECT class_id FROM faculty_classes WHERE department = ? AND academic_year = ? AND division = ?
    ");
    $stmtClassIds->execute([$wardStudent['department'] ?? '', $wardStudent['academic_year'] ?? 'FY', $wardStudent['division'] ?? '']);
    $class_ids = $stmtClassIds->fetchAll(PDO::FETCH_COLUMN) ?: [];

    // Query Ward's Activities
    if (!empty($class_ids)) {
        $inClause = implode(',', array_map('intval', $class_ids));
        $stmtActs = $pdo->prepare("
            SELECT a.*, u.name AS faculty_name, fc.class_name
            FROM activities a
            LEFT JOIN users u ON a.faculty_id = u.user_id
            LEFT JOIN faculty_classes fc ON a.target_id = fc.class_id
            WHERE a.target_type = 'all' OR (a.target_type = 'class' AND a.target_id IN ($inClause))
            ORDER BY a.due_date DESC
        ");
        $stmtActs->execute();
        $wardActivities = $stmtActs->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $stmtActs = $pdo->prepare("
            SELECT a.*, u.name AS faculty_name, 'All Students' AS class_name
            FROM activities a
            LEFT JOIN users u ON a.faculty_id = u.user_id
            WHERE a.target_type = 'all'
            ORDER BY a.due_date DESC
        ");
        $stmtActs->execute();
        $wardActivities = $stmtActs->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

// Compute statistics
$totalAssigned = count($wardActivities);
$totalSubmitted = count($wardSubmissions);
$totalPending = max(0, $totalAssigned - $totalSubmitted);

$totalObtained = 0;
$totalPossible = 0;
foreach ($wardSubmissions as $sub) {
    $totalObtained += ($sub['marks'] !== null) ? (float)$sub['marks'] : (float)$sub['max_marks'];
    $totalPossible += (float)$sub['max_marks'];
}
$avgPercentage = ($totalPossible > 0) ? round(($totalObtained / $totalPossible) * 100, 1) : 0;

// 3. Generate Dynamic Notifications for Parent
$notifications = [];
if ($wardStudent) {
    // Submissions evaluated
    foreach ($wardSubmissions as $sub) {
        $score = ($sub['marks'] !== null) ? $sub['marks'] : $sub['max_marks'];
        $notifications[] = [
            'id' => 'sub_' . $sub['id'],
            'title' => 'Submission Graded',
            'message' => htmlspecialchars($sub['activity_title']) . ' evaluated: ' . $score . ' / ' . $sub['max_marks'] . ' marks.',
            'type' => 'success',
            'time' => date('M d, g:i A', strtotime($sub['submission_date'])),
            'read' => false
        ];
    }

    // Pending activities
    $submitted_act_ids = array_column($wardSubmissions, 'activity_id');
    foreach ($wardActivities as $act) {
        if (!in_array($act['activity_id'], $submitted_act_ids)) {
            $notifications[] = [
                'id' => 'act_' . $act['activity_id'],
                'title' => 'Pending Activity Due',
                'message' => htmlspecialchars($act['title']) . ' is due on ' . date('M d, Y', strtotime($act['due_date'])),
                'type' => 'warning',
                'time' => 'Action Needed',
                'read' => false
            ];
        }
    }
} else {
    $notifications[] = [
        'id' => 'alert_no_ward',
        'title' => 'Ward Account Unlinked',
        'message' => 'Please contact college administration to link your student account.',
        'type' => 'danger',
        'time' => 'Attention Required',
        'read' => false
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Portal | College Student Activity Assessment System (SAAES)</title>
    
    <!-- Google Fonts: Poppins & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- PDF, Excel & Chart.js Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* ==========================================================================
           CORPORATE SAAS UNIVERSITY ERP PARENT DASHBOARD SYSTEM
           ========================================================================== */
        :root {
            --primary: #2563EB;
            --secondary: #1E40AF;
            --success: #22C55E;
            --warning: #F59E0B;
            --danger: #EF4444;
            --purple: #7C3AED;
            
            --bg-main: #F8FAFC;
            --bg-card: #FFFFFF;
            --text-main: #1E293B;
            --text-muted: #64748B;
            --border-color: #E5E7EB;
            
            --radius-btn: 12px;
            --radius-card: 18px;
            --radius-hero: 24px;
            
            --shadow-soft: 0 4px 20px rgba(15, 23, 42, 0.04);
            --shadow-card: 0 10px 30px -5px rgba(15, 23, 42, 0.04), 0 4px 12px -2px rgba(15, 23, 42, 0.02);
            --shadow-hover: 0 20px 35px -10px rgba(37, 99, 235, 0.16);
            --shadow-hero: 0 20px 40px -12px rgba(37, 99, 235, 0.35);
            
            --font-main: 'Inter', sans-serif;
            --font-heading: 'Poppins', sans-serif;
            --transition-smooth: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        html {
            scroll-behavior: smooth;
        }

        body {
            background-color: var(--bg-main);
            color: var(--text-main);
            font-family: var(--font-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        ::selection { background: var(--primary); color: #fff; }
        a { text-decoration: none; color: inherit; }

        .app-container { display: flex; min-height: 100vh; width: 100%; position: relative; }

        /* ================= SIDEBAR & MOBILE OVERLAY ================= */
        .sidebar-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.48);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 250;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition-smooth);
        }
        
        .sidebar-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .sidebar {
            width: 275px;
            background: var(--bg-card);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 300;
            box-shadow: var(--shadow-soft);
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1), transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-header {
            padding: 1.5rem 1.35rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.85rem;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 1.22rem;
            color: var(--secondary);
            letter-spacing: -0.01em;
            overflow: hidden;
        }

        .brand-logo-icon {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #fff;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.28);
            flex-shrink: 0;
        }

        .sidebar-collapse-btn {
            background: var(--bg-main);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition-smooth);
            font-size: 0.85rem;
            flex-shrink: 0;
        }

        .sidebar-collapse-btn:hover {
            background: #EFF6FF;
            color: var(--primary);
            border-color: var(--primary);
        }

        .sidebar-menu {
            padding: 1.25rem 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            flex: 1;
            overflow-y: auto;
        }

        .menu-label {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin: 1.25rem 0.5rem 0.4rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            white-space: nowrap;
        }

        .menu-label:first-child {
            margin-top: 0.25rem;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 0.75rem 1.1rem;
            color: var(--text-muted);
            font-weight: 500;
            font-size: 0.9rem;
            border-radius: var(--radius-btn);
            transition: var(--transition-smooth);
            white-space: nowrap;
            overflow: hidden;
        }

        .sidebar-link i {
            font-size: 1.15rem;
            width: 22px;
            text-align: center;
            color: var(--text-muted);
            transition: var(--transition-smooth);
            flex-shrink: 0;
        }

        .sidebar-link:hover {
            background: #EFF6FF;
            color: var(--primary);
            transform: translateX(4px);
        }

        .sidebar-link:hover i {
            color: var(--primary);
        }

        .sidebar-link.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #FFFFFF;
            font-weight: 600;
            box-shadow: 0 6px 18px rgba(37, 99, 235, 0.25);
        }

        .sidebar-link.active i {
            color: #FFFFFF;
        }

        .sidebar-link.logout-btn {
            color: var(--danger);
            background: rgba(239, 68, 68, 0.08);
            margin-top: 0.75rem;
            border: 1px solid rgba(239, 68, 68, 0.15);
        }

        .sidebar-link.logout-btn i {
            color: var(--danger);
        }

        .sidebar-link.logout-btn:hover {
            background: var(--danger);
            color: #FFFFFF;
            transform: translateX(4px);
            box-shadow: 0 6px 16px rgba(239, 68, 68, 0.28);
        }

        .sidebar-link.logout-btn:hover i {
            color: #FFFFFF;
        }

        /* ================= DESKTOP COLLAPSIBLE SIDEBAR MODE ================= */
        @media (min-width: 1025px) {
            .sidebar {
                transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            }

            .sidebar.collapsed {
                transform: translateX(-100%);
                visibility: hidden;
                pointer-events: none;
            }

            .content-wrapper.collapsed {
                margin-left: 0 !important;
            }
        }

        /* ================= CONTENT WRAPPER ================= */
        .content-wrapper {
            margin-left: 275px;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* ================= TOP NAVBAR HEADER ================= */
        .top-navbar {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-color);
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            height: 76px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        }

        .navbar-left {
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }

        .mobile-toggle-btn {
            display: flex;
            background: var(--bg-main);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            width: 42px;
            height: 42px;
            border-radius: 12px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.15rem;
            transition: var(--transition-smooth);
        }

        .mobile-toggle-btn:hover {
            background: #EFF6FF;
            color: var(--primary);
            border-color: var(--primary);
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            position: relative;
        }

        .clock-widget {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--text-muted);
            background: var(--bg-main);
            padding: 0.5rem 1rem;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 0.55rem;
        }

        .clock-widget i {
            color: var(--primary);
        }

        /* ================= NOTIFICATION BELL & DROPDOWN PANEL ================= */
        .notif-container {
            position: relative;
        }

        .notif-bell-btn {
            position: relative;
            background: var(--bg-main);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition-smooth);
        }

        .notif-bell-btn:hover {
            background: #EFF6FF;
            color: var(--primary);
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .bell-dot {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 8px;
            height: 8px;
            background: var(--danger);
            border-radius: 50%;
            border: 2px solid var(--bg-card);
        }

        .notif-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: var(--danger);
            color: #FFFFFF;
            font-size: 0.68rem;
            font-weight: 700;
            min-width: 18px;
            height: 18px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
            border: 2px solid var(--bg-card);
            box-shadow: 0 2px 6px rgba(239, 68, 68, 0.4);
        }

        .notif-dropdown {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 360px;
            max-width: 90vw;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            box-shadow: 0 16px 36px -8px rgba(15, 23, 42, 0.16);
            z-index: 500;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: var(--transition-smooth);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .notif-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .notif-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #FAFAFA;
        }

        .notif-title-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .notif-title {
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 0.95rem;
            color: var(--text-main);
        }

        .notif-unread-tag {
            font-size: 0.72rem;
            font-weight: 700;
            background: #EFF6FF;
            color: var(--primary);
            padding: 0.15rem 0.55rem;
            border-radius: 999px;
        }

        .notif-mark-all-btn {
            background: transparent;
            border: none;
            color: var(--primary);
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition-smooth);
        }

        .notif-mark-all-btn:hover {
            text-decoration: underline;
            color: var(--secondary);
        }

        .notif-list {
            max-height: 340px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .notif-item {
            padding: 0.9rem 1.15rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            gap: 0.85rem;
            align-items: flex-start;
            transition: var(--transition-smooth);
            cursor: pointer;
            position: relative;
        }

        .notif-item:last-child {
            border-bottom: none;
        }

        .notif-item:hover {
            background: #F8FAFC;
        }

        .notif-item.unread {
            background: #EFF6FF;
        }

        .notif-item.unread:hover {
            background: #E0EDFF;
        }

        .notif-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            flex-shrink: 0;
        }

        .notif-icon.info { background: #E0F2FE; color: #0284C7; }
        .notif-icon.success { background: #DCFCE7; color: var(--success); }
        .notif-icon.warning { background: #FEF3C7; color: #D97706; }
        .notif-icon.danger { background: #FEE2E2; color: var(--danger); }

        .notif-body {
            flex: 1;
        }

        .notif-item-title {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--text-main);
            margin-bottom: 0.15rem;
            line-height: 1.3;
        }

        .notif-item-desc {
            font-size: 0.78rem;
            color: var(--text-muted);
            line-height: 1.4;
            margin-bottom: 0.25rem;
        }

        .notif-item-time {
            font-size: 0.7rem;
            color: #94A3B8;
            font-weight: 500;
        }

        .notif-item-unread-dot {
            width: 8px;
            height: 8px;
            background: var(--primary);
            border-radius: 50%;
            margin-top: 6px;
            flex-shrink: 0;
        }

        .notif-empty-state {
            padding: 2.5rem 1.5rem;
            text-align: center;
            color: var(--text-muted);
        }

        .notif-empty-icon {
            font-size: 2.2rem;
            color: #CBD5E1;
            margin-bottom: 0.5rem;
        }

        .notif-footer {
            padding: 0.65rem 1rem;
            background: #F8FAFC;
            border-top: 1px solid var(--border-color);
            text-align: center;
        }

        .notif-footer-btn {
            font-size: 0.78rem;
            color: var(--text-muted);
            font-weight: 600;
            background: transparent;
            border: none;
            cursor: pointer;
            transition: var(--transition-smooth);
        }

        .notif-footer-btn:hover {
            color: var(--primary);
        }

        /* ================= RIGHT-SIDE PROFILE BAR & DROPDOWN ================= */
        .profile-dropdown-container {
            position: relative;
        }

        .parent-profile-bar {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            cursor: pointer;
            padding: 0.4rem 0.75rem;
            border-radius: 14px;
            background: var(--bg-main);
            border: 1px solid var(--border-color);
            transition: var(--transition-smooth);
            outline: none;
        }

        .parent-profile-bar:hover {
            background: #EFF6FF;
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .parent-avatar-sm {
            width: 38px;
            height: 38px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #FFFFFF;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.95rem;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.22);
            flex-shrink: 0;
        }

        .parent-profile-meta {
            display: flex;
            flex-direction: column;
            text-align: left;
        }

        .parent-name-txt {
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 0.88rem;
            color: var(--text-main);
            line-height: 1.2;
        }

        .parent-role-txt {
            font-size: 0.72rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .dropdown-chevron {
            font-size: 0.78rem;
            color: var(--text-muted);
            transition: var(--transition-smooth);
            margin-left: 0.15rem;
        }

        .parent-profile-bar[aria-expanded="true"] .dropdown-chevron {
            transform: rotate(180deg);
            color: var(--primary);
        }

        .profile-dropdown-menu {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 280px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            box-shadow: 0 16px 36px -8px rgba(15, 23, 42, 0.16);
            z-index: 500;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: var(--transition-smooth);
            overflow: hidden;
        }

        .profile-dropdown-menu.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .profile-dropdown-header {
            padding: 1.15rem;
            background: #FAFAFA;
            display: flex;
            align-items: center;
            gap: 0.85rem;
        }

        .profile-dropdown-avatar {
            width: 46px;
            height: 46px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #FFFFFF;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.15rem;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
            flex-shrink: 0;
        }

        .profile-dropdown-info {
            overflow: hidden;
        }

        .profile-dropdown-name {
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 0.92rem;
            color: var(--text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .profile-dropdown-email {
            font-size: 0.75rem;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .profile-dropdown-divider {
            height: 1px;
            background: var(--border-color);
        }

        .profile-dropdown-body {
            padding: 0.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }

        .profile-dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.65rem 0.85rem;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-main);
            border-radius: 10px;
            transition: var(--transition-smooth);
            text-decoration: none;
            cursor: pointer;
        }

        .profile-dropdown-item i {
            font-size: 0.95rem;
            width: 18px;
            text-align: center;
            color: var(--text-muted);
            transition: var(--transition-smooth);
        }

        .profile-dropdown-item:hover {
            background: #EFF6FF;
            color: var(--primary);
        }

        .profile-dropdown-item:hover i {
            color: var(--primary);
        }

        .profile-dropdown-item.danger {
            color: var(--danger);
        }

        .profile-dropdown-item.danger i {
            color: var(--danger);
        }

        .profile-dropdown-item.danger:hover {
            background: rgba(239, 68, 68, 0.08);
            color: var(--danger);
        }

        .ward-info-item {
            background: var(--bg-main);
            cursor: default;
        }

        /* ================= MAIN CONTENT AREA ================= */
        .main-content {
            padding: 2rem;
            flex: 1;
            max-width: 1440px;
            width: 100%;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 1.75rem;
            animation: fadeInUp 0.45s ease-out;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ================= MODULE CARDS ================= */
        .module-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: 1.75rem 2rem;
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            transition: var(--transition-smooth);
        }

        .module-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .module-title {
            font-family: var(--font-heading);
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }

        .module-controls {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .select-filter-ui {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: var(--bg-main);
            font-family: var(--font-main);
            font-size: 0.85rem;
            color: var(--text-main);
            outline: none;
            cursor: pointer;
            transition: var(--transition-smooth);
        }

        .select-filter-ui:focus {
            border-color: var(--primary);
            background: #FFFFFF;
        }

        /* ================= MAIN STUDENT HERO BANNER ================= */
        .hero-banner {
            background: linear-gradient(135deg, #2563EB 0%, #1E40AF 100%);
            color: #FFFFFF;
            padding: 2.5rem 2.75rem;
            border-radius: var(--radius-hero);
            box-shadow: var(--shadow-hero);
            position: relative;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }

        .hero-banner::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -30px;
            width: 360px;
            height: 360px;
            background: radial-gradient(circle, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .hero-banner::after {
            content: '';
            position: absolute;
            bottom: -60px;
            left: -40px;
            width: 280px;
            height: 280px;
            background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .hero-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.75rem;
            position: relative;
            z-index: 2;
        }

        .hero-student-meta {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .student-avatar-hero {
            width: 76px;
            height: 76px;
            background: rgba(255, 255, 255, 0.22);
            border: 3px solid rgba(255, 255, 255, 0.45);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--font-heading);
            font-size: 2.1rem;
            font-weight: 800;
            color: #FFFFFF;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.16);
            backdrop-filter: blur(10px);
            flex-shrink: 0;
        }

        .hero-info-block {
            display: flex;
            flex-direction: column;
            gap: 0.55rem;
        }

        .hero-title-row {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            flex-wrap: wrap;
        }

        .hero-title {
            font-family: var(--font-heading);
            font-size: 1.95rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #FFFFFF;
        }

        .hero-status-badge {
            background: rgba(34, 197, 94, 0.25);
            border: 1px solid rgba(34, 197, 94, 0.5);
            color: #DCFCE7;
            padding: 0.25rem 0.8rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        .hero-badges-wrapper {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.65rem;
        }

        .hero-badge {
            background: rgba(255, 255, 255, 0.16);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.28);
            padding: 0.45rem 1rem;
            border-radius: 999px;
            font-weight: 500;
            font-size: 0.85rem;
            color: #FFFFFF;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .hero-badge strong {
            font-weight: 700;
        }

        .hero-actions {
            display: flex;
            gap: 0.85rem;
        }

        /* ================= BUTTONS ================= */
        .btn {
            font-family: var(--font-main);
            font-weight: 600;
            font-size: 0.88rem;
            padding: 0.7rem 1.4rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            border-radius: var(--radius-btn);
            border: 1px solid transparent;
            cursor: pointer;
            transition: var(--transition-smooth);
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .btn-primary {
            background: var(--primary);
            color: #FFFFFF;
        }

        .btn-primary:hover {
            background: var(--secondary);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.35);
        }

        .btn-danger {
            background: var(--danger);
            color: #FFFFFF;
        }

        .btn-danger:hover {
            background: #DC2626;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.35);
        }

        .btn-hero-outline {
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.38);
            color: #FFFFFF;
            backdrop-filter: blur(10px);
        }

        .btn-hero-outline:hover {
            background: rgba(255, 255, 255, 0.28);
            transform: translateY(-3px);
            color: #FFFFFF;
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.18);
        }

        .btn-hero-solid {
            background: #FFFFFF;
            color: var(--secondary);
            font-weight: 700;
        }

        .btn-hero-solid:hover {
            background: #F8FAFC;
            color: var(--primary);
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        }

        /* ================= STATISTICS CARDS ================= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .stat-block {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-card);
            padding: 1.6rem 1.75rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 165px;
            box-shadow: var(--shadow-card);
            transition: var(--transition-smooth);
            position: relative;
            overflow: hidden;
        }

        .stat-block:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-hover);
            border-color: rgba(37, 99, 235, 0.25);
        }

        /* Subtle Card Accents */
        .stat-block.card-blue { border-top: 4px solid var(--primary); }
        .stat-block.card-green { border-top: 4px solid var(--success); }
        .stat-block.card-red { border-top: 4px solid var(--danger); }
        .stat-block.card-purple { border-top: 4px solid var(--purple); }

        .stat-block-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.85rem;
        }

        .stat-label {
            font-family: var(--font-heading);
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-icon-badge {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
        }

        .icon-blue-bg { background: #EFF6FF; color: var(--primary); }
        .icon-green-bg { background: #DCFCE7; color: var(--success); }
        .icon-red-bg { background: #FEE2E2; color: var(--danger); }
        .icon-purple-bg { background: #F3E8FF; color: var(--purple); }

        .stat-val {
            font-family: var(--font-heading);
            font-size: 2.35rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 0.4rem;
            letter-spacing: -0.03em;
        }

        .val-blue { color: var(--primary); }
        .val-green { color: var(--success); }
        .val-red { color: var(--danger); }
        .val-purple { color: var(--purple); }

        .stat-trend {
            font-size: 0.78rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 0.25rem;
        }

        .trend-blue { color: var(--primary); }
        .trend-green { color: var(--success); }
        .trend-red { color: var(--danger); }
        .trend-purple { color: var(--purple); }

        .stat-progress-bar {
            width: 100%;
            height: 6px;
            background: var(--bg-main);
            border-radius: 999px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .stat-progress-fill {
            height: 100%;
            border-radius: 999px;
        }

        .fill-blue { background: var(--primary); width: 100%; }
        .fill-green { background: var(--success); width: 85%; }
        .fill-red { background: var(--danger); width: 35%; }
        .fill-purple { background: var(--purple); width: 92%; }

        /* ================= TAGS / BADGES ================= */
        .sys-tag {
            font-size: 0.78rem;
            font-weight: 600;
            padding: 0.35rem 0.85rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .sys-tag.accent { background: #EFF6FF; color: var(--primary); border: 1px solid rgba(37, 99, 235, 0.15); }
        .sys-tag.success { background: #DCFCE7; color: var(--success); border: 1px solid rgba(34, 197, 94, 0.2); }
        .sys-tag.danger { background: #FEE2E2; color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.2); }
        .sys-tag.warning { background: #FEF3C7; color: #D97706; border: 1px solid rgba(245, 158, 11, 0.2); }
        .sys-tag.info { background: #E0F2FE; color: #0284C7; border: 1px solid rgba(2, 132, 199, 0.2); }

        /* ================= SUBMISSION TABLES ================= */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid var(--border-color);
            border-radius: 16px;
            margin-bottom: 1rem;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.02);
        }

        .custom-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            background: var(--bg-card);
        }

        .custom-table th {
            position: sticky;
            top: 0;
            background: #F8FAFC;
            color: var(--text-muted);
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            padding: 1.15rem 1.5rem;
            border-bottom: 2px solid var(--border-color);
            z-index: 5;
        }

        .custom-table td {
            padding: 1.15rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.92rem;
            vertical-align: middle;
        }

        .custom-table tbody tr:nth-child(even) { background-color: #FAFAFA; }
        .custom-table tbody tr:hover { background-color: #F1F5F9; }
        .custom-table tbody tr:last-child td { border-bottom: none; }

        .faculty-pill {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .faculty-avatar-sm {
            width: 34px;
            height: 34px;
            background: #E0F2FE;
            color: #0284C7;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.82rem;
            flex-shrink: 0;
        }

        .action-dots-btn {
            background: transparent;
            border: none;
            color: var(--text-muted);
            width: 32px;
            height: 32px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition-smooth);
        }

        .action-dots-btn:hover {
            background: #EFF6FF;
            color: var(--primary);
        }

        .table-pagination-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 0.5rem;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .pagination-btns {
            display: flex;
            gap: 0.4rem;
        }

        .page-btn {
            padding: 0.35rem 0.75rem;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            border-radius: 8px;
            font-size: 0.8rem;
            color: var(--text-muted);
            cursor: pointer;
        }

        .page-btn.active {
            background: var(--primary);
            color: #FFFFFF;
            border-color: var(--primary);
        }

        /* ================= VISUAL TAB NAVIGATION SWITCHER ================= */
        .dash-tab-navigation {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--bg-card);
            padding: 0.45rem;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-soft);
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin-bottom: 1.25rem;
        }

        .dash-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.65rem 1.15rem;
            border-radius: 12px;
            font-family: var(--font-heading);
            font-size: 0.86rem;
            font-weight: 600;
            color: var(--text-muted);
            background: transparent;
            border: none;
            cursor: pointer;
            transition: var(--transition-smooth);
            white-space: nowrap;
        }

        .dash-tab-btn:hover {
            color: var(--primary);
            background: #EFF6FF;
        }

        .dash-tab-btn.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: #FFFFFF;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.28);
        }

        /* ================= CHARTS GRID & DATA VISUALIZATIONS ================= */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .chart-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-card);
            padding: 1.5rem;
            box-shadow: var(--shadow-card);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .chart-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.25rem;
        }

        .chart-card-title {
            font-family: var(--font-heading);
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chart-container {
            position: relative;
            height: 240px;
            width: 100%;
        }

        /* ================= ACTIVITY ROADMAP TIMELINE ================= */
        .timeline-wrapper {
            position: relative;
            padding-left: 2rem;
            margin-top: 0.75rem;
        }

        .timeline-wrapper::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 12px;
            width: 3px;
            background: linear-gradient(to bottom, var(--primary), var(--border-color));
            border-radius: 999px;
        }

        .timeline-item {
            position: relative;
            margin-bottom: 1.75rem;
        }

        .timeline-item:last-child {
            margin-bottom: 0;
        }

        .timeline-node {
            position: absolute;
            left: -2rem;
            top: 4px;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            color: #FFFFFF;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
            z-index: 2;
        }

        .timeline-node.success { background: var(--success); }
        .timeline-node.warning { background: var(--warning); }
        .timeline-node.danger { background: var(--danger); }

        .timeline-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 1.15rem 1.35rem;
            box-shadow: var(--shadow-soft);
            transition: var(--transition-smooth);
        }

        .timeline-card:hover {
            transform: translateX(4px);
            border-color: rgba(37, 99, 235, 0.3);
            box-shadow: var(--shadow-card);
        }

        .timeline-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.4rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        /* ================= ACTIVITY CARDS GRID ================= */
        .activity-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
            margin-top: 1rem;
        }

        .act-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.35rem;
            box-shadow: var(--shadow-soft);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: var(--transition-smooth);
        }

        .act-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-hover);
            border-color: rgba(37, 99, 235, 0.3);
        }

        /* ================= MOBILE PHONE & TABLET RESPONSIVE MEDIA QUERIES ================= */
        @media (max-width: 1024px) {
            .mobile-toggle-btn { display: flex; }
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .content-wrapper {
                margin-left: 0 !important;
            }
            .top-navbar {
                padding: 0 1.25rem;
                gap: 0.5rem;
            }
            .main-content {
                padding: 1.25rem;
                gap: 1.25rem;
            }
            .sidebar-collapse-btn {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .hero-banner {
                padding: 1.75rem;
                border-radius: 18px;
            }
            .hero-student-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            .hero-title {
                font-size: 1.5rem;
            }
            .hero-content {
                flex-direction: column;
                align-items: flex-start;
            }
            .hero-actions {
                width: 100%;
            }
            .hero-actions .btn {
                flex: 1;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
            .stat-block {
                padding: 1.2rem;
                min-height: 145px;
            }
            .stat-val {
                font-size: 1.9rem;
            }
            .clock-widget {
                display: none;
            }
            .module-card {
                padding: 1.25rem;
            }
            .notif-dropdown {
                width: 320px;
                right: -10px;
            }
        }

        @media (max-width: 576px) {
            .top-navbar {
                padding: 0 0.75rem;
                gap: 0.5rem;
            }
            .navbar-left {
                gap: 0.5rem;
            }
            .navbar-right {
                gap: 0.65rem;
            }
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 0.85rem;
            }
            .hero-badges-wrapper {
                flex-direction: column;
                align-items: flex-start;
                width: 100%;
            }
            .hero-badge {
                width: 100%;
            }
            .custom-table th, .custom-table td {
                padding: 0.85rem 0.85rem;
                font-size: 0.82rem;
            }
            .notif-dropdown {
                width: 280px;
                max-width: calc(100vw - 20px);
                right: -40px;
            }
            .parent-profile-meta, .dropdown-chevron {
                display: none;
            }
            .parent-profile-bar {
                padding: 0;
                border: none;
                background: transparent;
            }
            .profile-dropdown-menu {
                right: 0;
                width: 260px;
                max-width: calc(100vw - 20px);
            }
        }

        @media (max-width: 380px) {
            .notif-dropdown {
                right: -60px;
                width: 260px;
            }
        }
    </style>
</head>
<body>

<div class="app-container">

    <!-- SIDEBAR OVERLAY FOR MOBILE DESK -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- LEFT SIDEBAR (COLLAPSIBLE ADMIN DASHBOARD STYLE) -->
    <aside class="sidebar" id="erpSidebar">
        <div class="sidebar-header">
            <a href="parent_dashboard.php" class="brand-logo">
                <div class="brand-logo-icon">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <span>Parent Portal</span>
            </a>
        </div>

        <div class="sidebar-menu">
            <div class="menu-label">Main Navigation</div>
            <a href="parent_dashboard.php" class="sidebar-link active" title="Dashboard Overview">
                <i class="fa-solid fa-house-chimney"></i>
                <span>Dashboard Overview</span>
            </a>

            <div class="menu-label">Account Action</div>
            <a href="auth/logout.php" class="sidebar-link logout-btn" title="Logout">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- CONTENT WRAPPER -->
    <div class="content-wrapper" id="contentWrapper">
        
        <!-- TOP NAVBAR HEADER -->
        <header class="top-navbar">
            <!-- Left: Mobile Toggle -->
            <div class="navbar-left">
                <button class="mobile-toggle-btn" id="sidebarToggle" aria-label="Toggle Menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>

            <!-- Right: Live Clock, Notification Bell, & Right-Side Profile Card -->
            <div class="navbar-right">
                <div class="clock-widget">
                    <i class="fa-regular fa-calendar-check"></i>
                    <span id="clock">00:00:00</span>
                </div>

                <!-- NOTIFICATION CONTAINER & DROPDOWN PANEL -->
                <div class="notif-container">
                    <button class="notif-bell-btn" id="notifBellBtn" title="Notifications" aria-label="Toggle notifications" aria-expanded="false">
                        <i class="fa-regular fa-bell"></i>
                        <span class="bell-dot" id="notifBellDot"></span>
                        <span class="notif-badge" id="notifCountBadge" style="display: none;">0</span>
                    </button>

                    <div class="notif-dropdown" id="notifDropdown" role="menu" aria-label="Notifications Panel">
                        <div class="notif-header">
                            <div class="notif-title-group">
                                <span class="notif-title">Notifications</span>
                                <span class="notif-unread-tag" id="notifUnreadTag">0 Unread</span>
                            </div>
                            <button class="notif-mark-all-btn" onclick="markAllNotifsAsRead()" title="Mark all notifications as read">Mark all read</button>
                        </div>
                        <div class="notif-list" id="notifList">
                            <!-- Populated dynamically by JavaScript -->
                        </div>
                        <div class="notif-footer">
                            <button class="notif-footer-btn" onclick="clearAllNotifs()">Clear All Notifications</button>
                        </div>
                    </div>
                </div>

                <!-- RIGHT-SIDE PROFILE BAR & DROPDOWN MENU -->
                <div class="profile-dropdown-container">
                    <button class="parent-profile-bar" id="profileDropdownBtn" aria-label="User profile menu" aria-expanded="false">
                        <div class="parent-avatar-sm">
                            <?php echo strtoupper(substr($parentName, 0, 1)); ?>
                        </div>
                        <div class="parent-profile-meta">
                            <span class="parent-name-txt"><?php echo htmlspecialchars($parentName); ?></span>
                            <span class="parent-role-txt">Parent / Guardian</span>
                        </div>
                        <i class="fa-solid fa-chevron-down dropdown-chevron" id="profileChevron"></i>
                    </button>

                    <!-- PROFILE DROPDOWN MENU -->
                    <div class="profile-dropdown-menu" id="profileDropdownMenu" role="menu" aria-label="User Profile Dropdown">
                        <div class="profile-dropdown-header">
                            <div class="profile-dropdown-avatar">
                                <?php echo strtoupper(substr($parentName, 0, 1)); ?>
                            </div>
                            <div class="profile-dropdown-info">
                                <div class="profile-dropdown-name"><?php echo htmlspecialchars($parentName); ?></div>
                                <?php if (!empty($parentEmail)): ?>
                                    <div class="profile-dropdown-email"><?php echo htmlspecialchars($parentEmail); ?></div>
                                <?php endif; ?>
                                <span class="sys-tag accent" style="font-size: 0.68rem; margin-top: 0.25rem;">Parent Account</span>
                            </div>
                        </div>
                        <div class="profile-dropdown-divider"></div>
                        <div class="profile-dropdown-body">
                            <?php if ($wardStudent): ?>
                                <div class="profile-dropdown-item ward-info-item">
                                    <i class="fa-solid fa-graduation-cap"></i>
                                    <div>
                                        <div style="font-weight: 600; font-size: 0.82rem; color: var(--text-main);">Linked Ward</div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($wardStudent['student_name']); ?> (<?php echo htmlspecialchars($wardStudent['prn']); ?>)</div>
                                    </div>
                                </div>
                                <div class="profile-dropdown-divider"></div>
                            <?php endif; ?>
                            <a href="javascript:void(0)" class="profile-dropdown-item" onclick="exportPDF(); closeProfileDropdown();">
                                <i class="fa-solid fa-file-pdf"></i>
                                <span>Download PDF Report</span>
                            </a>
                            <div class="profile-dropdown-divider"></div>
                            <a href="auth/logout.php" class="profile-dropdown-item danger">
                                <i class="fa-solid fa-right-from-bracket"></i>
                                <span>Logout</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="main-content">

        <?php if (!$wardStudent): ?>
            <!-- UNLINKED WARD ALERT -->
            <div class="module-card" style="text-align: center; padding: 4rem 2rem;">
                <i class="fa-solid fa-user-slash" style="font-size: 3.5rem; color: var(--border-color); margin-bottom: 1rem;"></i>
                <h2 style="font-size: 1.75rem; font-weight: 700; margin-bottom: 0.5rem; color: var(--secondary);">No Student Linked to Account</h2>
                <p style="color: var(--text-muted); max-width: 600px; margin: 0 auto 1.5rem; font-size: 0.95rem;">
                    We could not find an approved student account matching your registered parent profile. Please contact college administration to link your student.
                </p>
                <?php if (!empty($parentEmail)): ?>
                    <p style="font-size: 0.85rem; color: var(--text-main);">Registered Parent Email: <strong><?php echo htmlspecialchars($parentEmail); ?></strong></p>
                <?php endif; ?>
            </div>
        <?php else: ?>

            <!-- MAIN STUDENT INFORMATION CARD (HERO BANNER) -->
            <div class="hero-banner">
                <div class="hero-content">
                    <div class="hero-student-meta">
                        <div class="student-avatar-hero">
                            <?php echo strtoupper(substr($wardStudent['student_name'] ?: 'S', 0, 1)); ?>
                        </div>
                        <div class="hero-info-block">
                            <div class="hero-title-row">
                                <h1 class="hero-title"><?php echo htmlspecialchars($wardStudent['student_name'] ?: 'Student Overview'); ?></h1>
                                <span class="hero-status-badge"><i class="fa-solid fa-circle-check"></i> Active Student</span>
                            </div>
                            <div class="hero-badges-wrapper">
                                <span class="hero-badge"><i class="fa-solid fa-id-card"></i> PRN: <strong><?php echo htmlspecialchars($wardStudent['prn']); ?></strong></span>
                                <span class="hero-badge"><i class="fa-solid fa-hashtag"></i> Roll No: <strong><?php echo htmlspecialchars($wardStudent['roll_no'] ?: 'N/A'); ?></strong></span>
                                <span class="hero-badge"><i class="fa-solid fa-building-columns"></i> Dept: <strong><?php echo htmlspecialchars($wardStudent['department'] ?: 'Engineering'); ?></strong></span>
                                <span class="hero-badge"><i class="fa-solid fa-graduation-cap"></i> Year: <strong><?php echo htmlspecialchars($wardStudent['academic_year'] ?: 'FY'); ?> (Div <?php echo htmlspecialchars($wardStudent['division'] ?: 'A'); ?>)</strong></span>
                                <span class="hero-badge"><i class="fa-solid fa-user-tie"></i> Guardian: <strong><?php echo htmlspecialchars($parentName); ?></strong></span>
                            </div>
                        </div>
                    </div>
                    <div class="hero-actions">
                        <button class="btn btn-hero-outline" onclick="exportPDF()">
                            <i class="fa-solid fa-file-pdf"></i> Export PDF
                        </button>
                        <button class="btn btn-hero-solid" onclick="exportExcel()">
                            <i class="fa-solid fa-file-excel"></i> Export Excel
                        </button>
                    </div>
                </div>
            </div>

            <div id="exportTable">
                <!-- VISUAL TAB SWITCHER BAR -->
                <div class="dash-tab-navigation">
                    <button class="dash-tab-btn active" id="tabBtn-overview" onclick="switchDashboardTab('overview')">
                        <i class="fa-solid fa-chart-pie"></i> Visual Analytics & Key Stats
                    </button>
                    <button class="dash-tab-btn" id="tabBtn-evaluations" onclick="switchDashboardTab('evaluations')">
                        <i class="fa-solid fa-clipboard-list"></i> Submissions & Evaluations
                    </button>
                    <button class="dash-tab-btn" id="tabBtn-activities" onclick="switchDashboardTab('activities')">
                        <i class="fa-solid fa-list-check"></i> Assigned Tasks & Cards
                    </button>
                    <button class="dash-tab-btn" id="tabBtn-timeline" onclick="switchDashboardTab('timeline')">
                        <i class="fa-solid fa-timeline"></i> Activity Timeline Roadmap
                    </button>
                </div>

                <!-- TAB CONTENT 1: VISUAL ANALYTICS & STATS -->
                <div class="dash-tab-content" id="tabContent-overview">
                    <!-- STATISTICS CARDS (4 COLUMNS) -->
                    <div class="stats-grid" id="statsGrid">
                        <!-- Assigned Activities -->
                        <div class="stat-block card-blue">
                            <div class="stat-block-top">
                                <span class="stat-label">📋 Assigned Activities</span>
                                <div class="stat-icon-badge icon-blue-bg">
                                    <i class="fa-solid fa-list-check"></i>
                                </div>
                            </div>
                            <div class="stat-val val-blue"><?php echo $totalAssigned; ?></div>
                            <div class="stat-trend trend-blue">
                                <span>Total Assigned Tasks</span>
                                <span>100%</span>
                            </div>
                            <div class="stat-progress-bar"><div class="stat-progress-fill fill-blue"></div></div>
                        </div>

                        <!-- Completed -->
                        <div class="stat-block card-green">
                            <div class="stat-block-top">
                                <span class="stat-label">✅ Completed</span>
                                <div class="stat-icon-badge icon-green-bg">
                                    <i class="fa-solid fa-circle-check"></i>
                                </div>
                            </div>
                            <div class="stat-val val-green"><?php echo $totalSubmitted; ?></div>
                            <div class="stat-trend trend-green">
                                <span>Evaluated & Submitted</span>
                                <span><?php echo ($totalAssigned > 0) ? round(($totalSubmitted / $totalAssigned) * 100) : 0; ?>%</span>
                            </div>
                            <div class="stat-progress-bar"><div class="stat-progress-fill fill-green"></div></div>
                        </div>

                        <!-- Pending -->
                        <div class="stat-block card-red">
                            <div class="stat-block-top">
                                <span class="stat-label">⏰ Pending</span>
                                <div class="stat-icon-badge icon-red-bg">
                                    <i class="fa-solid fa-clock"></i>
                                </div>
                            </div>
                            <div class="stat-val val-red"><?php echo $totalPending; ?></div>
                            <div class="stat-trend trend-red">
                                <span>Action Required</span>
                                <span><?php echo ($totalAssigned > 0) ? round(($totalPending / $totalAssigned) * 100) : 0; ?>%</span>
                            </div>
                            <div class="stat-progress-bar"><div class="stat-progress-fill fill-red"></div></div>
                        </div>

                        <!-- Avg Score -->
                        <div class="stat-block card-purple">
                            <div class="stat-block-top">
                                <span class="stat-label">📊 Average Score</span>
                                <div class="stat-icon-badge icon-purple-bg">
                                    <i class="fa-solid fa-chart-line"></i>
                                </div>
                            </div>
                            <div class="stat-val val-purple"><?php echo $avgPercentage; ?>%</div>
                            <div class="stat-trend trend-purple">
                                <span>Overall Rating</span>
                                <span>★ Top</span>
                            </div>
                            <div class="stat-progress-bar"><div class="stat-progress-fill fill-purple"></div></div>
                        </div>
                    </div>

                    <!-- VISUAL CHARTS GRID (DOUGHNUT & BAR CHARTS) -->
                    <div class="charts-grid">
                        <!-- Chart Card 1: Task Completion Breakdown (Doughnut) -->
                        <div class="chart-card">
                            <div class="chart-card-header">
                                <h4 class="chart-card-title">
                                    <i class="fa-solid fa-chart-pie" style="color: var(--primary);"></i> Completion Ratio Breakdown
                                </h4>
                                <span class="sys-tag accent"><?php echo $totalSubmitted; ?> / <?php echo $totalAssigned; ?> Tasks</span>
                            </div>
                            <div class="chart-container">
                                <canvas id="completionDoughnutChart"></canvas>
                            </div>
                        </div>

                        <!-- Chart Card 2: Marks Performance Trend (Bar Chart) -->
                        <div class="chart-card">
                            <div class="chart-card-header">
                                <h4 class="chart-card-title">
                                    <i class="fa-solid fa-chart-bar" style="color: var(--success);"></i> Marks Performance Trend (%)
                                </h4>
                                <span class="sys-tag success">Avg: <?php echo $avgPercentage; ?>%</span>
                            </div>
                            <div class="chart-container">
                                <canvas id="scoresBarChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB CONTENT 2: SUBMISSIONS & EVALUATIONS SHEET -->
                <div class="dash-tab-content" id="tabContent-evaluations" style="display: none;">
                    <div class="module-card" id="submissionsCard">
                        <div class="module-card-header">
                            <h3 class="module-title">
                                <i class="fa-solid fa-clipboard-list" style="color: var(--primary);"></i> Submissions & Evaluations
                            </h3>
                            <div class="module-controls">
                                <select class="select-filter-ui" id="statusFilterSelect" onchange="filterTables()">
                                    <option value="all">Filter: All Submissions</option>
                                    <option value="on time">On Time</option>
                                    <option value="late">Late</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="custom-table" id="submissionsTable">
                                <thead>
                                    <tr>
                                        <th>Activity Title</th>
                                        <th>Status</th>
                                        <th>Submission Date</th>
                                        <th>Faculty</th>
                                        <th>Score</th>
                                        <th style="width: 50px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($wardSubmissions)): ?>
                                        <tr>
                                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2.5rem; font-weight: 500;">
                                                No activity submissions recorded for your ward yet.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($wardSubmissions as $sub): 
                                            $is_late = !empty($sub['is_late']);
                                            $score = $sub['marks'] !== null ? $sub['marks'] : $sub['max_marks'];
                                        ?>
                                        <tr>
                                            <td>
                                                <strong style="font-size: 0.95rem; color: var(--text-main); display: block; margin-bottom: 0.25rem;"><?php echo htmlspecialchars($sub['activity_title']); ?></strong>
                                                <span class="sys-tag accent" style="font-size: 0.72rem;">Type: <?php echo htmlspecialchars(ucfirst($sub['activity_type'])); ?></span>
                                            </td>
                                            <td>
                                                <?php if ($is_late): ?>
                                                    <span class="sys-tag warning"><i class="fa-solid fa-clock"></i> Late</span>
                                                <?php else: ?>
                                                    <span class="sys-tag success"><i class="fa-solid fa-check"></i> On Time</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-size: 0.85rem; color: var(--text-muted);">
                                                <?php echo date('M d, Y h:i A', strtotime($sub['submission_date'])); ?>
                                            </td>
                                            <td>
                                                <div class="faculty-pill">
                                                    <div class="faculty-avatar-sm"><?php echo strtoupper(substr($sub['faculty_name'] ?: 'F', 0, 1)); ?></div>
                                                    <strong style="font-weight: 600; font-size: 0.9rem; color: var(--text-main);"><?php echo htmlspecialchars($sub['faculty_name'] ?: 'Faculty'); ?></strong>
                                                </div>
                                            </td>
                                            <td>
                                                <strong style="font-size: 1.15rem; color: var(--secondary);"><?php echo $score; ?></strong>
                                                <span style="font-size: 0.8rem; color: var(--text-muted);">/ <?php echo $sub['max_marks']; ?></span>
                                            </td>
                                            <td>
                                                <button class="action-dots-btn" title="Actions"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="table-pagination-footer">
                            <span>Showing evaluated submissions</span>
                            <div class="pagination-btns">
                                <button class="page-btn active">1</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB CONTENT 3: ASSIGNED ACTIVITIES (TABLE & CARDS) -->
                <div class="dash-tab-content" id="tabContent-activities" style="display: none;">
                    <div class="module-card" id="assignedActivitiesCard">
                        <div class="module-card-header">
                            <h3 class="module-title">
                                <i class="fa-solid fa-list-check" style="color: var(--primary);"></i> All Assigned Activities
                            </h3>
                        </div>

                        <!-- Card Grid Representation -->
                        <div class="activity-grid">
                            <?php if (empty($wardActivities)): ?>
                                <div style="grid-column: 1 / -1; text-align: center; color: var(--text-muted); padding: 2rem;">No activities assigned.</div>
                            <?php else: ?>
                                <?php 
                                $submitted_act_ids = array_column($wardSubmissions, 'activity_id');
                                foreach ($wardActivities as $act): 
                                    $is_done = in_array($act['activity_id'], $submitted_act_ids);
                                ?>
                                <div class="act-card">
                                    <div>
                                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                                            <span class="sys-tag accent" style="font-size: 0.72rem;"><?php echo htmlspecialchars($act['class_name'] ?: 'All Class'); ?></span>
                                            <?php if ($is_done): ?>
                                                <span class="sys-tag success" style="font-size: 0.72rem;"><i class="fa-solid fa-check"></i> Completed</span>
                                            <?php else: ?>
                                                <span class="sys-tag danger" style="font-size: 0.72rem;"><i class="fa-solid fa-hourglass-half"></i> Pending</span>
                                            <?php endif; ?>
                                        </div>
                                        <h4 style="font-family: var(--font-heading); font-size: 1rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.4rem;"><?php echo htmlspecialchars($act['title']); ?></h4>
                                    </div>
                                    <div style="margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                                        <span style="font-size: 0.78rem; color: var(--text-muted);"><i class="fa-regular fa-calendar"></i> Due: <?php echo date('M d, Y', strtotime($act['due_date'])); ?></span>
                                        <strong style="font-size: 0.88rem; color: var(--primary);"><?php echo $act['max_marks']; ?> Marks</strong>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="table-responsive" style="margin-top: 1.5rem;">
                            <table class="custom-table" id="assignedActivitiesTable">
                                <thead>
                                    <tr>
                                        <th>Activity Title</th>
                                        <th>Class / Group</th>
                                        <th>Due Date</th>
                                        <th>Max Marks</th>
                                        <th>Status</th>
                                        <th style="width: 50px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($wardActivities)): ?>
                                        <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2.5rem; font-weight: 500;">No activities assigned yet.</td></tr>
                                    <?php else: ?>
                                        <?php 
                                        $submitted_act_ids = array_column($wardSubmissions, 'activity_id');
                                        foreach ($wardActivities as $act): 
                                            $is_done = in_array($act['activity_id'], $submitted_act_ids);
                                        ?>
                                        <tr>
                                            <td><strong style="font-size: 0.95rem; color: var(--text-main);"><?php echo htmlspecialchars($act['title']); ?></strong></td>
                                            <td><span class="sys-tag accent"><?php echo htmlspecialchars($act['class_name'] ?: 'All Class'); ?></span></td>
                                            <td style="font-size: 0.85rem; color: var(--text-muted);"><?php echo date('M d, Y', strtotime($act['due_date'])); ?></td>
                                            <td style="font-weight: 600;"><?php echo $act['max_marks']; ?> Marks</td>
                                            <td>
                                                <?php if ($is_done): ?>
                                                    <span class="sys-tag success"><i class="fa-solid fa-check"></i> Completed</span>
                                                <?php else: ?>
                                                    <span class="sys-tag danger"><i class="fa-solid fa-hourglass-half"></i> Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="action-dots-btn" title="Actions"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- TAB CONTENT 4: ACTIVITY ROADMAP TIMELINE -->
                <div class="dash-tab-content" id="tabContent-timeline" style="display: none;">
                    <div class="module-card">
                        <div class="module-card-header">
                            <h3 class="module-title">
                                <i class="fa-solid fa-timeline" style="color: var(--primary);"></i> Ward Activity Milestone Roadmap
                            </h3>
                        </div>

                        <div class="timeline-wrapper">
                            <?php if (empty($wardSubmissions) && empty($wardActivities)): ?>
                                <div style="text-align: center; color: var(--text-muted); padding: 2rem;">No milestone activity data to display.</div>
                            <?php else: ?>
                                <?php foreach ($wardSubmissions as $sub): 
                                    $score = $sub['marks'] !== null ? $sub['marks'] : $sub['max_marks'];
                                ?>
                                <div class="timeline-item">
                                    <div class="timeline-node success"><i class="fa-solid fa-check"></i></div>
                                    <div class="timeline-card">
                                        <div class="timeline-header">
                                            <strong style="font-family: var(--font-heading); font-size: 0.95rem; color: var(--text-main);"><?php echo htmlspecialchars($sub['activity_title']); ?></strong>
                                            <span class="sys-tag success" style="font-size: 0.72rem;">Score: <?php echo $score; ?> / <?php echo $sub['max_marks']; ?></span>
                                        </div>
                                        <div style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 0.4rem;">
                                            Faculty Evaluator: <strong><?php echo htmlspecialchars($sub['faculty_name'] ?: 'Faculty'); ?></strong>
                                        </div>
                                        <div style="font-size: 0.75rem; color: #94A3B8;">
                                            <i class="fa-regular fa-clock"></i> Submitted: <?php echo date('M d, Y h:i A', strtotime($sub['submission_date'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        </main>
    </div>
</div>

<script>
// Initial Notification items rendered from server data
const initialNotifications = <?php echo json_encode($notifications, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let notificationsState = Array.isArray(initialNotifications) ? initialNotifications : [];

document.addEventListener("DOMContentLoaded", () => {
    const erpSidebar = document.getElementById('erpSidebar');
    const contentWrapper = document.getElementById('contentWrapper');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarCollapseBtn = document.getElementById('sidebarCollapseBtn');

    // 1. Restore Desktop Sidebar Collapse State
    const isCollapsed = localStorage.getItem('parent_sidebar_collapsed') === 'true';
    if (isCollapsed && window.innerWidth > 1024) {
        if (erpSidebar) erpSidebar.classList.add('collapsed');
        if (contentWrapper) contentWrapper.classList.add('collapsed');
    }

    // Toggle Sidebar Navigation via 3 Lines Hamburger Button
    if (sidebarToggle && erpSidebar) {
        sidebarToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            if (window.innerWidth > 1024) {
                const collapsed = erpSidebar.classList.toggle('collapsed');
                if (contentWrapper) contentWrapper.classList.toggle('collapsed', collapsed);
                localStorage.setItem('parent_sidebar_collapsed', collapsed ? 'true' : 'false');
            } else {
                const isShown = erpSidebar.classList.toggle('show');
                if (sidebarOverlay) sidebarOverlay.classList.toggle('show', isShown);
            }
        });
    }

    if (sidebarOverlay && erpSidebar) {
      sidebarOverlay.addEventListener('click', () => {
        erpSidebar.classList.remove('show');
        sidebarOverlay.classList.remove('show');
      });
    }

    // Auto-close drawer on mobile menu link click
    document.querySelectorAll('.sidebar-link').forEach(link => {
      link.addEventListener('click', () => {
        if (window.innerWidth <= 1024 && erpSidebar) {
          erpSidebar.classList.remove('show');
          if (sidebarOverlay) sidebarOverlay.classList.remove('show');
        }
      });
    });

    // 2. Live Clock
    const clockEl = document.getElementById('clock');
    if (clockEl) {
        function updateClock() {
            const now = new Date();
            const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            const ist = new Date(utc + (3600000 * 5.5));
            
            let h = ist.getHours(), m = ist.getMinutes(), s = ist.getSeconds();
            h = h < 10 ? '0'+h : h;
            m = m < 10 ? '0'+m : m;
            s = s < 10 ? '0'+s : s;
            clockEl.textContent = `${h}:${m}:${s}`;
        }
        setInterval(updateClock, 1000);
        updateClock();
    }

    // 3. Notification Panel Logic
    renderNotifications();

    const notifBellBtn = document.getElementById('notifBellBtn');
    const notifDropdown = document.getElementById('notifDropdown');

    if (notifBellBtn && notifDropdown) {
        notifBellBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            closeProfileDropdown(); // Close profile dropdown if open

            const isOpen = notifDropdown.classList.contains('show');
            if (isOpen) {
                notifDropdown.classList.remove('show');
                notifBellBtn.setAttribute('aria-expanded', 'false');
            } else {
                notifDropdown.classList.add('show');
                notifBellBtn.setAttribute('aria-expanded', 'true');
            }
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!notifDropdown.contains(e.target) && !notifBellBtn.contains(e.target)) {
                notifDropdown.classList.remove('show');
                notifBellBtn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // 4. Right-side Profile Dropdown Logic
    const profileDropdownBtn = document.getElementById('profileDropdownBtn');
    const profileDropdownMenu = document.getElementById('profileDropdownMenu');

    if (profileDropdownBtn && profileDropdownMenu) {
        profileDropdownBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            // Close notification dropdown if open
            if (notifDropdown) {
                notifDropdown.classList.remove('show');
                if (notifBellBtn) notifBellBtn.setAttribute('aria-expanded', 'false');
            }

            const isOpen = profileDropdownMenu.classList.contains('show');
            if (isOpen) {
                closeProfileDropdown();
            } else {
                profileDropdownMenu.classList.add('show');
                profileDropdownBtn.setAttribute('aria-expanded', 'true');
            }
        });

        // Close profile dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!profileDropdownMenu.contains(e.target) && !profileDropdownBtn.contains(e.target)) {
                closeProfileDropdown();
            }
        });

        // Close dropdowns on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeProfileDropdown();
                if (notifDropdown) {
                    notifDropdown.classList.remove('show');
                    if (notifBellBtn) notifBellBtn.setAttribute('aria-expanded', 'false');
                }
            }
        });
    }

    // 5. Initialize Chart.js Data Visualizations
    const ctxDoughnut = document.getElementById('completionDoughnutChart');
    if (ctxDoughnut && typeof Chart !== 'undefined') {
        new Chart(ctxDoughnut, {
            type: 'doughnut',
            data: {
                labels: ['Completed Submissions', 'Pending Activities'],
                datasets: [{
                    data: [<?php echo $totalSubmitted; ?>, <?php echo $totalPending; ?>],
                    backgroundColor: ['#22C55E', '#EF4444'],
                    borderWidth: 0,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { font: { family: 'Inter', size: 12, weight: '600' } } }
                },
                cutout: '70%'
            }
        });
    }

    const ctxBar = document.getElementById('scoresBarChart');
    if (ctxBar && typeof Chart !== 'undefined') {
        <?php
        $chartLabels = [];
        $chartScores = [];
        foreach ($wardSubmissions as $s) {
            $chartLabels[] = mb_strimwidth($s['activity_title'], 0, 14, '...');
            $chartScores[] = ($s['max_marks'] > 0) ? round((($s['marks'] !== null ? $s['marks'] : $s['max_marks']) / $s['max_marks']) * 100, 1) : 0;
        }
        ?>
        new Chart(ctxBar, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_reverse($chartLabels)); ?>,
                datasets: [{
                    label: 'Score (%)',
                    data: <?php echo json_encode(array_reverse($chartScores)); ?>,
                    backgroundColor: 'rgba(37, 99, 235, 0.85)',
                    borderColor: '#2563EB',
                    borderRadius: 8,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } },
                    x: { grid: { display: false } }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }
});

function switchDashboardTab(tabName) {
    document.querySelectorAll('.dash-tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.dash-tab-content').forEach(content => content.style.display = 'none');
    
    const targetBtn = document.getElementById(`tabBtn-${tabName}`);
    const targetContent = document.getElementById(`tabContent-${tabName}`);
    
    if (targetBtn) targetBtn.classList.add('active');
    if (targetContent) targetContent.style.display = 'block';
}

function closeProfileDropdown() {
    const profileDropdownBtn = document.getElementById('profileDropdownBtn');
    const profileDropdownMenu = document.getElementById('profileDropdownMenu');
    if (profileDropdownMenu) profileDropdownMenu.classList.remove('show');
    if (profileDropdownBtn) profileDropdownBtn.setAttribute('aria-expanded', 'false');
}

function scrollToPerformanceSummary(e) {
    if (e) e.preventDefault();
    closeProfileDropdown();
    switchDashboardTab('overview');
    const target = document.getElementById('exportTable') || document.getElementById('statsGrid');
    if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// Render Notification Items & Update Badges
function renderNotifications() {
    const notifList = document.getElementById('notifList');
    const notifCountBadge = document.getElementById('notifCountBadge');
    const notifBellDot = document.getElementById('notifBellDot');
    const notifUnreadTag = document.getElementById('notifUnreadTag');

    if (!notifList) return;

    const unreadCount = notificationsState.filter(n => !n.read).length;

    // Update count badge & bell dot
    if (notifCountBadge) {
        if (unreadCount > 0) {
            notifCountBadge.textContent = unreadCount > 99 ? '99+' : unreadCount;
            notifCountBadge.style.display = 'flex';
        } else {
            notifCountBadge.style.display = 'none';
        }
    }

    if (notifBellDot) {
        notifBellDot.style.display = unreadCount > 0 ? 'block' : 'none';
    }

    if (notifUnreadTag) {
        notifUnreadTag.textContent = `${unreadCount} Unread`;
    }

    if (notificationsState.length === 0) {
        notifList.innerHTML = `
            <div class="notif-empty-state">
                <i class="fa-regular fa-bell-slash notif-empty-icon"></i>
                <div style="font-weight: 600; font-size: 0.9rem; color: var(--text-main); margin-bottom: 0.2rem;">No Notifications</div>
                <div style="font-size: 0.78rem;">You have caught up with all updates!</div>
            </div>
        `;
        return;
    }

    let html = '';
    notificationsState.forEach((item, index) => {
        const iconClass = item.type === 'success' ? 'fa-circle-check' 
                        : item.type === 'warning' ? 'fa-clock' 
                        : item.type === 'danger' ? 'fa-triangle-exclamation' 
                        : 'fa-info-circle';

        html += `
            <div class="notif-item ${item.read ? 'read' : 'unread'}" onclick="markNotifAsRead(${index}, event)">
                <div class="notif-icon ${item.type}">
                    <i class="fa-solid ${iconClass}"></i>
                </div>
                <div class="notif-body">
                    <div class="notif-item-title">${item.title}</div>
                    <div class="notif-item-desc">${item.message}</div>
                    <div class="notif-item-time">${item.time}</div>
                </div>
                ${!item.read ? '<div class="notif-item-unread-dot" title="Unread"></div>' : ''}
            </div>
        `;
    });

    notifList.innerHTML = html;
}

// Mark single notification as read
function markNotifAsRead(index, event) {
    if (event) event.stopPropagation();
    if (notificationsState[index]) {
        notificationsState[index].read = true;
        renderNotifications();
    }
}

// Mark all notifications as read
function markAllNotifsAsRead() {
    notificationsState.forEach(n => n.read = true);
    renderNotifications();
}

// Clear all notifications
function clearAllNotifs() {
    notificationsState = [];
    renderNotifications();
}

// Client-side search and status filter
function filterTables() {
    const searchVal = (document.getElementById('tableSearchInput')?.value || '').toLowerCase().trim();
    const statusVal = (document.getElementById('statusFilterSelect')?.value || 'all').toLowerCase();

    // Filter Submissions table
    const subRows = document.querySelectorAll('#submissionsTable tbody tr');
    subRows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const matchesSearch = !searchVal || text.includes(searchVal);
        const matchesStatus = statusVal === 'all' || text.includes(statusVal);
        row.style.display = (matchesSearch && matchesStatus) ? '' : 'none';
    });

    // Filter Assigned Activities table
    const actRows = document.querySelectorAll('#assignedActivitiesTable tbody tr');
    actRows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const matchesSearch = !searchVal || text.includes(searchVal);
        row.style.display = matchesSearch ? '' : 'none';
    });
}

function exportPDF() {
    var element = document.getElementById('exportTable');
    if (!element) return;
    var opt = {
      margin:       10,
      filename:     'Student_Performance_Report.pdf',
      image:        { type: 'jpeg', quality: 0.98 },
      html2canvas:  { scale: 2 },
      jsPDF:        { unit: 'mm', format: 'a4', orientation: 'landscape' }
    };
    html2pdf().set(opt).from(element).save();
}

function exportExcel() {
    var element = document.getElementById("exportTable");
    if (!element) return;
    var wb = XLSX.utils.table_to_book(element, {sheet:"Ward_Report"});
    XLSX.writeFile(wb, "Student_Performance_Report.xlsx");
}
</script>

<?php 
// Safely include modal without fatal error
$modalPath = __DIR__ . '/includes/end_session_modal.php';
if (file_exists($modalPath)) {
    include_once $modalPath;
}
?>
</body>
</html>