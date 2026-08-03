<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$activePage = $active_page ?? 'dashboard';
?>
<style>
/* CSS Variables & Global Resets for Admin Panel */
:root {
    --primary-blue: #0ea5e9;
    --primary-hover: #0284c7;
    --bg-body: #f8fafc;
    --bg-card: #ffffff;
    --border-light: #e2e8f0;
    --text-dark: #0f172a;
    --text-muted: #64748b;
    
    --sidebar-bg: #0f172a; /* Deep Navy from screenshot */
    --sidebar-text: #94a3b8;
    
    --success: #10b981;
    --danger: #ef4444;
    --warning: #f97316; /* Orange from notice board screenshot */
    --radius-md: 10px;
    --radius-lg: 16px;
    --shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.05);
    --shadow-md: 0 4px 6px -1px rgba(15, 23, 42, 0.05);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', sans-serif;
    background-color: var(--bg-body);
    color: var(--text-dark);
    min-height: 100vh;
    line-height: 1.5;
}

.admin-layout {
    display: flex;
    min-height: 100vh;
}

/* Sidebar Styles */
.admin-sidebar {
    width: 260px;
    background: var(--sidebar-bg);
    border-right: 1px solid rgba(255, 255, 255, 0.05);
    display: flex;
    flex-direction: column;
    padding: 2rem 1.25rem;
    position: fixed;
    top: 0;
    bottom: 0;
    left: 0;
    z-index: 100;
    overflow: hidden; /* Contain background animations */
}

.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: #ffffff;
    margin-bottom: 2.5rem;
    padding: 0 0.5rem;
    position: relative;
    z-index: 5;
}
.brand-icon {
    font-size: 1.5rem;
    color: var(--primary-blue);
}

.sidebar-nav {
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex-grow: 1;
    position: relative;
    z-index: 5;
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    color: var(--sidebar-text);
    font-weight: 500;
    font-size: 0.95rem;
    border-radius: var(--radius-md);
    transition: all 0.25s ease;
    text-decoration: none;
}
.nav-item i {
    font-size: 1.1rem;
    width: 20px;
    text-align: center;
}
.nav-item:hover {
    background: rgba(14, 165, 233, 0.08);
    color: #ffffff;
}
.nav-item.active {
    background: var(--primary-blue);
    color: #ffffff;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);
}

.sidebar-footer {
    display: flex;
    flex-direction: column;
    gap: 8px;
    border-top: 1px solid rgba(255, 255, 255, 0.05);
    padding-top: 1.5rem;
    position: relative;
    z-index: 5;
}

.logout-btn {
    color: #f87171;
}
.logout-btn:hover {
    background: rgba(239, 68, 68, 0.1);
    color: #f87171;
}

/* Layout Content Wrapper */
.admin-main {
    margin-left: 260px;
    flex-grow: 1;
    padding: 2.5rem;
    width: calc(100% - 260px);
}

@media (max-width: 1024px) {
    .admin-main {
        padding: 2rem 1.5rem;
    }
}

@media (max-width: 768px) {
    .admin-sidebar {
        width: 70px;
        padding: 2rem 0.5rem;
    }
    .sidebar-brand span, .nav-item span {
        display: none;
    }
    .admin-main {
        margin-left: 70px;
        width: calc(100% - 70px);
        padding: 1.5rem;
    }
}

/* ================= AMBIENT EFFECTS & FLOATING SHAPES ================= */
/* Glowing Blobs in Background */
.dreamy-glow-blob {
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
    mix-blend-mode: screen;
    filter: blur(80px);
    opacity: 0.18;
    z-index: 1;
}
.dreamy-glow-blob.blob-1 {
    width: 280px;
    height: 280px;
    background: radial-gradient(circle, rgba(56, 189, 248, 0.85) 0%, rgba(124, 58, 237, 0.45) 50%, rgba(0, 0, 0, 0) 100%);
    animation: blobPulse 8s ease-in-out infinite;
}
.dreamy-glow-blob.blob-2 {
    width: 280px;
    height: 280px;
    background: radial-gradient(circle, rgba(124, 58, 237, 0.85) 0%, rgba(56, 189, 248, 0.45) 50%, rgba(0, 0, 0, 0) 100%);
    animation: blobPulse2 8s ease-in-out infinite;
}

/* Circuit Gate vertical scanning neon pulse */
.circuit-gate {
    position: absolute;
    width: 100%;
    height: 100%;
    top: 0;
    left: 0;
    pointer-events: none;
    z-index: 2;
    overflow: hidden;
}
.gate-neon-pulse {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 35%;
    background: linear-gradient(0deg, rgba(56, 189, 248, 0) 0%, rgba(56, 189, 248, 0.25) 50%, rgba(124, 58, 237, 0.35) 100%);
    mix-blend-mode: screen;
    opacity: 0.65;
    animation: gate-flow 5s linear infinite;
    border-top: 1.5px solid rgba(56, 189, 248, 0.45);
    box-shadow: 0 -4px 15px rgba(56, 189, 248, 0.25);
}

/* Floating shape icons with pulsing glows */
.shape {
    position: absolute;
    color: rgba(186, 230, 253, 0.28);
    font-size: 1.4rem;
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 3;
    filter: drop-shadow(0 0 8px rgba(56, 189, 248, 0.6));
    animation: float-shape 6s ease-in-out infinite;
    pointer-events: none;
}
.shape .glow {
    position: absolute;
    width: 44px;
    height: 44px;
    background: radial-gradient(circle, rgba(56, 189, 248, 0.35) 0%, rgba(56, 189, 248, 0) 70%);
    border-radius: 50%;
    z-index: -1;
    animation: pulse-glow 3s ease-in-out infinite alternate;
}

/* Keyframes */
@keyframes blobPulse {
    0%, 100% { opacity: 0.12; transform: scale(0.9) translate(0, 0); }
    50% { opacity: 0.22; transform: scale(1.1) translate(5px, -5px); }
}
@keyframes blobPulse2 {
    0%, 100% { opacity: 0.12; transform: scale(0.9) translate(0, 0); }
    50% { opacity: 0.22; transform: scale(1.1) translate(-5px, 5px); }
}
@keyframes float-shape {
    0% { transform: translateY(0) rotate(0deg); }
    50% { transform: translateY(-10px) rotate(6deg); }
    100% { transform: translateY(0) rotate(0deg); }
}
@keyframes pulse-glow {
    0%, 100% { transform: scale(0.85); opacity: 0.35; }
    50% { transform: scale(1.2); opacity: 0.75; }
}
@keyframes gate-flow {
    0% { transform: translateY(120%); opacity: 0; }
    8% { opacity: 0.75; }
    92% { opacity: 0.75; }
    100% { transform: translateY(-120%); opacity: 0; }
}

/* Responsiveness overrides */
@media (max-width: 768px) {
    .shape, .dreamy-glow-blob, .circuit-gate {
        display: none !important;
    }
}
</style>

<aside class="admin-sidebar">
    <!-- Glowing background atmosphere & scans -->
    <div class="dreamy-glow-blob blob-1" style="top: 10%; left: -50px; width: 180px; height: 180px;"></div>
    <div class="dreamy-glow-blob blob-2" style="bottom: 20%; right: -50px; width: 180px; height: 180px;"></div>
    
    <div class="circuit-gate">
        <div class="gate-neon-pulse" style="animation-duration: 6s;"></div>
    </div>
    
    <!-- Floating Glowing Icons behind layout -->
    <div class="shape shape-1" style="top: 18%; right: 12%; font-size: 1.1rem; animation-duration: 7s;"><div class="glow"></div><i class="fa-solid fa-graduation-cap"></i></div>
    <div class="shape shape-2" style="top: 50%; left: 8%; font-size: 1rem; animation-duration: 9s; animation-delay: 1.5s;"><div class="glow"></div><i class="fa-solid fa-book"></i></div>
    <div class="shape shape-3" style="bottom: 15%; right: 10%; font-size: 1.1rem; animation-duration: 8s; animation-delay: 3s;"><div class="glow"></div><i class="fa-solid fa-award"></i></div>
    <div class="sidebar-brand">
        <i class="fa-solid fa-graduation-cap brand-icon"></i>
        <span>SAAES Admin</span>
    </div>
    <nav class="sidebar-nav">
        <a href="admin_dashboard.php" class="nav-item <?= $activePage === 'dashboard' ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-line"></i>
            <span>Dashboard</span>
        </a>
        <a href="admin_users.php" class="nav-item <?= $activePage === 'users' ? 'active' : '' ?>">
            <i class="fa-solid fa-users"></i>
            <span>Users</span>
        </a>
        <a href="admin_audit.php" class="nav-item <?= $activePage === 'audit' ? 'active' : '' ?>">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span>Audit Logs</span>
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php" class="nav-item logout-btn">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>
