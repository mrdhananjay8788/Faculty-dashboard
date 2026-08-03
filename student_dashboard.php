<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-caching headers to prevent browser back-button access after logout
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/config/db.php';

// TEMPORARY: Auto-login as the first student for testing without login
if (empty($_SESSION['user_id'])) {
    $stmtTest = $pdo->query("SELECT user_id, name FROM users WHERE LOWER(role) = 'student' LIMIT 1");
    $testStudent = $stmtTest->fetch(PDO::FETCH_ASSOC);
    if ($testStudent) {
        $_SESSION['user_id'] = $testStudent['user_id'];
        $_SESSION['role'] = 'student';
        $_SESSION['full_name'] = $testStudent['name'];
    }
}

if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'student') {
    header('Location: auth/login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function e($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function fmtDate($d) { return $d ? (new DateTime($d))->format('d M Y') : '—'; }
function fmtDateTime($d) { return $d ? (new DateTime($d))->format('d M Y, h:i A') : '—'; }

$studentUserId = (int) $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Student';
$_SESSION['full_name'] = $fullName;

// 1. Fetch student user profile directly from users table
$stmt = $pdo->prepare("SELECT user_id, name, username, email, role, department, academic_year, roll_no, division, phone, linked_student_prn FROM users WHERE user_id = ? LIMIT 1");
$stmt->execute([$studentUserId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$studentTableId   = (int) ($student['user_id'] ?? $studentUserId);
$_SESSION['student_id'] = $studentTableId;
$studentUsername  = $student['username'] ?? ($_SESSION['username'] ?? '');
$studentLinkedPrn = $student['linked_student_prn'] ?? $studentUsername;
$role             = $_SESSION['role'] ?? 'student';

// Determine PRN / ZPRN display label (uses real registered PRN)
$studentZprn      = !empty($studentUsername) ? $studentUsername : ('ZPRN' . str_pad((string)$studentTableId, 4, '0', STR_PAD_LEFT));

// Determine Roll No, Division, and Academic Year from users table
$rollNo           = !empty($student['roll_no']) ? $student['roll_no'] : 'N/A';
$division         = !empty($student['division']) ? $student['division'] : 'N/A';
$academic_year    = !empty($student['academic_year']) ? $student['academic_year'] : 'FY';

// Determine Department, Academic Year, Division with Auto-healing sync from access_requests if blank
$deptName = trim($student['department'] ?? '');
if (empty($deptName) || $deptName === 'N/A' || $division === 'N/A' || $academic_year === 'FY') {
    try {
        $stmtAr = $pdo->prepare("SELECT department, academic_year, division FROM access_requests WHERE (UPPER(prn_number) = UPPER(?) OR LOWER(email) = LOWER(?)) AND department != '' ORDER BY request_id DESC LIMIT 1");
        $stmtAr->execute([$studentUsername, $student['email'] ?? '']);
        $arRow = $stmtAr->fetch(PDO::FETCH_ASSOC);
        if ($arRow) {
            $updateFields = [];
            $updateParams = [];
            
            if ((empty($deptName) || $deptName === 'N/A') && !empty($arRow['department'])) {
                $deptName = trim($arRow['department']);
                $updateFields[] = "department = ?";
                $updateParams[] = $deptName;
            }
            if ($academic_year === 'FY' && !empty($arRow['academic_year'])) {
                $academic_year = trim($arRow['academic_year']);
                $updateFields[] = "academic_year = ?";
                $updateParams[] = $academic_year;
            }
            if ($division === 'N/A' && !empty($arRow['division'])) {
                $division = trim($arRow['division']);
                $updateFields[] = "division = ?";
                $updateParams[] = $division;
            }
            
            // Sync back to users table permanently
            if (!empty($updateFields)) {
                $updateParams[] = $studentUserId;
                $stmtUpDept = $pdo->prepare("UPDATE users SET " . implode(", ", $updateFields) . " WHERE user_id = ?");
                $stmtUpDept->execute($updateParams);
            }
        }
    } catch (PDOException $e) {
        // Swallowed safely
    }
}
if (empty($deptName)) {
    $deptName = 'Electronics and Computer Engineering';
}

// 2. Fetch Joined Classes for the Sidebar
$stmtClasses = $pdo->prepare("
    SELECT DISTINCT fc.class_id, fc.class_name, fc.subject_code, fc.description, u.name AS faculty_name
    FROM faculty_classes fc
    LEFT JOIN users u ON fc.faculty_id = u.user_id
    LEFT JOIN faculty_class_students fcs ON fcs.class_id = fc.class_id
    WHERE (fc.department = ? AND fc.academic_year = ? AND fc.division = ?)
       OR (fcs.student_prn = ? OR fcs.student_prn = ?)
    ORDER BY fc.created_at DESC
");
$stmtClasses->execute([$deptName, $academic_year, $division, $studentUsername, $studentLinkedPrn]);
$myClasses = $stmtClasses->fetchAll(PDO::FETCH_ASSOC) ?: [];

// 3. Fetch all activities & student submissions
$stmtAct = $pdo->prepare("
    SELECT DISTINCT a.activity_id AS id, a.type, a.subject AS subject_code, a.unit, a.title, a.description, a.due_date, a.max_marks,
           s.id AS submission_id, s.original_filename, s.submission_date, s.status AS sub_status,
           s.marks, s.file_type, s.remarks
    FROM activities a
    LEFT JOIN submissions s ON s.activity_id = a.activity_id AND s.student_id = ?
    WHERE a.target_type = 'all' 
       OR (a.target_type = 'individual' AND a.target_id = ?)
       OR (a.target_type IN ('class', 'group') AND a.target_id IN (
           SELECT fc.class_id FROM faculty_classes fc 
           LEFT JOIN faculty_class_students fcs ON fcs.class_id = fc.class_id
           WHERE (fc.department = ? AND fc.academic_year = ? AND fc.division = ?)
              OR (fcs.student_prn = ? OR fcs.student_prn = ?)
       ))
    ORDER BY a.due_date ASC
");
$stmtAct->execute([$studentTableId, $studentTableId, $deptName, $academic_year, $division, $studentUsername, $studentLinkedPrn]);
$activities = $stmtAct->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalActivities  = count($activities);
$totalSubmitted   = 0;
$totalEvaluated   = 0;
$totalMissed      = 0;
$totalPending     = 0;
$totalEarnedMarks = 0;
$maxPossibleMarks = 0;
$subjectStats     = [];
$upcomingDeadlines = [];
$actionRequiredItems = [];

$now = new DateTime();
foreach ($activities as &$a) {
    $maxPossibleMarks += (float)($a['max_marks'] ?? 5);
    $due = new DateTime($a['due_date']);
    $subj = $a['subject_code'];

    if (!isset($subjectStats[$subj])) {
        $subjectStats[$subj] = ['total' => 0, 'completed' => 0, 'earned' => 0, 'max' => 0];
    }
    $subjectStats[$subj]['total']++;
    $subjectStats[$subj]['max'] += (float)($a['max_marks'] ?? 5);

    if (!empty($a['submission_id'])) {
        $totalSubmitted++;
        $subjectStats[$subj]['completed']++;
        
        if ($a['sub_status'] === 'Submitted' && $a['marks'] !== null) {
            $totalEvaluated++;
            $totalEarnedMarks += (float) $a['marks'];
            $subjectStats[$subj]['earned'] += (float) $a['marks'];
            $a['display_status'] = 'Evaluated';
        } elseif ($a['sub_status'] === 'Approved') {
            $totalEvaluated++;
            $totalEarnedMarks += (float) ($a['marks'] ?? $a['max_marks']);
            $subjectStats[$subj]['earned'] += (float) ($a['marks'] ?? $a['max_marks']);
            $a['display_status'] = 'Evaluated';
        } else {
            $a['display_status'] = 'Submitted';
        }
    } else {
        if ($now > $due) {
            $totalMissed++;
            $a['display_status'] = 'Missed';
        } else {
            $totalPending++;
            $a['display_status'] = 'Pending';
            
            $diff = $now->diff($due);
            $daysLeft = $diff->days;
            $hoursLeft = $diff->h;
            
            if ($daysLeft == 0) {
                $a['countdown_label'] = "Due in {$hoursLeft} hrs";
                $a['countdown_class'] = "danger";
            } elseif ($daysLeft <= 3) {
                $a['countdown_label'] = "Due in {$daysLeft} day" . ($daysLeft > 1 ? 's' : '');
                $a['countdown_class'] = "warning";
            } else {
                $a['countdown_label'] = "Due in {$daysLeft} days";
                $a['countdown_class'] = "info";
            }

            if (!$diff->invert) {
                $upcomingDeadlines[] = $a;
            }
            if ($daysLeft <= 7 && !$diff->invert) {
                $actionRequiredItems[] = $a;
            }
        }
    }
}
unset($a);

$overallCompletionRate = $totalActivities > 0 ? round(($totalSubmitted / $totalActivities) * 100) : 0;
$scorePercent = $maxPossibleMarks > 0 ? round(($totalEarnedMarks / $maxPossibleMarks) * 100) : 0;

if ($scorePercent >= 85) {
    $standingBadge = ['label' => 'Outstanding', 'color' => '#10b981'];
} elseif ($scorePercent >= 70) {
    $standingBadge = ['label' => 'Good', 'color' => '#2563eb'];
} elseif ($scorePercent >= 50) {
    $standingBadge = ['label' => 'Average', 'color' => '#f59e0b'];
} else {
    $standingBadge = ['label' => 'Needs Attention', 'color' => '#ef4444'];
}

// Recent Graded Submissions Snapshot
$recentEvaluated = array_values(array_filter($activities, fn($x) => !empty($x['submission_id'])));
usort($recentEvaluated, fn($x, $y) => strtotime($y['submission_date']) <=> strtotime($x['submission_date']));
$recentEvaluatedSnapshot = array_slice($recentEvaluated, 0, 4);

// Closest Upcoming Deadline calculation
$closestDeadline = !empty($upcomingDeadlines) ? $upcomingDeadlines[0] : null;

// Notification Feed Construction
$notifications = [];

// 1. Graded evaluations
foreach ($recentEvaluatedSnapshot as $rEval) {
    if (!empty($rEval['submission_id']) && $rEval['marks'] !== null) {
        $notifications[] = [
            'id' => 'eval_' . $rEval['submission_id'],
            'type' => 'grade',
            'title' => 'Grade Posted: ' . $rEval['title'],
            'desc' => 'Score: ' . number_format((float)$rEval['marks'], 1) . ' / ' . number_format((float)$rEval['max_marks'], 1) . ' (' . $rEval['subject_code'] . ')',
            'time' => fmtDate($rEval['submission_date']),
            'link' => 'student_history.php',
            'icon' => 'fa-award',
            'color' => '#10b981'
        ];
    }
}

// 2. Impending deadlines (< 4 days)
foreach ($upcomingDeadlines as $upD) {
    $dueDtNotif = new DateTime($upD['due_date']);
    $diffNotif = $now->diff($dueDtNotif);
    if ($diffNotif->days <= 3 && !$diffNotif->invert) {
        $notifications[] = [
            'id' => 'due_' . $upD['id'],
            'type' => 'deadline',
            'title' => 'Upcoming Deadline: ' . $upD['title'],
            'desc' => $upD['subject_code'] . ' Unit ' . $upD['unit'] . ' - ' . $upD['countdown_label'],
            'time' => fmtDate($upD['due_date']),
            'link' => 'student_submit.php?activity_id=' . $upD['id'],
            'icon' => 'fa-clock',
            'color' => '#ef4444'
        ];
    }
}

// 3. System Announcement
$notifications[] = [
    'id' => 'sys_welcome_' . $studentUserId,
    'type' => 'system',
    'title' => 'CIE 2 Evaluation Portal Active',
    'desc' => 'Department of ' . $deptName . ' (' . $academic_year . ' Div ' . $division . ')',
    'time' => 'Today',
    'link' => 'student_dashboard.php',
    'icon' => 'fa-circle-info',
    'color' => '#4f46e5'
];

$notificationsJson = json_encode($notifications);


// Prepare Chart.js Analytics Datasets
$chartTrendLabels = [];
$chartTrendData = [];
$evalChronological = array_values(array_filter($activities, fn($x) => !empty($x['submission_id']) && $x['marks'] !== null));
usort($evalChronological, fn($a, $b) => strtotime($a['submission_date'] ?? 'now') <=> strtotime($b['submission_date'] ?? 'now'));

if (count($evalChronological) > 0) {
    foreach ($evalChronological as $ec) {
        $chartTrendLabels[] = fmtDate($ec['submission_date']);
        $pct = ($ec['max_marks'] > 0) ? round(($ec['marks'] / $ec['max_marks']) * 100) : 100;
        $chartTrendData[] = $pct;
    }
} else {
    $chartTrendLabels = ['Week 1', 'Week 2', 'Week 3', 'Week 4'];
    $chartTrendData = [75, 82, 88, max(50, $scorePercent)];
}

$doughnutLabels = ['Evaluated', 'Pending Submit', 'Under Review', 'Missed'];
$doughnutData = [
    (int) $totalEvaluated,
    (int) $totalPending,
    (int) max(0, $totalSubmitted - $totalEvaluated),
    (int) $totalMissed
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Dashboard | SAAES</title>

<!-- Premium Fonts & Icons -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<!-- Chart.js Engine -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* ==========================================================================
   MODERN PREMIUM DESIGN SYSTEM
   ========================================================================== */
:root {
  --bg-body: #f0f7ff;
  --bg-card: #ffffff;
  --bg-card-glass: rgba(255, 255, 255, 0.92);
  --navy-primary: #0f172a;
  --navy-sidebar: #0f172a;
  --accent-primary: #0ea5e9;
  --accent-hover: #0284c7;
  --accent-light: #e0f2fe;
  --accent-gradient: linear-gradient(135deg, #0ea5e9 0%, #38bdf8 50%, #0284c7 100%);
  --hero-gradient: linear-gradient(135deg, #0284c7 0%, #0ea5e9 50%, #38bdf8 100%);
  --text-main: #1e293b;
  --text-muted: #64748b;
  --border-color: #e2e8f0;
  --border-glow: rgba(14, 165, 233, 0.25);
  
  --success: #10b981;
  --success-bg: #dcfce7;
  --success-border: #bbf7d0;
  --success-text: #047857;
  --danger: #ef4444;
  --danger-bg: #fee2e2;
  --danger-border: #fecaca;
  --danger-text: #991b1b;
  --warning: #f59e0b;
  --warning-bg: #ffedd5;
  --warning-border: #fed7aa;
  --warning-text: #9a3412;
  --info: #0284c7;
  --info-bg: #e0f2fe;
  --info-border: #bae6fd;
  --info-text: #075985;
  
  --radius-sm: 10px;
  --radius-md: 12px;
  --radius-lg: 16px;
  --radius-xl: 16px;
  
  --shadow-sm: 0 4px 12px -2px rgba(14, 165, 233, 0.08), 0 2px 6px -1px rgba(15, 23, 42, 0.03);
  --shadow-md: 0 10px 25px -4px rgba(14, 165, 233, 0.12), 0 4px 10px -2px rgba(15, 23, 42, 0.04);
  --shadow-lg: 0 20px 30px -8px rgba(14, 165, 233, 0.18), 0 10px 15px -5px rgba(15, 23, 42, 0.04);
  --shadow-glow: 0 8px 25px -5px rgba(14, 165, 233, 0.4);
  
  --font-body: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
  --font-heading: 'Outfit', 'Plus Jakarta Sans', system-ui, sans-serif;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
  font-family: var(--font-body);
  background-color: var(--bg-body);
  color: var(--text-main);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  line-height: 1.5;
  -webkit-font-smoothing: antialiased;
}

h1, h2, h3, h4, h5, h6 { font-family: var(--font-heading); }

::selection { background: var(--accent-primary); color: #fff; }
a { text-decoration: none; color: inherit; }

.app-container { display: flex; min-height: 100vh; width: 100%; position: relative; }

/* ================= SIDEBAR ================= */
.sidebar-overlay {
  position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6);
  backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
  z-index: 190; opacity: 0; pointer-events: none; display: none; visibility: hidden;
  transition: opacity 0.3s ease, visibility 0.3s ease;
}
.sidebar-overlay.show { opacity: 1; pointer-events: auto; display: block; visibility: visible; }

.sidebar {
  width: 280px;
  background: var(--navy-sidebar);
  background-image: linear-gradient(180deg, #0f172a 0%, #0369a1 100%);
  color: #f8fafc;
  display: flex; flex-direction: column;
  position: fixed; top: 0; bottom: 0; left: 0; z-index: 200;
  transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  box-shadow: 4px 0 25px rgba(0, 0, 0, 0.15);
}

.sidebar-header {
  padding: 1.5rem; border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  display: flex; align-items: center; justify-content: space-between;
}
.brand-logo {
  display: flex; align-items: center; gap: 0.85rem;
  font-family: var(--font-heading); font-weight: 700; font-size: 1.3rem; color: #fff;
}
.brand-logo-icon {
  width: 40px; height: 40px; border-radius: var(--radius-sm);
  background: var(--accent-gradient); display: flex; align-items: center; justify-content: center;
  font-size: 1.2rem; color: #fff; box-shadow: var(--shadow-glow);
}

.sidebar-menu { padding: 1.5rem 1rem; display: flex; flex-direction: column; gap: 0.35rem; flex: 1; overflow-y: auto; }
.sidebar-menu::-webkit-scrollbar { width: 4px; }
.sidebar-menu::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.15); border-radius: 4px; }

.menu-label {
  font-size: 0.685rem; color: #64748b; margin: 1.6rem 0.6rem 0.45rem;
  font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em;
  display: flex; justify-content: space-between; align-items: center;
}

.sidebar-link {
  display: flex; align-items: center; gap: 0.85rem; padding: 0.75rem 1rem;
  color: #cbd5e1; font-weight: 500; font-size: 0.925rem; border-radius: var(--radius-sm);
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); position: relative;
}
.sidebar-link:hover { background: rgba(255, 255, 255, 0.08); color: #fff; transform: translateX(3px); }
.sidebar-link.active {
  background: var(--accent-gradient); color: #fff; font-weight: 600;
  box-shadow: 0 4px 14px rgba(14, 165, 233, 0.4);
}
.sidebar-link i { font-size: 1.05rem; width: 22px; text-align: center; color: #94a3b8; transition: color 0.2s; }
.sidebar-link:hover i, .sidebar-link.active i { color: #fff; }

/* Joined Classes UI in Sidebar */
.joined-class-item {
  padding: 0.8rem 0.95rem; margin-bottom: 0.6rem;
  background: rgba(255, 255, 255, 0.06); border: 1px solid rgba(255, 255, 255, 0.1);
  border-left: 3px solid var(--accent-primary); border-radius: var(--radius-sm);
  font-size: 0.825rem; transition: background 0.2s, transform 0.2s;
}
.joined-class-item:hover { background: rgba(255, 255, 255, 0.1); transform: translateX(2px); }
.joined-class-item strong { display: block; color: #f8fafc; margin-bottom: 0.25rem; white-space: normal; word-break: break-word; line-height: 1.35; font-weight: 600; font-size: 0.825rem; }
.joined-class-item span { color: #94a3b8; font-size: 0.775rem; }

.sidebar-user {
  padding: 1.25rem; border-top: 1px solid rgba(255, 255, 255, 0.08);
  display: flex; align-items: center; gap: 0.85rem; background: rgba(15, 23, 42, 0.6);
}
.avatar {
  width: 42px; height: 42px; background: var(--accent-gradient); border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-heading); font-weight: 700; font-size: 1.1rem; color: #fff;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2); position: relative;
}
.avatar::after {
  content: ''; position: absolute; bottom: 0; right: 0; width: 11px; height: 11px;
  background: var(--success); border: 2px solid #0f172a; border-radius: 50%;
}

/* ================= MAIN CONTENT ================= */
.content-wrapper {
  margin-left: 280px; flex: 1; display: flex; flex-direction: column; min-height: 100vh; width: calc(100% - 280px);
  transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1), width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.app-container.sidebar-collapsed .sidebar {
  transform: translateX(-100%) !important;
}
.app-container.sidebar-collapsed .content-wrapper {
  margin-left: 0 !important;
  width: 100% !important;
}


.top-navbar {
  background: var(--bg-card-glass); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
  border-bottom: 1px solid var(--border-color); padding: 0 2rem;
  position: sticky; top: 0; z-index: 100; height: 70px; display: flex; align-items: center;
}
.top-navbar-inner {
  max-width: 1400px; width: 100%; margin: 0 auto;
  display: flex; justify-content: space-between; align-items: center;
}
.top-navbar h3 { font-size: 1.25rem; font-weight: 700; color: var(--navy-primary); margin: 0; }

.main-content { padding: 2rem; flex: 1; max-width: 1400px; width: 100%; margin: 0 auto; display: flex; flex-direction: column; gap: 1.75rem; }

/* ================= MODULE CARDS & ANALYTICS ================= */
.module-card {
  background: var(--bg-card); border: 1px solid var(--border-color);
  padding: 1.75rem 2rem; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);
  transition: box-shadow 0.2s, border-color 0.2s;
}
.module-card:hover { box-shadow: var(--shadow-md); border-color: #cbd5e1; }

.hero-banner {
  background: var(--hero-gradient); color: #fff;
  padding: 2.5rem 2.75rem; border-radius: var(--radius-lg); box-shadow: var(--shadow-md);
  position: relative; overflow: hidden;
}
.hero-banner::before {
  content: ''; position: absolute; top: -50%; right: -10%; width: 350px; height: 350px;
  background: radial-gradient(circle, rgba(99, 102, 241, 0.25) 0%, rgba(255, 255, 255, 0) 70%);
  border-radius: 50%; pointer-events: none;
}
.hero-banner::after {
  content: ''; position: absolute; bottom: -40%; left: 30%; width: 250px; height: 250px;
  background: radial-gradient(circle, rgba(59, 130, 246, 0.2) 0%, rgba(255, 255, 255, 0) 70%);
  border-radius: 50%; pointer-events: none;
}
.hero-content { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem; position: relative; z-index: 1; }
.hero-title { font-size: 2.1rem; font-weight: 800; margin-bottom: 0.85rem; letter-spacing: -0.02em; color: #ffffff; }
.hero-pills { display: flex; gap: 0.85rem; flex-wrap: wrap; align-items: center; }

.analytics-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; }

.sys-tag {
  font-size: 0.75rem; font-weight: 700; padding: 0.35rem 0.85rem; border-radius: 20px;
  display: inline-flex; align-items: center; gap: 0.45rem; background: #f1f5f9; color: #475569;
  border: 1px solid var(--border-color); line-height: 1; transition: all 0.2s;
  width: auto; max-width: fit-content; flex-shrink: 0; align-self: flex-start; margin: 0;
}
.sys-tag.accent { background: #e0f2fe; color: #0369a1; border-color: #bae6fd; }
.sys-tag.success { background: var(--success-bg); color: var(--success-text); border-color: var(--success-border); }
.sys-tag.danger { background: var(--danger-bg); color: var(--danger-text); border-color: var(--danger-border); }
.sys-tag.warning { background: var(--warning-bg); color: var(--warning-text); border-color: var(--warning-border); }
.sys-tag.info { background: var(--info-bg); color: var(--info-text); border-color: var(--info-border); }
.sys-tag.hero {
  background: rgba(255, 255, 255, 0.12); color: rgba(255, 255, 255, 0.92);
  border: 1px solid rgba(255, 255, 255, 0.15); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
  font-weight: 500; font-size: 0.825rem; padding: 0.45rem 1.15rem; gap: 0.6rem;
}

/* ================= BUTTONS ================= */
.btn {
  font-family: var(--font-body); font-weight: 600; font-size: 0.875rem;
  padding: 0.65rem 1.35rem; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
  border-radius: var(--radius-sm); border: 1px solid transparent; cursor: pointer; transition: all 0.2s ease; text-decoration: none;
  line-height: 1.25;
}
.btn-primary { background: var(--accent-gradient); color: #fff; box-shadow: var(--shadow-glow); }
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.45); color: #fff; }

.btn-danger { background: var(--danger); color: #fff; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25); }
.btn-danger:hover { background: #dc2626; transform: translateY(-1px); color: #fff; }

.btn-outline { background: var(--bg-card); border-color: var(--border-color); color: var(--text-main); }
.btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; transform: translateY(-1px); }

/* ================= STATS GRID ================= */
.stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; }
.stat-block {
  background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-md);
  padding: 1.35rem 1.5rem; display: flex; flex-direction: column; justify-content: space-between;
  box-shadow: var(--shadow-sm); position: relative; overflow: hidden; transition: transform 0.2s, box-shadow 0.2s;
}
.stat-block:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
.stat-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem; width: 100%; }
.stat-icon {
  width: 44px; height: 44px; border-radius: var(--radius-sm); display: flex;
  align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0;
}
.stat-val { font-family: var(--font-heading); font-size: 1.85rem; font-weight: 800; color: var(--navy-primary); line-height: 1.1; margin-bottom: 0.25rem; }
.stat-label { font-size: 0.825rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }

.analytics-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; align-items: stretch; }
.analytics-grid > .module-card { display: flex; flex-direction: column; justify-content: space-between; height: 100%; }

/* ================= EVALUATION CARDS & GRIDS ================= */
.task-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; }
.task-card {
  background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.5rem 1.65rem;
  display: flex; flex-direction: column; justify-content: space-between; box-shadow: var(--shadow-sm);
  transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.25s, border-color 0.25s;
}
.task-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); border-color: #cbd5e1; }

.eval-card {
  background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-md);
  padding: 1.5rem 1.65rem; display: flex; flex-direction: column; justify-content: space-between;
  box-shadow: var(--shadow-sm); transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
}
.eval-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); border-color: #cbd5e1; }
.eval-footer {
  font-size: 0.825rem; color: var(--text-muted);
  border-top: 1px solid var(--border-color);
  padding-top: 1rem; margin-top: 1rem;
  display: flex; justify-content: space-between; align-items: center;
}

/* ================= CLOSEST UPCOMING DEADLINE WIDGET ================= */
.closest-deadline-card {
  border-radius: var(--radius-lg);
  padding: 1.75rem 2rem;
  color: #ffffff;
  position: relative;
  overflow: hidden;
  box-shadow: var(--shadow-md);
  transition: transform 0.2s, box-shadow 0.2s;
  margin-bottom: 0.5rem;
}
.closest-deadline-card.urgency-normal, .closest-deadline-card.urgency-warning {
  background: linear-gradient(135deg, #0369a1 0%, #0284c7 45%, #38bdf8 100%);
  border: 1px solid rgba(56, 189, 248, 0.4);
}
.closest-deadline-card.urgency-critical, .closest-deadline-card.urgency-overdue {
  background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 50%, #dc2626 100%);
  border: 1px solid rgba(248, 113, 113, 0.4);
}
.closest-deadline-card::before {
  content: ''; position: absolute; top: -50px; right: -50px; width: 220px; height: 220px;
  background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0) 70%);
  border-radius: 50%; pointer-events: none;
}
.closest-deadline-badge {
  display: inline-flex; align-items: center; gap: 0.5rem;
  font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em;
  padding: 0.35rem 0.85rem; border-radius: 20px;
  background: rgba(255, 255, 255, 0.18); backdrop-filter: blur(6px);
  color: #ffffff; margin-bottom: 1.25rem; border: 1px solid rgba(255, 255, 255, 0.25);
}
.closest-deadline-body {
  display: flex; justify-content: space-between; align-items: center;
  flex-wrap: wrap; gap: 1.75rem; position: relative; z-index: 2;
}
.closest-deadline-main { flex: 1; min-width: 280px; }
.closest-deadline-tags { display: flex; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 0.75rem; }
.closest-deadline-title {
  font-size: 1.65rem; font-weight: 800; color: #ffffff;
  margin-bottom: 0.5rem; line-height: 1.25; font-family: var(--font-heading);
}
.closest-deadline-meta { font-size: 0.9rem; color: rgba(255, 255, 255, 0.85); margin: 0; }

.closest-deadline-timer-box {
  background: rgba(15, 23, 42, 0.35); border: 1px solid rgba(255, 255, 255, 0.18);
  backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
  padding: 1.25rem 1.5rem; border-radius: var(--radius-md); text-align: center;
  min-width: 290px; box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.1);
}
.timer-label {
  font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.08em; color: rgba(255, 255, 255, 0.75); margin-bottom: 0.65rem;
}
.live-countdown-clock { display: flex; align-items: center; justify-content: center; gap: 0.4rem; margin-bottom: 0.85rem; }
.timer-unit { display: flex; flex-direction: column; align-items: center; min-width: 44px; }
.unit-val {
  font-family: var(--font-heading); font-size: 1.6rem; font-weight: 800;
  line-height: 1; color: #ffffff; background: rgba(255, 255, 255, 0.12);
  padding: 0.4rem 0.6rem; border-radius: 8px; width: 100%; text-align: center;
  box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.2); border: 1px solid rgba(255, 255, 255, 0.15);
}
.unit-lbl { font-size: 0.65rem; font-weight: 600; text-transform: uppercase; color: rgba(255, 255, 255, 0.7); margin-top: 0.3rem; }
.timer-colon { font-size: 1.3rem; font-weight: 800; color: rgba(255, 255, 255, 0.6); margin-top: -0.8rem; }

.btn-submit-now {
  background: #ffffff !important; color: #1e1b4b !important; font-weight: 700 !important;
  font-size: 0.875rem !important; padding: 0.65rem 1.25rem !important; width: 100%;
  border-radius: var(--radius-sm); border: none !important; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2);
  transition: all 0.2s ease; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
}
.btn-submit-now:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3); background: #f8fafc !important; }

.no-deadline-card {
  border-left: 5px solid var(--success);
  background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%);
  padding: 1.25rem 1.5rem;
}
.no-deadline-icon {
  width: 48px; height: 48px; border-radius: var(--radius-md);
  background: var(--success-bg); color: var(--success);
  display: flex; align-items: center; justify-content: center; font-size: 1.4rem; flex-shrink: 0;
}

/* ================= NOTIFICATION BELL SYSTEM ================= */
.notif-wrapper { position: relative; display: inline-block; }
.notif-bell-btn {
  width: 40px; height: 40px; border-radius: 50%;
  background: #ffffff; border: 1px solid var(--border-color);
  color: var(--navy-primary); display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; cursor: pointer; position: relative; transition: all 0.2s ease;
  box-shadow: var(--shadow-sm);
}
.notif-bell-btn:hover { background: #f8fafc; border-color: #cbd5e1; transform: translateY(-1px); color: var(--accent-primary); }

.notif-badge {
  position: absolute; top: -3px; right: -3px;
  background: #ef4444; color: #ffffff; font-size: 0.65rem; font-weight: 800;
  min-width: 18px; height: 18px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center; padding: 0 4px;
  border: 2px solid #ffffff; box-shadow: 0 2px 6px rgba(239, 68, 68, 0.4);
  animation: pulse-ring 2s infinite;
}
@keyframes pulse-ring {
  0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
  70% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); }
  100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
}

.notif-dropdown {
  position: absolute; top: calc(100% + 12px); right: 0; width: 340px;
  background: #ffffff; border: 1px solid var(--border-color);
  border-radius: var(--radius-md); box-shadow: var(--shadow-lg);
  z-index: 250; display: none; opacity: 0; transform: translateY(-10px);
  transition: opacity 0.2s ease, transform 0.2s ease; overflow: hidden;
}
.notif-dropdown.show { display: block; opacity: 1; transform: translateY(0); }

.notif-header {
  padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-color);
  display: flex; align-items: center; justify-content: space-between;
  background: #f8fafc;
}
.notif-clear-btn {
  background: none; border: none; font-size: 0.75rem; font-weight: 600;
  color: var(--accent-primary); cursor: pointer; padding: 0;
}
.notif-clear-btn:hover { text-decoration: underline; color: var(--accent-hover); }

.notif-list { max-height: 360px; overflow-y: auto; }
.notif-list::-webkit-scrollbar { width: 4px; }
.notif-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

.notif-item {
  display: flex; gap: 0.85rem; padding: 0.9rem 1.15rem;
  border-bottom: 1px solid #f1f5f9; transition: background 0.15s ease;
  text-decoration: none; color: inherit;
}
.notif-item:last-child { border-bottom: none; }
.notif-item:hover { background: #f8fafc; }
.notif-item.unread { background: #f0f7ff; }

.notif-icon {
  width: 36px; height: 36px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 0.95rem; flex-shrink: 0; margin-top: 0.15rem;
}
.notif-content { flex: 1; min-width: 0; }
.notif-title-row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.2rem; }
.notif-item-title { font-size: 0.85rem; font-weight: 700; color: var(--navy-primary); line-height: 1.3; }
.notif-unread-dot { width: 7px; height: 7px; border-radius: 50%; background: #2563eb; flex-shrink: 0; }
.notif-item-desc { font-size: 0.775rem; color: var(--text-muted); margin: 0 0 0.25rem 0; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.notif-item-time { font-size: 0.7rem; font-weight: 600; color: #94a3b8; }
.notif-empty { padding: 2rem 1.5rem; text-align: center; color: var(--text-muted); }
.notif-empty i { font-size: 2rem; color: #cbd5e1; margin-bottom: 0.5rem; display: block; }

/* ================= ENTRANCE ANIMATIONS & MICRO-INTERACTIONS ================= */
@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes slideDownFade {
  from { opacity: 0; transform: translateY(-16px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes fadeInUp {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes pulseGlow {
  0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
  70% { transform: scale(1.05); box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
  100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

@keyframes bounceEmoji {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-6px) rotate(12deg); }
}

.animate-fade-in { animation: fadeIn 0.5s ease-out forwards; }
.animate-slide-down { animation: slideDownFade 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
.animate-card { opacity: 0; animation: fadeInUp 0.55s cubic-bezier(0.16, 1, 0.3, 1) forwards; }

.stagger-1 { animation-delay: 0.08s; }
.stagger-2 { animation-delay: 0.16s; }
.stagger-3 { animation-delay: 0.24s; }
.stagger-4 { animation-delay: 0.32s; }
.stagger-5 { animation-delay: 0.40s; }

.stat-block {
  transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.25s ease, border-color 0.25s ease;
}
.stat-block:hover {
  transform: translateY(-5px);
  box-shadow: 0 14px 28px -5px rgba(15, 23, 42, 0.12), 0 6px 12px -2px rgba(15, 23, 42, 0.06);
  border-color: #cbd5e1;
}

.sidebar-link {
  transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
.sidebar-link i {
  transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), color 0.2s;
}
.sidebar-link:hover i {
  transform: translateX(4px);
}

.btn-arrow-slide i, a.btn-arrow-slide i, .eval-footer a i {
  transition: transform 0.25s ease;
  display: inline-block;
}
.btn-arrow-slide:hover i, a.btn-arrow-slide:hover i, .eval-footer a:hover i {
  transform: translateX(5px);
}

.no-deadline-icon i {
  animation: pulseGlow 2.5s infinite ease-in-out;
  border-radius: 50%;
}
.bounce-party-emoji {
  display: inline-block;
  animation: bounceEmoji 2s infinite ease-in-out;
}

.btn { position: relative; overflow: hidden; }
.ripple {
  position: absolute; border-radius: 50%;
  background: rgba(255, 255, 255, 0.45);
  transform: scale(0); animation: ripple-animation 0.6s linear;
  pointer-events: none;
}
@keyframes ripple-animation {
  to { transform: scale(4); opacity: 0; }
}

/* ===================== RESPONSIVE BREAKPOINTS ===================== */

/* Large Tablet */
@media (max-width: 1100px) {
  .stats-grid { grid-template-columns: repeat(2, 1fr); }
  .analytics-grid { grid-template-columns: 1fr; }
  .hero-banner { padding: 2rem 2rem; }
  .hero-title { font-size: 1.8rem; }
}

/* Tablet / Collapsed sidebar */
@media (max-width: 1024px) {
  .sidebar { transform: translateX(-100%); }
  .sidebar.show { transform: translateX(0); }
  .content-wrapper { margin-left: 0 !important; width: 100% !important; }
  .main-content { padding: 1.5rem; gap: 1.25rem; }
  .top-navbar { padding: 0 1.25rem; }
}

/* Small Tablet */
@media (max-width: 900px) {
  .analytics-grid { grid-template-columns: 1fr; }
  .hero-banner { padding: 1.75rem 1.5rem; }
  .hero-title { font-size: 1.6rem; }
  .hero-pills { gap: 0.5rem; }
  .closest-deadline-body { flex-direction: column; gap: 1.25rem; }
  .closest-deadline-timer-box { min-width: 100%; width: 100%; }
  .closest-deadline-main { min-width: 100%; }
  .closest-deadline-title { font-size: 1.35rem; }
  .module-card { padding: 1.35rem 1.5rem; }
  .task-grid { grid-template-columns: 1fr; }
}

/* Mobile */
@media (max-width: 768px) {
  .stats-grid { grid-template-columns: repeat(2, 1fr); }
  .hero-banner { padding: 1.5rem 1.25rem; border-radius: var(--radius-md); }
  .hero-title { font-size: 1.4rem; margin-bottom: 0.6rem; }
  .hero-content { flex-direction: column; align-items: flex-start; gap: 1rem; }
  .main-content { padding: 1rem; gap: 1rem; }
  .top-navbar { padding: 0 1rem; height: 60px; }
  .top-navbar h3 { font-size: 1rem; }
  .notif-dropdown { right: -40px; width: 290px; }
  .stat-val { font-size: 1.55rem; }
  .module-card { padding: 1.25rem; }
  .closest-deadline-card { padding: 1.35rem 1.25rem; }
  .closest-deadline-title { font-size: 1.2rem; }
  .live-countdown-clock { gap: 0.25rem; }
  .unit-val { font-size: 1.35rem; padding: 0.35rem 0.45rem; }
  .analytics-grid { grid-template-columns: 1fr; }
}

/* Small Mobile */
@media (max-width: 480px) {
  .stats-grid { grid-template-columns: 1fr; }
  .hero-title { font-size: 1.25rem; }
  .hero-pills .sys-tag { font-size: 0.7rem; padding: 0.3rem 0.65rem; }
  .notif-dropdown { right: -10px; width: 270px; }
  .stat-block { padding: 1rem; }
  .stat-val { font-size: 1.4rem; }
  .closest-deadline-card { padding: 1rem; }
  .btn-submit-now { font-size: 0.8rem !important; padding: 0.55rem 1rem !important; }
  .live-countdown-clock { gap: 0.2rem; }
  .unit-val { font-size: 1.1rem; min-width: 36px; }
  .timer-colon { font-size: 1rem; }
  .module-card { padding: 1rem; border-radius: var(--radius-md); }
  .eval-footer { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
  .task-grid { grid-template-columns: 1fr; }
  .top-navbar h3 { font-size: 0.9rem; }
}
</style>

</head>
<body>

<div class="app-container">

    <!-- MOBILE SIDEBAR OVERLAY -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- LEFT SIDEBAR -->
    <aside class="sidebar" id="erpSidebar">
        <div class="sidebar-header">
            <a href="student_dashboard.php" class="brand-logo">
                <div class="brand-logo-icon"><i class="fa-solid fa-graduation-cap"></i></div>
                <span>Student Hub</span>
            </a>
            <button class="btn btn-outline" id="closeSidebar" style="padding: 0.3rem 0.6rem; color: #94a3b8; border-color: rgba(255,255,255,0.1); background: transparent;" title="Close Sidebar">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="sidebar-menu">
            <div class="menu-label">Navigation</div>
            <a href="student_dashboard.php" class="sidebar-link active">
                <i class="fa-solid fa-house"></i> <span>Dashboard</span>
            </a>

            <a href="student_submit.php" class="sidebar-link">
                <i class="fa-solid fa-cloud-arrow-up"></i> <span>Upload Assignment</span>
            </a>

            <a href="student_history.php" class="sidebar-link">
                <i class="fa-solid fa-clock-rotate-left"></i> <span>Submission History</span>
            </a>

            <div class="menu-label">Account</div>
            <a href="auth/logout.php" class="sidebar-link" style="color: #f87171;">
                <i class="fa-solid fa-power-off"></i> <span>Logout</span>
            </a>

            <!-- Joined Classes Section -->
            <div class="menu-label" style="margin-top: 1rem;">
                <span>Joined Classes</span>
                <span class="sys-tag accent" style="margin: 0; padding: 0.15rem 0.5rem; background: rgba(79, 70, 229, 0.2); color: #a5b4fc; border-color: rgba(165, 180, 252, 0.3);"><?= count($myClasses) ?></span>
            </div>
            <div style="padding: 0 0.5rem;">
                <?php if (empty($myClasses)): ?>
                    <div style="font-size: 0.8rem; color: #94a3b8; padding: 0.5rem 0.5rem;">No classes joined yet.</div>
                <?php else: ?>
                    <?php foreach ($myClasses as $c): ?>
                        <div class="joined-class-item">
                            <strong title="<?= e($c['class_name']) ?>"><?= e($c['class_name']) ?></strong>
                            <?php if (!empty($c['subject_code'])): ?>
                                <span><?= e($c['subject_code']) ?></span><br>
                            <?php endif; ?>
                            <?php if (!empty($c['faculty_name'])): ?>
                                <span><?= e($c['faculty_name']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="sidebar-user">
            <div class="avatar"><?php echo strtoupper(substr($fullName, 0, 1)); ?></div>
            <div>
                <div style="font-weight: 700; font-size: 0.9rem; color: #f8fafc;"><?php echo e(ucwords(strtolower($fullName))); ?></div>
                <div style="font-size: 0.75rem; color: #94a3b8; font-weight: 500;">Role: <?php echo ucfirst(e($role)); ?></div>
            </div>
        </div>
    </aside>

    <!-- CONTENT WRAPPER -->
    <div class="content-wrapper">
        <header class="top-navbar">
            <div class="top-navbar-inner">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <button class="btn btn-outline" id="sidebarToggle" style="padding: 0.5rem 0.8rem;" title="Toggle Sidebar"><i class="fa-solid fa-bars"></i></button>
                    <h3>Student Dashboard</h3>
                </div>
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <!-- Notification Bell Widget -->
                    <div class="notif-wrapper" id="notifWrapper">
                        <button class="notif-bell-btn" id="notifBellBtn" aria-label="Notifications" title="Notifications">
                            <i class="fa-regular fa-bell"></i>
                            <span class="notif-badge" id="notifBadge" style="display: none;">0</span>
                        </button>
                        <div class="notif-dropdown" id="notifDropdown">
                            <div class="notif-header">
                                <div>
                                    <h4 style="margin:0; font-size: 0.95rem; font-weight:700; color:var(--navy-primary);">Notifications</h4>
                                    <span style="font-size:0.75rem; color:var(--text-muted);" id="notifSubtext">0 unread alerts</span>
                                </div>
                                <button class="notif-clear-btn" id="notifClearBtn">Mark all as read</button>
                            </div>
                            <div class="notif-list" id="notifList"></div>
                        </div>
                    </div>

                    <span class="sys-tag success" style="font-weight: 600;"><i class="fa-solid fa-circle" style="font-size: 0.45rem;"></i> Active Session</span>
                </div>
            </div>
        </header>

        <main class="main-content">

            <!-- Hero Welcome Banner -->
            <div class="hero-banner animate-slide-down">
                <div class="hero-content">
                    <div>
                        <h1 class="hero-title">Welcome, <?= e(ucwords(strtolower(explode(' ', $fullName)[0]))) ?></h1>
                        <div class="hero-pills">
                            <span class="sys-tag hero"><i class="fa-solid fa-barcode"></i> PRN: <?php echo e($studentZprn); ?></span>
                            <span class="sys-tag hero"><i class="fa-solid fa-id-card"></i> ROLL: <?php echo e($rollNo); ?></span>
                            <span class="sys-tag hero"><i class="fa-solid fa-layer-group"></i> DIV: <?php echo e($division); ?></span>
                            <span class="sys-tag hero"><i class="fa-solid fa-building-columns"></i> DEPT: <?php echo e($deptName); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Closest Upcoming Deadline Highlight Widget -->
            <?php if ($closestDeadline): 
                $dueDt = new DateTime($closestDeadline['due_date']);
                $diffClosest = $now->diff($dueDt);
                $closestDays = $diffClosest->days;
                $closestHours = $diffClosest->h;
                $closestMins = $diffClosest->i;
                $closestTimeIso = $dueDt->format('c');
                $urgencyTheme = 'normal';
                if ($diffClosest->invert) {
                    $urgencyTheme = 'overdue';
                } elseif ($closestDays == 0) {
                    $urgencyTheme = 'critical';
                } elseif ($closestDays <= 2) {
                    $urgencyTheme = 'warning';
                }
            ?>
            <div class="closest-deadline-card urgency-<?= $urgencyTheme ?> animate-card stagger-1" id="closestDeadlineWidget">
                <div class="closest-deadline-badge">
                    <i class="fa-solid fa-fire-flame-curved"></i> Closest Upcoming Deadline
                </div>
                <div class="closest-deadline-body">
                    <div class="closest-deadline-main">
                        <div class="closest-deadline-tags">
                            <span class="sys-tag accent" style="background: rgba(255,255,255,0.2); color:#fff; border-color: rgba(255,255,255,0.3);"><i class="fa-solid fa-book"></i> <?= e($closestDeadline['subject_code']) ?></span>
                            <span class="sys-tag info" style="background: rgba(255,255,255,0.2); color:#fff; border-color: rgba(255,255,255,0.3);"><i class="fa-solid fa-layer-group"></i> Unit <?= e($closestDeadline['unit']) ?></span>
                            <span class="sys-tag hero" style="background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.25);">
                                <i class="fa-solid fa-star"></i> Max Marks: <?= e($closestDeadline['max_marks']) ?>
                            </span>
                        </div>
                        <h2 class="closest-deadline-title"><?= e($closestDeadline['title']) ?></h2>
                        <p class="closest-deadline-meta">
                            <i class="fa-regular fa-calendar-check"></i> Due Date: <strong><?= fmtDateTime($closestDeadline['due_date']) ?></strong>
                        </p>
                    </div>
                    
                    <div class="closest-deadline-timer-box">
                        <div class="timer-label">Time Remaining</div>
                        <div class="live-countdown-clock" id="liveCountdownTimer" data-due="<?= e($closestTimeIso) ?>">
                            <div class="timer-unit">
                                <span class="unit-val" id="cd-days"><?= sprintf('%02d', $closestDays) ?></span>
                                <span class="unit-lbl">Days</span>
                            </div>
                            <span class="timer-colon">:</span>
                            <div class="timer-unit">
                                <span class="unit-val" id="cd-hours"><?= sprintf('%02d', $closestHours) ?></span>
                                <span class="unit-lbl">Hours</span>
                            </div>
                            <span class="timer-colon">:</span>
                            <div class="timer-unit">
                                <span class="unit-val" id="cd-mins"><?= sprintf('%02d', $closestMins) ?></span>
                                <span class="unit-lbl">Mins</span>
                            </div>
                            <span class="timer-colon">:</span>
                            <div class="timer-unit">
                                <span class="unit-val" id="cd-secs">00</span>
                                <span class="unit-lbl">Secs</span>
                            </div>
                        </div>
                        
                        <a href="student_submit.php?activity_id=<?= (int)$closestDeadline['id'] ?>" class="btn btn-submit-now">
                            <i class="fa-solid fa-bolt-lightning"></i> Submit Assignment Now
                        </a>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="module-card no-deadline-card animate-card stagger-1">
                <div style="display: flex; align-items: center; gap: 1.25rem;">
                    <div class="no-deadline-icon"><i class="fa-solid fa-circle-check"></i></div>
                    <div>
                        <h3 style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin-bottom: 0.2rem;">All Caught Up! <span class="bounce-party-emoji">🎉</span></h3>
                        <p style="font-size: 0.875rem; color: #64748b; margin: 0;">You have no active pending deadlines at this moment. Excellent work keeping up with your coursework!</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- "Action Required" Priority Alert Banner -->
            <?php if (!empty($actionRequiredItems)): ?>
            <div class="module-card" style="border-left: 5px solid var(--danger); background: linear-gradient(135deg, #ffffff 0%, #fff5f5 100%);">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem;">
                    <div style="display: flex; gap: 1.25rem; align-items: center;">
                        <div style="width: 52px; height: 52px; border-radius: var(--radius-md); background: #fee2e2; color: var(--danger); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.15);">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </div>
                        <div>
                            <h2 style="font-size: 1.2rem; font-weight: 700; color: #991b1b; margin-bottom: 0.25rem;">Action Required: Pending Submissions</h2>
                            <p style="font-size: 0.9rem; color: var(--text-muted); margin: 0;">You have <strong><?= count($actionRequiredItems) ?> assignment(s)</strong> closing within the next 7 days.</p>
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.6rem; flex-wrap: wrap;">
                        <?php foreach (array_slice($actionRequiredItems, 0, 2) as $actReq): ?>
                            <a href="student_submit.php" class="btn btn-danger">
                                <i class="fa-solid fa-cloud-arrow-up"></i> Submit: <?= e($actReq['subject_code']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Consolidated KPI Telemetry Grid -->
            <div class="stats-grid">
                <div class="stat-block animate-card stagger-2">
                    <div class="stat-top">
                        <span class="sys-tag accent">Assigned</span>
                        <div class="stat-icon" style="background: #eeeffe; color: var(--accent-primary);"><i class="fa-solid fa-folder-open"></i></div>
                    </div>
                    <div class="stat-bottom">
                        <div class="stat-val count-up" data-target="<?= $totalActivities ?>"><?= $totalActivities ?></div>
                        <div class="stat-label">Total Activities</div>
                    </div>
                </div>
                
                <div class="stat-block animate-card stagger-3">
                    <div class="stat-top">
                        <span class="sys-tag warning">Action Needed</span>
                        <div class="stat-icon" style="background: var(--warning-bg); color: var(--warning);"><i class="fa-solid fa-clock"></i></div>
                    </div>
                    <div class="stat-bottom">
                        <div class="stat-val count-up" style="color: var(--warning);" data-target="<?= $totalPending ?>"><?= $totalPending ?></div>
                        <div class="stat-label">Pending Submit</div>
                    </div>
                </div>
                
                <div class="stat-block animate-card stagger-4">
                    <div class="stat-top">
                        <span class="sys-tag success count-up" data-target="<?= number_format((float)$totalEarnedMarks, 1) ?>" data-suffix=" PTS" data-decimals="1"><?= number_format((float)$totalEarnedMarks, 1) ?> PTS</span>
                        <div class="stat-icon" style="background: var(--success-bg); color: var(--success);"><i class="fa-solid fa-circle-check"></i></div>
                    </div>
                    <div class="stat-bottom">
                        <div class="stat-val count-up" style="color: var(--success);" data-target="<?= $totalEvaluated ?>"><?= $totalEvaluated ?></div>
                        <div class="stat-label">Evaluated Submissions</div>
                    </div>
                </div>
                
                <div class="stat-block animate-card stagger-5">
                    <div class="stat-top">
                        <span class="sys-tag" style="background: <?= $standingBadge['color'] ?>15; color: <?= $standingBadge['color'] ?>; border-color: <?= $standingBadge['color'] ?>40;"><?= strtoupper($standingBadge['label']) ?></span>
                        <div class="stat-icon" style="background: rgba(15, 23, 42, 0.05); color: <?= $standingBadge['color'] ?>;"><i class="fa-solid fa-chart-line"></i></div>
                    </div>
                    <div class="stat-bottom">
                        <div class="stat-val count-up" style="color: <?= $standingBadge['color'] ?>;" data-target="<?= $scorePercent ?>" data-suffix="%"><?= $scorePercent ?>%</div>
                        <div class="stat-label">Academic Standing</div>
                    </div>
                </div>
            </div>

            <!-- Performance & Task Telemetry Analytics Charts Module -->
            <div class="analytics-grid animate-card stagger-5">
                <div class="module-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.5rem;">
                        <div>
                            <h2 style="font-size: 1.2rem; font-weight: 700; color: var(--navy-primary); margin: 0;">
                                <i class="fa-regular fa-chart-bar" style="color: var(--accent-primary); margin-right: 0.5rem;"></i> Performance Progression
                            </h2>
                            <span style="color: var(--text-muted); font-size: 0.85rem;">Grade percentage trend over coursework</span>
                        </div>
                        <span class="sys-tag accent" style="font-size: 0.75rem;"><i class="fa-solid fa-arrow-trend-up"></i> <?= $scorePercent ?>% Standing</span>
                    </div>
                    <div style="height: 220px; position: relative;">
                        <canvas id="perfTrendChart"></canvas>
                    </div>
                </div>

                <div class="module-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                        <div>
                            <h2 style="font-size: 1.2rem; font-weight: 700; color: var(--navy-primary); margin: 0;">
                                <i class="fa-regular fa-compass" style="color: var(--accent-primary); margin-right: 0.5rem;"></i> Task Ratio
                            </h2>
                            <span style="color: var(--text-muted); font-size: 0.85rem;">Coursework completion breakdown</span>
                        </div>
                    </div>
                    <div style="height: 220px; position: relative; display: flex; align-items: center; justify-content: center;">
                        <canvas id="activityDoughnutChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Upcoming Deadlines Chronological Checklist -->
            <?php if (!empty($upcomingDeadlines)): ?>
            <div class="module-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.75rem; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <h2 style="font-size: 1.3rem; font-weight: 700; color: var(--navy-primary); margin: 0;"><i class="fa-regular fa-calendar-days" style="color: var(--accent-primary); margin-right: 0.6rem;"></i> Upcoming Deadlines</h2>
                        <span style="color: var(--text-muted); font-size: 0.875rem;">Chronological task queue for active coursework</span>
                    </div>
                    <a href="student_submit.php" class="btn btn-outline">Submit Queue &rarr;</a>
                </div>

                <div class="task-grid">
                    <?php foreach ($upcomingDeadlines as $item): ?>
                    <div class="task-card">
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
                                <span class="sys-tag" style="background: #f1f5f9; color: #475569; border-color: #cbd5e1;"><?= e($item['subject_code']) ?> &middot; Unit <?= e($item['unit']) ?></span>
                                <span class="sys-tag <?= $item['countdown_class'] ?>">
                                    <i class="fa-regular fa-clock"></i> <?= $item['countdown_label'] ?>
                                </span>
                            </div>
                            <strong style="font-size: 1.1rem; font-weight: 700; margin-bottom: 0.5rem; display: block; color: var(--text-main); font-family: var(--font-heading);"><?= e($item['title']) ?></strong>
                            <small style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.5rem; display: block;">Due Date: <strong><?= fmtDate($item['due_date']) ?></strong></small>
                        </div>
                        
                        <a href="student_submit.php" class="btn btn-primary" style="width: 100%;">
                            <i class="fa-solid fa-cloud-arrow-up"></i> Go to Submit
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recent Grade & Feedback Snapshot Grid Section -->
            <div class="module-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.75rem; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <h2 style="font-size: 1.3rem; font-weight: 700; color: var(--navy-primary); margin: 0;"><i class="fa-regular fa-circle-check" style="color: var(--success); margin-right: 0.6rem;"></i> Recent Evaluations</h2>
                        <span style="color: var(--text-muted); font-size: 0.875rem;">Latest graded assignments & marks</span>
                    </div>
                    <a href="student_history.php" class="btn btn-outline">Full History &rarr;</a>
                </div>

                <?php if (empty($recentEvaluatedSnapshot)): ?>
                    <div style="text-align: center; padding: 3.5rem 2rem; border: 2px dashed var(--border-color); border-radius: var(--radius-md);">
                        <i class="fa-regular fa-clipboard" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem;"></i>
                        <p style="font-size: 0.95rem; font-weight: 600; color: var(--text-muted); margin: 0;">No evaluation data available yet.</p>
                    </div>
                <?php else: ?>
                    <div class="task-grid">
                        <?php foreach ($recentEvaluatedSnapshot as $snap): ?>
                        <div class="eval-card">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 0.75rem;">
                                <div>
                                    <strong style="font-size: 1.1rem; font-weight: 700; color: var(--text-main); font-family: var(--font-heading); display: block; margin-bottom: 0.45rem; line-height: 1.25;"><?= e($snap['title']) ?></strong>
                                    <span class="sys-tag" style="background: #f1f5f9; color: #475569; border-color: #cbd5e1;"><?= e($snap['subject_code']) ?></span>
                                </div>
                                <div style="text-align: right; flex-shrink: 0;">
                                    <div style="display: flex; align-items: baseline; gap: 0.15rem; justify-content: flex-end;">
                                        <span style="font-size: 1.35rem; font-weight: 800; color: var(--success); font-family: var(--font-heading); line-height: 1;"><?= number_format((float)$snap['marks'], 1) ?></span>
                                        <span style="font-size: 0.8rem; color: #94a3b8; font-weight: 500;">/<?= number_format((float)$snap['max_marks'], 1) ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="eval-footer">
                                <span><i class="fa-regular fa-calendar-check" style="color: var(--text-muted); margin-right: 0.35rem;"></i> Submitted: <strong><?= fmtDate($snap['submission_date']) ?></strong></span>
                                <a href="student_history.php" style="color: var(--accent-primary); font-weight: 600; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.35rem;">
                                    View <i class="fa-solid fa-arrow-right" style="font-size: 0.7rem;"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    // 1. Sidebar Toggle (Mobile)
    const sidebarToggle = document.getElementById('sidebarToggle');
    const closeSidebar = document.getElementById('closeSidebar');
    const erpSidebar = document.getElementById('erpSidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function toggleMenu() {
        if (erpSidebar && sidebarOverlay) {
            erpSidebar.classList.toggle('show');
            sidebarOverlay.classList.toggle('show');
        }
    }

    if (sidebarToggle) sidebarToggle.addEventListener('click', toggleMenu);
    if (closeSidebar) closeSidebar.addEventListener('click', toggleMenu);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', toggleMenu);

    // 2. Chart.js Performance Spline Area Chart
    const ctxLine = document.getElementById('perfTrendChart');
    if (ctxLine) {
        const lineCtx = ctxLine.getContext('2d');
        const fillGradient = lineCtx.createLinearGradient(0, 0, 0, 220);
        fillGradient.addColorStop(0, 'rgba(79, 70, 229, 0.22)');
        fillGradient.addColorStop(1, 'rgba(79, 70, 229, 0)');

        new Chart(lineCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chartTrendLabels); ?>,
                datasets: [{
                    label: 'Score %',
                    data: <?php echo json_encode($chartTrendData); ?>,
                    borderColor: '#0ea5e9',
                    borderWidth: 3,
                    fill: true,
                    backgroundColor: fillGradient,
                    tension: 0.4,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#0ea5e9',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleFont: { family: 'Outfit', size: 13, weight: '700' },
                        bodyFont: { family: 'Plus Jakarta Sans', size: 12 },
                        padding: 12,
                        cornerRadius: 10,
                        displayColors: false,
                        callbacks: {
                            label: (ctx) => ` Score: ${ctx.parsed.y}%`
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { family: 'Plus Jakarta Sans', size: 11 }, color: '#64748b' }
                    },
                    y: {
                        min: 0,
                        max: 100,
                        grid: { color: 'rgba(226, 232, 240, 0.6)', borderDash: [4, 4] },
                        ticks: {
                            stepSize: 25,
                            font: { family: 'Plus Jakarta Sans', size: 11 },
                            color: '#64748b',
                            callback: (val) => val + '%'
                        }
                    }
                },
                animation: {
                    duration: 1600,
                    easing: 'easeInOutQuart'
                }
            }
        });
    }

    // 3. Chart.js Activity Status Doughnut Ring Chart
    const ctxRing = document.getElementById('activityDoughnutChart');
    if (ctxRing) {
        new Chart(ctxRing.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($doughnutLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($doughnutData); ?>,
                    backgroundColor: ['#10b981', '#f59e0b', '#4f46e5', '#ef4444'],
                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: { family: 'Plus Jakarta Sans', size: 11, weight: '600' },
                            padding: 14,
                            color: '#1e293b'
                        }
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        padding: 10,
                        cornerRadius: 10,
                        titleFont: { family: 'Outfit', size: 12, weight: '700' },
                        bodyFont: { family: 'Plus Jakarta Sans', size: 12 }
                    }
                },
                animation: {
                    animateRotate: true,
                    animateScale: true,
                    duration: 1400,
                    easing: 'easeOutQuart'
                }
            }
        });
    }

    // 4. Notification System Interactive Manager
    const userStudentId = <?= json_encode((string)$studentTableId) ?>;
    const storageKey = 'saaes_read_notifs_user_' + userStudentId;
    const initialNotifs = <?= $notificationsJson ?>;

    function getReadIds() {
        try { return JSON.parse(localStorage.getItem(storageKey)) || []; } catch(e) { return []; }
    }

    function saveReadIds(ids) {
        try { localStorage.setItem(storageKey, JSON.stringify(ids)); } catch(e) {}
    }

    function escapeHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function renderNotifications() {
        const readIds = getReadIds();
        const badge = document.getElementById('notifBadge');
        const subtext = document.getElementById('notifSubtext');
        const list = document.getElementById('notifList');
        if (!list) return;

        let unreadCount = 0;
        list.innerHTML = '';

        if (!initialNotifs || initialNotifs.length === 0) {
            list.innerHTML = `<div class="notif-empty"><i class="fa-regular fa-bell-slash"></i><p>No notifications yet</p></div>`;
            if (badge) badge.style.display = 'none';
            if (subtext) subtext.textContent = '0 unread alerts';
            return;
        }

        initialNotifs.forEach(item => {
            const isRead = readIds.includes(item.id);
            if (!isRead) unreadCount++;

            const itemEl = document.createElement('a');
            itemEl.href = item.link || '#';
            itemEl.className = `notif-item ${isRead ? 'read' : 'unread'}`;
            itemEl.onclick = () => {
                if (!readIds.includes(item.id)) {
                    readIds.push(item.id);
                    saveReadIds(readIds);
                }
            };

            itemEl.innerHTML = `
                <div class="notif-icon" style="background:${item.color}15; color:${item.color};">
                    <i class="fa-solid ${item.icon}"></i>
                </div>
                <div class="notif-content">
                    <div class="notif-title-row">
                        <span class="notif-item-title">${escapeHtml(item.title)}</span>
                        ${!isRead ? '<span class="notif-unread-dot"></span>' : ''}
                    </div>
                    <p class="notif-item-desc">${escapeHtml(item.desc)}</p>
                    <span class="notif-item-time">${escapeHtml(item.time)}</span>
                </div>
            `;
            list.appendChild(itemEl);
        });

        if (badge) {
            if (unreadCount > 0) {
                badge.textContent = unreadCount > 9 ? '9+' : unreadCount;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        }
        if (subtext) {
            subtext.textContent = unreadCount === 1 ? '1 unread alert' : `${unreadCount} unread alerts`;
        }
    }

    renderNotifications();

    const bellBtn = document.getElementById('notifBellBtn');
    const dropdown = document.getElementById('notifDropdown');
    const clearBtn = document.getElementById('notifClearBtn');

    if (bellBtn && dropdown) {
        bellBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdown.classList.toggle('show');
        });
        document.addEventListener('click', (e) => {
            if (!dropdown.contains(e.target) && !bellBtn.contains(e.target)) {
                dropdown.classList.remove('show');
            }
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const allIds = initialNotifs.map(n => n.id);
            saveReadIds(allIds);
            renderNotifications();
        });
    }

    // 5. Live Countdown Timer for Closest Upcoming Deadline
    const timerContainer = document.getElementById('liveCountdownTimer');
    if (timerContainer) {
        const dueStr = timerContainer.getAttribute('data-due');
        if (dueStr) {
            const dueDate = new Date(dueStr).getTime();
            function updateClock() {
                const now = new Date().getTime();
                const distance = dueDate - now;

                const daysEl = document.getElementById('cd-days');
                const hoursEl = document.getElementById('cd-hours');
                const minsEl = document.getElementById('cd-mins');
                const secsEl = document.getElementById('cd-secs');

                if (distance <= 0) {
                    if (daysEl) daysEl.textContent = '00';
                    if (hoursEl) hoursEl.textContent = '00';
                    if (minsEl) minsEl.textContent = '00';
                    if (secsEl) secsEl.textContent = '00';
                    return;
                }

                const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((distance % (1000 * 60)) / 1000);

                if (daysEl) daysEl.textContent = String(days).padStart(2, '0');
                if (hoursEl) hoursEl.textContent = String(hours).padStart(2, '0');
                if (minsEl) minsEl.textContent = String(minutes).padStart(2, '0');
                if (secsEl) secsEl.textContent = String(seconds).padStart(2, '0');
            }
            updateClock();
            setInterval(updateClock, 1000);
        }
    }

    // 6. Responsive Sidebar Collapse & Toggle Handler
    const appContainer = document.querySelector('.app-container');
    if (localStorage.getItem('saaes_sidebar_collapsed') === 'true' && window.innerWidth > 1024) {
        if (appContainer) appContainer.classList.add('sidebar-collapsed');
    }

    function toggleSidebarMenu() {
        if (window.innerWidth <= 1024) {
            if (erpSidebar) erpSidebar.classList.toggle('show');
            if (sidebarOverlay) sidebarOverlay.classList.toggle('show');
        } else {
            if (sidebarOverlay) sidebarOverlay.classList.remove('show');
            if (appContainer) {
                appContainer.classList.toggle('sidebar-collapsed');
                localStorage.setItem('saaes_sidebar_collapsed', appContainer.classList.contains('sidebar-collapsed') ? 'true' : 'false');
            }
        }
    }

    function closeSidebarMenu() {
        if (erpSidebar) erpSidebar.classList.remove('show');
        if (sidebarOverlay) sidebarOverlay.classList.remove('show');
        if (window.innerWidth > 1024 && appContainer) {
            appContainer.classList.add('sidebar-collapsed');
            localStorage.setItem('saaes_sidebar_collapsed', 'true');
        }
    }

    if (sidebarToggle) sidebarToggle.addEventListener('click', toggleSidebarMenu);
    if (closeSidebar) closeSidebar.addEventListener('click', closeSidebarMenu);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebarMenu);

    // 7. Rolling Number Counter Animation Handler
    function animateCounters() {
        const counters = document.querySelectorAll('.count-up');
        counters.forEach(counter => {
            const targetRaw = counter.getAttribute('data-target');
            if (targetRaw === null) return;
            const target = parseFloat(targetRaw) || 0;
            const prefix = counter.getAttribute('data-prefix') || '';
            const suffix = counter.getAttribute('data-suffix') || '';
            const decimals = parseInt(counter.getAttribute('data-decimals') || '0', 10);
            
            const duration = 1200;
            const startTime = performance.now();
            
            function updateCount(currentTime) {
                const elapsedTime = currentTime - startTime;
                const progress = Math.min(elapsedTime / duration, 1);
                const easedProgress = 1 - Math.pow(1 - progress, 3);
                const currentVal = target * easedProgress;
                
                counter.textContent = prefix + currentVal.toFixed(decimals) + suffix;
                
                if (progress < 1) {
                    requestAnimationFrame(updateCount);
                } else {
                    counter.textContent = prefix + target.toFixed(decimals) + suffix;
                }
            }
            requestAnimationFrame(updateCount);
        });
    }
    setTimeout(animateCounters, 150);

    // 8. Button Click Ripple Effect Handler
    document.querySelectorAll('.btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            const rect = this.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            const ripple = document.createElement('span');
            ripple.className = 'ripple';
            ripple.style.left = `${x}px`;
            ripple.style.top = `${y}px`;
            const size = Math.max(rect.width, rect.height);
            ripple.style.width = ripple.style.height = `${size}px`;
            
            const existing = this.querySelector('.ripple');
            if (existing) existing.remove();
            
            this.appendChild(ripple);
            setTimeout(() => ripple.remove(), 600);
        });
    });
});
</script>

<?php 
$modalPath = __DIR__ . '/includes/end_session_modal.php';
if (file_exists($modalPath)) {
    include_once $modalPath;
}
?>
</body>
</html>