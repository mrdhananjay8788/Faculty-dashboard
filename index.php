<?php
/**
 * index.php
 * SAAES — Landing Page (Entry point)
 * Traditional Academic Theme (Zeal College UI)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Connect database safely with fallback
$pdo = null;
try {
    $pdo = require __DIR__ . '/config/db.php';
} catch (Exception $e) {
    $pdo = null;
}

// Check logged in user state
$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$userRole   = $_SESSION['role'] ?? '';

$dashUrl = 'auth/login.php';
if ($isLoggedIn) {
    switch (strtolower($userRole)) {
        case 'admin':   $dashUrl = 'auth/admin_users.php'; break;
        case 'faculty': $dashUrl = 'faculty_dashboard.php'; break;
        case 'hod':     $dashUrl = 'hod_dashboard.php'; break;
        case 'gfm':     $dashUrl = 'gfm_dashboard.php'; break;
        case 'student': $dashUrl = 'student_dashboard.php'; break;
        case 'parent':  $dashUrl = 'parent_dashboard.php'; break;
        default:        $dashUrl = 'auth/login.php'; break;
    }
}

// Fetch dynamic system metrics for statistics section
$statsData = ['users' => 0, 'activities' => 0, 'submissions' => 0, 'units' => 6];
if ($pdo) {
    try {
        $statsData['users'] = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $statsData['activities'] = (int)$pdo->query("SELECT COUNT(*) FROM activities")->fetchColumn();
        $statsData['submissions'] = (int)$pdo->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
    } catch (Exception $e) {
        $statsData['users'] = 120; $statsData['activities'] = 24; $statsData['submissions'] = 310;
    }
}

// Fetch dynamic ticker announcements from database
$tickerNotices = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT title, unit, subject, due_date FROM activities ORDER BY created_at DESC LIMIT 5");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $subj = !empty($row['subject']) ? htmlspecialchars($row['subject']) . " - " : "";
            $unit = !empty($row['unit']) ? "Unit " . htmlspecialchars($row['unit']) . " - " : "";
            $due  = !empty($row['due_date']) ? " (Due: " . date("d M Y", strtotime($row['due_date'])) . ")" : "";
            $tickerNotices[] = $subj . $unit . htmlspecialchars($row['title']) . $due;
        }
    } catch (Exception $ex) {}
}

if (empty($tickerNotices)) {
    $tickerNotices = [
        "Unit 2 Activity last date 20 May 2025.",
        "Final Activity Marksheet will be available after completion of all 6 units.",
        "Unit 3 Activity for Data Structures is now live.",
        "Please ensure all submissions are uploaded in PDF format."
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CIE 2 | Zeal College of Engineering</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        :root {
            --primary-blue: #0284c7;
            --primary-hover: #0369a1;
            --navy-dark: #0f172a;
            --navy-accent: #1e3a8a;
            --navy-footer: #090d16;
            --text-dark: #0f172a;
            --text-muted: #475569;
            --bg-body: #f8fafc;
            --bg-white: #ffffff;
            --border-light: #e2e8f0;
            --border-hover: #cbd5e1;
            
            --font-head: 'Plus Jakarta Sans', sans-serif;
            --font-body: 'Inter', sans-serif;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--font-body);
            background-color: var(--bg-body);
            color: var(--text-dark);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        a { text-decoration: none; color: inherit; }
        ul { list-style: none; }

        /* ================= NAVBAR ================= */
        .navbar {
            background-color: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            height: 80px;
            padding: 0 4%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
            position: sticky;
            top: 0;
            z-index: 1000;
            border-bottom: 1px solid rgba(15, 23, 42, 0.05);
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .logo-img {
            width: 54px;
            height: 54px;
            object-fit: contain;
        }

        .logo-text {
            display: flex;
            flex-direction: column;
        }

        .logo-top { font-size: 0.7rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
        .logo-main { font-family: var(--font-head); font-size: 1.15rem; font-weight: 800; color: var(--navy-dark); line-height: 1.2;}
        .logo-sub { font-size: 0.8rem; color: var(--text-muted); font-weight: 500; }

        .nav-links {
            display: flex;
            gap: 2.5rem;
            font-family: var(--font-head);
            font-weight: 600;
            font-size: 0.95rem;
        }

        .nav-links a {
            color: var(--text-dark);
            transition: all 0.3s ease;
            padding: 6px 0;
            border-bottom: 2px solid transparent;
            opacity: 0.85;
        }
        
        .nav-links a:hover, .nav-links a.active {
            color: var(--primary-blue);
            border-bottom-color: var(--primary-blue);
            opacity: 1;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .datetime-display {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: var(--navy-dark);
            font-weight: 600;
            font-family: var(--font-head);
            background: rgba(15, 23, 42, 0.03);
            padding: 6px 12px;
            border-radius: 8px;
        }
        .datetime-display i { color: var(--primary-blue); font-size: 1.1rem; }
        .dt-text { display: flex; flex-direction: column; line-height: 1.2;}

        .btn {
            font-family: var(--font-head);
            font-weight: 600;
            font-size: 0.875rem;
            padding: 0.625rem 1.6rem;
            border-radius: 50px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }
        .btn:active {
            transform: translateY(0);
        }

        .btn-outline {
            border: 1.5px solid rgba(2, 132, 199, 0.4);
            color: var(--primary-blue);
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }
        .btn-outline::before {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.1) 0%, rgba(2, 132, 199, 0.1) 100%);
            z-index: -1;
            opacity: 0;
            transition: opacity 0.4s ease;
        }
        .btn-outline:hover {
            border-color: var(--primary-blue);
            color: var(--primary-hover);
            box-shadow: 0 4px 15px rgba(2, 132, 199, 0.12);
            transform: translateY(-2px);
        }
        .btn-outline:hover::before {
            opacity: 1;
        }
        .btn-outline i {
            transition: transform 0.3s ease;
        }
        .btn-outline:hover i {
            transform: scale(1.15) rotate(5deg);
        }

        .btn-primary {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #fff;
            border: 1.5px solid transparent;
            box-shadow: 0 4px 15px rgba(2, 132, 199, 0.25);
        }
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(
                90deg,
                transparent,
                rgba(255, 255, 255, 0.25),
                transparent
            );
            transition: all 0.6s ease;
            z-index: -1;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(2, 132, 199, 0.35);
        }
        .btn-primary:hover::before {
            left: 100%;
        }
        .btn-primary i {
            transition: transform 0.3s ease;
        }
        .btn-primary:hover i {
            transform: translateX(3px);
        }

        /* ================= HERO SECTION ================= */
        .hero {
            position: relative;
            height: 52vh;
            min-height: 420px;
            background-image: url('assets/images/college_building.jpg'); 
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 0 5%;
        }

        .hero-overlay {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(135deg, rgba(15, 32, 67, 0.85) 0%, rgba(15, 32, 67, 0.7) 50%, rgba(15, 32, 67, 0.35) 100%);
            z-index: 1;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            max-width: 850px;
            color: #fff;
            margin: 0 auto;
        }

        .hero-dept {
            font-size: 0.95rem;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.85);
            text-transform: uppercase;
            letter-spacing: 0.12em;
            margin-bottom: 1.25rem;
            font-family: var(--font-head);
            text-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }

        .hero-title {
            font-family: var(--font-head);
            font-size: 2.85rem;
            font-weight: 800;
            line-height: 1.25;
            margin-bottom: 1.25rem;
            text-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        .hero-title span { color: #38bdf8; }

        .hero-subtitle {
            font-size: 1.05rem;
            line-height: 1.6;
            color: #cbd5e1;
            max-width: 680px;
            margin: 0 auto;
            text-shadow: 0 1px 5px rgba(0,0,0,0.2);
        }

        /* ================= TICKER BOARD ================= */
        .notice-board {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 40px;
            background: rgba(8, 14, 27, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            z-index: 10;
            display: flex;
            align-items: center;
            border-top: 2px solid #38bdf8;
            box-shadow: 0 -4px 25px rgba(56, 189, 248, 0.25);
        }

        .notice-label {
            background: linear-gradient(135deg, #38bdf8 0%, #0284c7 100%);
            color: #0f172a;
            font-family: var(--font-head);
            font-weight: 800;
            font-size: 0.85rem;
            padding: 0 2.5rem 0 1.5rem;
            height: 100%;
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
            z-index: 2;
            white-space: nowrap;
            clip-path: polygon(0 0, calc(100% - 15px) 0, 100% 100%, 0 100%);
        }

        .notice-scroll {
            flex: 1;
            overflow: hidden;
            white-space: nowrap;
            color: #f1f5f9;
            font-weight: 500;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
        }

        .ticker-text {
            display: inline-block;
            animation: scroll-left 35s linear infinite;
        }

        .ticker-item { margin-right: 3rem; }
        .ticker-dot { color: #38bdf8; text-shadow: 0 0 6px rgba(56, 189, 248, 0.8); margin-right: 0.5rem; font-size: 0.5rem; vertical-align: middle; }

        @keyframes scroll-left {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        /* ================= SECTION STYLES ================= */
        .section-wrapper {
            padding: 3.5rem 5%;
            background: var(--bg-white);
        }
        .section-wrapper.alt-bg { background: var(--bg-body); }

        .section-title {
            font-family: var(--font-head);
            font-size: 1.85rem;
            font-weight: 800;
            color: var(--navy-dark);
            text-align: center;
            margin-bottom: 2.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }
        .section-title::before, .section-title::after {
            content: '';
            display: block;
            width: 30px;
            height: 3px;
            background: var(--primary-blue);
            border-radius: 2px;
        }

        .grid-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.75rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* ================= CARDS ================= */
        .feature-card {
            background: var(--bg-white);
            border-radius: 20px;
            padding: 2.25rem 1.75rem;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.04);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            flex-direction: column;
            border: 1px solid rgba(15, 23, 42, 0.04);
            position: relative;
            overflow: hidden;
        }
        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.09);
        }
        
        .feature-card.feat-1 {
            border-top: 4px solid #3b82f6;
            background: linear-gradient(180deg, #ffffff 0%, #f4f7ff 100%);
        }
        .feature-card.feat-2 {
            border-top: 4px solid #10b981;
            background: linear-gradient(180deg, #ffffff 0%, #f3fcf7 100%);
        }
        .feature-card.feat-3 {
            border-top: 4px solid #8b5cf6;
            background: linear-gradient(180deg, #ffffff 0%, #f7f3ff 100%);
        }
        .feature-card.feat-4 {
            border-top: 4px solid #f59e0b;
            background: linear-gradient(180deg, #ffffff 0%, #fffbf2 100%);
        }
        .feature-card.feat-5 {
            border-top: 4px solid #0ea5e9;
            background: linear-gradient(180deg, #ffffff 0%, #f0f9ff 100%);
        }
        
        .feature-card:hover.feat-1 { border-color: #2563eb; }
        .feature-card:hover.feat-2 { border-color: #059669; }
        .feature-card:hover.feat-3 { border-color: #7c3aed; }
        .feature-card:hover.feat-4 { border-color: #d97706; }
        .feature-card:hover.feat-5 { border-color: #0284c7; }

        .f-icon-wrap {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 1.25rem;
        }
        
        .fi-1 { background: #EEF2FF; color: #3B82F6; }
        .fi-2 { background: #ECFDF5; color: #10B981; }
        .fi-3 { background: #F5F3FF; color: #8B5CF6; }
        .fi-4 { background: #FFFBEB; color: #F59E0B; }
        .fi-5 { background: #EFF6FF; color: #0EA5E9; }

        .f-title {
            font-family: var(--font-head);
            font-weight: 700;
            font-size: 1.05rem;
            color: var(--navy-dark);
            margin-bottom: 0.6rem;
        }
        .f-desc {
            font-size: 0.85rem;
            color: var(--text-muted);
            line-height: 1.5;
        }

        /* Role Cards & Layout on One Line */
        .roles-grid {
            grid-template-columns: repeat(6, 1fr);
            gap: 1.25rem;
        }
        .roles-grid .feature-card {
            padding: 1.75rem 1.25rem;
            align-items: center;
            text-align: center;
            border-radius: 20px;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.04);
            border: 1px solid rgba(15, 23, 42, 0.04);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .roles-grid .feature-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.09);
        }
        
        /* Individual Role Card Borders and Gradients */
        .roles-grid .feature-card.r-card-1 {
            border-top: 4px solid #0284c7;
            background: linear-gradient(180deg, #ffffff 0%, #f4faff 100%);
        }
        .roles-grid .feature-card.r-card-2 {
            border-top: 4px solid #10b981;
            background: linear-gradient(180deg, #ffffff 0%, #f3fcf7 100%);
        }
        .roles-grid .feature-card.r-card-3 {
            border-top: 4px solid #8b5cf6;
            background: linear-gradient(180deg, #ffffff 0%, #f7f3ff 100%);
        }
        .roles-grid .feature-card.r-card-4 {
            border-top: 4px solid #f59e0b;
            background: linear-gradient(180deg, #ffffff 0%, #fffbf2 100%);
        }
        .roles-grid .feature-card.r-card-5 {
            border-top: 4px solid #0ea5e9;
            background: linear-gradient(180deg, #ffffff 0%, #f0f9ff 100%);
        }
        .roles-grid .feature-card.r-card-6 {
            border-top: 4px solid #ef4444;
            background: linear-gradient(180deg, #ffffff 0%, #fff5f5 100%);
        }

        .roles-grid .f-title {
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }
        .roles-grid .f-desc {
            font-size: 0.8rem;
            line-height: 1.4;
        }

        .role-icon-wrap {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            color: #fff;
            margin-bottom: 1rem;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            position: relative;
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        .roles-grid .feature-card:hover .role-icon-wrap {
            transform: scale(1.1);
        }
        
        /* Pulse ring effect on hover */
        .roles-grid .role-icon-wrap::after {
            content: '';
            position: absolute;
            top: -6px; left: -6px; right: -6px; bottom: -6px;
            border-radius: 50%;
            border: 2px solid currentColor;
            opacity: 0;
            transform: scale(0.9);
            transition: all 0.4s ease;
        }
        .roles-grid .feature-card:hover .role-icon-wrap::after {
            opacity: 0.35;
            transform: scale(1.15);
        }
        
        .ri-1 { background: #0284c7; } 
        .ri-2 { background: #10b981; } 
        .ri-3 { background: #8b5cf6; } 
        .ri-4 { background: #f59e0b; } 
        .ri-5 { background: #0ea5e9; } 
        .ri-6 { background: #ef4444; } 

        /* ================= STATS GRID ================= */
        .stats-grid { 
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 1.5rem; max-width: 1400px; margin: 0 auto; 
        }
        .stat-block { 
            padding: 2.25rem 1.75rem; 
            border: 1px solid rgba(15, 23, 42, 0.04); 
            border-radius: 20px; 
            background: var(--bg-white); 
            text-align: center; 
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.04);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .stat-block:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.09);
        }
        
        .stat-block.stat-1 {
            border-top: 4px solid #3b82f6;
            background: linear-gradient(180deg, #ffffff 0%, #f4f7ff 100%);
        }
        .stat-block.stat-2 {
            border-top: 4px solid #10b981;
            background: linear-gradient(180deg, #ffffff 0%, #f3fcf7 100%);
        }
        .stat-block.stat-3 {
            border-top: 4px solid #8b5cf6;
            background: linear-gradient(180deg, #ffffff 0%, #f7f3ff 100%);
        }
        .stat-block.stat-4 {
            border-top: 4px solid #f59e0b;
            background: linear-gradient(180deg, #ffffff 0%, #fffbf2 100%);
        }
        
        .stat-icon-wrap {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .stat-icon-wrap.s-1 { background: #eef2ff; color: #3b82f6; }
        .stat-icon-wrap.s-2 { background: #ecfdf5; color: #10b981; }
        .stat-icon-wrap.s-3 { background: #f5f3ff; color: #8b5cf6; }
        .stat-icon-wrap.s-4 { background: #fffbeb; color: #f59e0b; }
        
        .stat-val { font-family: var(--font-head); font-size: 2.75rem; font-weight: 800; line-height: 1; margin-bottom: 0.5rem; }
        
        .stat-block.stat-1 .stat-val { color: #3b82f6; }
        .stat-block.stat-2 .stat-val { color: #10b981; }
        .stat-block.stat-3 .stat-val { color: #8b5cf6; }
        .stat-block.stat-4 .stat-val { color: #f59e0b; }
        
        .stat-label { font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;}

        /* ================= FOOTER ================= */
        .main-footer {
            background: linear-gradient(180deg, rgba(9, 13, 22, 0.95) 0%, rgba(9, 13, 22, 1) 100%);
            color: #cbd5e1;
            padding: 2rem 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            flex-wrap: wrap;
            gap: 1rem;
            border-top: 1px solid rgba(124, 58, 237, 0.25);
            box-shadow: 0 -10px 45px rgba(124, 58, 237, 0.12);
            position: relative;
        }

        .footer-info {
            display: flex;
            gap: 2rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .f-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .f-item i {
            color: #38bdf8;
            font-size: 0.95rem;
            filter: drop-shadow(0 0 5px rgba(56, 189, 248, 0.7));
            animation: footer-glow-pulse 3s ease-in-out infinite alternate;
        }

        @keyframes footer-glow-pulse {
            0% { filter: drop-shadow(0 0 2px rgba(56, 189, 248, 0.4)); opacity: 0.85; }
            100% { filter: drop-shadow(0 0 8px rgba(56, 189, 248, 0.9)); opacity: 1; }
        }

        /* ANIMATIONS */
        .reveal-scroll { opacity: 0; transform: translateY(20px); transition: all 0.6s ease-out; }
        .reveal-scroll.in-view { opacity: 1; transform: translateY(0); }

        .reveal-card {
            opacity: 0;
            transform: translateY(24px);
            transition: opacity 0.6s cubic-bezier(0.16, 1, 0.3, 1), transform 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .reveal-card.in-view {
            opacity: 1;
            transform: translateY(0);
            transition-delay: calc(var(--card-index) * 0.1s);
        }

        @media (max-width: 1200px) {
            .roles-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 1rem;
            }
        }
        @media (max-width: 1024px) {
            .nav-links { display: none; }
            .hero-title { font-size: 2.35rem; }
        }
        @media (max-width: 768px) {
            .navbar { height: auto; padding: 15px 5%; flex-wrap: wrap; gap: 15px;}
            .datetime-display { display: none; }
            .hero { padding-left: 5%; padding-right: 5%; min-height: 380px; height: auto; padding-top: 3rem; padding-bottom: 5rem; }
            .hero-title { font-size: 2rem; }
            .notice-label { padding: 0 1.5rem 0 1rem; font-size: 0.8rem;}
            .main-footer { flex-direction: column; text-align: center; justify-content: center; }
            .footer-info { justify-content: center; }
            .roles-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        /* Ambient Academic Shapes for Hero Background */
        .hero-shapes {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            pointer-events: none;
            z-index: 1;
            animation: float-container 20s ease-in-out infinite alternate;
        }
        
        .hero-network {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }
        .hero-network line {
            stroke: rgba(255, 255, 255, 0.08);
            stroke-width: 1.2;
            stroke-dasharray: 4 5;
        }
                .shape {
            position: absolute;
            color: rgba(186, 230, 253, 0.75);
            font-size: 2.2rem;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2;
            filter: drop-shadow(0 0 10px rgba(56, 189, 248, 0.8));
            animation: float-shape 6s ease-in-out infinite;
        }
        
        .shape .glow {
            position: absolute;
            width: 60px;
            height: 60px;
            background: radial-gradient(circle, rgba(56, 189, 248, 0.4) 0%, rgba(56, 189, 248, 0) 70%);
            border-radius: 50%;
            z-index: -1;
            animation: pulse-glow 3s ease-in-out infinite alternate;
        }
        
        .shape-1 { top: 20%; left: 10%; animation-duration: 7s; animation-delay: 0s; }
        .shape-2 { top: 40%; left: 22%; font-size: 1.8rem; animation-duration: 9s; animation-delay: 1.5s; }
        .shape-3 { top: 65%; left: 8%; font-size: 2rem; animation-duration: 8s; animation-delay: 3s; }
        .shape-4 { top: 25%; right: 10%; font-size: 1.7rem; animation-duration: 10s; animation-delay: 4.5s; }
        .shape-5 { top: 45%; right: 24%; font-size: 1.9rem; animation-duration: 7.5s; animation-delay: 2s; }
        .shape-6 { top: 68%; right: 8%; font-size: 1.8rem; animation-duration: 8.5s; animation-delay: 0.5s; }
        
        .shape-1 .glow { animation-delay: 0s; }
        .shape-2 .glow { animation-delay: 0.6s; }
        .shape-3 .glow { animation-delay: 1.2s; }
        .shape-4 .glow { animation-delay: 1.8s; }
        .shape-5 .glow { animation-delay: 2.4s; }
        .shape-6 .glow { animation-delay: 3s; }

        /* Holographic globe backdrop behind text */
        .hologram-globe {
            position: absolute;
            top: 45%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 320px;
            height: 320px;
            background: radial-gradient(circle, rgba(56, 189, 248, 0.22) 0%, rgba(56, 189, 248, 0.05) 50%, rgba(56, 189, 248, 0) 80%);
            border: 2px dashed rgba(56, 189, 248, 0.45);
            border-radius: 50%;
            pointer-events: none;
            z-index: 1;
            animation: rotate-globe 25s linear infinite, globe-pulse 6s ease-in-out infinite alternate;
            box-shadow: 0 0 35px rgba(56, 189, 248, 0.25), inset 0 0 35px rgba(56, 189, 248, 0.15);
        }
        .hologram-globe::after {
            content: '';
            position: absolute;
            top: 20px; left: 20px; right: 20px; bottom: 20px;
            border: 1px dotted rgba(56, 189, 248, 0.2);
            border-radius: 50%;
            animation: rotate-globe-inner 12s linear infinite reverse;
        }

        /* Circuit gate at bottom center */
        .circuit-gate {
            position: absolute;
            bottom: 40px;
            left: 50%;
            transform: translateX(-50%);
            width: 340px;
            height: 180px;
            border: 2px solid rgba(56, 189, 248, 0.35);
            border-bottom: none;
            border-radius: 20px 20px 0 0;
            background: linear-gradient(180deg, rgba(56, 189, 248, 0.08) 0%, rgba(56, 189, 248, 0) 100%);
            box-shadow: 0 0 40px rgba(56, 189, 248, 0.25), inset 0 0 20px rgba(56, 189, 248, 0.15);
            pointer-events: none;
            z-index: 1;
            overflow: hidden;
        }
        .circuit-gate::before {
            content: '';
            position: absolute;
            top: 15%; left: 10%; right: 10%; bottom: 0;
            border: 1.5px dashed rgba(124, 58, 237, 0.4);
            border-bottom: none;
            border-radius: 12px 12px 0 0;
        }

        .gate-neon-pulse {
            position: absolute;
            bottom: 0;
            left: 10%;
            width: 80%;
            height: 100%;
            background: linear-gradient(0deg, rgba(56, 189, 248, 0) 0%, rgba(56, 189, 248, 0.4) 50%, rgba(124, 58, 237, 0.6) 100%);
            mix-blend-mode: screen;
            opacity: 0.7;
            animation: gate-flow 4s linear infinite;
        }

        @keyframes float-shape {
            0% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-12px) rotate(5deg); }
            100% { transform: translateY(0) rotate(0deg); }
        }

        @keyframes globe-pulse {
            0% { transform: translate(-50%, -50%) scale(0.95); opacity: 0.7; }
            100% { transform: translate(-50%, -50%) scale(1.05); opacity: 1; }
        }

        @keyframes rotate-globe-inner {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes gate-flow {
            0% { transform: translateY(100%); opacity: 0; }
            50% { opacity: 0.8; }
            100% { transform: translateY(-100%); opacity: 0; }
        }

        @keyframes rotate-globe {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }
        
        @keyframes float-container {
            0% { transform: translateY(0); }
            100% { transform: translateY(-20px); }
        }
        
        @keyframes rotate-ambient {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(12deg); }
        }
        
        @keyframes pulse-glow {
            0%, 100% {
                transform: scale(0.8);
                opacity: 0.4;
            }
            50% {
                transform: scale(1.3);
                opacity: 0.8;
            }
        }
        
        @media (max-width: 768px) {
            .hero-shapes {
                display: none;
            }
        }
        @media (max-width: 480px) {
            .roles-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Dreamy glowing atmosphere overlay styles */
        .hero-dreamy-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            pointer-events: none;
            z-index: 1;
        }

        /* Glow Blobs */
        .dreamy-glow-blob {
            position: absolute;
            width: 350px;
            height: 350px;
            border-radius: 50%;
            pointer-events: none;
            mix-blend-mode: screen;
            filter: blur(100px);
            opacity: 0.15;
            animation: blobPulse 8s ease-in-out infinite;
        }

        .dreamy-glow-blob.blob-1 {
            background: radial-gradient(circle, rgba(56, 189, 248, 0.8) 0%, rgba(124, 58, 237, 0.4) 50%, rgba(0, 0, 0, 0) 100%);
            top: 20%;
            left: 30%;
            transform: translate(-50%, -50%);
        }

        .dreamy-glow-blob.blob-2 {
            background: radial-gradient(circle, rgba(124, 58, 237, 0.8) 0%, rgba(56, 189, 248, 0.4) 50%, rgba(0, 0, 0, 0) 100%);
            bottom: 20%;
            right: 30%;
            transform: translate(50%, 50%);
            animation-name: blobPulse2;
            animation-duration: 8s;
            animation-timing-function: ease-in-out;
            animation-iteration-count: infinite;
            animation-delay: 4s;
        }

        @keyframes blobPulse {
            0%, 100% {
                opacity: 0.1;
                transform: scale(0.9) translate(-50%, -50%);
            }
            50% {
                opacity: 0.25;
                transform: scale(1.1) translate(-50%, -50%);
            }
        }

        @keyframes blobPulse2 {
            0%, 100% {
                opacity: 0.1;
                transform: scale(0.9) translate(50%, 50%);
            }
            50% {
                opacity: 0.25;
                transform: scale(1.1) translate(50%, 50%);
            }
        }

        /* Icons */
        .dreamy-icon {
            position: absolute;
            width: 32px;
            height: 32px;
            color: rgba(255, 255, 255, 0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            filter: drop-shadow(0 0 8px rgba(120, 180, 255, 0.6));
            animation: floatDrift 8s ease-in-out infinite;
        }

        .dreamy-icon svg {
            width: 100%;
            height: 100%;
            stroke: currentColor;
        }

        /* Soft glow blob behind each icon */
        .dreamy-icon-glow {
            position: absolute;
            width: 60px;
            height: 60px;
            background: radial-gradient(circle, rgba(120, 180, 255, 0.25) 0%, rgba(120, 180, 255, 0) 70%);
            border-radius: 50%;
            z-index: -1;
            pointer-events: none;
        }

        /* Positions scattered near edges */
        .dreamy-icon-grad {
            top: 15%;
            left: 12%;
            animation-duration: 7s;
            animation-delay: 0s;
        }

        .dreamy-icon-book {
            top: 18%;
            right: 15%;
            animation-duration: 9s;
            animation-delay: 1.5s;
        }

        .dreamy-icon-cpu {
            bottom: 25%;
            left: 15%;
            animation-duration: 8s;
            animation-delay: 3s;
        }

        .dreamy-icon-net {
            top: 35%;
            left: 28%;
            animation-duration: 10s;
            animation-delay: 4.5s;
        }

        @keyframes floatDrift {
            0% { transform: translate(0, 0) rotate(0deg); opacity: 0.2; }
            50% { transform: translate(10px, -14px) rotate(6deg); opacity: 0.35; }
            100% { transform: translate(0, 0) rotate(0deg); opacity: 0.2; }
        }

        /* Responsiveness: hide 1-2 icons below 768px width */
        @media (max-width: 768px) {
            .dreamy-icon-cpu, .dreamy-icon-net {
                display: none !important;
            }
        }

        /* ================= CHATBOT WIDGET ================= */
        #zcoer-chatbot-toggle {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--primary-hover) 100%);
            color: #ffffff;
            border: none;
            box-shadow: 0 4px 20px rgba(2, 132, 199, 0.4);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            z-index: 9999;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        #zcoer-chatbot-toggle:hover {
            transform: scale(1.1) rotate(10deg);
            box-shadow: 0 6px 24px rgba(2, 132, 199, 0.6);
        }
        #zcoer-chatbot-toggle i {
            transition: all 0.3s ease;
        }
        #zcoer-chatbot-toggle.open-active i {
            transform: rotate(90deg) scale(0);
            opacity: 0;
        }
        #zcoer-chatbot-toggle .close-icon {
            position: absolute;
            transform: rotate(-90deg) scale(0);
            opacity: 0;
            transition: all 0.3s ease;
        }
        #zcoer-chatbot-toggle.open-active .close-icon {
            transform: rotate(0) scale(1);
            opacity: 1;
        }

        #zcoer-chatbot-window {
            position: fixed;
            bottom: 105px;
            right: 30px;
            width: 370px;
            height: 520px;
            max-height: calc(100vh - 140px);
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 12px 40px rgba(15, 23, 42, 0.15);
            border: 1px solid rgba(15, 23, 42, 0.08);
            display: flex;
            flex-direction: column;
            z-index: 9999;
            overflow: hidden;
            transform: scale(0.9) translateY(20px);
            opacity: 0;
            pointer-events: none;
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        #zcoer-chatbot-window.open {
            transform: scale(1) translateY(0);
            opacity: 1;
            pointer-events: auto;
        }

        .chat-header {
            background: linear-gradient(135deg, var(--navy-dark) 0%, var(--navy-accent) 100%);
            color: #ffffff;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .chat-header-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .chat-header-logo {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            color: var(--primary-blue);
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .chat-header-title {
            display: flex;
            flex-direction: column;
        }
        .chat-header-name {
            font-family: var(--font-head);
            font-weight: 700;
            font-size: 0.95rem;
            line-height: 1.2;
        }
        .chat-header-status {
            font-size: 0.75rem;
            opacity: 0.85;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .chat-header-status::before {
            content: '';
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 8px #10b981;
        }
        .chat-header-close {
            background: none;
            border: none;
            color: #ffffff;
            font-size: 1.2rem;
            cursor: pointer;
            opacity: 0.8;
            transition: all 0.3s ease;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        .chat-header-close:hover {
            opacity: 1;
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(90deg);
        }

        .chat-body {
            flex-grow: 1;
            overflow-y: auto;
            padding: 20px;
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            gap: 12px;
            scroll-behavior: smooth;
        }
        
        /* Custom scrollbar for chat body */
        .chat-body::-webkit-scrollbar {
            width: 5px;
        }
        .chat-body::-webkit-scrollbar-track {
            background: transparent;
        }
        .chat-body::-webkit-scrollbar-thumb {
            background: rgba(15, 23, 42, 0.1);
            border-radius: 10px;
        }

        .chat-msg {
            max-width: 80%;
            padding: 10px 14px;
            font-size: 0.88rem;
            line-height: 1.4;
            border-radius: 16px;
            box-shadow: 0 2px 5px rgba(15, 23, 42, 0.03);
            word-wrap: break-word;
            animation: msg-fade-in 0.3s ease forwards;
            opacity: 0;
            transform: translateY(10px);
        }
        @keyframes msg-fade-in {
            to { opacity: 1; transform: translateY(0); }
        }
        
        .chat-msg.bot {
            align-self: flex-start;
            background: #ffffff;
            color: var(--text-dark);
            border: 1px solid rgba(15, 23, 42, 0.05);
            border-bottom-left-radius: 4px;
        }
        
        .chat-msg.user {
            align-self: flex-end;
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--primary-hover) 100%);
            color: #ffffff;
            border-bottom-right-radius: 4px;
        }

        .chat-suggestions-container {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 5px;
            align-self: flex-start;
            width: 100%;
        }
        
        .chat-suggest-title {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 2px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .chat-suggest-btn {
            background: #ffffff;
            border: 1px solid rgba(2, 132, 199, 0.2);
            color: var(--primary-blue);
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.25s ease;
            text-align: left;
            box-shadow: 0 2px 4px rgba(15, 23, 42, 0.02);
            font-family: var(--font-body);
        }
        .chat-suggest-btn:hover {
            background: rgba(2, 132, 199, 0.05);
            border-color: var(--primary-blue);
            transform: translateX(3px);
            box-shadow: 0 4px 8px rgba(15, 23, 42, 0.04);
        }

        .chat-input-area {
            background: #ffffff;
            padding: 12px 16px;
            border-top: 1px solid rgba(15, 23, 42, 0.06);
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .chat-input-field {
            flex-grow: 1;
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 24px;
            padding: 10px 16px;
            font-size: 0.88rem;
            outline: none;
            transition: all 0.3s ease;
            font-family: var(--font-body);
            background: #f8fafc;
        }
        .chat-input-field:focus {
            border-color: var(--primary-blue);
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1);
        }

        .chat-send-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--primary-hover) 100%);
            color: #ffffff;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            box-shadow: 0 2px 8px rgba(2, 132, 199, 0.25);
            transition: all 0.25s ease;
        }
        .chat-send-btn:hover {
            transform: scale(1.08);
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.4);
        }
        .chat-send-btn:active {
            transform: scale(0.95);
        }

        @media (max-width: 480px) {
            #zcoer-chatbot-window {
                bottom: 0;
                right: 0;
                width: 100%;
                height: 100%;
                max-height: 100%;
                border-radius: 0;
                z-index: 10000;
            }
            #zcoer-chatbot-toggle {
                bottom: 15px;
                right: 15px;
                width: 50px;
                height: 50px;
                z-index: 10001;
            }
        }
    </style>
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar">
        <div class="logo-container">
            <img src="assets/images/zeal_logo.png" alt="ZCOER Logo" class="logo-img" onerror="this.style.display='none'">
            <div class="logo-text">
                <span class="logo-top">Zeal Education Society's</span>
                <span class="logo-main">Zeal College of Engineering & Research, Pune</span>
                <span class="logo-sub">Department of Electronics & Computer Engineering</span>
            </div>
        </div>

        <div class="nav-links">
            <a href="#home" class="active">Home</a>
            <a href="#features">Features</a>
            <a href="#stats">Statistics</a>
            <a href="#roles">User Roles</a>
        </div>

        <div class="nav-actions">
            <div class="datetime-display">
                <i class="fa-regular fa-calendar"></i>
                <div class="dt-text">
                    <span id="currentDate"></span>
                    <span id="currentTime" style="color: var(--text-muted); font-size: 0.75rem; font-weight: 500;"></span>
                </div>
            </div>
            
            <?php if ($isLoggedIn): ?>
                <a href="<?= htmlspecialchars($dashUrl) ?>" class="btn btn-primary">
                    Dashboard <i class="fa-solid fa-arrow-right"></i>
                </a>
            <?php else: ?>
                <a href="auth/register.php" class="btn btn-outline">
                    <i class="fa-solid fa-user-plus"></i> Register
                </a>
                <a href="auth/login.php" class="btn btn-primary">
                    <i class="fa-solid fa-right-to-bracket"></i> Login
                </a>
            <?php endif; ?>
        </div>
    </nav>

    <!-- HERO SECTION -->
    <section class="hero" id="home">
        <div class="hero-overlay"></div>
        
        <!-- Dreamy glowing atmosphere overlay -->
        <div class="hero-dreamy-overlay">
            <!-- Glow Blobs behind the headline text -->
            <div class="dreamy-glow-blob blob-1"></div>
            <div class="dreamy-glow-blob blob-2"></div>

            <!-- Decorative Floating Icons -->
            <div class="dreamy-icon dreamy-icon-grad">
                <div class="dreamy-icon-glow"></div>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/><path d="M21.5 12v6"/></svg>
            </div>
            <div class="dreamy-icon dreamy-icon-book">
                <div class="dreamy-icon-glow"></div>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
            </div>
            <div class="dreamy-icon dreamy-icon-cpu">
                <div class="dreamy-icon-glow"></div>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 1v3M15 1v3M9 20v3M15 20v3M20 9h3M20 15h3M1 9h3M1 15h3"/></svg>
            </div>
            <div class="dreamy-icon dreamy-icon-net">
                <div class="dreamy-icon-glow"></div>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="16" y="16" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="9" y="2" width="6" height="6" rx="1"/><path d="M12 8v8M12 11H5M12 11h7"/></svg>
            </div>
        </div>

        <div class="hero-shapes">
            <!-- Holographic Tech Globe & Entrance Gate -->
            <div class="hologram-globe"></div>
            <div class="circuit-gate">
                <div class="gate-neon-pulse"></div>
            </div>

            <!-- Left and Right scattered connection lines -->
            <svg class="hero-network" viewBox="0 0 100 100" preserveAspectRatio="none">
                <!-- Left constellation network lines -->
                <line x1="10" y1="20" x2="22" y2="40" />
                <line x1="22" y1="40" x2="8" y2="65" />
                <!-- Right constellation network lines -->
                <line x1="90" y1="25" x2="76" y2="45" />
                <line x1="76" y1="45" x2="92" y2="68" />
            </svg>

            <!-- Floating Academic Icons with Glows -->
            <!-- Left Side Icons -->
            <div class="shape shape-1">
                <div class="glow"></div>
                <i class="fa-solid fa-graduation-cap"></i>
            </div>
            <div class="shape shape-2">
                <div class="glow"></div>
                <i class="fa-solid fa-book"></i>
            </div>
            <div class="shape shape-3">
                <div class="glow"></div>
                <i class="fa-solid fa-award"></i>
            </div>
            <!-- Right Side Icons -->
            <div class="shape shape-4">
                <div class="glow"></div>
                <i class="fa-solid fa-chart-line"></i>
            </div>
            <div class="shape shape-5">
                <div class="glow"></div>
                <i class="fa-solid fa-pencil"></i>
            </div>
            <div class="shape shape-6">
                <div class="glow"></div>
                <i class="fa-solid fa-lightbulb"></i>
            </div>
        </div>
        <div class="hero-content">
            <div class="hero-dept reveal-scroll">Department of Electronics & Computer Engineering</div>
            <h1 class="hero-title reveal-scroll">
                Student Activity Assessment<br>
                & Evaluation System <span>(CIE 2)</span>
            </h1>
            <p class="hero-subtitle reveal-scroll">
                A smart platform to manage activities, submit assignments, evaluate performance and generate final marksheets efficiently and transparently.
            </p>
        </div>

        <!-- TICKER BOARD -->
        <div class="notice-board">
            <div class="notice-label">
                <i class="fa-solid fa-bullhorn"></i> Notice Board
            </div>
            <div class="notice-scroll">
                <div class="ticker-text">
                    <?php 
                    $formattedNotices = array_map(function($notice) {
                        return "<span class='ticker-item'><i class='fa-solid fa-circle ticker-dot'></i> " . $notice . "</span>";
                    }, $tickerNotices);
                    $tickerString = implode("", $formattedNotices);
                    echo $tickerString . $tickerString; 
                    ?>
                </div>
            </div>
        </div>
    </section>

    <!-- KEY FEATURES SECTION -->
    <section class="section-wrapper" id="features">
        <h2 class="section-title reveal-scroll">Key Features</h2>
        
        <div class="grid-container reveal-scroll">
            <div class="feature-card feat-1">
                <div class="f-icon-wrap fi-1"><i class="fa-solid fa-list-check"></i></div>
                <h3 class="f-title">Activity Management</h3>
                <p class="f-desc">Faculty can create and manage unit-wise activities with due dates.</p>
            </div>
            
            <div class="feature-card feat-2">
                <div class="f-icon-wrap fi-2"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                <h3 class="f-title">Easy Submission</h3>
                <p class="f-desc">Students can upload PDF, JPG or PNG files in just a few clicks.</p>
            </div>
            
            <div class="feature-card feat-3">
                <div class="f-icon-wrap fi-3"><i class="fa-regular fa-clock"></i></div>
                <h3 class="f-title">Automatic Evaluation</h3>
                <p class="f-desc">Marks are allocated automatically based on submission time.</p>
            </div>
            
            <div class="feature-card feat-4">
                <div class="f-icon-wrap fi-4"><i class="fa-solid fa-chart-line"></i></div>
                <h3 class="f-title">Progress Tracking</h3>
                <p class="f-desc">Students, Parents and Faculty can track performance and view results.</p>
            </div>
            
            <div class="feature-card feat-5">
                <div class="f-icon-wrap fi-5"><i class="fa-solid fa-file-invoice"></i></div>
                <h3 class="f-title">Transparent Marksheets</h3>
                <p class="f-desc">Final marksheets are generated automatically once all units are complete.</p>
            </div>
        </div>
    </section>

    <!-- STATISTICS SECTION -->
    <section class="section-wrapper alt-bg" id="stats">
        <h2 class="section-title reveal-scroll">System Statistics</h2>
        
        <div class="stats-grid reveal-scroll">
            <div class="stat-block stat-1">
                <div class="stat-icon-wrap s-1"><i class="fa-solid fa-users"></i></div>
                <div class="stat-val" data-target="<?= (int)max($statsData['users'], 1) ?>">0</div>
                <div class="stat-label">Registered Users</div>
            </div>
            <div class="stat-block stat-2">
                <div class="stat-icon-wrap s-2"><i class="fa-solid fa-clipboard-list"></i></div>
                <div class="stat-val" data-target="<?= (int)max($statsData['activities'], 1) ?>">0</div>
                <div class="stat-label">Active Assignments</div>
            </div>
            <div class="stat-block stat-3">
                <div class="stat-icon-wrap s-3"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                <div class="stat-val" data-target="<?= (int)max($statsData['submissions'], 1) ?>">0</div>
                <div class="stat-label">Student Submissions</div>
            </div>
            <div class="stat-block stat-4">
                <div class="stat-icon-wrap s-4"><i class="fa-solid fa-layer-group"></i></div>
                <div class="stat-val" data-target="6">0</div>
                <div class="stat-label">Total Units</div>
            </div>
        </div>
    </section>

    <!-- USER ROLES SECTION -->
    <section class="section-wrapper" id="roles">
        <h2 class="section-title reveal-scroll">User Roles</h2>
        
        <div class="grid-container roles-grid">
            <a href="auth/login.php?role=student" class="feature-card r-card-1 reveal-card" style="--card-index: 0; text-decoration: none;">
                <div class="role-icon-wrap ri-1"><i class="fa-solid fa-user-graduate"></i></div>
                <h3 class="f-title">Student</h3>
                <p class="f-desc">View activities, submit assignments and track performance.</p>
            </a>
            
            <a href="auth/login.php?role=faculty" class="feature-card r-card-2 reveal-card" style="--card-index: 1; text-decoration: none;">
                <div class="role-icon-wrap ri-2"><i class="fa-solid fa-chalkboard-user"></i></div>
                <h3 class="f-title">Faculty</h3>
                <p class="f-desc">Create activities, evaluate submissions and generate reports.</p>
            </a>
            
            <a href="auth/login.php?role=parent" class="feature-card r-card-3 reveal-card" style="--card-index: 2; text-decoration: none;">
                <div class="role-icon-wrap ri-3"><i class="fa-solid fa-users"></i></div>
                <h3 class="f-title">Parent</h3>
                <p class="f-desc">Monitor student progress, marks and pending activities.</p>
            </a>
            
            <a href="auth/login.php?role=admin" class="feature-card r-card-4 reveal-card" style="--card-index: 3; text-decoration: none;">
                <div class="role-icon-wrap ri-4"><i class="fa-solid fa-user-shield"></i></div>
                <h3 class="f-title">Admin</h3>
                <p class="f-desc">Manage users, subjects, activities and system settings.</p>
            </a>
            
            <a href="auth/login.php?role=hod" class="feature-card r-card-5 reveal-card" style="--card-index: 4; text-decoration: none;">
                <div class="role-icon-wrap ri-5"><i class="fa-solid fa-user-tie"></i></div>
                <h3 class="f-title">HOD</h3>
                <p class="f-desc">Oversee department activities and performance.</p>
            </a>
            
            <a href="auth/login.php?role=gfm" class="feature-card r-card-6 reveal-card" style="--card-index: 5; text-decoration: none;">
                <div class="role-icon-wrap ri-6"><i class="fa-solid fa-users-gear"></i></div>
                <h3 class="f-title">GFM</h3>
                <p class="f-desc">Monitor student progress and academic data.</p>
            </a>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="main-footer">
        <div class="footer-info">
            <div class="f-item">
                <i class="fa-solid fa-location-dot"></i>
                <span>Narhe, Pune - 411041, Maharashtra, India</span>
            </div>
            <div class="f-item">
                <i class="fa-solid fa-phone"></i>
                <span>755866663</span>
            </div>
            <div class="f-item">
                <i class="fa-solid fa-envelope"></i>
                <span>zcoer@zealeducation.com</span>
            </div>
        </div>
        <div>
            &copy; <?= date('Y') ?> Zeal College of Engineering & Research, Pune. All Rights Reserved.
        </div>
    </footer>

    <!-- Chatbot Widget Toggle Button -->
    <button id="zcoer-chatbot-toggle" aria-label="Open FAQ Chatbot">
        <i class="fa-solid fa-comments"></i>
        <i class="fa-solid fa-xmark close-icon"></i>
    </button>

    <!-- Chatbot Widget Window -->
    <div id="zcoer-chatbot-window" role="dialog" aria-label="ZCOER Chatbot Assistant">
        <div class="chat-header">
            <div class="chat-header-info">
                <div class="chat-header-logo">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div class="chat-header-title">
                    <span class="chat-header-name">ZCOER Assistant</span>
                    <span class="chat-header-status">Online</span>
                </div>
            </div>
            <button class="chat-header-close" id="zcoer-chat-close" aria-label="Minimize chatbot">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="chat-body" id="zcoer-chat-body">
            <!-- Messages are appended here -->
        </div>
    </div>

    <!-- Scripts -->
    <script>
        // Update Live Date & Time in Navbar
        function updateDateTime() {
            const dateEl = document.getElementById('currentDate');
            const timeEl = document.getElementById('currentTime');
            if(!dateEl || !timeEl) return;

            const now = new Date();
            
            const optionsDate = { day: '2-digit', month: 'short', year: 'numeric' };
            dateEl.textContent = now.toLocaleDateString('en-GB', optionsDate);
            
            const optionsTime = { hour: '2-digit', minute: '2-digit', hour12: true };
            timeEl.textContent = now.toLocaleTimeString('en-US', optionsTime);
        }
        
        setInterval(updateDateTime, 1000);
        updateDateTime();

        // Smooth Scroll for Nav Links
        document.querySelectorAll('.nav-links a').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const targetId = this.getAttribute('href');
                if(targetId.startsWith('#')) {
                    e.preventDefault();
                    document.querySelectorAll('.nav-links a').forEach(a => a.classList.remove('active'));
                    this.classList.add('active');
                    
                    const targetSection = document.querySelector(targetId);
                    if(targetSection) {
                        const headerOffset = 80;
                        const elementPosition = targetSection.getBoundingClientRect().top;
                        const offsetPosition = elementPosition + window.pageYOffset - headerOffset;
                    
                        window.scrollTo({
                            top: offsetPosition,
                            behavior: "smooth"
                        });
                    }
                }
            });
        });

        // Intersection Observer for scroll animations and counting numbers
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add("in-view");
                    
                    // Trigger number counter if it's the stats block
                    if (entry.target.classList.contains('stats-grid')) {
                        document.querySelectorAll('.stat-val').forEach(el => {
                            const target = parseInt(el.getAttribute('data-target'));
                            if (el.innerText === "0") {
                                animateValue(el, 0, target, 1500);
                            }
                        });
                    }
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1 });

        document.querySelectorAll(".reveal-scroll, .reveal-card").forEach(el => observer.observe(el));

        function animateValue(obj, start, end, duration) {
            let startTimestamp = null;
            const step = (timestamp) => {
                if (!startTimestamp) startTimestamp = timestamp;
                const progress = Math.min((timestamp - startTimestamp) / duration, 1);
                const ease = progress === 1 ? 1 : 1 - Math.pow(2, -10 * progress);
                obj.innerHTML = Math.floor(ease * (end - start) + start) + (end > 10 ? '+' : '');
                if (progress < 1) window.requestAnimationFrame(step);
            };
            window.requestAnimationFrame(step);
        }

        // ================= CHATBOT FUNCTIONALITY =================
        (function() {
            const categories = [
                {
                    name: "Login & Registration",
                    color: "#3b82f6", // Blue
                    questions: [
                        {
                            q: "How do I register?",
                            a: "Click the \"Register\" button in the top navigation and fill in your role-based details."
                        },
                        {
                            q: "How do I log in?",
                            a: "Click the \"Login\" button in the top navigation and select your role (Student, Faculty, Parent, Admin, HOD, GFM)."
                        },
                        {
                            q: "I forgot my password, what do I do?",
                            a: "Use the \"Forgot Password\" option on the login page, or contact your administrator if that option isn't available yet."
                        }
                    ]
                },
                {
                    name: "Roles & Dashboards",
                    color: "#8b5cf6", // Purple
                    questions: [
                        {
                            q: "What roles exist in this system?",
                            a: "Six roles: Student, Faculty, Parent, Admin, HOD, and GFM — each with a different dashboard and permissions."
                        },
                        {
                            q: "What can I do as a Student?",
                            a: "View assigned activities, submit responses, and track your marks and unit-wise performance on your dashboard."
                        },
                        {
                            q: "What can I do as a Faculty member?",
                            a: "Create unit-wise activities, view and verify student submissions, override marks if needed, and generate reports."
                        },
                        {
                            q: "What can I do as a Parent?",
                            a: "Monitor your ward's submitted and pending activities, marks, and overall progress."
                        },
                        {
                            q: "What does an Admin/HOD/GFM see?",
                            a: "Admins manage users, subjects, and system settings; HOD oversees department-wide activities and performance; GFM monitors student progress and academic data."
                        }
                    ]
                },
                {
                    name: "Submission Help",
                    color: "#10b981", // Green
                    questions: [
                        {
                            q: "What file formats can I submit?",
                            a: "PDF, JPG, or PNG files only."
                        },
                        {
                            q: "How do I submit my activity?",
                            a: "Go to your Student Dashboard, open the relevant activity, and use the upload button to attach your PDF/JPG/PNG file before the due date."
                        },
                        {
                            q: "My file won't upload, what's wrong?",
                            a: "Make sure it's a PDF, JPG, or PNG and under the allowed size limit; if it still fails, refresh and try again or contact support."
                        },
                        {
                            q: "Can I resubmit an activity?",
                            a: "Check with your faculty — resubmission depends on whether the due date has passed and faculty settings for that activity."
                        },
                        {
                            q: "I missed the deadline, what happens?",
                            a: "Late submissions are still accepted but receive fewer marks based on how late they are (see \"Marks & Evaluation\" category)."
                        }
                    ]
                },
                {
                    name: "Marks & Evaluation",
                    color: "#f59e0b", // Orange
                    questions: [
                        {
                            q: "How are marks calculated?",
                            a: "Based on submission timing: Same day = 5, Next day = 4, 2 days late = 3, 3 days late = 2, 4 days late = 1, After 4 days = 0."
                        },
                        {
                            q: "Can a faculty member change my marks?",
                            a: "Yes, faculty can manually override auto-assigned marks in exceptional cases — for example, valid excuses for late submission."
                        },
                        {
                            q: "Where can I see my marks?",
                            a: "On your Student Dashboard, under marks obtained and unit-wise performance."
                        }
                    ]
                },
                {
                    name: "Final Marksheet",
                    color: "#0ea5e9", // Teal/Sky Blue
                    questions: [
                        {
                            q: "When is the final marksheet generated?",
                            a: "After all six units are completed, the system calculates your total and generates the Final Activity Marksheet."
                        },
                        {
                            q: "What is the final marksheet out of?",
                            a: "Your total marks across all six units are converted to a final score out of 20."
                        },
                        {
                            q: "Can I download my marksheet?",
                            a: "Yes, the final marksheet can be exported as a PDF once available."
                        }
                    ]
                },
                {
                    name: "Contact & Support",
                    color: "#ef4444", // Red
                    questions: [
                        {
                            q: "Who do I contact for help?",
                            a: "Email zcoer@zealeducation.com or call 755866663."
                        },
                        {
                            q: "Where is the department located?",
                            a: "Narhe, Pune - 411041, Maharashtra, India."
                        }
                    ]
                }
            ];

            const chatToggle = document.getElementById('zcoer-chatbot-toggle');
            const chatWindow = document.getElementById('zcoer-chatbot-window');
            const chatClose = document.getElementById('zcoer-chat-close');
            const chatBody = document.getElementById('zcoer-chat-body');

            let hasGreetingBeenShown = false;

            function toggleChat() {
                chatWindow.classList.toggle('open');
                chatToggle.classList.toggle('open-active');
                if (chatWindow.classList.contains('open')) {
                    showGreeting();
                }
            }

            function appendMessage(text, sender) {
                const msgDiv = document.createElement('div');
                msgDiv.classList.add('chat-msg', sender);
                if (sender === 'user') {
                    msgDiv.textContent = text;
                } else {
                    msgDiv.innerHTML = text;
                }
                chatBody.appendChild(msgDiv);
                chatBody.scrollTop = chatBody.scrollHeight;
            }

            function disableAllPreviousMenus() {
                document.querySelectorAll('.chat-menu-options').forEach(container => {
                    container.querySelectorAll('button').forEach(btn => {
                        btn.disabled = true;
                        btn.style.opacity = '0.5';
                        btn.style.pointerEvents = 'none';
                    });
                });
            }

            function showGreeting() {
                if (hasGreetingBeenShown) return;
                appendMessage("Hello! Welcome to the ZCOER ECE Assistant. Please select a topic to get started:", 'bot');
                renderCategoryMenu();
                hasGreetingBeenShown = true;
            }

            function renderCategoryMenu() {
                const container = document.createElement('div');
                container.classList.add('chat-menu-options', 'chat-suggestions-container');
                
                categories.forEach(cat => {
                    const btn = document.createElement('button');
                    btn.classList.add('chat-suggest-btn');
                    btn.style.borderColor = cat.color;
                    btn.style.color = cat.color;
                    btn.tabIndex = 0;
                    btn.innerHTML = `<i class="fa-solid fa-folder-open" style="margin-right: 6px; font-size: 0.8rem; color: ${cat.color}"></i> ${cat.name}`;
                    
                    const selectCategory = () => {
                        disableAllPreviousMenus();
                        appendMessage(cat.name, 'user');
                        setTimeout(() => {
                            renderCategoryQuestions(cat);
                        }, 300);
                    };
                    btn.onclick = selectCategory;
                    container.appendChild(btn);
                });
                
                chatBody.appendChild(container);
                chatBody.scrollTop = chatBody.scrollHeight;
            }

            function renderCategoryQuestions(cat) {
                appendMessage(`Here are some common questions about <strong>${cat.name}</strong>:`, 'bot');
                
                const container = document.createElement('div');
                container.classList.add('chat-menu-options', 'chat-suggestions-container');
                
                cat.questions.forEach(qItem => {
                    const btn = document.createElement('button');
                    btn.classList.add('chat-suggest-btn');
                    btn.style.borderColor = cat.color;
                    btn.style.color = cat.color;
                    btn.tabIndex = 0;
                    btn.textContent = qItem.q;
                    
                    const selectQuestion = () => {
                        disableAllPreviousMenus();
                        appendMessage(qItem.q, 'user');
                        setTimeout(() => {
                            appendMessage(qItem.a, 'bot');
                            renderPostQuestionMenu(cat, qItem);
                        }, 300);
                    };
                    btn.onclick = selectQuestion;
                    container.appendChild(btn);
                });
                
                // Add Back button
                const backBtn = document.createElement('button');
                backBtn.classList.add('chat-suggest-btn');
                backBtn.style.borderColor = '#64748b'; // Slate gray
                backBtn.style.color = '#64748b';
                backBtn.tabIndex = 0;
                backBtn.innerHTML = `⬅ Back`;
                backBtn.onclick = () => {
                    disableAllPreviousMenus();
                    appendMessage("⬅ Back", 'user');
                    setTimeout(() => {
                        appendMessage("Please select a topic to get started:", 'bot');
                        renderCategoryMenu();
                    }, 300);
                };
                container.appendChild(backBtn);
                
                chatBody.appendChild(container);
                chatBody.scrollTop = chatBody.scrollHeight;
            }

            function renderPostQuestionMenu(cat, qItem) {
                const container = document.createElement('div');
                container.classList.add('chat-menu-options', 'chat-suggestions-container');
                
                // Back to Category Button
                const backBtn = document.createElement('button');
                backBtn.classList.add('chat-suggest-btn');
                backBtn.style.borderColor = cat.color;
                backBtn.style.color = cat.color;
                backBtn.tabIndex = 0;
                backBtn.innerHTML = `⬅ Back to ${cat.name}`;
                backBtn.onclick = () => {
                    disableAllPreviousMenus();
                    appendMessage(`⬅ Back to ${cat.name}`, 'user');
                    setTimeout(() => {
                        renderCategoryQuestions(cat);
                    }, 300);
                };
                container.appendChild(backBtn);
                
                // Main Menu Button
                const mainBtn = document.createElement('button');
                mainBtn.classList.add('chat-suggest-btn');
                mainBtn.style.borderColor = '#0284c7';
                mainBtn.style.color = '#0284c7';
                mainBtn.tabIndex = 0;
                mainBtn.innerHTML = `🏠 Main Menu`;
                mainBtn.onclick = () => {
                    disableAllPreviousMenus();
                    appendMessage("🏠 Main Menu", 'user');
                    setTimeout(() => {
                        appendMessage("Please select a topic to get started:", 'bot');
                        renderCategoryMenu();
                    }, 300);
                };
                container.appendChild(mainBtn);
                
                chatBody.appendChild(container);
                chatBody.scrollTop = chatBody.scrollHeight;
            }

            chatToggle.addEventListener('click', toggleChat);
            chatClose.addEventListener('click', toggleChat);
        })();
    </script>
</body>
</html>