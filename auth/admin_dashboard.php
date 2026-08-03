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

$yearMapping = [
    '1st Year' => 'FY',
    '2nd Year' => 'SY',
    '3rd Year' => 'TY',
    '4th Year' => 'Final Year'
];

$selectedYear = isset($_GET['year']) ? trim($_GET['year']) : '';
$selectedSubject = isset($_GET['subject']) ? trim($_GET['subject']) : '';
$selectedDivision = isset($_GET['division']) ? trim($_GET['division']) : '';

$yearDbCode = $yearMapping[$selectedYear] ?? $selectedYear;
$selectedDivCode = str_replace('Division ', '', $selectedDivision);

// 1. PHP CSV Export Logic
if (isset($_GET['export']) && $_GET['export'] === 'excel' && !empty($selectedYear) && !empty($selectedSubject) && !empty($selectedDivision)) {
    // Get students in this year & division
    // Get students in this year & division
    $stdStmt = $conn->prepare("SELECT user_id AS id, COALESCE(NULLIF(name, ''), username) AS name, roll_no, email, department FROM users WHERE (role = 'Student' OR role = 'student') AND academic_year = ? AND division = ? ORDER BY roll_no ASC");
    $stdStmt->bind_param("ss", $yearDbCode, $selectedDivCode);
    $stdStmt->execute();
    $stdRes = $stdStmt->get_result();

    // Query total max marks of activities for this subject
    $actStmt = $conn->prepare("SELECT SUM(max_marks) AS max_total, COUNT(*) AS activity_count FROM activities WHERE UPPER(subject) = UPPER(?)");
    $actStmt->bind_param("s", $selectedSubject);
    $actStmt->execute();
    $actMeta = $actStmt->get_result()->fetch_assoc();
    $totalSubjectMaxMarks = (float)($actMeta['max_total'] ?? 30);
    $totalSubjectActivitiesCount = (int)($actMeta['activity_count'] ?? 6);
    if ($totalSubjectMaxMarks <= 0) $totalSubjectMaxMarks = 30;
    if ($totalSubjectActivitiesCount <= 0) $totalSubjectActivitiesCount = 6;
    $actStmt->close();

    $studentsData = [];
    $submStmt = $conn->prepare("
        SELECT a.unit, s.marks, s.status, a.max_marks
        FROM submissions s
        JOIN activities a ON s.activity_id = a.activity_id
        WHERE s.student_id = ? AND UPPER(a.subject) = UPPER(?)
    ");

    while ($std = $stdRes->fetch_assoc()) {
        $sId = $std['id'];
        $submStmt->bind_param("is", $sId, $selectedSubject);
        $submStmt->execute();
        $submRes = $submStmt->get_result();

        $unitMarks = array_fill(1, 6, null);
        $submittedCount = 0;
        $totalMarks = 0;

        while ($subm = $submRes->fetch_assoc()) {
            // FIX: Use preg_replace to extract digits for units like "Unit 5"
            $uNum = (int)preg_replace('/[^0-9]/', '', $subm['unit']);
            if ($uNum >= 1 && $uNum <= 6) {
                $unitMarks[$uNum] = (float)$subm['marks'];
                $totalMarks += (float)$subm['marks'];
                $submittedCount++;
            }
        }

        $isComplete = ($submittedCount >= $totalSubjectActivitiesCount);
        if ($isComplete) {
            $status = 'Generated';
            $totalDisplay = $totalMarks . ' / ' . $totalSubjectMaxMarks;
            $finalDisplay = floor(($totalMarks * 20) / $totalSubjectMaxMarks) . ' / 20';
        } else {
            $status = 'Pending';
            $totalDisplay = '—';
            $finalDisplay = '—';
        }

        $studentsData[] = [
            'roll_no' => $std['roll_no'] ?: '—',
            'name' => $std['name'] ?: 'Unknown',
            'unit_marks' => $unitMarks,
            'total' => $totalDisplay,
            'final_cie' => $finalDisplay,
            'status' => $status,
            'department' => $std['department'] ?: 'Electronics and Computer Engineering'
        ];
    }
    $submStmt->close();
    $stdStmt->close();

    $deptName = !empty($studentsData) ? $studentsData[0]['department'] : 'Electronics and Computer Engineering';

    $safeYear = preg_replace('/[^A-Za-z0-9_\-]/', '_', $selectedYear);
    $safeSubject = preg_replace('/[^A-Za-z0-9_\-]/', '_', $selectedSubject);
    $safeDivision = preg_replace('/[^A-Za-z0-9_\-]/', '_', $selectedDivision);
    $filename = "CIE2_Marksheet_{$safeYear}_{$safeSubject}_{$safeDivision}.csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    $output = fopen('php://output', 'w');

    fputcsv($output, ["Zeal Education Society's"]);
    fputcsv($output, ["Zeal College of Engineering & Research, Pune"]);
    fputcsv($output, ["Statement of CIE-2 Marks - Division Report"]);
    fputcsv($output, []);

    fputcsv($output, ["Academic Year", $selectedYear]);
    fputcsv($output, ["Subject", $selectedSubject]);
    fputcsv($output, ["Division", $selectedDivision]);
    fputcsv($output, ["Department", $deptName]);
    fputcsv($output, []);

    fputcsv($output, ["Roll No", "Student Name", "Unit 1 (5)", "Unit 2 (5)", "Unit 3 (5)", "Unit 4 (5)", "Unit 5 (5)", "Unit 6 (5)", "Total (30)", "Final CIE (20)", "Status"]);

    foreach ($studentsData as $row) {
        $u1 = $row['unit_marks'][1] !== null ? $row['unit_marks'][1] : '—';
        $u2 = $row['unit_marks'][2] !== null ? $row['unit_marks'][2] : '—';
        $u3 = $row['unit_marks'][3] !== null ? $row['unit_marks'][3] : '—';
        $u4 = $row['unit_marks'][4] !== null ? $row['unit_marks'][4] : '—';
        $u5 = $row['unit_marks'][5] !== null ? $row['unit_marks'][5] : '—';
        $u6 = $row['unit_marks'][6] !== null ? $row['unit_marks'][6] : '—';

        fputcsv($output, [
            $row['roll_no'],
            $row['name'],
            $u1,
            $u2,
            $u3,
            $u4,
            $u5,
            $u6,
            $row['total'],
            $row['final_cie'],
            $row['status']
        ]);
    }

    fclose($output);
    exit;
}

// Check Step
$currentStep = 1;
if (!empty($selectedYear) && empty($selectedSubject)) {
    $currentStep = 2;
} elseif (!empty($selectedYear) && !empty($selectedSubject) && empty($selectedDivision)) {
    $currentStep = 3;
} elseif (!empty($selectedYear) && !empty($selectedSubject) && !empty($selectedDivision)) {
    $currentStep = 4;
}

$active_page = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | SAAES Admin</title>
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- html2pdf dependency for client-side PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        /* Color Palette & Custom Root Tokens */
        :root {
            --primary-blue: #0ea5e9;
            --primary-hover: #0284c7;
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --border-light: #e2e8f0;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            
            --sidebar-bg: #0f172a;
            --sidebar-text: #94a3b8;
            
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f97316;
            
            --radius-md: 10px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(15, 23, 42, 0.05);
            
            --font-head: 'Plus Jakarta Sans', sans-serif;
            --font-body: 'Inter', sans-serif;
        }

        /* Premium Hero Banner (matching the royal navy landing page overlay) */
        .premium-hero {
            background: linear-gradient(135deg, rgba(15, 32, 67, 0.92) 0%, rgba(15, 32, 67, 0.82) 50%, rgba(15, 32, 67, 0.5) 100%), url('../assets/images/college_building.jpg');
            background-size: cover;
            background-position: center;
            border-radius: var(--radius-lg);
            padding: 2.5rem;
            color: #ffffff;
            margin-bottom: 2rem;
            box-shadow: 0 12px 30px -10px rgba(15, 32, 67, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.08);
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
            font-family: var(--font-head);
            font-size: 1.75rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            line-height: 1.3;
            letter-spacing: -0.02em;
        }
        .hero-desc {
            font-size: 0.925rem;
            color: #cbd5e1;
            max-width: 650px;
        }

        /* Breadcrumbs navigation - Glass rounded pill style */
        .breadcrumb-nav {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.825rem;
            margin-bottom: 2rem;
            color: var(--text-muted);
            font-weight: 600;
            background: rgba(15, 23, 42, 0.03);
            padding: 10px 20px;
            border-radius: 50px;
            width: fit-content;
            border: 1px solid rgba(15, 23, 42, 0.02);
        }
        .breadcrumb-link {
            color: var(--primary-blue);
            text-decoration: none;
            transition: color 0.2s ease;
        }
        .breadcrumb-link:hover {
            color: var(--primary-hover);
        }
        .breadcrumb-sep {
            color: #94a3b8;
        }

        /* Module card design - Sleek light glass cards */
        .card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(15, 23, 42, 0.06);
            border-radius: var(--radius-lg);
            padding: 2.25rem;
            box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.02);
            margin-bottom: 2rem;
        }
        .card-header-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.75rem;
            flex-wrap: wrap;
            gap: 12px;
        }
        .card-title {
            font-family: var(--font-head);
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--text-dark);
            letter-spacing: -0.01em;
        }
        .card-subtitle {
            font-size: 0.88rem;
            color: var(--text-muted);
            margin-bottom: 1.75rem;
        }

        /* Selection cards grid with 3D translations & glows */
        .selection-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
        }
        .select-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: 1.75rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            text-decoration: none;
            color: var(--text-dark);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: var(--shadow-sm);
        }
        .select-card:hover {
            transform: translateY(-4px);
            border-color: var(--primary-blue);
            box-shadow: 0 15px 30px -10px rgba(14, 165, 233, 0.15);
        }
        .select-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: rgba(14, 165, 233, 0.06);
            color: var(--primary-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1.25rem;
            transition: all 0.3s ease;
        }
        .select-card:hover .select-icon {
            background: var(--primary-blue);
            color: #ffffff;
            transform: scale(1.1);
        }
        .select-title {
            font-family: var(--font-head);
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }
        .select-desc {
            font-size: 0.825rem;
            color: var(--text-muted);
        }

        /* Sleek Tables design */
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
            padding: 14px 18px;
            border-bottom: 1px solid var(--border-light);
            font-size: 0.9rem;
            vertical-align: middle;
        }
        .data-table th {
            background: #f8fafc;
            color: var(--text-dark);
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border-bottom: 2px solid var(--border-light);
        }
        .data-table tbody tr:hover {
            background: rgba(14, 165, 233, 0.02); /* Soft blue tint */
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.775rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            text-transform: uppercase;
        }
        .badge.warning { background: #fffbeb; color: #d97706; border: 1px solid #fef3c7; }
        .badge.success { background: #ecfdf5; color: #059669; border: 1px solid #d1fae5; }
        .badge.dark { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

        /* Premium Buttons - matching index.php hover gradients & scales */
        .btn-group {
            display: flex;
            gap: 8px;
        }
        .btn {
            font-family: var(--font-head);
            font-weight: 600;
            font-size: 0.8rem;
            padding: 8px 16px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-light);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            text-decoration: none;
            background: var(--bg-card);
            color: var(--text-dark);
        }
        .btn:hover {
            background: var(--bg-body);
            border-color: var(--text-muted);
            transform: translateY(-2px);
        }
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            border: 1.5px solid transparent;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
        }
        .btn-success:hover {
            background: linear-gradient(135deg, #34d399 0%, #10b981 100%);
            box-shadow: 0 6px 18px rgba(16, 185, 129, 0.35);
        }
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: #ffffff;
            border: 1.5px solid transparent;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
        }
        .btn-danger:hover {
            background: linear-gradient(135deg, #f87171 0%, #ef4444 100%);
            box-shadow: 0 6px 18px rgba(239, 68, 68, 0.35);
        }

        /* PDF print container layout (hidden on screen, visible to PDF exporter) */
        #pdfPrintArea {
            display: none;
            padding: 20px;
            background: #ffffff;
            font-family: 'Inter', sans-serif;
            color: #000000;
        }
        .pdf-title {
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .pdf-subtitle {
            text-align: center;
            font-size: 14px;
            color: #0ea5e9;
            margin-bottom: 20px;
            font-weight: bold;
        }
        .pdf-meta-table {
            width: 100%;
            margin-bottom: 20px;
            border-collapse: collapse;
        }
        .pdf-meta-table td {
            padding: 4px 8px;
            font-size: 11px;
            vertical-align: top;
        }
        .pdf-data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 40px;
        }
        .pdf-data-table th, .pdf-data-table td {
            border: 1px solid #cbd5e1;
            padding: 8px;
            font-size: 10px;
            text-align: center;
        }
        .pdf-data-table th {
            background-color: #f1f5f9;
            font-weight: bold;
        }
        .pdf-data-table td.name {
            text-align: left;
            font-weight: bold;
        }
        .pdf-signature-table {
            width: 100%;
            margin-top: 50px;
            text-align: center;
        }
        .pdf-signature-table td {
            font-size: 10px;
            padding-top: 50px;
        }
    </style>
</head>
<body>

<div class="admin-layout">
    <?php 
    $active_page = 'dashboard'; 
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
            <h1 class="hero-title">Student Activity Assessment &amp; Evaluation System (CIE 2)</h1>
            <p class="hero-desc">A smart platform to manage activities, submit assignments, evaluate and publish marksheets.</p>
        </div>

        <!-- Breadcrumbs -->
        <nav class="breadcrumb-nav">
            <a href="admin_dashboard.php" class="breadcrumb-link">Years</a>
            <?php if ($currentStep >= 2): ?>
                <span class="breadcrumb-sep">/</span>
                <a href="admin_dashboard.php?year=<?php echo urlencode($selectedYear); ?>" class="breadcrumb-link"><?php echo htmlspecialchars($selectedYear); ?></a>
            <?php endif; ?>
            <?php if ($currentStep >= 3): ?>
                <span class="breadcrumb-sep">/</span>
                <a href="admin_dashboard.php?year=<?php echo urlencode($selectedYear); ?>&subject=<?php echo urlencode($selectedSubject); ?>" class="breadcrumb-link"><?php echo htmlspecialchars($selectedSubject); ?></a>
            <?php endif; ?>
            <?php if ($currentStep == 4): ?>
                <span class="breadcrumb-sep">/</span>
                <span style="color: var(--text-dark); font-weight:700;"><?php echo htmlspecialchars($selectedDivision); ?></span>
            <?php endif; ?>
        </nav>

        <?php if ($currentStep == 1): ?>
            <!-- STEP 1: SELECT ACADEMIC YEAR -->
            <div class="card">
                <h2 class="card-title" style="margin-bottom: 0.5rem;"><i class="fa-solid fa-calendar-days"></i> Step 1: Select Academic Year</h2>
                <p class="card-subtitle">Choose an academic year to drill down into subjects and student evaluations.</p>
                
                <div class="selection-grid">
                    <?php 
                    $years = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
                    foreach ($years as $yr): 
                        $yrCode = $yearMapping[$yr];
                    ?>
                        <a href="admin_dashboard.php?year=<?php echo urlencode($yr); ?>" class="select-card">
                            <div class="select-icon"><i class="fa-solid fa-graduation-cap"></i></div>
                            <div class="select-title"><?php echo $yr; ?></div>
                            <div class="select-desc">View Courses & Evaluations</div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php elseif ($currentStep == 2): ?>
            <!-- STEP 2: SELECT SUBJECT -->
            <div class="card">
                <div class="card-header-flex">
                    <h2 class="card-title"><i class="fa-solid fa-book-bookmark"></i> Step 2: Select Subject (<?php echo htmlspecialchars($selectedYear); ?>)</h2>
                    <a href="admin_dashboard.php" class="btn"><i class="fa-solid fa-arrow-left"></i> Change Year</a>
                </div>
                <p class="card-subtitle">Select a subject mapped to <?php echo htmlspecialchars($selectedYear); ?> classes in this project.</p>
                
                <div class="selection-grid">
                    <?php
                    // Dynamic Subject retrieval from faculty_classes and activities mapped to this year code
                    $stmt = $conn->prepare("
                        SELECT DISTINCT fc.subject_code AS subject_code 
                        FROM faculty_classes fc 
                        WHERE UPPER(fc.academic_year) = UPPER(?) AND fc.subject_code != ''
                        UNION
                        SELECT DISTINCT a.subject AS subject_code 
                        FROM activities a
                        JOIN faculty_classes fc ON a.target_id = fc.class_id AND a.target_type = 'class'
                        WHERE UPPER(fc.academic_year) = UPPER(?) AND a.subject != ''
                        ORDER BY subject_code ASC
                    ");
                    $stmt->bind_param("ss", $yearDbCode, $yearDbCode);
                    $stmt->execute();
                    $res = $stmt->get_result();

                    if ($res && $res->num_rows > 0):
                        while ($sub = $res->fetch_assoc()):
                    ?>
                        <a href="admin_dashboard.php?year=<?php echo urlencode($selectedYear); ?>&subject=<?php echo urlencode($sub['subject_code']); ?>" class="select-card">
                            <div class="select-icon"><i class="fa-solid fa-book"></i></div>
                            <div class="select-title"><?php echo htmlspecialchars($sub['subject_code']); ?></div>
                            <div class="select-desc">Inspect marksheet matrix</div>
                        </a>
                    <?php 
                        endwhile;
                    else:
                    ?>
                        <div style="grid-column: 1 / -1; text-align: center; padding: 2rem; color: var(--text-muted);">
                            <i class="fa-regular fa-folder-open" style="font-size: 2rem; display: block; margin-bottom: 10px; opacity: 0.5;"></i>
                            No dynamic classes/subjects mapped in the database for <?php echo htmlspecialchars($selectedYear); ?>.
                        </div>
                    <?php endif; $stmt->close(); ?>
                </div>
            </div>

        <?php elseif ($currentStep == 3): ?>
            <!-- STEP 3: SELECT DIVISION -->
            <div class="card">
                <div class="card-header-flex">
                    <h2 class="card-title"><i class="fa-solid fa-users-rectangle"></i> Step 3: Select Division (<?php echo htmlspecialchars($selectedSubject); ?>)</h2>
                    <a href="admin_dashboard.php?year=<?php echo urlencode($selectedYear); ?>" class="btn"><i class="fa-solid fa-arrow-left"></i> Change Subject</a>
                </div>
                <p class="card-subtitle">Choose a division to view the student evaluation marks grid.</p>
                
                <div class="selection-grid">
                    <?php 
                    $divisions = ['Division A', 'Division B', 'Division C', 'Division D'];
                    foreach ($divisions as $div): 
                    ?>
                        <a href="admin_dashboard.php?year=<?php echo urlencode($selectedYear); ?>&subject=<?php echo urlencode($selectedSubject); ?>&division=<?php echo urlencode($div); ?>" class="select-card">
                            <div class="select-icon"><i class="fa-solid fa-users"></i></div>
                            <div class="select-title"><?php echo $div; ?></div>
                            <div class="select-desc">Student Marks Grid</div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php elseif ($currentStep == 4): ?>
            <!-- STEP 4: MARKS GRID -->
            <?php
            // Fetch students list
            $stdStmt = $conn->prepare("SELECT user_id AS id, COALESCE(NULLIF(name, ''), username) AS name, roll_no, email, department FROM users WHERE (role = 'Student' OR role = 'student') AND academic_year = ? AND division = ? ORDER BY roll_no ASC");
            $stdStmt->bind_param("ss", $yearDbCode, $selectedDivCode);
            $stdStmt->execute();
            $stdRes = $stdStmt->get_result();

            // Query total max marks of activities for this subject
            $actStmt = $conn->prepare("SELECT SUM(max_marks) AS max_total, COUNT(*) AS activity_count FROM activities WHERE UPPER(subject) = UPPER(?)");
            $actStmt->bind_param("s", $selectedSubject);
            $actStmt->execute();
            $actMeta = $actStmt->get_result()->fetch_assoc();
            $totalSubjectMaxMarks = (float)($actMeta['max_total'] ?? 30);
            $totalSubjectActivitiesCount = (int)($actMeta['activity_count'] ?? 6);
            if ($totalSubjectMaxMarks <= 0) $totalSubjectMaxMarks = 30;
            if ($totalSubjectActivitiesCount <= 0) $totalSubjectActivitiesCount = 6;
            $actStmt->close();

            $studentsData = [];
            $submStmt = $conn->prepare("
                SELECT a.unit, s.marks, s.status, a.max_marks
                FROM submissions s
                JOIN activities a ON s.activity_id = a.activity_id
                WHERE s.student_id = ? AND UPPER(a.subject) = UPPER(?)
            ");

            while ($std = $stdRes->fetch_assoc()) {
                $sId = $std['id'];
                $submStmt->bind_param("is", $sId, $selectedSubject);
                $submStmt->execute();
                $submRes = $submStmt->get_result();

                $unitMarks = array_fill(1, 6, null);
                $submittedCount = 0;
                $totalMarks = 0;

                while ($subm = $submRes->fetch_assoc()) {
                    // FIX: Use preg_replace to extract digits for units like "Unit 5"
                    $uNum = (int)preg_replace('/[^0-9]/', '', $subm['unit']);
                    if ($uNum >= 1 && $uNum <= 6) {
                        $unitMarks[$uNum] = (float)$subm['marks'];
                        $totalMarks += (float)$subm['marks'];
                        $submittedCount++;
                    }
                }

                $isComplete = ($submittedCount >= $totalSubjectActivitiesCount);
                if ($isComplete) {
                    $status = 'Generated';
                    $totalDisplay = $totalMarks . ' / ' . $totalSubjectMaxMarks;
                    $finalCie = floor(($totalMarks * 20) / $totalSubjectMaxMarks);
                    $finalDisplay = $finalCie . ' / 20';
                } else {
                    $status = 'Pending';
                    $totalDisplay = '—';
                    $finalDisplay = '—';
                }

                $studentsData[] = [
                    'roll_no' => $std['roll_no'] ?: '—',
                    'name' => $std['name'] ?: 'Unknown',
                    'unit_marks' => $unitMarks,
                    'total' => $totalDisplay,
                    'final_cie' => $finalDisplay,
                    'status' => $status,
                    'department' => $std['department'] ?: 'Electronics and Computer Engineering'
                ];
            }
            $submStmt->close();
            $stdStmt->close();

            $deptName = !empty($studentsData) ? $studentsData[0]['department'] : 'Electronics and Computer Engineering';
            ?>

            <div class="card">
                <div class="card-header-flex">
                    <div>
                        <h2 class="card-title" style="margin-bottom: 4px;"><i class="fa-solid fa-list-check"></i> Student Marks Grid</h2>
                        <div style="font-size: 0.85rem; color: var(--text-muted);">
                            <strong>Year:</strong> <?php echo htmlspecialchars($selectedYear); ?> &nbsp;|&nbsp; 
                            <strong>Subject:</strong> <?php echo htmlspecialchars($selectedSubject); ?> &nbsp;|&nbsp; 
                            <strong>Division:</strong> <?php echo htmlspecialchars($selectedDivision); ?>
                        </div>
                    </div>
                    
                    <div class="btn-group">
                        <a href="admin_dashboard.php?year=<?php echo urlencode($selectedYear); ?>&subject=<?php echo urlencode($selectedSubject); ?>&division=<?php echo urlencode($selectedDivision); ?>&export=excel" class="btn btn-success">
                            <i class="fa-solid fa-file-csv"></i> Export to Excel
                        </a>
                        <button type="button" onclick="exportPDF()" class="btn btn-danger">
                            <i class="fa-solid fa-file-pdf"></i> Export to PDF
                        </button>
                        <a href="admin_dashboard.php?year=<?php echo urlencode($selectedYear); ?>&subject=<?php echo urlencode($selectedSubject); ?>" class="btn">
                            <i class="fa-solid fa-arrow-rotate-left"></i> Change Division
                        </a>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="data-table" id="reportTable">
                        <thead>
                            <tr>
                                <th>Roll No</th>
                                <th>Student Name</th>
                                <th style="text-align: center;">U1 (5)</th>
                                <th style="text-align: center;">U2 (5)</th>
                                <th style="text-align: center;">U3 (5)</th>
                                <th style="text-align: center;">U4 (5)</th>
                                <th style="text-align: center;">U5 (5)</th>
                                <th style="text-align: center;">U6 (5)</th>
                                <th style="text-align: center;">Total</th>
                                <th style="text-align: center;">Final CIE (20)</th>
                                <th style="text-align: center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($studentsData)): ?>
                                <tr>
                                    <td colspan="11" style="text-align: center; color: var(--text-muted); padding: 2.5rem;">
                                        <i class="fa-regular fa-folder-open" style="font-size: 2rem; display: block; margin-bottom: 10px; opacity: 0.5;"></i>
                                        No students found registered for <?php echo htmlspecialchars($selectedYear); ?>, <?php echo htmlspecialchars($selectedDivision); ?>.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($studentsData as $row): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['roll_no']); ?></strong></td>
                                        <td style="font-weight: 600; color: var(--text-dark);"><?php echo htmlspecialchars($row['name']); ?></td>
                                        <?php for ($u = 1; $u <= 6; $u++): ?>
                                            <td style="text-align: center;">
                                                <?php echo ($row['unit_marks'][$u] !== null) ? $row['unit_marks'][$u] : '<span style="color: #cbd5e1;">—</span>'; ?>
                                            </td>
                                        <?php endfor; ?>
                                        <td style="text-align: center; font-weight: 700; color: var(--text-dark);">
                                            <?php echo $row['total']; ?>
                                        </td>
                                        <td style="text-align: center; font-weight: 800; color: var(--primary-blue);">
                                            <?php echo $row['final_cie']; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($row['status'] === 'Generated'): ?>
                                                <span class="badge success">Generated</span>
                                            <?php else: ?>
                                                <span class="badge warning">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- PDF PRINT AREA (HIDDEN ON SCREEN, RENDERED IN EXPORT) -->
            <div id="pdfPrintArea">
                <div class="pdf-title">Zeal Education Society's</div>
                <div class="pdf-title" style="margin-bottom: 6px;">Zeal College of Engineering &amp; Research, Pune</div>
                <div class="pdf-subtitle">Statement of CIE-2 Marks - Division Report</div>

                <table class="pdf-meta-table">
                    <tr>
                        <td width="50%"><strong>Academic Year:</strong> <?php echo htmlspecialchars($selectedYear); ?></td>
                        <td width="50%"><strong>Division:</strong> <?php echo htmlspecialchars($selectedDivision); ?></td>
                    </tr>
                    <tr>
                        <td width="50%"><strong>Subject:</strong> <?php echo htmlspecialchars($selectedSubject); ?></td>
                        <td width="50%"><strong>Department:</strong> <?php echo htmlspecialchars($deptName); ?></td>
                    </tr>
                </table>

                <table class="pdf-data-table">
                    <thead>
                        <tr>
                            <th width="10%">Roll No</th>
                            <th width="30%">Student Name</th>
                            <th width="7%">U1 (5)</th>
                            <th width="7%">U2 (5)</th>
                            <th width="7%">U3 (5)</th>
                            <th width="7%">U4 (5)</th>
                            <th width="7%">U5 (5)</th>
                            <th width="7%">U6 (5)</th>
                            <th width="9%">Total</th>
                            <th width="9%">CIE (20)</th>
                            <th width="10%">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($studentsData)): ?>
                            <tr>
                                <td colspan="11">No student data available.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($studentsData as $row): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['roll_no']); ?></strong></td>
                                    <td class="name"><?php echo htmlspecialchars($row['name']); ?></td>
                                    <?php for ($u = 1; $u <= 6; $u++): ?>
                                        <td><?php echo ($row['unit_marks'][$u] !== null) ? $row['unit_marks'][$u] : '—'; ?></td>
                                    <?php endfor; ?>
                                    <td style="font-weight: bold;"><?php echo explode(' / ', $row['total'])[0]; ?></td>
                                    <td style="font-weight: bold; color: #0ea5e9;"><?php echo explode(' / ', $row['final_cie'])[0]; ?></td>
                                    <td><?php echo $row['status']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <table class="pdf-signature-table">
                    <tr>
                        <td width="33%">____________________<br/>Group Advisor</td>
                        <td width="33%">____________________<br/>Class Coordinator</td>
                        <td width="33%">____________________<br/>Head of Department</td>
                    </tr>
                </table>
            </div>

            <script>
            function exportPDF() {
                var element = document.getElementById('pdfPrintArea');
                
                // Temporarily display the print container so html2pdf can capture it
                element.style.display = 'block';
                
                var opt = {
                    margin:       15,
                    filename:     'CIE2_Marksheet_<?php echo $selectedYear . "_" . $selectedSubject . "_" . $selectedDivision; ?>.pdf',
                    image:        { type: 'jpeg', quality: 0.98 },
                    html2canvas:  { scale: 2.5, useCORS: true },
                    jsPDF:        { unit: 'mm', format: 'a4', orientation: 'landscape' }
                };
                
                html2pdf().set(opt).from(element).save().then(function() {
                    // Hide the print container again after generating PDF
                    element.style.display = 'none';
                });
            }
            </script>

        <?php endif; ?>
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