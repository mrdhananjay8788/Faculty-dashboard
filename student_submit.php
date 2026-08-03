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
if (empty($_SESSION['user_id']) || !in_array($role, ['parent', 'admin', 'student', 'faculty', 'hod', 'gfm'])) {
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
function jsAttr($v) { return htmlspecialchars(json_encode($v), ENT_QUOTES, 'UTF-8'); }

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

// Fix undefined variables used in the UI
$studentName = $fullName;
$studentPrn = $studentZprn;
$studentCode = $studentLinkedPrn;

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

// Fetch Assigned Activities
$stmtActs = $pdo->prepare("
    SELECT a.*, a.subject AS subject_code,
           s.id AS submission_id, s.status AS sub_status, s.marks, s.submission_date, s.file_path, s.file_type, s.original_filename, s.is_late
    FROM activities a
    LEFT JOIN submissions s ON a.activity_id = s.activity_id AND (s.student_id = ? OR s.student_id = ?)
    WHERE a.target_type = 'all' 
       OR (a.target_type = 'individual' AND (a.target_id = ? OR a.target_id = ?)) 
       OR (a.target_type = 'group' AND a.target_id IN (SELECT group_id FROM group_members WHERE student_id = ? OR student_id = ?))
       OR (a.target_type = 'class' AND a.target_id IN (SELECT fc.class_id FROM faculty_classes fc LEFT JOIN faculty_class_students fcs ON fcs.class_id = fc.class_id WHERE (fc.department = ? AND fc.academic_year = ? AND fc.division = ?) OR (fcs.student_prn = ? OR fcs.student_prn = ?)))
    ORDER BY a.due_date ASC, a.activity_id DESC
");
$stmtActs->execute([$studentTableId, $studentUserId, $studentTableId, $studentUserId, $studentTableId, $studentUserId, $deptName, $academic_year, $division, $studentUsername, $studentLinkedPrn]);
$activities = $stmtActs->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($activities as &$a) {
    $a['id'] = $a['activity_id'];
}
unset($a);
$totalActivities = count($activities);

// Notification Feed Construction
$notifications = [];
$nowNotif = new DateTime();
foreach ($activities as $actItem) {
    if (!empty($actItem['submission_id']) && $actItem['marks'] !== null) {
        $notifications[] = [
            'id' => 'eval_' . $actItem['submission_id'],
            'type' => 'grade',
            'title' => 'Grade Posted: ' . $actItem['title'],
            'desc' => 'Score: ' . number_format((float)$actItem['marks'], 1) . ' / ' . number_format((float)$actItem['max_marks'], 1) . ' (' . ($actItem['subject_code'] ?? '') . ')',
            'time' => fmtDate($actItem['submission_date'] ?? ''),
            'link' => 'student_history.php',
            'icon' => 'fa-award',
            'color' => '#10b981'
        ];
    } elseif (empty($actItem['submission_id']) && !empty($actItem['due_date'])) {
        try {
            $dueDt = new DateTime($actItem['due_date']);
            $diffN = $nowNotif->diff($dueDt);
            if ($diffN->days <= 3 && !$diffN->invert) {
                $notifications[] = [
                    'id' => 'due_' . $actItem['activity_id'],
                    'type' => 'deadline',
                    'title' => 'Upcoming Deadline: ' . $actItem['title'],
                    'desc' => ($actItem['subject_code'] ?? '') . ' Unit ' . $actItem['unit'] . ' - Due in ' . $diffN->days . ' days',
                    'time' => fmtDate($actItem['due_date']),
                    'link' => 'student_submit.php?activity_id=' . $actItem['activity_id'],
                    'icon' => 'fa-clock',
                    'color' => '#ef4444'
                ];
            }
        } catch(Exception $e) {}
    }
}
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


function displayStatus($a) {
    if (!empty($a['submission_id'])) {
        $st = $a['sub_status'] ?: 'Submitted';
        if ($a['marks'] !== null && $st === 'Submitted') return 'Graded';
        if (in_array($st, ['Approved', 'Graded', 'Evaluated'], true)) return 'Graded';
        if ($st === 'Submitted') return 'Submitted';
        return 'Under Review';
    }
    try {
        $now = new DateTime();
        $due = new DateTime($a['due_date']);
        if ($now > $due) {
            return 'Missed';
        }
    } catch (Exception $e) {}
    return 'Pending';
}

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

// ---------------------------------------------------------------------------------
// UPLOAD HANDLER (Student Upload)
// ---------------------------------------------------------------------------------
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST' && strtolower($role) === 'student') {
    header('Content-Type: application/json');
    $UPLOAD_ROOT = __DIR__ . '/uploads/';
    $ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];
    $MAX_SIZE     = 5 * 1024 * 1024; // 5 MB

    try {
        if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
            throw new Exception('Your session expired. Please refresh the page and try again.');
        }
        $activityId = (int) ($_POST['activity_id'] ?? 0);
        if (!$activityId) throw new Exception('Invalid activity.');

        // Verify Student Assignment
        $stmt = $pdo->prepare("
            SELECT * FROM activities 
            WHERE activity_id = ? 
              AND (target_type = 'all' 
                   OR (target_type = 'individual' AND (target_id = ? OR target_id = ?)) 
                   OR (target_type = 'group' AND target_id IN (SELECT group_id FROM group_members WHERE student_id = ? OR student_id = ?))
                   OR (target_type = 'class' AND target_id IN (SELECT fc.class_id FROM faculty_classes fc LEFT JOIN faculty_class_students fcs ON fcs.class_id = fc.class_id WHERE (fc.department = ? AND fc.academic_year = ? AND fc.division = ?) OR (fcs.student_prn = ? OR fcs.student_prn = ?))))
        ");
        $stmt->execute([$activityId, $studentTableId, $studentUserId, $studentTableId, $studentUserId, $deptName, $academic_year, $division, $studentUsername, $studentLinkedPrn]);
        $activity = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$activity) throw new Exception('Activity not found or not assigned to you.');

        $now = new DateTime();
        $due = new DateTime($activity['due_date']);

        if (empty($_FILES['activity_file']) || $_FILES['activity_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Please choose a valid file to upload.');
        }
        $file = $_FILES['activity_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $ALLOWED_EXT, true)) {
            throw new Exception('Only PDF, JPG, and PNG files are allowed.');
        }

        $fileSize = (int) ($file['size'] ?? 0);
        if ($fileSize > $MAX_SIZE) {
            throw new Exception('File exceeds the 5 MB size limit.');
        }

        // Check for Existing Submissions
        $stmt = $pdo->prepare("SELECT * FROM submissions WHERE activity_id = ? AND (student_id = ? OR student_id = ?)");
        $stmt->execute([$activityId, $studentTableId, $studentUserId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $studentDir = $UPLOAD_ROOT . $studentTableId . '/';
        if (!is_dir($studentDir) && !mkdir($studentDir, 0755, true) && !is_dir($studentDir)) {
            throw new Exception('Server could not prepare the upload folder.');
        }

        $storedName = 'act' . $activityId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath   = $studentDir . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new Exception('Failed to save the uploaded file on the server.');
        }

        $isLate = ($due < $now) ? 1 : 0;
        $submissionDateStr = (new DateTime())->format('Y-m-d H:i:s');
        
        // Simple automatic mark deduction logic if late (as per original)
        $calcMarks = $activity['max_marks'];
        if ($isLate) {
            $diffDays = (new DateTime($activity['due_date']))->setTime(0,0)->diff((new DateTime($submissionDateStr))->setTime(0,0))->days;
            if ($diffDays == 0 || $diffDays == 1) $calcMarks = max(0, $calcMarks - 1);
            elseif ($diffDays == 2) $calcMarks = max(0, $calcMarks - 2);
            else $calcMarks = 0;
        }

        if ($existing) {
            if (!empty($existing['file_path']) && is_file($existing['file_path'])) {
                @unlink($existing['file_path']);
            }
            $stmt = $pdo->prepare("UPDATE submissions SET student_id = ?, original_filename = ?, saved_filename = ?, file_path = ?, file_type = ?, file_size = ?, submission_date = ?, is_late = ?, marks = ?, status = 'Submitted' WHERE id = ?");
            $stmt->execute([$studentTableId, basename($file['name']), $storedName, $destPath, $ext, $fileSize, $submissionDateStr, $isLate, $calcMarks, $existing['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO submissions (activity_id, student_id, original_filename, saved_filename, file_path, file_type, file_size, submission_date, is_late, marks, status) VALUES (?,?,?,?,?,?,?,?,?,?,'Submitted')");
            $stmt->execute([$activityId, $studentTableId, basename($file['name']), $storedName, $destPath, $ext, $fileSize, $submissionDateStr, $isLate, $calcMarks]);
        }

        // Write audit log entry
        try {
            $logAction = "Submitted Activity";
            $logDetails = "Activity: " . $activity['title'] . " | Subject: " . $activity['subject'] . " | Unit: " . $activity['unit'] . " | Marks: " . $calcMarks;
            $logStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, details) VALUES (?, 'Student', ?, ?)");
            $logStmt->execute([$studentTableId, $logAction, $logDetails]);
        } catch (Exception $e) {
            // Swallowed safely
        }

        echo json_encode(['success' => true, 'message' => 'Activity uploaded successfully.', 'late' => (bool) $isLate]);
    } catch (Exception $ex) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------------------------------
// DOWNLOAD / PREVIEW HANDLER
// ---------------------------------------------------------------------------------
if ($action === 'preview' || $action === 'download') {
    $UPLOAD_ROOT = __DIR__ . '/uploads/';
    $subId = (int) ($_GET['id'] ?? 0);
    if (in_array($role, ['faculty', 'hod', 'gfm', 'admin'])) {
        $stmt = $pdo->prepare("SELECT * FROM submissions WHERE id = ?");
        $stmt->execute([$subId]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM submissions WHERE id = ? AND student_id = ?");
        $stmt->execute([$subId, $studentTableId]);
    }
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Upload Assignment | SAAES</title>
  
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
    .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.4); color: #fff; }

    .btn-outline { background: var(--bg-card); border-color: var(--border-color); color: var(--text-main); }
    .btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; transform: translateY(-1px); }

    /* ================= INFO GRID ================= */
    .info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.25rem; margin-bottom: 1.75rem; }
    .info-block {
      background: rgba(79, 70, 229, 0.04); border: 1px solid rgba(79, 70, 229, 0.1);
      padding: 1.45rem 1.35rem; border-radius: var(--radius-md);
      transition: transform 0.2s, box-shadow 0.2s, background 0.2s;
    }
    .info-block:hover { transform: translateY(-2px); background: rgba(79, 70, 229, 0.08); box-shadow: var(--shadow-sm); }
    .info-label { font-size: 0.725rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 0.35rem; display: block; letter-spacing: 0.08em; }
    .info-value { font-family: var(--font-heading); font-weight: 700; color: var(--navy-primary); font-size: 1.15rem; }

    @media (max-width: 900px) {
      .info-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 500px) {
      .info-grid { grid-template-columns: 1fr; }
    }

    /* ================= FILTERS & FORMS ================= */
    .filter-card {
        background: var(--bg-card); border: 1px solid var(--border-color); padding: 1.15rem 1.35rem; border-radius: var(--radius-md);
        display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; box-shadow: var(--shadow-sm); margin-bottom: 1.5rem;
    }
    .form-label { font-size: 0.85rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.5rem; display: block; }
    .form-control-custom, .form-select-custom {
        width: 100%; padding: 0.65rem 1rem; background: var(--bg-body); border: 1px solid var(--border-color);
        color: var(--text-main); font-family: inherit; font-size: 0.875rem; outline: none; transition: all 0.2s;
        border-radius: var(--radius-md); -webkit-appearance: none;
    }
    .form-control-custom:focus, .form-select-custom:focus {
        border-color: var(--accent-primary); background: var(--bg-card); box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.12);
    }
    .form-select-custom {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right 1rem center; background-size: 1em; padding-right: 2.5rem;
    }

    /* ================= TABLES ================= */
    .table-responsive { overflow-x: auto; border: 1px solid var(--border-color); border-radius: var(--radius-md); box-shadow: var(--shadow-xs); }
    .custom-table { width: 100%; border-collapse: collapse; text-align: left; background: var(--bg-card); }
    .custom-table th, .custom-table td { padding: 0.75rem 0.85rem; border-bottom: 1px solid var(--border-color); font-size: 0.85rem; vertical-align: middle; }
    .custom-table th { background: #f8fafc; color: var(--text-muted); font-weight: 700; font-size: 0.725rem; text-transform: uppercase; letter-spacing: 0.07em; }
    .custom-table tbody tr { transition: background 0.15s ease; }
    .custom-table tbody tr:hover { background: rgba(238, 239, 254, 0.4); }
    .custom-table tbody tr:last-child td { border-bottom: none; }

    /* Pagination */
    .pagination { display: flex; list-style: none; gap: 0.4rem; margin: 0; padding: 0; }
    .page-item .page-link { font-weight: 600; border: 1px solid var(--border-color); border-radius: var(--radius-md); color: var(--text-main); background: var(--bg-card); padding: 0.45rem 0.9rem; text-decoration: none; font-size: 0.85rem; transition: all 0.2s; }
    .page-item:not(.active):not(.disabled) .page-link:hover { background: #f1f5f9; border-color: #cbd5e1; }
    .page-item.active .page-link { background: var(--accent-gradient); color: #fff; border-color: transparent; box-shadow: var(--shadow-glow); }
    .page-item.disabled .page-link { opacity: 0.4; pointer-events: none; background: var(--bg-body); }

    /* ================= ALERTS & MODALS ================= */
    .alert { font-size: 0.925rem; font-weight: 600; border-radius: var(--radius-md); padding: 1.15rem 1.35rem; display: flex; align-items: center; gap: 0.85rem; margin-bottom: 1.75rem; }
    .alert-danger { background: var(--danger-bg); color: #991b1b; border: 1px solid var(--danger-border); }
    .alert-success { background: var(--success-bg); color: #166534; border: 1px solid var(--success-border); }
    .alert-info {
      background: linear-gradient(135deg, #ffffff 0%, #f0f9ff 100%);
      color: #0c4a6e;
      border: 1px solid #bae6fd;
      border-left: 4px solid var(--info);
      box-shadow: var(--shadow-sm);
    }

    .modal-overlay {
      position: fixed; top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
      display: none; align-items: center; justify-content: center; z-index: 1000; padding: 1.5rem;
      animation: fadeIn 0.25s ease-out;
    }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    .modal-content {
      background: var(--bg-card); border: 1px solid var(--border-color);
      max-width: 620px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 2.25rem;
      border-radius: var(--radius-xl); box-shadow: var(--shadow-lg); animation: slideUp 0.25s ease-out;
    }
    @keyframes slideUp { from { transform: translateY(15px); } to { transform: translateY(0); } }

    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.75rem; padding-bottom: 1.15rem; border-bottom: 1px solid var(--border-color); }
    .modal-header h3 { font-weight: 700; color: var(--navy-primary); font-size: 1.3rem; margin: 0; }
    .close-btn { background: none; border: none; color: var(--text-muted); font-size: 1.5rem; cursor: pointer; padding: 0; line-height: 1; transition: color 0.2s; }
    .close-btn:hover { color: var(--danger); }
    
    /* Drag and Drop Zone */
    .dropzone-box {
      border: 2px dashed var(--border-color); border-radius: var(--radius-lg); padding: 2rem 1.5rem;
      text-align: center; background: #f8fafc; transition: all 0.25s ease; cursor: pointer; position: relative;
    }
    .dropzone-box:hover, .dropzone-box.dragover { border-color: var(--accent-primary); background: var(--accent-light); }
    .dropzone-box i { font-size: 2.5rem; color: var(--accent-primary); margin-bottom: 0.75rem; display: block; }

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
      .info-grid { grid-template-columns: repeat(2, 1fr); }
      .module-card { padding: 1.35rem 1.5rem; }
      .filter-card { flex-direction: column; align-items: stretch; }
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
      .dropzone-box { padding: 1.5rem 1rem; }
      .btn { font-size: 0.825rem; padding: 0.55rem 1rem; }
    }

    /* Small Mobile */
    @media (max-width: 480px) {
      .info-grid { grid-template-columns: 1fr; }
      .top-navbar h3 { font-size: 0.9rem; }
      .module-card { padding: 1rem; border-radius: var(--radius-md); }
      .modal-content { padding: 1.25rem; }
      .notif-dropdown { right: -10px; width: 270px; }
      .custom-table { font-size: 0.8rem; }
      .custom-table th, .custom-table td { padding: 0.5rem 0.6rem; }
      #previewFrame, #previewImg { height: 50vh; }
      .pagination { flex-wrap: wrap; }
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
            <a href="student_submit.php" class="sidebar-link active">
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
                    <h3>Submit Activity</h3>
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

                    <a href="student_history.php" class="btn btn-outline" style="padding: 0.45rem 1rem;">
                        <i class="fa-solid fa-clock-rotate-left"></i> View Log
                    </a>
                </div>
            </div>
        </header>

        <main class="main-content">

            <div class="alert alert-info">
                <i class="fa-solid fa-circle-info" style="font-size: 1.25rem; color: #0284c7;"></i>
                <div>
                    <strong style="color: #0369a1; font-weight: 800;">Submission Guidelines:</strong> Upload your activity files before the due date. Accepted formats: <strong>PDF, JPG, PNG</strong> (max file size: <strong>5 MB</strong>).
                </div>
            </div>

            <div class="module-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.75rem; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <h2 style="font-size: 1.5rem; font-weight: 800; color: var(--navy-primary); margin-bottom: 0.25rem;">Student Activities Portal</h2>
                        <p style="color: var(--text-muted); font-size: 0.9rem; margin: 0;">Upload, track and manage your coursework submissions. &middot; <span style="font-weight: 600; color: var(--navy-primary);"><i class="fa-regular fa-calendar-days" style="color: var(--accent-primary); margin-right: 0.25rem;"></i> Today: <?= (new DateTime())->format('d M Y') ?></span></p>
                    </div>
                    <span class="sys-tag accent" style="font-size: 0.825rem; padding: 0.4rem 0.85rem;">
                        <i class="fa-solid fa-folder-open"></i> <?= $totalActivities ?> Activities &middot; <?= count($subjects) ?> Subject<?= count($subjects) === 1 ? '' : 's' ?>
                    </span>
                </div>

                <!-- Student Details Grid (Symmetrical 4-Card Row) -->
                <div class="info-grid">
                    <div class="info-block">
                        <span class="info-label">Student PRN</span>
                        <span class="info-value"><?= e($studentPrn ?: $studentCode) ?></span>
                    </div>
                    <div class="info-block">
                        <span class="info-label">Student Name</span>
                        <span class="info-value"><?= e(ucwords(strtolower($studentName))) ?></span>
                    </div>
                    <div class="info-block">
                        <span class="info-label">Roll Number</span>
                        <span class="info-value"><?= e($rollNo) ?></span>
                    </div>
                    <div class="info-block">
                        <span class="info-label">Division</span>
                        <span class="info-value"><?= e($division) ?></span>
                    </div>
                </div>

                <!-- Control Toolbar & Filters -->
                <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--navy-primary); margin-bottom: 1rem;"><i class="fa-solid fa-sliders" style="color: var(--accent-primary); margin-right: 0.5rem;"></i> Activities Queue</h3>
                <div class="filter-card">
                    <div style="flex: 1 1 280px; min-width: 220px;">
                        <input type="text" id="searchInput" class="form-control-custom" placeholder="Search by activity title...">
                    </div>
                    <div style="display: flex; gap: 1rem; flex-wrap: wrap; flex: 0 0 auto;">
                        <div style="min-width: 140px;">
                            <select id="subjectFilter" class="form-select-custom">
                                <option value="">All Subjects</option>
                                <?php foreach ($subjects as $s): ?><option><?= e($s) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div style="min-width: 130px;">
                            <select id="unitFilter" class="form-select-custom">
                                <option value="">All Units</option>
                                <?php foreach ($units as $u): ?>
                                    <?php $cleanU = preg_replace('/^unit\s*/i', '', trim((string)$u)); ?>
                                    <option>Unit <?= e($cleanU) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="min-width: 140px;">
                            <select id="statusFilter" class="form-select-custom">
                                <option value="">All Statuses</option>
                                <option>Pending</option><option>Submission Closed</option><option>Submitted</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Submission Table -->
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Subject</th>
                                <th>Unit</th>
                                <th>Activity Title</th>
                                <th>Due Date</th>
                                <th>Sub. Date</th>
                                <th>File</th>
                                <th>Marks</th>
                                <th>Status</th>
                                <th style="text-align: center;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="activitiesTableBody">
                            <?php if (!$activities): ?>
                                <tr class="js-empty"><td colspan="10" style="text-align: center; padding: 3.5rem; color: var(--text-muted); font-weight: 500;">No activities have been assigned yet.</td></tr>
                            <?php endif; ?>
                            
                            <?php foreach ($activities as $a):
                                $status = displayStatus($a);
                                $hasSub = !empty($a['submission_id']);
                                $due = new DateTime($a['due_date']);
                                $cleanUnit = preg_replace('/^unit\s*/i', '', trim((string)$a['unit']));
                            ?>
                            <tr data-subject="<?= e($a['subject_code']) ?>" data-unit="Unit <?= e($cleanUnit) ?>" data-status="<?= e($status) ?>" data-title="<?= e(mb_strtolower($a['title'])) ?>">
                                <td style="font-weight: 700; color: var(--text-muted); font-size: 0.85rem;">#<?= str_pad($a['id'], 3, '0', STR_PAD_LEFT) ?></td>
                                <td><span class="sys-tag accent" style="margin:0; font-weight: 700;"><?= e($a['subject_code']) ?></span></td>
                                <td><span style="font-weight: 600; font-size: 0.85rem; color: var(--text-muted); white-space: nowrap;">Unit <?= e($cleanUnit) ?></span></td>
                                <td>
                                    <strong style="font-size: 0.95rem; display: block; margin-bottom: 0.2rem; color: var(--text-main); font-family: var(--font-heading);"><?= e($a['title']) ?></strong>
                                    <?php if (!empty($a['target_type']) && $a['target_type'] === 'class' && !empty($a['class_name'])): ?>
                                        <span class="sys-tag info" style="font-size: 0.65rem; margin:0;">Class: <?= e($a['class_name']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 0.85rem; color: var(--text-muted); font-weight: 500; white-space: nowrap;"><?= fmtDate($a['due_date']) ?></td>
                                <td style="font-size: 0.825rem; font-weight: 600; white-space: nowrap;">
                                    <?php 
                                    if ($hasSub && !empty($a['submission_date'])) {
                                        $subDt = new DateTime($a['submission_date']);
                                        echo $subDt->format('d M Y') . ' &middot; <span style="color: var(--text-muted); font-size: 0.775rem;">' . $subDt->format('h:i A') . '</span>';
                                    } else {
                                        echo '<span style="color: var(--text-muted);">—</span>';
                                    }
                                    ?>
                                </td>
                                <td style="font-size: 0.85rem;">
                                    <?php 
                                    if ($hasSub) {
                                        $ext = strtolower(pathinfo($a['original_filename'], PATHINFO_EXTENSION));
                                        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                                            echo '<span class="sys-tag" style="background: #f1f5f9; color: var(--accent-primary); border-color: #cbd5e1; font-weight: 600; margin:0; white-space: nowrap;" title="'.e($a['original_filename']).'"><i class="fa-regular fa-file-image"></i> Image (.' . $ext . ')</span>';
                                        } elseif ($ext === 'pdf') {
                                            echo '<span class="sys-tag" style="background: #fee2e2; color: #dc2626; border-color: #fecaca; font-weight: 600; margin:0; white-space: nowrap;" title="'.e($a['original_filename']).'"><i class="fa-regular fa-file-pdf"></i> PDF Document</span>';
                                        } else {
                                            echo '<span class="sys-tag" style="background: #f1f5f9; color: #475569; border-color: #cbd5e1; font-weight: 600; margin:0; white-space: nowrap;" title="'.e($a['original_filename']).'"><i class="fa-regular fa-file-lines"></i> ' . strtoupper($ext) . ' File</span>';
                                        }
                                    } else {
                                        echo '<span style="color: var(--text-muted);">Not uploaded</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($hasSub && $a['marks'] !== null): ?>
                                        <strong style="font-size: 1.05rem; color: var(--success); font-family: var(--font-heading);"><?= e($a['marks']) ?></strong> <span style="font-size: 0.75rem; color: var(--text-muted);">/ <?= e($a['max_marks']) ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="sys-tag <?= badgeClass($status) ?>" style="margin:0; font-weight: 600;"><?= e($status) ?></span></td>
                                <td style="text-align: center;">
                                    <div style="display: flex; gap: 0.4rem; justify-content: center;">
                                        <?php if (!$hasSub): ?>
                                            <button class="btn btn-primary" style="padding: 0.35rem 0.85rem; font-size: 0.8rem;" onclick='openUploadModal(<?= (int)$a['id'] ?>, <?= jsAttr($a['subject_code']) ?>, <?= jsAttr("Unit " . $cleanUnit) ?>, <?= jsAttr($a['title']) ?>, <?= jsAttr(fmtDate($a['due_date'])) ?>)'>
                                                <i class="fa-solid fa-cloud-arrow-up"></i> Upload
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-outline" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;" title="View File" onclick='openPreview(<?= (int)$a['submission_id'] ?>, <?= jsAttr($a['file_type']) ?>, <?= jsAttr($a['original_filename']) ?>)'><i class="fa-regular fa-eye"></i></button>
                                            <a class="btn btn-outline" style="padding: 0.35rem 0.65rem; font-size: 0.8rem; margin: 0;" title="Download File" href="student_submit.php?action=download&id=<?= (int)$a['submission_id'] ?>"><i class="fa-solid fa-download"></i></a>
                                            <button class="btn btn-outline" style="padding: 0.35rem 0.65rem; font-size: 0.8rem; color: var(--warning); border-color: var(--warning-border);" title="Replace File" onclick='openUploadModal(<?= (int)$a['id'] ?>, <?= jsAttr($a['subject_code']) ?>, <?= jsAttr("Unit " . $cleanUnit) ?>, <?= jsAttr($a['title']) ?>, <?= jsAttr(fmtDate($a['due_date'])) ?>)'><i class="fa-solid fa-rotate"></i> Swap</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; border-top: 1px solid var(--border-color); padding-top: 1.25rem; margin-top: 1rem;">
                    <div style="font-size: 0.875rem; font-weight: 600; color: var(--text-muted);" id="resultsSummary">Showing activities</div>
                    <nav aria-label="Page navigation">
                        <ul class="pagination" id="activitiesPagination"></ul>
                    </nav>
                </div>
            </div>

        </main>
    </div>
</div>

<!-- Upload Activity Modal -->
<div class="modal-overlay" id="uploadModalWrapper">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fa-solid fa-cloud-arrow-up" style="color: var(--accent-primary); margin-right: 0.5rem;"></i> Upload Activity File</h3>
            <button class="close-btn" id="closeUploadModal">&times;</button>
        </div>
        <form id="uploadForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="activity_id" id="mActivityId">
            
            <div id="uploadAlert" class="alert d-none" style="margin-bottom: 1.5rem;"></div>

            <div class="info-grid" style="grid-template-columns: 1fr 1fr; margin-bottom: 1.5rem;">
                <div class="info-block">
                    <span class="info-label">Student PRN</span>
                    <span class="info-value" style="font-size: 0.95rem;"><?= e($studentPrn ?: $studentCode) ?></span>
                </div>
                <div class="info-block">
                    <span class="info-label">Subject & Unit</span>
                    <span class="info-value" style="font-size: 0.95rem; color: var(--accent-primary);"><span id="mSubject"></span> &middot; <span id="mUnit"></span></span>
                </div>
                <div class="info-block" style="grid-column: span 2;">
                    <span class="info-label">Activity Title</span>
                    <span class="info-value" style="font-size: 0.95rem;" id="mTitle"></span>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label">Select or Drag File to Upload</label>
                <div class="dropzone-box" id="dropzoneBox" onclick="document.getElementById('fileInput').click()">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                    <div style="font-weight: 700; color: var(--navy-primary); margin-bottom: 0.25rem;">Click to browse or drag file here</div>
                    <div style="font-size: 0.8rem; color: var(--text-muted);" id="fileHelp">Allowed formats: PDF, JPG, PNG (Max size: 5 MB)</div>
                    <input type="file" id="fileInput" name="activity_file" accept=".pdf,.jpg,.jpeg,.png" style="display: none;">
                </div>
            </div>
            
            <!-- Custom Progress Bar -->
            <div id="progressWrap" class="d-none" style="width: 100%; height: 8px; border-radius: 4px; background: var(--border-color); margin-bottom: 1.5rem; overflow: hidden;">
                <div id="progressBar" style="width: 0%; height: 100%; background: var(--accent-gradient); transition: width 0.3s;"></div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem;">
                <button type="button" class="btn btn-outline" id="cancelUploadModal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="uploadBtn">
                    <i class="fa-solid fa-cloud-arrow-up"></i> Upload File
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal-overlay" id="previewModalWrapper">
    <div class="modal-content" style="max-width: 820px;">
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

    // 1. Table Search, Filter & Pagination Logic
    const tbody = document.getElementById('activitiesTableBody');
    const allRows = Array.from(tbody.querySelectorAll('tr[data-subject]'));
    const pager = document.getElementById('activitiesPagination');
    const summary = document.getElementById('resultsSummary');
    const searchInput = document.getElementById('searchInput');
    const subjectFilter = document.getElementById('subjectFilter');
    const unitFilter = document.getElementById('unitFilter');
    const statusFilter = document.getElementById('statusFilter');
    const perPage = 8;
    let page = 1;

    function filteredRows() {
        const q = searchInput.value.toLowerCase();
        const subj = subjectFilter.value;
        const unit = unitFilter.value;
        const status = statusFilter.value;
        return allRows.filter(r =>
            (!q || r.dataset.title.includes(q)) &&
            (!subj || r.dataset.subject === subj) &&
            (!unit || r.dataset.unit === unit) &&
            (!status || r.dataset.status === status)
        );
    }

    function renderTable() {
        if (!allRows.length) return;
        const rows = filteredRows();
        const totalPages = Math.max(1, Math.ceil(rows.length / perPage));
        page = Math.min(page, totalPages);

        allRows.forEach(r => r.style.display = 'none');
        const slice = rows.slice((page - 1) * perPage, page * perPage);
        slice.forEach(r => r.style.display = '');

        let emptyRow = tbody.querySelector('tr.js-empty');
        if (!rows.length) {
            if (!emptyRow) {
                emptyRow = document.createElement('tr');
                emptyRow.className = 'js-empty';
                emptyRow.innerHTML = `<td colspan="10" style="text-align: center; padding: 3.5rem; color: var(--text-muted); font-weight: 500;">No activities match your filters.</td>`;
                tbody.appendChild(emptyRow);
            }
            emptyRow.style.display = '';
        } else if (emptyRow) {
            emptyRow.style.display = 'none';
        }

        pager.innerHTML = '';
        if (totalPages > 1) {
            let html = `<li class="page-item ${page===1?'disabled':''}"><a class="page-link" href="#" data-p="${page-1}">Prev</a></li>`;
            for (let p = 1; p <= totalPages; p++) html += `<li class="page-item ${p===page?'active':''}"><a class="page-link" href="#" data-p="${p}">${p}</a></li>`;
            html += `<li class="page-item ${page===totalPages?'disabled':''}"><a class="page-link" href="#" data-p="${page+1}">Next</a></li>`;
            pager.innerHTML = html;
        }
        const start = rows.length ? (page - 1) * perPage + 1 : 0;
        const end = Math.min(page * perPage, rows.length);
        summary.textContent = `Showing ${start}-${end} of ${rows.length} activities`;
    }

    pager.addEventListener('click', e => {
        e.preventDefault();
        const t = e.target.closest('[data-p]');
        if (!t || t.parentElement.classList.contains('disabled')) return;
        page = parseInt(t.dataset.p, 10);
        renderTable();
    });

    searchInput.addEventListener('input', () => { page = 1; renderTable(); });
    ['subjectFilter', 'unitFilter', 'statusFilter'].forEach(id =>
        document.getElementById(id).addEventListener('change', () => { page = 1; renderTable(); })
    );
    renderTable();

    // 2. Upload Modal & Drag-and-Drop Logic
    const uploadModalWrapper = document.getElementById('uploadModalWrapper');
    const uploadForm = document.getElementById('uploadForm');
    const uploadAlert = document.getElementById('uploadAlert');
    const fileHelp = document.getElementById('fileHelp');
    const dropzoneBox = document.getElementById('dropzoneBox');
    const progressWrap = document.getElementById('progressWrap');
    const progressBar = document.getElementById('progressBar');
    const uploadBtn = document.getElementById('uploadBtn');
    const fileInput = document.getElementById('fileInput');

    function closeUploadModalFunc() { uploadModalWrapper.style.display = 'none'; }
    document.getElementById('closeUploadModal').addEventListener('click', (e) => { e.preventDefault(); closeUploadModalFunc(); });
    document.getElementById('cancelUploadModal').addEventListener('click', (e) => { e.preventDefault(); closeUploadModalFunc(); });
    uploadModalWrapper.addEventListener('click', (e) => { if(e.target === uploadModalWrapper) closeUploadModalFunc(); });

    window.openUploadModal = function(activityId, subject, unit, title, dueDate) {
        uploadForm.reset();
        document.getElementById('mActivityId').value = activityId;
        document.getElementById('mSubject').textContent = subject;
        document.getElementById('mUnit').textContent = unit;
        document.getElementById('mTitle').textContent = title;
        fileHelp.innerHTML = 'Allowed formats: PDF, JPG, PNG (Max size: 5 MB)';
        uploadAlert.className = 'alert d-none';
        progressWrap.classList.add('d-none');
        progressBar.style.width = '0%';
        uploadModalWrapper.style.display = 'flex';
    };

    ['dragenter', 'dragover'].forEach(eventName => {
        dropzoneBox.addEventListener(eventName, (e) => { e.preventDefault(); e.stopPropagation(); dropzoneBox.classList.add('dragover'); });
    });
    ['dragleave', 'drop'].forEach(eventName => {
        dropzoneBox.addEventListener(eventName, (e) => { e.preventDefault(); e.stopPropagation(); dropzoneBox.classList.remove('dragover'); });
    });
    dropzoneBox.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (files.length) {
            fileInput.files = files;
            validateAndShowFile(files[0]);
        }
    });

    fileInput.addEventListener('change', function () {
        if (this.files.length) validateAndShowFile(this.files[0]);
    });

    function validateAndShowFile(f) {
        if (!f) { fileHelp.innerHTML = 'Allowed formats: PDF, JPG, PNG (Max size: 5 MB)'; return; }
        const ext = f.name.split('.').pop().toLowerCase();
        if (!['pdf','jpg','jpeg','png'].includes(ext)) {
            fileHelp.innerHTML = '<span style="color: var(--danger); font-weight:700;">Only PDF, JPG, PNG files are allowed.</span>';
            fileInput.value = '';
            return;
        }
        if (f.size > 5*1024*1024) {
            fileHelp.innerHTML = '<span style="color: var(--danger); font-weight:700;">File exceeds the 5 MB size limit.</span>';
            fileInput.value = '';
            return;
        }
        fileHelp.innerHTML = `<span style="color: var(--success); font-weight:700;"><i class="fa-solid fa-circle-check"></i> Selected: ${f.name} (${(f.size/1024).toFixed(0)} KB)</span>`;
    }

    uploadForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!fileInput.files.length) { showAlert('Please choose a file to upload.', 'danger'); return; }

        uploadBtn.disabled = true; uploadBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Uploading...';
        progressWrap.classList.remove('d-none'); progressBar.style.width = '15%';

        const formData = new FormData(this);

        fetch('student_submit.php?action=upload', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                progressBar.style.width = '100%';
                if (data.success) {
                    showAlert(data.message, 'success');
                    uploadBtn.disabled = false; uploadBtn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Upload File';
                    setTimeout(() => { closeUploadModalFunc(); location.reload(); }, 1000);
                } else {
                    showAlert(data.message || 'Upload failed.', 'danger');
                    uploadBtn.disabled = false; uploadBtn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Upload File';
                }
            })
            .catch(() => {
                showAlert('A network error occurred. Please try again.', 'danger');
                uploadBtn.disabled = false; uploadBtn.innerHTML = '<i class="fa-solid fa-cloud-arrow-up"></i> Upload File';
            });
    });

    function showAlert(msg, type) {
        uploadAlert.className = `alert alert-${type}`;
        const icon = type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation';
        uploadAlert.innerHTML = `<i class="fa-solid ${icon}"></i> ` + msg;
        uploadAlert.classList.remove('d-none');
    }

    // 3. Custom Modal Preview Logic
    const previewModalWrapper = document.getElementById('previewModalWrapper');
    
    function closePreviewModalFunc() {
        previewModalWrapper.style.display = 'none';
        document.getElementById('previewFrame').src = '';
        document.getElementById('previewImg').src = '';
    }

    document.getElementById('closePreviewModal').addEventListener('click', closePreviewModalFunc);
    document.getElementById('cancelPreviewModal').addEventListener('click', closePreviewModalFunc);
    previewModalWrapper.addEventListener('click', (e) => {
        if(e.target === previewModalWrapper) closePreviewModalFunc();
    });

    window.openPreview = function(submissionId, fileType, fileName) {
        const frame = document.getElementById('previewFrame');
        const img = document.getElementById('previewImg');
        const unsupported = document.getElementById('previewUnsupported');
        const title = document.getElementById('previewTitle');
        const dlBtn = document.getElementById('previewDownloadBtn');

        title.innerHTML = fileName;
        dlBtn.href = `student_submit.php?action=download&id=${submissionId}`;

        frame.classList.add('d-none');
        img.classList.add('d-none');
        unsupported.classList.add('d-none');
        frame.src = '';
        img.src = '';

        const previewUrl = `student_submit.php?action=preview&id=${submissionId}`;
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
    };


    // Notification System Interactive Manager
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
$modalPath = __DIR__ . '/includes/end_session_modal.php';
if (file_exists($modalPath)) {
    include_once $modalPath;
}
?>
</body>
</html>