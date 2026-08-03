<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-caching headers to prevent browser back-button access after logout
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/config/db.php';

// 1. AUTO-INITIALIZE GFM TABLES (Mapping removed, now department-based)
function init_gfm_tables() {
    global $pdo;
    try {
        $pdo->exec("ALTER TABLE activities ADD COLUMN IF NOT EXISTS faculty_id INT NULL AFTER activity_id");
    } catch (PDOException $e) {
        error_log("GFM Table Init Error: " . $e->getMessage());
    }
}
init_gfm_tables();

// Check user authorization
$role = strtolower($_SESSION['role'] ?? '');
if (empty($_SESSION['user_id']) || !in_array($role, ['gfm', 'faculty', 'hod', 'admin'])) {
    header('Location: auth/login.php');
    exit;
}

$gfm_id = (int)$_SESSION['user_id'];
$gfmName = $_SESSION['full_name'] ?? $_SESSION['user_name'] ?? "Prof. GFM";
$deptName = $_SESSION['department'] ?? "Electronics and Computer Engineering";
$collegeName = "Zeal College of Engineering";

$message = '';
$success_message = '';
$view = $_GET['view'] ?? 'dashboard';

// ----------------------------------------------------
// 2. ACTION HANDLERS (EMAIL-BASED FACULTY MONITORING)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    // Legacy mapping actions removed; automatic department matching is used.
}

// ----------------------------------------------------
// 3. FETCH DATA & ANALYTICS FOR HOD-STYLE DRILL-DOWN
// ----------------------------------------------------

// LEVEL 1: Fetch all faculty in this GFM's department
$stmtFac = $pdo->prepare("
    SELECT DISTINCT u.user_id, u.name, u.email,
           (SELECT COUNT(*) FROM faculty_classes fc WHERE fc.faculty_id = u.user_id AND fc.department = ?) AS total_classes,
           (SELECT COUNT(*) FROM activities a WHERE a.faculty_id = u.user_id) AS total_activities
    FROM users u
    JOIN faculty_classes fc_main ON fc_main.faculty_id = u.user_id
    WHERE fc_main.department = ? AND LOWER(u.role) = 'faculty'
    ORDER BY u.name ASC
");
$stmtFac->execute([$deptName, $deptName]);
$mapped_faculty = $stmtFac->fetchAll(PDO::FETCH_ASSOC) ?: [];

// LEVEL 1.5: ACADEMIC YEARS STATS for Reports View
$years_list = [
    'FY' => 'First Year (FY)', 
    'SY' => 'Second Year (SY)', 
    'TY' => 'Third Year (TY)', 
    'Final Year' => 'Final Year (B.Tech)'
];
$year_stats = [];
foreach ($years_list as $y_code => $y_name) {
    $stmtY = $pdo->prepare("
        SELECT COUNT(DISTINCT fc.subject_code) AS subject_count,
               COUNT(DISTINCT fc.faculty_id) AS faculty_count,
               COUNT(fc.class_id) AS class_count
        FROM faculty_classes fc
        WHERE fc.department = ? AND UPPER(fc.academic_year) = UPPER(?)
    ");
    $stmtY->execute([$deptName, $y_code]);
    $rowY = $stmtY->fetch(PDO::FETCH_ASSOC);
    $year_stats[$y_code] = [
        'name' => $y_name,
        'subject_count' => (int)($rowY['subject_count'] ?? 0),
        'faculty_count' => (int)($rowY['faculty_count'] ?? 0),
        'class_count' => (int)($rowY['class_count'] ?? 0)
    ];
}

// NEW DRILL-DOWN: Classes in a selected Year
$selected_year = $_GET['year'] ?? '';
$year_classes = [];
if ($view === 'gfm_year_classes' && !empty($selected_year)) {
    $stmtYrCls = $pdo->prepare("
        SELECT fc.class_id, fc.class_name, fc.subject_code, fc.academic_year, fc.faculty_id,
               u.name AS faculty_name, u.email AS faculty_email,
               (SELECT COUNT(*) FROM users us WHERE LOWER(us.role) = 'student' AND us.department = fc.department AND us.academic_year = fc.academic_year AND us.division = fc.division) AS student_count
        FROM faculty_classes fc
        JOIN users u ON u.user_id = fc.faculty_id
        WHERE fc.department = ? AND UPPER(fc.academic_year) = UPPER(?)
        ORDER BY fc.class_name ASC, u.name ASC
    ");
    $stmtYrCls->execute([$deptName, $selected_year]);
    $year_classes = $stmtYrCls->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// LEVEL 2: Fetch specific Faculty's Classes
$faculty_info = null;
$faculty_classes = [];
$fid = isset($_GET['fid']) ? (int)$_GET['fid'] : 0;
if ($view === 'faculty_classes' && $fid > 0) {
    $check = $pdo->prepare("SELECT 1 FROM faculty_classes WHERE department = ? AND faculty_id = ? LIMIT 1");
    $check->execute([$deptName, $fid]);
    
    if ($check->fetchColumn()) {
        $stmtFacInfo = $pdo->prepare("SELECT name, email FROM users WHERE user_id = ?");
        $stmtFacInfo->execute([$fid]);
        $faculty_info = $stmtFacInfo->fetch(PDO::FETCH_ASSOC);

        $stmtClasses = $pdo->prepare("
            SELECT fc.class_id, fc.class_name, fc.subject_code, fc.academic_year,
                   (SELECT COUNT(*) FROM users us WHERE LOWER(us.role) = 'student' AND us.department = fc.department AND us.academic_year = fc.academic_year AND us.division = fc.division) AS student_count
            FROM faculty_classes fc WHERE fc.faculty_id = ? AND fc.department = ?
            ORDER BY fc.created_at DESC
        ");
        $stmtClasses->execute([$fid, $deptName]);
        $faculty_classes = $stmtClasses->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $message = "Unauthorized access to this faculty member.";
        $view = 'reports';
    }
}

// LEVEL 3: Fetch specific Class Report
$selected_class = null;
$class_students = [];
$class_activities = [];
$cid = isset($_GET['cid']) ? (int)$_GET['cid'] : 0;
if ($view === 'class_report' && $fid > 0 && $cid > 0) {
    $check = $pdo->prepare("SELECT 1 FROM faculty_classes WHERE class_id = ? AND department = ?");
    $check->execute([$cid, $deptName]);
    
    if ($check->fetchColumn()) {
        $stmtFacInfo = $pdo->prepare("SELECT name FROM users WHERE user_id = ?");
        $stmtFacInfo->execute([$fid]);
        $faculty_info = $stmtFacInfo->fetch(PDO::FETCH_ASSOC);

        $stmtClassInfo = $pdo->prepare("SELECT class_name, subject_code, academic_year FROM faculty_classes WHERE class_id = ? AND faculty_id = ?");
        $stmtClassInfo->execute([$cid, $fid]);
        $selected_class = $stmtClassInfo->fetch(PDO::FETCH_ASSOC);

        if ($selected_class) {
            // Enrolled students
            $stmtSt = $pdo->prepare("
                SELECT u.username AS student_prn, CURRENT_TIMESTAMP AS added_at, u.name AS student_name, u.email AS student_email, st.roll_no
                FROM faculty_classes fc
                JOIN users u ON LOWER(u.role) = 'student' AND u.department = fc.department AND u.academic_year = fc.academic_year AND u.division = fc.division
                LEFT JOIN students st ON st.user_id = u.user_id
                WHERE fc.class_id = ?
                ORDER BY u.name ASC
            ");
            $stmtSt->execute([$cid]);
            $class_students = $stmtSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Class activities & submission stats
            $stmtAct = $pdo->prepare("
                SELECT a.activity_id, a.title, a.type, a.due_date, a.max_marks,
                       (SELECT COUNT(*) FROM submissions s WHERE s.activity_id = a.activity_id) AS submitted_count,
                       (SELECT AVG(marks) FROM submissions s WHERE s.activity_id = a.activity_id AND marks IS NOT NULL) AS avg_score
                FROM activities a
                WHERE a.faculty_id = ? AND (a.target_type = 'all' OR (a.target_type = 'class' AND a.target_id = ?))
                ORDER BY a.due_date DESC
            ");
            $stmtAct->execute([$fid, $cid]);
            $class_activities = $stmtAct->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $message = "Class not found.";
            $view = 'faculty_classes';
        }
    } else {
        $message = "Unauthorized access.";
        $view = 'reports';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GFM Dashboard | SAAES</title>
    
    <!-- Clean Academic Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- PDF and Excel Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    
    <style>
    /* ==========================================================================
       ELEVATE UI DESIGN SYSTEM
       ========================================================================== */
    :root {
      --bg-body: #f0f9ff;
      --bg-app: #ffffff;
      --text-main: #0f172a;
      --text-muted: #475569;
      --border-color: #e0f2fe;
      
      /* Sky Blue Theme Colors */
      --accent-green: #bae6fd; 
      --accent-green-hover: #7dd3fc;
      --icon-purple: #0ea5e9;
      
      /* Pastel Card Colors */
      --pastel-orange: #f0f9ff;
      --pastel-purple: #e0f2fe;
      --pastel-green: #bae6fd;
      --pastel-blue: #7dd3fc;

      --radius-sm: 8px;
      --radius-md: 12px;
      --radius-lg: 20px;
      --radius-xl: 32px;
      
      --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
      --shadow-app: 0 25px 50px -12px rgb(0 0 0 / 0.08);
      
      --font-main: 'Inter', system-ui, -apple-system, sans-serif;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body {
      font-family: var(--font-main);
      background-color: var(--bg-app);
      color: var(--text-main);
      min-height: 100vh;
      display: flex;
      line-height: 1.6;
      -webkit-font-smoothing: antialiased;
      padding: 0;
      margin: 0;
    }

    ::selection { background: var(--accent-green); color: #111; }
    a { text-decoration: none; color: inherit; }

    /* The Main App Window */
    .app-wrapper {
      background: var(--bg-app);
      width: 100%;
      height: 100vh;
      display: flex;
      overflow: hidden;
      position: relative;
    }

    /* ================= UTILITIES ================= */
    .d-flex { display: flex; }
    .align-items-center { align-items: center; }
    .gap-3 { gap: 1rem; }

    /* ================= SIDEBAR ================= */
    .sidebar {
      width: 260px;
      background: #0369a1;
      border-right: none;
      display: flex; flex-direction: column;
      padding: 2rem 0;
      z-index: 10;
      transition: margin-left 0.3s ease;
    }
    .sidebar.collapsed {
        margin-left: -260px;
    }
    .sidebar-header {
      padding: 0 2rem 2rem;
      display: flex; align-items: center; gap: 0.75rem;
    }
    .brand-logo {
      display: flex; align-items: center; gap: 0.5rem;
      font-weight: 800; font-size: 1.5rem; color: #ffffff;
      letter-spacing: -0.02em;
    }
    .brand-logo i { 
      color: #bae6fd;
      font-size: 1.6rem; 
    }
    
    .sidebar-menu { display: flex; flex-direction: column; gap: 0.5rem; padding: 0 1rem; flex: 1; overflow-y: auto; }
    
    .sidebar-link {
      display: flex; align-items: center; gap: 1rem; padding: 0.8rem 1.25rem;
      color: #e0f2fe; font-weight: 600; font-size: 0.95rem; border-radius: var(--radius-md);
      transition: all 0.2s ease;
    }
    .sidebar-link:hover { 
      background: rgba(255, 255, 255, 0.1); 
      color: #ffffff;
    }
    .sidebar-link.active {
      background: rgba(255, 255, 255, 0.2); color: #ffffff;
    }
    .sidebar-link i { font-size: 1.1rem; width: 20px; text-align: center; }

    /* User at bottom of sidebar - hidden in Elevate, they put it in top bar */
    .sidebar-user { display: none; }

    /* ================= MAIN CONTENT ================= */
    .content-wrapper { flex: 1; display: flex; flex-direction: column; background: #fafafa; overflow-y: auto;}
    
    .top-navbar {
      background: #fafafa; 
      padding: 1.5rem 2.5rem;
      display: flex; justify-content: space-between; align-items: center;
      position: sticky; top: 0; z-index: 100; 
    }
    /* Hide the old top bar title, use a search bar styling instead */
    .top-navbar h3 { display: none; }
    
    /* Mock Search Bar for visual */
    .search-bar {
      background: #fff; border: 1px solid var(--border-color);
      border-radius: 999px; padding: 0.6rem 1.5rem;
      display: flex; align-items: center; gap: 0.75rem;
      color: var(--text-muted); font-size: 0.9rem;
      width: 300px;
    }
    
    /* User Profile in Top Nav */
    .user-profile-badge {
      display: flex; align-items: center; gap: 0.75rem;
      background: #fff; border: 1px solid var(--border-color);
      padding: 0.4rem 1rem 0.4rem 0.4rem;
      border-radius: 999px; font-size: 0.85rem; font-weight: 600; cursor: pointer;
    }
    .user-profile-badge .avatar {
      width: 32px; height: 32px; border-radius: 50%;
      background: var(--pastel-orange); color: #c2410c;
      display: flex; align-items: center; justify-content: center;
    }
    
    .profile-dropdown {
      position: absolute;
      top: calc(100% + 0.5rem);
      right: 0;
      background: #fff;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-md);
      box-shadow: var(--shadow-app);
      min-width: 180px;
      display: flex;
      flex-direction: column;
      padding: 0.5rem;
      opacity: 0;
      visibility: hidden;
      transform: translateY(-10px);
      transition: all 0.2s ease;
      z-index: 1000;
    }
    .profile-dropdown.show {
      opacity: 1;
      visibility: visible;
      transform: translateY(0);
    }
    .profile-dropdown a {
      padding: 0.75rem 1rem;
      color: var(--text-main);
      font-size: 0.9rem;
      font-weight: 500;
      border-radius: var(--radius-sm);
      display: flex;
      align-items: center;
      gap: 0.75rem;
      transition: background 0.2s;
    }
    .profile-dropdown a:hover {
      background: #f8fafc;
      color: #0ea5e9;
    }
    .profile-dropdown .dropdown-divider {
      height: 1px;
      background: var(--border-color);
      margin: 0.5rem 0;
    }
    
    .main-content { padding: 0 2.5rem 2.5rem; flex: 1; display: flex; flex-direction: column; gap: 2rem; max-width: 1400px; margin: 0 auto; width: 100%;}

    /* Page Titles */
    .page-title-section { margin-bottom: 0.5rem; }
    .page-title { font-size: 1.8rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.25rem; }
    .page-subtitle { font-size: 0.95rem; color: var(--text-muted); }

    /* ================= STATS CARDS (Pastel) ================= */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; }
    .stat-block { 
      border-radius: var(--radius-lg); 
      padding: 1.5rem; display: flex; flex-direction: column; justify-content: space-between; 
      min-height: 160px;
      position: relative; overflow: hidden;
      border: 1px solid #000;
    }
    /* Applying the specific pastel colors */
    .stat-block:nth-child(1) { background-color: var(--pastel-orange); }
    .stat-block:nth-child(2) { background-color: var(--pastel-purple); }
    .stat-block:nth-child(3) { background-color: var(--pastel-green); }
    .stat-block:nth-child(4) { background-color: var(--pastel-blue); }
    
    .stat-icon-wrapper {
      background: rgba(255,255,255,0.6);
      width: fit-content; padding: 0.4rem 0.8rem; border-radius: 999px;
      font-size: 0.8rem; font-weight: 600; color: var(--text-main);
      margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;
    }
    .stat-title { font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.5rem; line-height: 1.3;}
    .stat-desc { font-size: 0.85rem; color: rgba(0,0,0,0.5); margin-bottom: 1.5rem;}
    
    .stat-footer { display: flex; align-items: center; justify-content: space-between; margin-top: auto;}
    .stat-val { font-size: 1.8rem; font-weight: 800; color: var(--text-main); line-height: 1; }

    /* Progress bar mock */
    .mock-progress {
      width: 100%; height: 8px; background: rgba(255,255,255,0.5); border-radius: 4px;
      position: relative; overflow: hidden; margin-top: 1rem;
    }
    .mock-progress-fill { position: absolute; left: 0; top: 0; height: 100%; background: #fff; border-radius: 4px; }

    /* ================= MODULE CARDS (Generic containers) ================= */
    .module-card {
      background: #fff;
      border: 1px solid #000;
      padding: 2rem; border-radius: var(--radius-lg); 
      box-shadow: var(--shadow-sm); 
      transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275), box-shadow 0.4s ease;
      animation: fade-in-up 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
      opacity: 0;
      transform: translateY(30px);
    }
    .module-card:hover {
      transform: translateY(-8px);
      box-shadow: var(--shadow-app);
      border-color: rgba(139, 92, 246, 0.4);
    }
    
    @keyframes fade-in-up {
      to { opacity: 1; transform: translateY(0); }
    }
    
    /* Staggered animation delays for up to 10 cards */
    .module-card:nth-child(1) { animation-delay: 0.05s; }
    .module-card:nth-child(2) { animation-delay: 0.1s; }
    .module-card:nth-child(3) { animation-delay: 0.15s; }
    .module-card:nth-child(4) { animation-delay: 0.2s; }
    .module-card:nth-child(5) { animation-delay: 0.25s; }
    .module-card:nth-child(6) { animation-delay: 0.3s; }
    .module-card:nth-child(7) { animation-delay: 0.35s; }
    .module-card:nth-child(8) { animation-delay: 0.4s; }
    .module-card:nth-child(9) { animation-delay: 0.45s; }
    .module-card:nth-child(10) { animation-delay: 0.5s; }
    
    .hero-banner { display: none; /* Replaced by simpler page titles */ }

    /* ================= TAGS / BADGES ================= */
    .sys-tag { 
      font-size: 0.75rem; font-weight: 700; padding: 0.4rem 0.8rem; 
      border-radius: 999px; display: inline-flex; align-items: center; gap: 0.4rem; 
      background: #f1f5f9; color: var(--text-muted); 
    }
    .sys-tag.accent { background: var(--accent-green); color: var(--text-main); }
    .sys-tag.success { background: var(--pastel-green); color: #166534; }
    .sys-tag.info { background: var(--pastel-blue); color: #075985; }

    /* ================= BUTTONS ================= */
    .btn {
        font-family: var(--font-main); font-weight: 600; font-size: 0.9rem;
        padding: 0.75rem 1.5rem; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
        border-radius: 999px; border: 1px solid transparent; cursor: pointer; 
        transition: all 0.2s ease; text-decoration: none;
    }
    .btn-primary { background: #0ea5e9; color: #fff; }
    .btn-primary:hover { background: #0284c7; }
    
    .btn-outline { background: #fff; border-color: #e2e8f0; color: var(--text-main); }
    .btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; }

    /* ================= TABLES ================= */
    .table-responsive { overflow-x: auto; border: 1px solid var(--border-color); border-radius: var(--radius-md); margin-bottom: 1rem; background: #fff; }
    .custom-table { width: 100%; border-collapse: separate; border-spacing: 0; text-align: left; }
    .custom-table th, .custom-table td { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); font-size: 0.95rem; vertical-align: middle; }
    .custom-table th { 
      background: #f8fafc; color: var(--text-muted); 
      font-weight: 600; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em;
    }
    .custom-table tbody tr:hover { background: #f8fafc; }
    .custom-table tbody tr:last-child td { border-bottom: none; }
    
    /* ================= ALERTS ================= */
    .alert { font-size: 0.95rem; font-weight: 500; border-radius: var(--radius-md); padding: 1rem 1.5rem; display: flex; align-items: center; gap: 1rem; margin-bottom: 2rem; }
    .alert-danger { background: #fee2e2; color: #991b1b; }
    .alert-success { background: #dcfce7; color: #166534; }

    @media (max-width: 1024px) {
        body { padding: 0; }
        .app-wrapper { height: 100vh; border-radius: 0; min-height: 100vh; }
        .sidebar { position: fixed; transform: translateX(-100%); transition: transform 0.3s; z-index: 200; height: 100vh; margin-left: 0; }
        .sidebar.show { transform: translateX(0); }
        .main-content { padding: 1.5rem; }
    }

    /* ================= ANIMATED BACKGROUND ================= */
    .bg-shapes-container {
      position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
      z-index: -1; overflow: hidden; pointer-events: none;
    }
    .bg-shape {
      position: absolute; filter: blur(60px); opacity: 0.6; border-radius: 50%;
      animation: float 20s infinite ease-in-out alternate;
    }
    .bg-shape.shape1 { width: 400px; height: 400px; background: rgba(139, 92, 246, 0.4); top: -10%; left: -10%; animation-duration: 25s; }
    .bg-shape.shape2 { width: 500px; height: 500px; background: rgba(217, 249, 157, 0.5); bottom: -15%; right: -5%; animation-duration: 28s; animation-delay: -5s; }
    .bg-shape.shape3 { width: 300px; height: 300px; background: rgba(255, 237, 213, 0.6); top: 40%; left: 40%; animation-duration: 22s; animation-delay: -10s; }
    @keyframes float {
      0% { transform: translate(0, 0) scale(1); }
      33% { transform: translate(30px, -50px) scale(1.1); }
      66% { transform: translate(-20px, 20px) scale(0.9); }
      100% { transform: translate(0, 0) scale(1); }
    }
    </style>
</head>
<body>

<div class="bg-shapes-container">
    <div class="bg-shape shape1"></div>
    <div class="bg-shape shape2"></div>
    <div class="bg-shape shape3"></div>
</div>

<div class="app-wrapper">

    <!-- LEFT SIDEBAR -->
    <aside class="sidebar" id="erpSidebar">
        <div class="sidebar-header">
            <a href="gfm_dashboard.php?view=dashboard" class="brand-logo">
                <i class="fa-solid fa-shapes"></i>
                <span>GFM DASHBOARD</span>
            </a>
        </div>

        <div class="sidebar-menu">
            <a href="?view=dashboard" class="sidebar-link <?php echo ($view === 'dashboard') ? 'active' : ''; ?>">
                <i class="fa-solid fa-border-all"></i> <span>Dashboard</span>
            </a>
            <a href="?view=reports" class="sidebar-link <?php echo in_array($view, ['reports', 'faculty_classes', 'class_report']) ? 'active' : ''; ?>">
                <i class="fa-solid fa-chart-simple"></i> <span>Reports</span>
            </a>
            <a href="auth/logout.php" class="sidebar-link" style="margin-top: auto;">
                <i class="fa-solid fa-power-off"></i> <span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- CONTENT WRAPPER -->
    <div class="content-wrapper">
        <header class="top-navbar">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-outline" id="sidebarToggle" style="padding: 0.4rem 0.8rem; border-radius: 8px;"><i class="fa-solid fa-bars"></i></button>
            </div>

            <div style="display: flex; align-items: center; gap: 1rem; position: relative;">
                <div class="user-profile-badge" id="profileDropdownBtn">
                    <div class="avatar"><?php echo strtoupper(substr($gfmName, 0, 1)); ?></div>
                    <span style="padding-right: 0.5rem;"><?php echo htmlspecialchars($gfmName); ?> <i class="fa-solid fa-chevron-down ms-1" style="font-size: 0.7rem; color: #999;"></i></span>
                </div>
                
                <div class="profile-dropdown" id="profileDropdown">
                    <a href="?view=profile"><i class="fa-solid fa-user"></i> My Profile</a>
                    <div class="dropdown-divider"></div>
                    <a href="auth/logout.php" style="color: #ef4444;"><i class="fa-solid fa-power-off"></i> Logout</a>
                </div>
            </div>
        </header>

        <main class="main-content">

        <!-- ALERTS -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <!-- VIEW 1: OVERVIEW DASHBOARD -->
        <?php if ($view === 'dashboard'): ?>
            <div class="page-title-section">
                <h1 class="page-title">Dashboard Overview</h1>
                <p class="page-subtitle">Your department at a glance.</p>
            </div>

            <!-- OVERVIEW STATS (Pastel Cards) -->
            <div class="stats-grid">
                <div class="stat-block">
                    <div class="stat-icon-wrapper"><i class="fa-regular fa-calendar"></i> Active Now</div>
                    <div>
                        <div class="stat-title">Active Faculty</div>
                        <div class="stat-desc">Total faculty assigned classes.</div>
                        <div class="stat-footer">
                            <div class="mock-progress" style="width: 70%;"><div class="mock-progress-fill" style="width: 100%;"></div></div>
                            <div class="stat-val"><?php echo count($mapped_faculty); ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-block">
                    <div class="stat-icon-wrapper"><i class="fa-regular fa-calendar"></i> This Semester</div>
                    <div>
                        <div class="stat-title">Classes Created</div>
                        <div class="stat-desc">Total classes in department.</div>
                        <div class="stat-footer">
                            <div class="mock-progress" style="width: 70%;"><div class="mock-progress-fill" style="width: 100%;"></div></div>
                            <div class="stat-val"><?php echo array_sum(array_column($mapped_faculty, 'total_classes')); ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="stat-block">
                    <div class="stat-icon-wrapper"><i class="fa-regular fa-calendar"></i> Ongoing</div>
                    <div>
                        <div class="stat-title">Activities Assigned</div>
                        <div class="stat-desc">Total assignments & quizzes.</div>
                        <div class="stat-footer">
                            <div class="mock-progress" style="width: 70%;"><div class="mock-progress-fill" style="width: 100%;"></div></div>
                            <div class="stat-val"><?php echo array_sum(array_column($mapped_faculty, 'total_activities')); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CHARTS AND STATISTICS WIDGET -->
            <div class="charts-widget" style="margin-top: 2rem; background: #fff; border-radius: var(--radius-xl); padding: 2.5rem; position: relative; display: flex; flex-direction: column; box-shadow: var(--shadow-sm); border: 1px solid #000;">
                
                <div style="margin-bottom: 2rem;">
                    <h3 style="font-size: 1.5rem; font-weight: 800; color: var(--text-main); margin-bottom: 0.5rem;">Department Analytics</h3>
                    <p style="color: var(--text-muted); font-size: 0.95rem; margin: 0; line-height: 1.5;">Visual overview of classes and faculty activities.</p>
                </div>

                <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
                    <!-- Pie Chart -->
                    <div style="background: #fafafa; padding: 1.5rem; border-radius: var(--radius-md); border: 1px solid #000; display: flex; flex-direction: column; align-items: center;">
                        <h4 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin-bottom: 1rem; width: 100%; text-align: left;">Classes Distribution by Year</h4>
                        <div style="position: relative; width: 100%; max-width: 300px; aspect-ratio: 1;">
                            <canvas id="classesPieChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Bar Chart -->
                    <div style="background: #fafafa; padding: 1.5rem; border-radius: var(--radius-md); border: 1px solid #000; display: flex; flex-direction: column; align-items: center;">
                        <h4 style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); margin-bottom: 1rem; width: 100%; text-align: left;">Faculty Activities Assigned</h4>
                        <div style="position: relative; width: 100%; height: 300px;">
                            <canvas id="activitiesBarChart"></canvas>
                        </div>
                    </div>
                </div>

                <?php
                // Prepare data for charts
                $year_labels = [];
                $class_counts = [];
                foreach ($year_stats as $y_code => $y_data) {
                    $year_labels[] = $y_data['name'];
                    $class_counts[] = $y_data['class_count'];
                }

                $faculty_names = [];
                $faculty_activities = [];
                $limit = 0;
                foreach ($mapped_faculty as $fac) {
                    if ($limit++ >= 10) break; // Limit to 10 for better visualization
                    $faculty_names[] = $fac['name'];
                    $faculty_activities[] = $fac['total_activities'];
                }
                ?>

                <script>
                    // Pie Chart
                    const pieCtx = document.getElementById('classesPieChart').getContext('2d');
                    new Chart(pieCtx, {
                        type: 'doughnut',
                        data: {
                            labels: <?php echo json_encode($year_labels); ?>,
                            datasets: [{
                                data: <?php echo json_encode($class_counts); ?>,
                                backgroundColor: ['#ffedd5', '#f3e8ff', '#dcfce7', '#e0f2fe'],
                                hoverOffset: 4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { position: 'bottom' }
                            }
                        }
                    });

                    // Bar Chart
                    const barCtx = document.getElementById('activitiesBarChart').getContext('2d');
                    new Chart(barCtx, {
                        type: 'bar',
                        data: {
                            labels: <?php echo json_encode($faculty_names); ?>,
                            datasets: [{
                                label: 'Activities Assigned',
                                data: <?php echo json_encode($faculty_activities); ?>,
                                backgroundColor: '#8b5cf6',
                                borderRadius: 4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false }
                            },
                            scales: {
                                y: { beginAtZero: true, ticks: { precision: 0 } },
                                x: { ticks: { maxRotation: 45, minRotation: 45 } }
                            }
                        }
                    });
                </script>
            </div>

        <!-- VIEW 2: REPORTS / ACADEMIC YEARS SELECTION -->
        <?php elseif ($view === 'reports'): ?>
            <div class="page-title-section" style="margin-bottom: 2rem;">
                <h1 class="page-title">Performance Reports</h1>
                <p class="page-subtitle">Select an academic year to inspect faculty classes and performance reports for your department.</p>
            </div>

            <!-- YEAR SELECTION CARDS -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem;">
                <?php foreach ($year_stats as $y_code => $y_data): ?>
                <div class="module-card" style="display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <span class="sys-tag accent" style="font-size: 0.85rem;"><?php echo $y_code; ?></span>
                            <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600;"><?php echo $y_data['class_count']; ?> Classes</span>
                        </div>
                        <h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1rem; color: var(--text-main);"><?php echo htmlspecialchars($y_data['name']); ?></h3>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 1.5rem;">
                            <div style="background: var(--bg-body); padding: 0.75rem; border-radius: 8px; text-align: center;">
                                <div style="font-size: 1.1rem; font-weight: 700; color: var(--navy-primary);"><?php echo $y_data['subject_count']; ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Subjects</div>
                            </div>
                            <div style="background: var(--bg-body); padding: 0.75rem; border-radius: 8px; text-align: center;">
                                <div style="font-size: 1.1rem; font-weight: 700; color: var(--navy-primary);"><?php echo $y_data['faculty_count']; ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Faculty</div>
                            </div>
                        </div>
                    </div>
                    
                    <a href="?view=gfm_year_classes&year=<?php echo urlencode($y_code); ?>" class="btn btn-primary" style="width: 100%; justify-content: center;">
                        View Classes <i class="fa-solid fa-arrow-right" style="margin-left: 0.5rem;"></i>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>

        <!-- NEW DRILL-DOWN VIEW: CLASSES IN A YEAR -->
        <?php elseif ($view === 'gfm_year_classes' && !empty($selected_year)): ?>
            <div style="margin-bottom: 1.5rem;">
                <a href="?view=reports" class="btn btn-outline" style="margin-bottom: 1rem; font-size: 0.8rem;">
                    <i class="fa-solid fa-arrow-left"></i> Back to Year Selection
                </a>
                <h2 class="page-title" style="margin-bottom: 0.2rem;">
                    Classes in <?php echo htmlspecialchars($years_list[$selected_year] ?? $selected_year); ?>
                </h2>
                <p class="page-subtitle">Select a class to view its detailed performance report.</p>
            </div>

            <div class="module-card">
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Class / Group Name</th>
                                <th>Subject Code</th>
                                <th>Faculty</th>
                                <th>Enrolled Students</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($year_classes)): ?>
                                <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 3rem; font-weight: 500;">No classes found for this year in your department.</td></tr>
                            <?php else: ?>
                                <?php foreach ($year_classes as $cls): ?>
                                <tr>
                                    <td><strong style="font-weight: 600; color: var(--text-main); font-size: 0.95rem;"><?php echo htmlspecialchars($cls['class_name']); ?></strong></td>
                                    <td><span class="sys-tag accent"><?php echo htmlspecialchars($cls['subject_code']); ?></span></td>
                                    <td>
                                        <div style="font-size: 0.9rem; font-weight: 500; color: var(--text-main);"><?php echo htmlspecialchars($cls['faculty_name']); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($cls['faculty_email']); ?></div>
                                    </td>
                                    <td>
                                        <span class="sys-tag info">
                                            <i class="fa-solid fa-users" style="margin-right: 4px;"></i> <?php echo $cls['student_count']; ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <a href="?view=class_report&fid=<?php echo $cls['faculty_id']; ?>&cid=<?php echo $cls['class_id']; ?>" class="btn btn-primary" style="font-size: 0.8rem; padding: 0.4rem 0.8rem;">
                                            View Report
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- VIEW 3: FACULTY CLASSES LIST -->
        <?php elseif ($view === 'faculty_classes' && $faculty_info): ?>
            <div style="margin-bottom: 1.5rem;">
                <a href="?view=reports" class="btn btn-outline" style="margin-bottom: 1rem; font-size: 0.8rem;">
                    <i class="fa-solid fa-arrow-left"></i> Back to Directory
                </a>
                <h2 style="font-size: 1.8rem; font-weight: 700; color: var(--navy-primary); margin-bottom: 0.2rem;">Classes Managed by <?php echo htmlspecialchars($faculty_info['name']); ?></h2>
                <p style="font-size: 0.9rem; color: var(--text-muted);">Email: <strong><?php echo htmlspecialchars($faculty_info['email']); ?></strong></p>
            </div>

            <div class="module-card">
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Class / Group Name</th>
                                <th>Subject Code</th>
                                <th>Enrolled Students</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($faculty_classes)): ?>
                                <tr><td colspan="4" style="text-align: center; color: var(--text-muted); padding: 3rem; font-weight: 500;">This faculty member hasn't created any classes yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($faculty_classes as $cls): ?>
                                <tr>
                                    <td><strong style="font-size: 1rem; color: var(--text-main);"><?php echo htmlspecialchars($cls['class_name']); ?></strong></td>
                                    <td><span style="font-weight: 600; color: var(--blue-accent);"><?php echo htmlspecialchars($cls['subject_code'] ?: 'N/A'); ?></span></td>
                                    <td><span style="font-weight: 500; color: var(--text-muted);"><?php echo $cls['student_count']; ?> Students</span></td>
                                    <td style="text-align: right;">
                                        <a href="?view=class_report&fid=<?php echo $fid; ?>&cid=<?php echo $cls['class_id']; ?>" class="btn btn-primary" style="font-size: 0.8rem; padding: 0.4rem 0.8rem;">
                                            View Class Report
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- VIEW 4: DETAILED CLASS REPORT -->
        <?php elseif ($view === 'class_report' && $selected_class): ?>
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
                <div>
                    <a href="?view=faculty_classes&fid=<?php echo $fid; ?>" class="btn btn-outline" style="margin-bottom: 1rem; font-size: 0.8rem;">
                        <i class="fa-solid fa-arrow-left"></i> Back to Classes
                    </a>
                    <h2 style="font-size: 1.8rem; font-weight: 700; color: var(--navy-primary); margin-bottom: 0.2rem;">Class Report: <?php echo htmlspecialchars($selected_class['class_name']); ?></h2>
                    <p style="font-size: 0.9rem; color: var(--text-muted); margin: 0;">
                        Faculty: <strong><?php echo htmlspecialchars($faculty_info['name'] ?? 'Faculty'); ?></strong> | Subject: <strong style="color: var(--blue-accent);"><?php echo htmlspecialchars($selected_class['subject_code'] ?: 'General'); ?></strong>
                    </p>
                </div>
                <div style="display: flex; gap: 0.5rem;">
                    <button class="btn btn-outline" onclick="exportPDF()"><i class="fa-solid fa-file-pdf text-danger me-1"></i> Export PDF</button>
                    <button class="btn btn-primary" onclick="exportExcel()"><i class="fa-solid fa-file-excel me-1"></i> Export Excel</button>
                </div>
            </div>

            <div id="exportTable">
                <!-- STATS SUMMARY -->
                <div class="stats-grid" style="grid-template-columns: 1fr 1fr; margin-bottom: 2rem;">
                    <div class="stat-block">
                        <div class="stat-val"><?php echo count($class_students); ?></div>
                        <div class="stat-label">Enrolled Students</div>
                    </div>
                    <div class="stat-block">
                        <div class="stat-val" style="color: var(--success);"><?php echo count($class_activities); ?></div>
                        <div class="stat-label">Total Activities</div>
                    </div>
                </div>

                <!-- ACTIVITIES ANALYTICS -->
                <div class="module-card" style="margin-bottom: 2rem;">
                    <h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.5rem; color: var(--navy-primary);">Activity Submissions & Scores</h3>
                    <div class="table-responsive">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Activity Title</th>
                                    <th>Due Date</th>
                                    <th style="text-align: center;">Submitted</th>
                                    <th style="text-align: center;">Pending</th>
                                    <th>Class Avg Score</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($class_activities)): ?>
                                    <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem; font-weight: 500;">No activities assigned for this class yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($class_activities as $act): 
                                        $pending = max(0, count($class_students) - $act['submitted_count']);
                                        $avg = $act['avg_score'] !== null ? number_format($act['avg_score'], 1) : '-';
                                    ?>
                                    <tr>
                                        <td><strong style="font-size: 0.95rem; color: var(--text-main);"><?php echo htmlspecialchars($act['title']); ?></strong></td>
                                        <td style="font-size: 0.85rem; color: var(--text-muted);"><?php echo date('M d, Y', strtotime($act['due_date'])); ?></td>
                                        <td style="text-align: center;"><strong style="color: var(--success); font-size: 1rem;"><?php echo $act['submitted_count']; ?></strong></td>
                                        <td style="text-align: center;"><strong style="color: var(--danger); font-size: 1rem;"><?php echo $pending; ?></strong></td>
                                        <td><strong style="font-size: 1rem;"><?php echo $avg; ?></strong> <span style="font-size: 0.75rem; color: var(--text-muted);">/ <?php echo $act['max_marks']; ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ENROLLED STUDENTS ROSTER -->
                <div class="module-card">
                    <h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 1.5rem; color: var(--navy-primary);">Enrolled Student Roster</h3>
                    <div class="table-responsive">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Student PRN</th>
                                    <th>Student Name</th>
                                    <th>Email</th>
                                    <th>Roll No</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($class_students)): ?>
                                    <tr><td colspan="4" style="text-align: center; color: var(--text-muted); padding: 2rem; font-weight: 500;">No students added to this class yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($class_students as $st): ?>
                                    <tr>
                                        <td style="font-weight: 600; color: var(--navy-primary);"><?php echo htmlspecialchars($st['student_prn']); ?></td>
                                        <td><strong style="font-weight: 500; color: var(--text-main);"><?php echo htmlspecialchars($st['student_name'] ?: 'Registered Student'); ?></strong></td>
                                        <td style="font-size: 0.85rem; color: var(--text-muted);"><?php echo htmlspecialchars($st['student_email'] ?: '—'); ?></td>
                                        <td style="font-weight: 600;"><?php echo htmlspecialchars($st['roll_no'] ?: '-'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
        <!-- VIEW 5: PROFILE SECTION -->
        <?php elseif ($view === 'profile'): ?>
            <div class="page-title-section" style="margin-bottom: 1.5rem;">
                <h1 class="page-title">My Profile</h1>
                <p class="page-subtitle">View professional information.</p>
            </div>
            
            <div class="module-card" style="max-width: 800px; padding: 3rem;">
                <div style="display:flex; gap: 2rem; align-items:flex-start; flex-wrap: wrap;">
                    <div style="flex-shrink:0;">
                        <div style="width:120px; height:120px; border-radius: 50%; background-color:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:4rem; color: #0ea5e9;">
                            <i class="fa-solid fa-user"></i>
                        </div>
                    </div>
                    <div style="flex:1;">
                        <h2 style="font-size:1.8rem; font-weight: 700; color:var(--text-main); margin-bottom: 0.2rem;"><?= htmlspecialchars($gfmName) ?></h2>
                        <p style="color:var(--text-muted); font-size:1rem; font-weight:500; margin-bottom: 1.5rem;">GFM (Guardian Faculty Member)</p>
                        
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem;">
                            <div>
                                <strong style="display:block; margin-bottom:0.25rem; font-size:0.85rem; color:var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Account Role</strong>
                                <span class="sys-tag accent" style="font-size: 0.85rem;"><?= strtoupper($role) ?></span>
                            </div>
                            <div>
                                <strong style="display:block; margin-bottom:0.25rem; font-size:0.85rem; color:var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Department</strong>
                                <span style="font-size: 0.9rem; font-weight: 500; color: var(--text-main);"><?= htmlspecialchars($deptName) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        </main>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    // 1. Sidebar Toggle
    const sidebarToggle = document.getElementById('sidebarToggle');
    const erpSidebar = document.getElementById('erpSidebar');

    if (sidebarToggle && erpSidebar) {
      sidebarToggle.addEventListener('click', () => {
        if (window.innerWidth <= 1024) {
            erpSidebar.classList.toggle('show');
        } else {
            erpSidebar.classList.toggle('collapsed');
        }
      });
    }

    // Profile Dropdown Toggle
    const profileBtn = document.getElementById('profileDropdownBtn');
    const profileDropdown = document.getElementById('profileDropdown');
    
    if (profileBtn && profileDropdown) {
        profileBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown.classList.toggle('show');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                profileDropdown.classList.remove('show');
            }
        });
    }

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
});

function exportPDF() {
    var element = document.getElementById('exportTable');
    if (!element) return;
    var opt = {
      margin:       10,
      filename:     'Class_Report.pdf',
      image:        { type: 'jpeg', quality: 0.98 },
      html2canvas:  { scale: 2 },
      jsPDF:        { unit: 'mm', format: 'a4', orientation: 'landscape' }
    };
    html2pdf().set(opt).from(element).save();
}

function exportExcel() {
    var element = document.getElementById("exportTable");
    if (!element) return;
    var wb = XLSX.utils.table_to_book(element, {sheet:"Class_Report"});
    XLSX.writeFile(wb, "Class_Report.xlsx");
}
</script>

<?php 
$modalPath = __DIR__ . '/includes/end_session_modal.php';
if (file_exists($modalPath)) {
    include_once $modalPath;
}
?>
</body>
</html>