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

// Ensure audit_logs table exists
$conn->query("CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'System',
    action VARCHAR(255) NOT NULL,
    details TEXT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Helper function to format timestamps into friendly relative times
function formatRelativeTime($datetimeStr) {
    if (empty($datetimeStr)) return '—';

    $time = strtotime($datetimeStr);
    $now = time();
    $todayStart = strtotime('today');
    $yesterdayStart = strtotime('yesterday');

    if ($time >= $todayStart) {
        return 'Today, ' . date('H:i', $time);
    } elseif ($time >= $yesterdayStart) {
        return 'Yesterday, ' . date('H:i', $time);
    } else {
        $diffDays = floor(($now - $time) / (60 * 60 * 24));
        if ($diffDays <= 7) {
            return $diffDays . ' days ago, ' . date('H:i', $time);
        }
        return date('M d, Y - H:i', $time);
    }
}

// Role filter
$filterRole = isset($_GET['role']) ? trim($_GET['role']) : 'All';

// Build Query
if ($filterRole !== 'All' && !empty($filterRole)) {
    $stmt = $conn->prepare("SELECT a.id, a.user_id, a.role, a.action, a.details, a.timestamp, COALESCE(NULLIF(u.name, ''), u.username) as user_name, u.email as user_email 
                            FROM audit_logs a 
                            LEFT JOIN users u ON a.user_id = u.user_id 
                            WHERE LOWER(a.role) = LOWER(?) 
                            ORDER BY a.timestamp DESC");
    $stmt->bind_param("s", $filterRole);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("SELECT a.id, a.user_id, a.role, a.action, a.details, a.timestamp, COALESCE(NULLIF(u.name, ''), u.username) as user_name, u.email as user_email 
                            FROM audit_logs a 
                            LEFT JOIN users u ON a.user_id = u.user_id 
                            ORDER BY a.timestamp DESC");
}

$active_page = 'audit';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Audit Logs | SAAES Admin</title>
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* Premium Hero Banner (Matching the screenshot theme) */
        .premium-hero {
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.92), rgba(30, 41, 59, 0.85)), url('../assets/images/college_building.jpg');
            background-size: cover;
            background-position: center;
            border-radius: var(--radius-lg);
            padding: 2.25rem 2.5rem;
            color: #ffffff;
            margin-bottom: 2rem;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.05);
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
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.65rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }
        .hero-desc {
            font-size: 0.9rem;
            color: #cbd5e1;
            max-width: 650px;
        }

        /* Filter bar */
        .filter-bar {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .filter-label {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filter-select {
            padding: 8px 14px;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            background: var(--bg-body);
            color: var(--text-dark);
            font-family: var(--font-body);
            font-size: 0.875rem;
            outline: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .filter-select:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.15);
        }

        /* Module card design */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: var(--shadow-sm);
        }

        /* Table styles */
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
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .data-table tbody tr:last-child td {
            border-bottom: none;
        }
        .data-table tbody tr:hover {
            background: rgba(15, 23, 42, 0.01);
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
        .badge.danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .badge.info { background: #eff6ff; color: #1e40af; border: 1px solid #dbeafe; }
        .badge.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .badge.warning { background: #fffbeb; color: #b45309; border: 1px solid #fef3c7; }
        .badge.purple { background: #f5f3ff; color: #6d28d9; border: 1px solid #ddd6fe; }
        .badge.dark { background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; }

        .btn {
            font-family: var(--font-head);
            font-weight: 600;
            font-size: 0.8rem;
            padding: 6px 12px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-light);
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            background: var(--bg-card);
            color: var(--text-dark);
        }
        .btn:hover {
            background: var(--bg-body);
            border-color: var(--text-muted);
        }
    </style>
</head>
<body>

<div class="admin-layout">
    <?php 
    $active_page = 'audit'; 
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
            <h1 class="hero-title">System Audit Logs</h1>
            <p class="hero-desc">Track activity logs, evaluation events, and administrative security actions.</p>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" action="admin_audit.php" style="display: flex; align-items: center; gap: 12px;">
                <span class="filter-label"><i class="fa-solid fa-filter"></i> Filter by Role:</span>
                <select name="role" id="role" class="filter-select" onchange="this.form.submit()">
                    <option value="All" <?php echo ($filterRole === 'All') ? 'selected' : ''; ?>>All Roles</option>
                    <option value="Admin" <?php echo ($filterRole === 'Admin') ? 'selected' : ''; ?>>Admin</option>
                    <option value="Faculty" <?php echo ($filterRole === 'Faculty') ? 'selected' : ''; ?>>Faculty</option>
                    <option value="HOD" <?php echo ($filterRole === 'HOD') ? 'selected' : ''; ?>>HOD</option>
                    <option value="GFM" <?php echo ($filterRole === 'GFM') ? 'selected' : ''; ?>>GFM</option>
                    <option value="Student" <?php echo ($filterRole === 'Student') ? 'selected' : ''; ?>>Student</option>
                    <option value="Parent" <?php echo ($filterRole === 'Parent') ? 'selected' : ''; ?>>Parent</option>
                    <option value="System" <?php echo ($filterRole === 'System') ? 'selected' : ''; ?>>System</option>
                </select>
            </form>
            <?php if ($filterRole !== 'All'): ?>
                <a href="admin_audit.php" class="btn"><i class="fa-solid fa-filter-circle-xmark"></i> Clear Filter</a>
            <?php endif; ?>
        </div>

        <!-- Audit Table Card -->
        <div class="card">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Log ID</th>
                            <th>User / Performer</th>
                            <th>Role</th>
                            <th>Action Performed</th>
                            <th>Details</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($log = $result->fetch_assoc()): ?>
                                <tr>
                                    <td style="font-weight: 700; color: var(--text-muted);">#LOG-<?php echo sprintf('%04d', $log['id']); ?></td>
                                    <td>
                                        <?php if (!empty($log['user_name'])): ?>
                                            <strong><?php echo htmlspecialchars($log['user_name']); ?></strong>
                                            <?php if (!empty($log['user_email'])): ?>
                                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($log['user_email']); ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-style: italic;">Automated System Process</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $roleClass = 'dark';
                                        $rLower = strtolower($log['role']);
                                        if ($rLower === 'admin') $roleClass = 'danger';
                                        elseif ($rLower === 'faculty') $roleClass = 'info';
                                        elseif ($rLower === 'hod') $roleClass = 'dark';
                                        elseif ($rLower === 'gfm') $roleClass = 'warning';
                                        elseif ($rLower === 'student') $roleClass = 'success';
                                        elseif ($rLower === 'parent') $roleClass = 'purple';
                                        ?>
                                        <span class="badge <?php echo $roleClass; ?>"><?php echo htmlspecialchars(ucfirst($log['role'])); ?></span>
                                    </td>
                                    <td style="font-weight: 700; color: var(--text-dark);"><?php echo htmlspecialchars($log['action']); ?></td>
                                    <td style="color: var(--text-muted); font-size: 0.85rem;">
                                        <?php echo htmlspecialchars($log['details'] ?: '—'); ?>
                                    </td>
                                    <td style="white-space: nowrap; font-weight: 600; color: var(--text-dark);">
                                        <?php echo formatRelativeTime($log['timestamp']); ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                                    <i class="fa-regular fa-folder-open" style="font-size: 2rem; display: block; margin-bottom: 10px; opacity: 0.5;"></i>
                                    No audit log records found for the selected filter.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
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
