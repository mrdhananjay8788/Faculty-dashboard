<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Anti-caching headers to prevent browser back-button access after logout
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'student') {
  header('Location: auth/login.php');
  exit;
}

$studentUserId  = (int) $_SESSION['user_id'];
$studentTableId = (int) ($_SESSION['student_id'] ?? 0);
$studentCode    = $_SESSION['student_code'] ?? ('STU' . $studentTableId);
$studentName    = $_SESSION['full_name']   ?? ($_SESSION['user_name'] ?? 'Student');
$_SESSION['full_name'] = $studentName;
$role           = $_SESSION['role']        ?? 'student';

// Fetch PRN to retrieve joined classes
$studentPrn = '';
$linkedPrn = '';
$stmtU = $pdo->prepare("SELECT username, linked_student_prn, department, academic_year, division FROM users WHERE user_id = ? LIMIT 1");
$stmtU->execute([$studentUserId]);
$uRow = $stmtU->fetch(PDO::FETCH_ASSOC);
if ($uRow) {
    $studentPrn = $uRow['username'] ?? '';
    $linkedPrn  = $uRow['linked_student_prn'] ?? '';
    $studentDept = trim($uRow['department'] ?? '');
    $studentYear = trim($uRow['academic_year'] ?? 'FY');
    $studentDiv  = trim($uRow['division'] ?? '');

    // Auto-heal missing academic details from access_requests
    if (empty($studentDept) || $studentDept === 'N/A' || empty($studentDiv) || $studentDiv === 'N/A' || $studentYear === 'FY') {
        try {
            $stmtAr = $pdo->prepare("SELECT department, academic_year, division FROM access_requests WHERE UPPER(prn_number) = UPPER(?) ORDER BY request_id DESC LIMIT 1");
            $stmtAr->execute([$studentPrn]);
            $arRow = $stmtAr->fetch(PDO::FETCH_ASSOC);
            if ($arRow) {
                $updateFields = [];
                $updateParams = [];
                if ((empty($studentDept) || $studentDept === 'N/A') && !empty($arRow['department'])) {
                    $studentDept = trim($arRow['department']);
                    $updateFields[] = "department = ?";
                    $updateParams[] = $studentDept;
                }
                if ($studentYear === 'FY' && !empty($arRow['academic_year'])) {
                    $studentYear = trim($arRow['academic_year']);
                    $updateFields[] = "academic_year = ?";
                    $updateParams[] = $studentYear;
                }
                if ((empty($studentDiv) || $studentDiv === 'N/A') && !empty($arRow['division'])) {
                    $studentDiv = trim($arRow['division']);
                    $updateFields[] = "division = ?";
                    $updateParams[] = $studentDiv;
                }
                if (!empty($updateFields)) {
                    $updateParams[] = $studentTableId;
                    $stmtUpDept = $pdo->prepare("UPDATE users SET " . implode(", ", $updateFields) . " WHERE user_id = ?");
                    $stmtUpDept->execute($updateParams);
                }
            }
        } catch (PDOException $e) {}
    }
}

function e($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function fmtDate($d) { return $d ? (new DateTime($d))->format('d M Y') : '—'; }
function fmtDateTime($d) { return $d ? (new DateTime($d))->format('d M Y, h:i A') : '—'; }
function jsAttr($v) { return htmlspecialchars(json_encode($v), ENT_QUOTES, 'UTF-8'); }

// File Download/Preview Handler
$action = $_GET['action'] ?? '';
if ($action === 'preview' || $action === 'download') {
  $UPLOAD_ROOT = __DIR__ . '/uploads/';
  $subId = (int) ($_GET['id'] ?? 0);
  $stmt = $pdo->prepare("SELECT * FROM submissions WHERE id = ? AND student_id = ?");
  $stmt->execute([$subId, $studentTableId]);
  $sub = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$sub) {
    http_response_code(404);
    exit('File not found.');
  }
  $fullPath = '';
  if (!empty($sub['file_path']) && is_file($sub['file_path'])) {
      $fullPath = $sub['file_path'];
  } else {
      $fullPath = $UPLOAD_ROOT . $sub['student_id'] . '/' . ($sub['original_filename'] ?? '');
  }
  if (!is_file($fullPath)) {
    http_response_code(404);
    exit('File is missing on the server.');
  }
  $mimeMap = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
  header('Content-Type: ' . ($mimeMap[$sub['file_type']] ?? 'application/octet-stream'));
  header('Content-Disposition: ' . ($action === 'download' ? 'attachment' : 'inline') . '; filename="' . basename($sub['original_filename']) . '"');
  header('Content-Length: ' . filesize($fullPath));
  header('X-Content-Type-Options: nosniff');
  readfile($fullPath);
  exit;
}

// Fetch Joined Classes for Sidebar
$stmtClasses = $pdo->prepare("
    SELECT fc.class_id, fc.class_name, fc.subject_code, fc.description, u.name AS faculty_name
    FROM faculty_classes fc
    LEFT JOIN users u ON fc.faculty_id = u.user_id
    WHERE fc.department = ? AND fc.academic_year = ? AND fc.division = ?
    ORDER BY fc.created_at DESC
");
$stmtClasses->execute([$studentDept ?? '', $studentYear ?? 'FY', $studentDiv ?? '']);
$myClasses = $stmtClasses->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Clean up any orphan submission records where activity no longer exists
try {
    $pdo->exec("DELETE FROM submissions WHERE activity_id NOT IN (SELECT activity_id FROM activities)");
} catch (PDOException $e) {
    // Ignore error if table doesn't exist
}

// Fetch History Data: ONLY SUBMITTED ACTIVITIES THAT BELONG TO EXISTING ACTIVITIES
$stmt = $pdo->prepare("
    SELECT s.id AS submission_id, s.original_filename, s.submission_date, s.status AS sub_status,
           s.marks, s.file_type, s.remarks,
           a.activity_id, a.type, a.subject AS subject_code, a.unit, a.title, a.max_marks, a.due_date
    FROM submissions s
    JOIN activities a ON s.activity_id = a.activity_id
    WHERE s.student_id = ?
    ORDER BY s.submission_date DESC
");
$stmt->execute([$studentTableId]);
$allRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$history = [];
$totalEvaluated = 0;
$totalReview = 0;

foreach ($allRows as $row) {
    $row['status'] = $row['sub_status'] ?: 'Submitted';
    if ($row['status'] === 'Submitted' && $row['marks'] !== null) {
        $totalEvaluated++;
        $row['display_status'] = 'Graded';
    } elseif (in_array($row['status'], ['Approved', 'Graded', 'Evaluated'], true)) {
        $totalEvaluated++;
        $row['display_status'] = 'Graded';
    } else {
        $totalReview++;
        $row['display_status'] = 'Under Review';
    }
    $row['id'] = $row['submission_id'];
    $history[] = $row;
}

$subjects = [];
foreach ($history as $h) {
  if (!empty($h['subject_code'])) {
    $subjects[$h['subject_code']] = true;
  }
}
$subjects = array_keys($subjects);
sort($subjects);

// Notification Feed Construction
$notifications = [];
foreach ($history as $hItem) {
    if (!empty($hItem['submission_id']) && $hItem['marks'] !== null) {
        $notifications[] = [
            'id' => 'eval_' . $hItem['submission_id'],
            'type' => 'grade',
            'title' => 'Grade Posted: ' . $hItem['title'],
            'desc' => 'Score: ' . number_format((float)$hItem['marks'], 1) . ' / ' . number_format((float)$hItem['max_marks'], 1) . ' (' . ($hItem['subject_code'] ?? '') . ')',
            'time' => fmtDate($hItem['submission_date'] ?? ''),
            'link' => 'student_history.php',
            'icon' => 'fa-award',
            'color' => '#10b981'
        ];
    }
}
$notifications[] = [
    'id' => 'sys_welcome_' . $studentUserId,
    'type' => 'system',
    'title' => 'CIE 2 Evaluation Portal Active',
    'desc' => 'Department of ' . ($deptName ?? 'ECE') . ' (' . ($academic_year ?? 'FY') . ' Div ' . ($division ?? 'A') . ')',
    'time' => 'Today',
    'link' => 'student_dashboard.php',
    'icon' => 'fa-circle-info',
    'color' => '#4f46e5'
];
$notificationsJson = json_encode($notifications);


function badgeClass($status) {
    return [
        'Submission Closed' => 'danger',
        'Missed'        => 'danger',
        'Pending'       => 'warning',
        'Late'          => 'warning',
        'Submitted'     => 'info',
        'Under Review'  => 'info',
        'Approved'      => 'success',
        'Rejected'      => 'danger',
        'Evaluated'     => 'success',
        'Graded'        => 'success',
    ][$status] ?? 'accent';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Submission History | SAAES</title>
  
  <!-- Premium Fonts & Icons -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  
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
      --danger: #ef4444;
      --danger-bg: #fee2e2;
      --danger-border: #fecaca;
      --warning: #f59e0b;
      --warning-bg: #fef3c7;
      --warning-border: #fde68a;
      --info: #0284c7;
      --info-bg: #e0f2fe;
      --info-border: #bae6fd;
      
      --radius-sm: 8px;
      --radius-md: 12px;
      --radius-lg: 16px;
      --radius-xl: 24px;
      
      --shadow-xs: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
      --shadow-sm: 0 4px 6px -1px rgba(14, 165, 233, 0.08), 0 2px 4px -1px rgba(15, 23, 42, 0.03);
      --shadow-md: 0 10px 15px -3px rgba(14, 165, 233, 0.12), 0 4px 6px -2px rgba(15, 23, 42, 0.04);
      --shadow-lg: 0 20px 25px -5px rgba(14, 165, 233, 0.18), 0 10px 10px -5px rgba(15, 23, 42, 0.04);
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
      width: 40px; height: 40px; border-radius: var(--radius-md);
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
      color: #cbd5e1; font-weight: 500; font-size: 0.925rem; border-radius: var(--radius-md);
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .sidebar-link:hover { background: rgba(255, 255, 255, 0.08); color: #fff; transform: translateX(3px); }
    .sidebar-link.active {
      background: var(--accent-gradient); color: #fff; font-weight: 600;
      box-shadow: 0 4px 14px rgba(14, 165, 233, 0.4);
    }
    .sidebar-link i { font-size: 1.05rem; width: 22px; text-align: center; color: #94a3b8; transition: color 0.2s; }
    .sidebar-link:hover i, .sidebar-link.active i { color: #fff; }

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

    /* Enhanced Micro-Interactions & Ripple */
    .sidebar-link {
      transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .sidebar-link i {
      transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), color 0.2s;
    }
    .sidebar-link:hover i {
      transform: translateX(4px);
    }

    .btn-arrow-slide i, a.btn-arrow-slide i {
      transition: transform 0.25s ease;
      display: inline-block;
    }
    .btn-arrow-slide:hover i, a.btn-arrow-slide:hover i {
      transform: translateX(5px);
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

    
    .main-content { padding: 2rem; flex: 1; max-width: 1400px; width: 100%; margin: 0 auto; display: flex; flex-direction: column; gap: 1.75rem; }

    /* ================= MODULE CARDS ================= */
    .module-card {
      background: var(--bg-card); border: 1px solid var(--border-color);
      padding: 1.75rem 2rem; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);
      transition: box-shadow 0.2s, border-color 0.2s;
    }
    .module-card:hover { box-shadow: var(--shadow-md); border-color: #cbd5e1; }
    
    .hero-banner {
      background: var(--hero-gradient); color: #fff;
      padding: 2.75rem 3rem; border-radius: var(--radius-xl); box-shadow: var(--shadow-lg);
      position: relative; overflow: hidden;
    }
    .hero-banner::before {
      content: ''; position: absolute; top: -50%; right: -10%; width: 350px; height: 350px;
      background: radial-gradient(circle, rgba(99, 102, 241, 0.25) 0%, rgba(255, 255, 255, 0) 70%);
      border-radius: 50%; pointer-events: none;
    }
    .hero-content { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem; position: relative; z-index: 1; }
    .hero-title { font-size: 2rem; font-weight: 800; margin-bottom: 0.5rem; letter-spacing: -0.02em; }
    .hero-subtitle { color: #cbd5e1; font-size: 0.95rem; margin: 0; max-width: 600px; }

    /* ================= TAGS / BADGES ================= */
    .d-none { display: none !important; }
    .sys-tag {
      font-size: 0.75rem; font-weight: 700; padding: 0.35rem 0.85rem; border-radius: 999px;
      display: inline-flex; align-items: center; gap: 0.4rem; background: #f1f5f9; color: var(--text-muted);
      border: 1px solid var(--border-color); line-height: 1; transition: all 0.2s;
      width: auto; max-width: fit-content; flex-shrink: 0; align-self: flex-start; margin: 0;
    }
    .sys-tag.accent { background: #e0f2fe; color: #0369a1; border-color: #bae6fd; }
    .sys-tag.success { background: #dcfce7; color: #047857; border-color: #bbf7d0; }
    .sys-tag.danger { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
    .sys-tag.warning { background: #ffedd5; color: #9a3412; border-color: #fed7aa; }
    .sys-tag.info { background: #e0f2fe; color: #075985; border-color: #bae6fd; }

    /* ================= BUTTONS ================= */
    .btn {
        font-family: var(--font-body); font-weight: 600; font-size: 0.875rem;
        padding: 0.65rem 1.35rem; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
        border-radius: var(--radius-md); border: 1px solid transparent; cursor: pointer; transition: all 0.2s ease; text-decoration: none;
        line-height: 1.25;
    }
    .btn-primary { background: var(--accent-gradient); color: #fff; box-shadow: var(--shadow-glow); }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.45); color: #fff; }

    .btn-outline { background: var(--bg-card); border-color: var(--border-color); color: var(--text-main); }
    .btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; transform: translateY(-1px); }

    /* ================= STATS GRID ================= */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; }
    .stat-block {
      background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-md);
      padding: 1.35rem 1.5rem; display: flex; flex-direction: column; justify-content: space-between;
      box-shadow: var(--shadow-sm); transition: transform 0.2s, box-shadow 0.2s;
    }
    .stat-block:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
    .stat-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem; width: 100%; }
    .stat-icon {
      width: 44px; height: 44px; border-radius: var(--radius-sm); display: flex;
      align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0;
    }
    .stat-val { font-family: var(--font-heading); font-size: 2.1rem; font-weight: 800; color: var(--navy-primary); line-height: 1.1; margin-bottom: 0.25rem; }
    .stat-label { font-size: 0.775rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.07em; }

    /* ================= FILTERS & FORMS ================= */
    .filter-card {
        background: var(--bg-card); border: 1px solid var(--border-color); padding: 1.25rem 1.5rem; border-radius: var(--radius-lg);
        display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; box-shadow: var(--shadow-sm);
    }
    .form-control-custom, .form-select-custom {
        width: 100%; padding: 0.7rem 1.1rem; background: var(--bg-body); border: 1px solid var(--border-color);
        color: var(--text-main); font-family: inherit; font-size: 0.9rem; outline: none; transition: all 0.2s;
        border-radius: var(--radius-md); -webkit-appearance: none;
    }
    .form-control-custom:focus, .form-select-custom:focus {
        border-color: var(--accent-primary); background: var(--bg-card); box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.12);
    }
    .form-select-custom {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right 1.1rem center; background-size: 1em; padding-right: 2.75rem;
    }

    /* ================= TABLES ================= */
    .table-responsive { overflow-x: auto; border: 1px solid var(--border-color); border-radius: var(--radius-md); box-shadow: var(--shadow-xs); }
    .custom-table { width: 100%; border-collapse: collapse; text-align: left; background: var(--bg-card); }
    .custom-table th, .custom-table td { padding: 1.1rem 1.5rem; border-bottom: 1px solid var(--border-color); font-size: 0.925rem; vertical-align: middle; }
    .custom-table th { background: #f8fafc; color: var(--text-muted); font-weight: 700; font-size: 0.775rem; text-transform: uppercase; letter-spacing: 0.07em; }
    .custom-table tbody tr { transition: background 0.15s ease; }
    .custom-table tbody tr:hover { background: rgba(238, 239, 254, 0.4); }
    .custom-table tbody tr:last-child td { border-bottom: none; }

    /* Pagination */
    .pagination { display: flex; list-style: none; gap: 0.4rem; margin: 0; padding: 0; }
    .page-item .page-link { font-weight: 600; border: 1px solid var(--border-color); border-radius: var(--radius-md); color: var(--text-main); background: var(--bg-card); padding: 0.45rem 0.9rem; text-decoration: none; font-size: 0.85rem; transition: all 0.2s; }
    .page-item:not(.active):not(.disabled) .page-link:hover { background: #f1f5f9; border-color: #cbd5e1; }
    .page-item.active .page-link { background: var(--accent-gradient); color: #fff; border-color: transparent; box-shadow: var(--shadow-glow); }
    .page-item.disabled .page-link { opacity: 0.4; pointer-events: none; background: var(--bg-body); }

    /* ================= ACTIVITY FEED CARDS ================= */
    .activity-feed-container { display: flex; flex-direction: column; gap: 1.25rem; }
    .activity-feed-card {
      background: var(--bg-card); border: 1px solid var(--border-color);
      border-radius: var(--radius-lg); padding: 1.5rem 1.75rem; box-shadow: var(--shadow-sm);
      transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
    }
    .activity-feed-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); border-color: #cbd5e1; }
    .attachment-bounding-box {
      background: #f8fafc; border: 1px solid var(--border-color);
      border-radius: var(--radius-md); padding: 0.85rem 1.15rem;
      display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.85rem;
    }

    /* ================= MODALS ================= */
    .modal-overlay {
      position: fixed; top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
      display: none; align-items: center; justify-content: center; z-index: 1000; padding: 1.5rem;
      animation: fadeIn 0.25s ease-out;
    }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    .modal-content {
      background: var(--bg-card); border: 1px solid var(--border-color);
      max-width: 820px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 2.25rem;
      border-radius: var(--radius-xl); box-shadow: var(--shadow-lg); animation: slideUp 0.25s ease-out;
    }
    @keyframes slideUp { from { transform: translateY(15px); } to { transform: translateY(0); } }

    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.75rem; padding-bottom: 1.15rem; border-bottom: 1px solid var(--border-color); }
    .modal-header h3 { font-weight: 700; color: var(--navy-primary); font-size: 1.3rem; margin: 0; }
    .close-btn { background: none; border: none; color: var(--text-muted); font-size: 1.5rem; cursor: pointer; padding: 0; line-height: 1; transition: color 0.2s; }
    .close-btn:hover { color: var(--danger); }
    
    #previewFrame { width: 100%; height: 62vh; border: 1px solid var(--border-color); border-radius: var(--radius-md); }
    #previewImg { max-width: 100%; max-height: 62vh; display: block; margin: 0 auto; border: 1px solid var(--border-color); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }

    /* ===================== RESPONSIVE BREAKPOINTS ===================== */

    /* Large Tablet */
    @media (max-width: 1024px) {
      .sidebar { transform: translateX(-100%); }
      .sidebar.show { transform: translateX(0); }
      .content-wrapper { margin-left: 0 !important; width: 100% !important; }
      .main-content { padding: 1.5rem; gap: 1.25rem; }
      .top-navbar { padding: 0 1.25rem; }
    }

    /* Small Tablet */
    @media (max-width: 900px) {
      .module-card { padding: 1.35rem 1.5rem; }
      .filter-card { flex-direction: column; align-items: stretch; gap: 0.75rem; }
      .hero-banner { padding: 1.75rem 1.5rem; }
      .hero-title { font-size: 1.6rem; }
    }

    /* Mobile */
    @media (max-width: 768px) {
      .main-content { padding: 1rem; gap: 1rem; }
      .top-navbar { padding: 0 1rem; height: 60px; }
      .top-navbar h3 { font-size: 1rem; }
      .module-card { padding: 1.25rem; }
      .modal-content { padding: 1.5rem; border-radius: var(--radius-lg); }
      .notif-dropdown { right: -40px; width: 290px; }
      .custom-table th, .custom-table td { padding: 0.6rem 0.7rem; font-size: 0.8rem; }
      .filter-card { flex-direction: column; align-items: stretch; gap: 0.75rem; }
      .hero-banner { padding: 1.5rem 1.25rem; }
      .hero-title { font-size: 1.35rem; }
      .hero-subtitle { font-size: 0.875rem; }
      .btn { font-size: 0.825rem; padding: 0.55rem 1rem; }
    }

    /* Small Mobile */
    @media (max-width: 480px) {
      .top-navbar h3 { font-size: 0.9rem; }
      .module-card { padding: 1rem; border-radius: var(--radius-md); }
      .modal-content { padding: 1.25rem; }
      .notif-dropdown { right: -10px; width: 270px; }
      .custom-table { font-size: 0.8rem; }
      .custom-table th, .custom-table td { padding: 0.5rem 0.6rem; }
      #previewFrame, #previewImg { height: 50vh; }
      .pagination { flex-wrap: wrap; }
      .hero-title { font-size: 1.2rem; }
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
            <a href="student_dashboard.php" class="sidebar-link">
                <i class="fa-solid fa-house"></i> <span>Dashboard</span>
            </a>
            <a href="student_submit.php" class="sidebar-link">
                <i class="fa-solid fa-cloud-arrow-up"></i> <span>Upload Assignment</span>
            </a>
            <a href="student_history.php" class="sidebar-link active">
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
            <div class="avatar"><?php echo strtoupper(substr($studentName, 0, 1)); ?></div>
            <div>
                <div style="font-weight: 700; font-size: 0.9rem; color: #f8fafc;"><?php echo e(ucwords(strtolower($studentName))); ?></div>
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
                    <h3>Submission History Log</h3>
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

                    <a href="student_submit.php" class="btn btn-primary">
                        <i class="fa-solid fa-cloud-arrow-up"></i> Upload New
                    </a>
                </div>
            </div>
        </header>

        <main class="main-content">

            <!-- Hero Header -->
            <div class="hero-banner">
                <div class="hero-content">
                    <div>
                        <h1 class="hero-title">Submission History</h1>
                        <p class="hero-subtitle">
                            Comprehensive activity submission records, earned evaluation marks, and instant document previews.
                        </p>
                    </div>
                </div>
            </div>

            <!-- KPI Metrics Grid -->
            <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr); gap: 1.5rem; margin-bottom: 1.75rem;">
                <div class="stat-block">
                    <div class="stat-top">
                        <span class="sys-tag accent">Submissions</span>
                        <div class="stat-icon" style="background: #eeeffe; color: #4338ca;"><i class="fa-solid fa-folder-open"></i></div>
                    </div>
                    <div class="stat-bottom">
                        <div class="stat-val" style="color: var(--navy-primary);"><?= count($history) ?></div>
                        <div class="stat-label">Total Submissions</div>
                    </div>
                </div>
                
                <div class="stat-block">
                    <div class="stat-top">
                        <span class="sys-tag success">Graded</span>
                        <div class="stat-icon" style="background: #dcfce7; color: #047857;"><i class="fa-solid fa-circle-check"></i></div>
                    </div>
                    <div class="stat-bottom">
                        <div class="stat-val" style="color: #047857;"><?= $totalEvaluated ?></div>
                        <div class="stat-label">Graded Submissions</div>
                    </div>
                </div>
                
                <div class="stat-block">
                    <div class="stat-top">
                        <span class="sys-tag warning">In Review</span>
                        <div class="stat-icon" style="background: #ffedd5; color: #9a3412;"><i class="fa-solid fa-clock"></i></div>
                    </div>
                    <div class="stat-bottom">
                        <div class="stat-val" style="color: #9a3412;"><?= $totalReview ?></div>
                        <div class="stat-label">Under Review</div>
                    </div>
                </div>
            </div>

            <!-- Control Toolbar & Filters -->
            <div class="filter-card">
                <div style="flex: 1 1 280px; min-width: 240px;">
                    <input type="text" id="searchInput" class="form-control-custom" placeholder="Search activity title or subject...">
                </div>
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; flex: 0 0 auto; align-items: center;">
                    <div style="min-width: 140px;">
                        <select id="subjectFilter" class="form-select-custom">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjects as $s): ?>
                                <option value="<?= e($s) ?>"><?= e($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="min-width: 140px;">
                        <select id="statusFilter" class="form-select-custom">
                            <option value="">All Statuses</option>
                            <option value="Graded">Graded</option>
                            <option value="Under Review">Under Review</option>
                        </select>
                    </div>
                    <button id="viewToggleBtn" class="btn btn-outline" style="margin:0; padding: 0.65rem 1rem;" title="Toggle View Mode">
                        <i class="fa-solid fa-table-cells-large" id="viewToggleIcon"></i> <span id="viewToggleLabel">Table View</span>
                    </button>
                    <button id="resetFiltersBtn" class="btn btn-outline" style="margin:0; padding: 0.65rem 0.9rem;" title="Reset Filters">
                        <i class="fa-solid fa-arrow-rotate-left"></i>
                    </button>
                </div>
            </div>

            <!-- Concept 1: Activity Feed Card Stack (Card-Based Layout) -->
            <div class="activity-feed-container" id="activityFeedContainer">
                <?php if (!$history): ?>
                    <div class="activity-feed-card js-empty-feed" style="text-align: center; padding: 3.5rem; color: var(--text-muted); font-weight: 500;">
                        No activity submissions recorded yet.
                    </div>
                <?php endif; ?>
                
                <?php foreach ($history as $h): 
                    $displayStatus = $h['display_status'];
                    $ext = strtolower(pathinfo($h['original_filename'] ?? '', PATHINFO_EXTENSION));
                    $cleanUnit = preg_replace('/^unit\s*/i', '', trim((string)$h['unit']));
                    $subDt = !empty($h['submission_date']) ? new DateTime($h['submission_date']) : null;
                ?>
                    <div class="activity-feed-card" 
                         data-subject="<?= e($h['subject_code']) ?>" 
                         data-status="<?= e($displayStatus) ?>" 
                         data-title="<?= e(mb_strtolower($h['title'] . ' ' . $h['subject_code'] . ' ' . ($h['original_filename'] ?? ''))) ?>">
                        
                        <!-- Top Row: Activity Title + Subject & Unit (Left) | Status Pill & Score (Right) -->
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.15rem; flex-wrap: wrap; gap: 0.85rem;">
                            <div>
                                <div style="display: flex; align-items: center; gap: 0.65rem; margin-bottom: 0.35rem; flex-wrap: wrap;">
                                    <h4 style="font-size: 1.15rem; font-weight: 800; color: var(--navy-primary); margin: 0; font-family: var(--font-heading);"><?= e($h['title']) ?></h4>
                                    <span class="sys-tag accent" style="font-size: 0.725rem; font-weight: 700;">Unit <?= e($cleanUnit) ?></span>
                                </div>
                                <p style="color: var(--text-muted); font-size: 0.875rem; font-weight: 600; margin: 0;">
                                    <i class="fa-solid fa-graduation-cap" style="color: var(--accent-primary); margin-right: 0.35rem;"></i> <?= e($h['subject_code']) ?>
                                    &middot; 
                                    <?php if ($subDt): ?>
                                        <span>Submitted <?= $subDt->format('d M Y') ?> at <?= $subDt->format('h:i A') ?></span>
                                    <?php else: ?>
                                        <span>Due: <?= fmtDate($h['due_date']) ?></span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            
                            <div style="display: flex; align-items: center; gap: 0.85rem;">
                                <span class="sys-tag <?= badgeClass($displayStatus) ?>" style="font-size: 0.8rem; font-weight: 700; padding: 0.4rem 0.95rem; margin:0;"><?= e($displayStatus) ?></span>
                                <?php if ($h['marks'] !== null): ?>
                                    <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.2); padding: 0.35rem 0.85rem; border-radius: var(--radius-md); font-family: var(--font-heading);">
                                        <strong style="font-size: 1.15rem; color: #047857; font-weight: 800;"><?= e($h['marks']) ?></strong> <span style="font-size: 0.8rem; color: var(--text-muted);">/ <?= e($h['max_marks']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Bottom Bounding Box: Attachment Pill & Action Buttons -->
                        <div class="attachment-bounding-box">
                            <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                                <i class="fa-solid fa-paperclip" style="color: var(--accent-primary); font-size: 1.1rem;"></i>
                                <span style="font-weight: 700; font-size: 0.875rem; color: var(--navy-primary);" title="<?= e($h['original_filename']) ?>"><?= e($h['original_filename']) ?></span>
                                <?php if (in_array($ext, ['jpg', 'jpeg', 'png'], true)): ?>
                                    <span class="sys-tag" style="background: #e2e8f0; color: #334155; border-color: #cbd5e1; font-size: 0.7rem; font-weight: 700; margin:0;"><i class="fa-regular fa-file-image"></i> Image (.<?= $ext ?>)</span>
                                <?php elseif ($ext === 'pdf'): ?>
                                    <span class="sys-tag" style="background: #fee2e2; color: #b91c1c; border-color: #fecaca; font-size: 0.7rem; font-weight: 700; margin:0;"><i class="fa-regular fa-file-pdf"></i> PDF Document</span>
                                <?php else: ?>
                                    <span class="sys-tag" style="background: #e2e8f0; color: #334155; border-color: #cbd5e1; font-size: 0.7rem; font-weight: 700; margin:0;"><i class="fa-regular fa-file-lines"></i> <?= strtoupper($ext ?: 'FILE') ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                <?php if (!empty($h['id'])): ?>
                                    <button type="button" class="btn btn-outline" style="padding: 0.45rem 0.95rem; font-size: 0.825rem;" title="View Document Preview"
                                            onclick='openPreview(<?= (int)$h['id'] ?>, <?= jsAttr($h['file_type']) ?>, <?= jsAttr($h['original_filename']) ?>)'>
                                        <i class="fa-regular fa-eye"></i> View Attachment
                                    </button>
                                    <a class="btn btn-primary" style="padding: 0.45rem 0.95rem; font-size: 0.825rem; margin: 0;" title="Download Original File"
                                       href="student_history.php?action=download&id=<?= (int)$h['id'] ?>">
                                        <i class="fa-solid fa-download"></i> Download
                                    </a>
                                <?php else: ?>
                                    <span class="sys-tag" style="background: transparent; color: var(--text-muted); border-color: var(--border-color); margin:0;">Submission Closed</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Alternative Table View (Hidden by default or switchable) -->
            <div class="table-responsive" id="historyTableView" style="display: none;">
                <table class="custom-table" id="historyTable">
                    <thead>
                        <tr>
                            <th>Activity Title</th>
                            <th>Subject</th>
                            <th>Uploaded File</th>
                            <th>Submission Date</th>
                            <th>Score</th>
                            <th>Status</th>
                            <th style="text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="historyTableBody">
                        <?php if (!$history): ?>
                            <tr class="js-empty">
                                <td colspan="7" style="text-align: center; padding: 3.5rem; color: var(--text-muted); font-weight: 500;">
                                    No activity submissions recorded yet.
                                </td>
                            </tr>
                        <?php endif; ?>
                        
                        <?php foreach ($history as $h): 
                            $displayStatus = $h['display_status'];
                            $fileExt = strtolower((string)($h['file_type'] ?? ''));
                            $cleanUnit = preg_replace('/^unit\s*/i', '', trim((string)$h['unit']));
                        ?>
                            <tr data-subject="<?= e($h['subject_code']) ?>" 
                                data-status="<?= e($displayStatus) ?>" 
                                data-title="<?= e(mb_strtolower($h['title'] . ' ' . $h['subject_code'] . ' ' . ($h['original_filename'] ?? ''))) ?>">
                                
                                <td>
                                    <strong style="font-size: 0.95rem; color: var(--text-main); display: block; margin-bottom: 0.3rem; font-family: var(--font-heading);"><?= e($h['title']) ?></strong>
                                    <span class="sys-tag accent" style="font-size: 0.7rem; margin:0; font-weight: 600;">Unit <?= e($cleanUnit) ?></span>
                                </td>
                                
                                <td>
                                    <strong style="color: var(--text-main); font-weight: 700;"><?= e($h['subject_code']) ?></strong>
                                </td>
                                
                                <td>
                                    <?php 
                                    $ext = strtolower(pathinfo($h['original_filename'] ?? '', PATHINFO_EXTENSION));
                                    if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                                        echo '<span class="sys-tag" style="background: #f1f5f9; color: var(--accent-primary); border-color: #cbd5e1; font-weight: 600; margin:0; white-space: nowrap;" title="'.e($h['original_filename']).'"><i class="fa-regular fa-file-image"></i> Image (.' . $ext . ')</span>';
                                    } elseif ($ext === 'pdf') {
                                        echo '<span class="sys-tag" style="background: #fee2e2; color: #dc2626; border-color: #fecaca; font-weight: 600; margin:0; white-space: nowrap;" title="'.e($h['original_filename']).'"><i class="fa-regular fa-file-pdf"></i> PDF Document</span>';
                                    } else {
                                        echo '<span class="sys-tag" style="background: #f1f5f9; color: #475569; border-color: #cbd5e1; font-weight: 600; margin:0; white-space: nowrap;" title="'.e($h['original_filename']).'"><i class="fa-regular fa-file-lines"></i> ' . strtoupper($ext ?: 'FILE') . '</span>';
                                    }
                                    ?>
                                </td>
                                
                                <td style="font-size: 0.825rem; font-weight: 600; white-space: nowrap;">
                                    <?php if ($h['submission_date']): ?>
                                        <?php $subDt = new DateTime($h['submission_date']); echo $subDt->format('d M Y') . ' &middot; <span style="color: var(--text-muted); font-size: 0.775rem;">' . $subDt->format('h:i A') . '</span>'; ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">Due: <?= fmtDate($h['due_date']) ?></span>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <?php if ($h['marks'] !== null): ?>
                                        <strong style="font-size: 1.1rem; color: #047857; font-family: var(--font-heading);"><?= e($h['marks']) ?></strong>
                                        <span style="font-size: 0.75rem; color: var(--text-muted);">/ <?= e($h['max_marks']) ?></span>
                                    <?php elseif ($displayStatus === 'Under Review'): ?>
                                        <span class="sys-tag info" style="margin:0; font-weight: 600;">Under Evaluation</span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <span class="sys-tag <?= badgeClass($displayStatus) ?>" style="margin:0; font-weight: 600;"><?= e($displayStatus) ?></span>
                                </td>
                                
                                <td style="text-align: center;">
                                    <div style="display: flex; gap: 0.4rem; justify-content: center;">
                                        <?php if (!empty($h['id'])): ?>
                                            <button type="button" class="btn btn-outline" style="padding: 0.35rem 0.7rem; font-size: 0.8rem;" title="View Document Preview"
                                                    onclick='openPreview(<?= (int)$h['id'] ?>, <?= jsAttr($h['file_type']) ?>, <?= jsAttr($h['original_filename']) ?>)'>
                                                <i class="fa-regular fa-eye"></i> View
                                            </button>
                                            <a class="btn btn-primary" style="padding: 0.35rem 0.7rem; font-size: 0.8rem; margin: 0;" title="Download Original File"
                                               href="student_history.php?action=download&id=<?= (int)$h['id'] ?>">
                                                <i class="fa-solid fa-download"></i> DL
                                            </a>
                                        <?php else: ?>
                                            <span class="sys-tag" style="background: transparent; color: var(--text-muted); border-color: var(--border-color); margin:0;">Closed</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination & Summary Footer -->
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-top: 1rem;">
                <div style="font-size: 0.875rem; font-weight: 600; color: var(--text-muted);" id="resultsSummary">Showing submissions</div>
                <nav aria-label="Page navigation">
                    <ul class="pagination" id="historyPagination"></ul>
                </nav>
            </div>

        </main>
    </div>
</div>

<!-- Document Preview Modal -->
<div class="modal-overlay" id="previewModalWrapper">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="previewTitle" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 85%;">Document Preview</h3>
            <button class="close-btn" id="closePreviewModal">&times;</button>
        </div>
        <div style="padding-bottom: 1.5rem;">
            <iframe id="previewFrame" class="d-none"></iframe>
            <img id="previewImg" class="d-none" alt="Submitted file preview">
            <div id="previewUnsupported" class="d-none" style="text-align: center; padding: 4rem 2rem;">
                <i class="fa-solid fa-file-circle-xmark" style="font-size: 3.5rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                <h4 style="font-weight: 700; color: var(--navy-primary); margin-bottom: 0.5rem;">Preview Unavailable</h4>
                <p style="font-size: 0.9rem; color: var(--text-muted);">Please download the file using the button below to view its contents.</p>
            </div>
        </div>
        <div style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem;">
            <button type="button" class="btn btn-outline" id="cancelPreviewModal">Close</button>
            <a class="btn btn-primary" id="previewDownloadBtn" href="#" style="margin: 0;">
                <i class="fa-solid fa-download"></i> Download File
            </a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {

    // 1. Activity Feed & Table Filter, Pagination & View Mode Logic
    const tbody = document.getElementById('historyTableBody');
    const feedContainer = document.getElementById('activityFeedContainer');
    const tableContainer = document.getElementById('historyTableView');
    const allRows = Array.from(tbody.querySelectorAll('tr[data-subject]'));
    const allFeedCards = Array.from(feedContainer.querySelectorAll('.activity-feed-card[data-subject]'));
    const pager = document.getElementById('historyPagination');
    const summary = document.getElementById('resultsSummary');
    const searchInput = document.getElementById('searchInput');
    const subjectFilter = document.getElementById('subjectFilter');
    const statusFilter = document.getElementById('statusFilter');
    const resetBtn = document.getElementById('resetFiltersBtn');
    const viewToggleBtn = document.getElementById('viewToggleBtn');
    const viewToggleIcon = document.getElementById('viewToggleIcon');
    const viewToggleLabel = document.getElementById('viewToggleLabel');
    
    const perPage = 6;
    let currentPage = 1;
    let currentView = 'feed'; // 'feed' or 'table'

    function filterItems(items) {
        const q = (searchInput.value || '').trim().toLowerCase();
        const subj = subjectFilter.value;
        const stat = statusFilter.value;

        return items.filter(item => {
            const matchTitle = !q || item.dataset.title.includes(q);
            const matchSubj = !subj || item.dataset.subject === subj;
            const matchStat = !stat || item.dataset.status === stat;
            return matchTitle && matchSubj && matchStat;
        });
    }

    function renderView() {
        const filteredRows = filterItems(allRows);
        const filteredCards = filterItems(allFeedCards);
        const totalItems = currentView === 'feed' ? filteredCards.length : filteredRows.length;
        const totalPages = Math.max(1, Math.ceil(totalItems / perPage));
        if (currentPage > totalPages) currentPage = totalPages;

        const startIdx = (currentPage - 1) * perPage;
        const endIdx = startIdx + perPage;

        // Render Table View
        allRows.forEach(r => r.style.display = 'none');
        const visibleRows = filteredRows.slice(startIdx, endIdx);
        visibleRows.forEach(r => r.style.display = '');

        // Render Feed View
        allFeedCards.forEach(c => c.style.display = 'none');
        const visibleCards = filteredCards.slice(startIdx, endIdx);
        visibleCards.forEach(c => c.style.display = '');

        // Empty States
        let emptyFeed = feedContainer.querySelector('.js-empty-feed');
        if (!filteredCards.length) {
            if (!emptyFeed) {
                emptyFeed = document.createElement('div');
                emptyFeed.className = 'activity-feed-card js-empty-feed';
                emptyFeed.style.cssText = 'text-align: center; padding: 3.5rem; color: var(--text-muted); font-weight: 500;';
                emptyFeed.textContent = 'No submissions found matching your filters.';
                feedContainer.appendChild(emptyFeed);
            }
            emptyFeed.style.display = '';
        } else if (emptyFeed) {
            emptyFeed.style.display = 'none';
        }

        // Render Pagination Links
        pager.innerHTML = '';
        if (totalPages > 1) {
            let html = `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                            <a class="page-link" href="#" data-p="${currentPage - 1}">Prev</a>
                        </li>`;
            for (let p = 1; p <= totalPages; p++) {
                html += `<li class="page-item ${p === currentPage ? 'active' : ''}">
                            <a class="page-link" href="#" data-p="${p}">${p}</a>
                         </li>`;
            }
            html += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                        <a class="page-link" href="#" data-p="${currentPage + 1}">Next</a>
                      </li>`;
            pager.innerHTML = html;
        }

        const start = totalItems ? startIdx + 1 : 0;
        const end = Math.min(endIdx, totalItems);
        summary.textContent = `Showing ${start}-${end} of ${totalItems} records`;
    }

    // View Switcher Handler
    if (viewToggleBtn) {
        viewToggleBtn.addEventListener('click', () => {
            if (currentView === 'feed') {
                currentView = 'table';
                feedContainer.style.display = 'none';
                tableContainer.style.display = 'block';
                viewToggleIcon.className = 'fa-solid fa-list-check';
                viewToggleLabel.textContent = 'Feed View';
            } else {
                currentView = 'feed';
                tableContainer.style.display = 'none';
                feedContainer.style.display = 'flex';
                viewToggleIcon.className = 'fa-solid fa-table-cells-large';
                viewToggleLabel.textContent = 'Table View';
            }
            currentPage = 1;
            renderView();
        });
    }

    pager.addEventListener('click', e => {
        e.preventDefault();
        const target = e.target.closest('[data-p]');
        if (!target || target.parentElement.classList.contains('disabled')) return;
        currentPage = parseInt(target.dataset.p, 10);
        renderView();
    });

    searchInput.addEventListener('input', () => { currentPage = 1; renderView(); });
    subjectFilter.addEventListener('change', () => { currentPage = 1; renderView(); });
    statusFilter.addEventListener('change', () => { currentPage = 1; renderView(); });

    resetBtn.addEventListener('click', () => {
        searchInput.value = '';
        subjectFilter.value = '';
        statusFilter.value = '';
        currentPage = 1;
        renderView();
    });

    renderView();

    // 2. Mobile Sidebar Toggle
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
});

// 3. Custom Modal Preview Logic
const previewModalWrapper = document.getElementById('previewModalWrapper');
const closePreviewBtn = document.getElementById('closePreviewModal');
const cancelPreviewBtn = document.getElementById('cancelPreviewModal');

function closePreviewModalFunc() {
    previewModalWrapper.style.display = 'none';
    document.getElementById('previewFrame').src = '';
    document.getElementById('previewImg').src = '';
}

closePreviewBtn.addEventListener('click', closePreviewModalFunc);
cancelPreviewBtn.addEventListener('click', closePreviewModalFunc);
previewModalWrapper.addEventListener('click', (e) => {
    if(e.target === previewModalWrapper) closePreviewModalFunc();
});

function openPreview(submissionId, fileType, fileName) {
    const frame = document.getElementById('previewFrame');
    const img = document.getElementById('previewImg');
    const unsupported = document.getElementById('previewUnsupported');
    const title = document.getElementById('previewTitle');
    const dlBtn = document.getElementById('previewDownloadBtn');

    title.innerHTML = fileName;
    dlBtn.href = `student_history.php?action=download&id=${submissionId}`;

    frame.classList.add('d-none');
    img.classList.add('d-none');
    unsupported.classList.add('d-none');
    frame.src = '';
    img.src = '';

    const previewUrl = `student_history.php?action=preview&id=${submissionId}`;
    const type = (fileType || '').toLowerCase();

    if (type === 'pdf') {
        frame.src = previewUrl;
        frame.classList.remove('d-none');
    } else if (['jpg', 'jpeg', 'png'].includes(type)) {
        img.src = previewUrl;
        img.classList.remove('d-none');
    } else {
        unsupported.classList.remove('d-none');
    }
    
    previewModalWrapper.style.display = 'flex';
}

// Notification System Interactive Manager
document.addEventListener("DOMContentLoaded", () => {
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

    // Responsive Sidebar Collapse & Toggle Handler
    const appContainer = document.querySelector('.app-container');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const closeSidebar = document.getElementById('closeSidebar');
    const erpSidebar = document.getElementById('erpSidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

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

    // Button Click Ripple Effect Handler
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
// Safely include modal without fatal error
$modalPath = __DIR__ . '/includes/end_session_modal.php';
if (file_exists($modalPath)) {
    include_once $modalPath;
}
?>
</body>
</html>