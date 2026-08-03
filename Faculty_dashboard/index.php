<?php
// faculty_dashboard/index.php
require_once '../db.php';
session_start();

// Fetch summary metrics for the dashboard
try {
    $stmt_activities = $pdo->query("SELECT COUNT(*) FROM activities");
    $total_activities = $stmt_activities->fetchColumn();

    // Fetch recent activities for the table
    $stmt_list = $pdo->query("SELECT * FROM activities ORDER BY upload_date DESC LIMIT 5");
    $activities = $stmt_list->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $total_activities = 0;
    $activities = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CoachPro Style - SAAES Faculty Dashboard</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Custom scrollbar and glass effect tweaks */
        body {
            background: linear-gradient(135deg, #e0f2f1 0%, #b2dfdb 50%, #80cbc4 100%);
            font-family: 'Inter', sans-serif;
        }
        .glass-panel {
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.6);
        }
        .glass-sidebar {
            background: rgba(255, 255, 255, 0.65);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-right: 1px solid rgba(255, 255, 255, 0.4);
        }
    </style>
</head>
<body class="min-h-screen text-slate-800 p-3 sm:p-6 flex items-center justify-center">

    <!-- Main Container Card (Mimicking the App Window Frame in Image) -->
    <div class="w-full max-w-7xl h-[92vh] glass-panel rounded-3xl shadow-2xl overflow-hidden flex flex-col md:flex-row relative">

        <!-- Sidebar Navigation -->
        <aside class="w-full md:w-64 glass-sidebar flex flex-col justify-between p-6 z-10">
            <div>
                <!-- Logo / Brand -->
                <div class="flex items-center space-x-3 mb-10">
                    <div class="w-9 h-9 rounded-xl bg-teal-800 flex items-center justify-center text-white font-bold shadow-md">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                    <span class="text-xl font-extrabold tracking-tight text-teal-900">SAAES<span class="text-teal-600">Pro</span></span>
                </div>

                <!-- Nav Links -->
                <nav class="space-y-2">
                    <a href="index.php" class="flex items-center space-x-3 px-4 py-3 rounded-2xl bg-teal-800 text-white font-medium shadow-lg transition">
                        <i class="fa-solid fa-house-chimney"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="deploy_activity.php" class="flex items-center space-x-3 px-4 py-3 rounded-2xl text-slate-600 hover:bg-white/50 font-medium transition">
                        <i class="fa-solid fa-rocket"></i>
                        <span>Deploy Activity</span>
                    </a>
                    <a href="submissions.php" class="flex items-center space-x-3 px-4 py-3 rounded-2xl text-slate-600 hover:bg-white/50 font-medium transition">
                        <i class="fa-solid fa-folder-open"></i>
                        <span>Submissions</span>
                    </a>
                    <a href="auto_marks.php" class="flex items-center space-x-3 px-4 py-3 rounded-2xl text-slate-600 hover:bg-white/50 font-medium transition">
                        <i class="fa-solid fa-chart-line"></i>
                        <span>Auto-Marks</span>
                    </a>
                    <a href="calendar.php" class="flex items-center space-x-3 px-4 py-3 rounded-2xl text-slate-600 hover:bg-white/50 font-medium transition">
                        <i class="fa-solid fa-calendar-days"></i>
                        <span>Calendar</span>
                    </a>
                    <a href="manual_override.php" class="flex items-center space-x-3 px-4 py-3 rounded-2xl text-slate-600 hover:bg-white/50 font-medium transition">
                        <i class="fa-solid fa-user-pen"></i>
                        <span>HOD Overrides</span>
                    </a>
                </nav>
            </div>

            <!-- Bottom User Section in Sidebar -->
            <div class="pt-6 border-t border-slate-200/60">
                <a href="notices.php" class="flex items-center space-x-3 px-4 py-3 rounded-2xl text-slate-600 hover:bg-white/50 font-medium transition">
                    <i class="fa-solid fa-bullhorn text-amber-600"></i>
                    <span>Notice Board</span>
                </a>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="flex-1 flex flex-col overflow-y-auto">
            
            <!-- Top Header Bar -->
            <header class="px-8 py-5 flex flex-wrap justify-between items-center border-b border-white/40">
                <div>
                    <p class="text-xs font-semibold text-teal-700 uppercase tracking-wider">Welcome back, Prof. Faculty 👋</p>
                    <h1 class="text-2xl font-black text-slate-900">Faculty Dashboard</h1>
                </div>
                <div class="flex items-center space-x-4 mt-3 sm:mt-0">
                    <div class="relative hidden sm:block">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                            <i class="fa-solid fa-magnifying-glass text-sm"></i>
                        </span>
                        <input type="text" placeholder="Search activities..." class="bg-white/60 border border-white/80 rounded-full pl-10 pr-4 py-2 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-500 w-64 shadow-sm">
                    </div>
                    <button class="w-10 h-10 rounded-full bg-white/70 shadow border border-white flex items-center justify-center text-slate-600 hover:bg-white transition relative">
                        <i class="fa-regular fa-bell"></i>
                        <span class="absolute top-2 right-2 w-2 h-2 bg-rose-500 rounded-full"></span>
                    </button>
                    <div class="flex items-center space-x-2 bg-white/60 px-3 py-1.5 rounded-full border border-white shadow-sm">
                        <div class="w-8 h-8 rounded-full bg-teal-700 text-white font-bold flex items-center justify-center text-xs">PF</div>
                        <span class="text-sm font-semibold text-slate-700 hidden md:inline">Prof. Faculty</span>
                    </div>
                </div>
            </header>

            <!-- Dashboard Body Grid -->
            <div class="p-6 sm:p-8 grid grid-cols-1 lg:grid-cols-3 gap-6 flex-1">
                
                <!-- Left Column (2 Spans): Standings / Recent Activities Table & Next Game equivalent -->
                <div class="lg:col-span-2 space-y-6">
                    
                    <!-- Next Activity Widget Card -->
                    <div class="glass-panel p-6 rounded-3xl shadow-xl border border-white/70 relative overflow-hidden">
                        <div class="flex justify-between items-center mb-3">
                            <div class="flex items-center space-x-2">
                                <span class="bg-teal-100 text-teal-800 text-xs font-bold px-3 py-1 rounded-full"><i class="fa-solid fa-clock mr-1"></i> Active Deadline</span>
                                <span class="text-xs text-slate-500">Auto-Penalty Active (-1 Mark/Day)</span>
                            </div>
                            <a href="deploy_activity.php" class="text-xs font-bold text-teal-700 hover:underline">Deploy New →</a>
                        </div>
                        <div class="flex items-center justify-between bg-white/50 p-4 rounded-2xl border border-white/60">
                            <div>
                                <h3 class="text-lg font-bold text-slate-900">Final Project Guidelines & Setup</h3>
                                <p class="text-xs text-slate-500 mt-0.5">Subject: CS101 • Unit 4 Assessment</p>
                            </div>
                            <div class="text-right">
                                <span class="text-xs text-slate-400 block">Due Date</span>
                                <span class="text-sm font-bold text-rose-600">Aug 10, 2026</span>
                            </div>
                        </div>
                    </div>

                    <!-- Activities Table (Styled like Standings in Reference Image) -->
                    <div class="glass-panel p-6 rounded-3xl shadow-xl border border-white/70">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-base font-bold text-slate-900">Deployed Activities & Performance</h3>
                            <a href="submissions.php" class="text-xs font-bold text-teal-700 hover:underline">View All</a>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                               <thead>
                                    <tr class="text-xs text-slate-500 border-b border-slate-200/60 pb-2">
                                        <th class="pb-3 font-semibold">#</th>
                                        <th class="pb-3 font-semibold">ACTIVITY TITLE</th>
                                        <th class="pb-3 font-semibold text-center">SUBJ</th>
                                        <th class="pb-3 font-semibold text-center">MAX MARKS</th>
                                        <th class="pb-3 font-semibold text-right">DEADLINE</th>
                                    </tr>
                               </thead>
                               <tbody class="text-sm divide-y divide-slate-200/40">
                                    <?php if (!empty($activities)): ?>
                                        <?php $i = 1; foreach($activities as $act): ?>
                                            <tr class="hover:bg-white/40 transition">
                                                <td class="py-3 font-bold text-slate-500"><?php echo $i++; ?></td>
                                                <td class="py-3 font-semibold text-slate-800 flex items-center space-x-2">
                                                    <span class="w-2 h-2 rounded-full bg-teal-500"></span>
                                                    <span><?php echo htmlspecialchars($act['title']); ?></span>
                                                </td>
                                                <td class="py-3 text-center text-slate-600 font-medium"><?php echo htmlspecialchars($act['subject_code']); ?></td>
                                                <td class="py-3 text-center text-slate-600 font-bold"><?php echo htmlspecialchars($act['max_marks']); ?></td>
                                                <td class="py-3 text-right text-xs font-semibold text-teal-800"><?php echo date('M d, Y', strtotime($act['deadline'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="py-6 text-center text-slate-500 text-xs">No activities deployed yet. Use <a href="deploy_activity.php" class="text-teal-700 font-bold underline">Deploy Activity</a> to add one.</td>
                                        </tr>
                                    <?php endif; ?>
                               </tbody>
                            </table>
                        </div>
                    </div>

                </div>

                <!-- Right Column (1 Span): Stats Widgets & Banner Card -->
                <div class="space-y-6">
                    
                    <!-- Games / Submission Statistic Card -->
                    <div class="glass-panel p-6 rounded-3xl shadow-xl border border-white/70">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-sm font-bold text-slate-900">Submission Rate Statistic</h3>
                            <span class="text-xs text-teal-700 font-bold">Live Data</span>
                        </div>
                        <div class="space-y-2">
                            <div class="flex justify-between text-xs font-semibold text-slate-600">
                                <span>On-Time Submissions</span>
                                <span class="text-teal-700">84%</span>
                            </div>
                            <div class="w-full bg-slate-200/80 h-2.5 rounded-full overflow-hidden">
                                <div class="bg-gradient-to-r from-teal-500 to-emerald-500 h-full rounded-full" style="width: 84%"></div>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-2 mt-4 pt-4 border-t border-slate-200/50 text-center">
                            <div class="bg-white/40 p-2 rounded-xl">
                                <span class="text-xs text-slate-400 block">Total</span>
                                <span class="text-base font-bold text-slate-800"><?php echo $total_activities; ?></span>
                            </div>
                            <div class="bg-white/40 p-2 rounded-xl">
                                <span class="text-xs text-slate-400 block">On-Time</span>
                                <span class="text-base font-bold text-emerald-600">18</span>
                            </div>
                            <div class="bg-white/40 p-2 rounded-xl">
                                <span class="text-xs text-slate-400 block">Late</span>
                                <span class="text-base font-bold text-rose-500">3</span>
                            </div>
                        </div>
                    </div>

                    <!-- Small Stat Cards Grid -->
                    <div class="grid grid-cols-2 gap-4">
                        <div class="glass-panel p-4 rounded-2xl shadow-md border border-white/70">
                            <div class="w-8 h-8 rounded-xl bg-purple-500/20 text-purple-600 flex items-center justify-center mb-2">
                                <i class="fa-solid fa-users"></i>
                            </div>
                            <span class="text-xs text-slate-500 block">Active Students</span>
                            <span class="text-lg font-black text-slate-900">142</span>
                        </div>
                        <div class="glass-panel p-4 rounded-2xl shadow-md border border-white/70">
                            <div class="w-8 h-8 rounded-xl bg-pink-500/20 text-pink-600 flex items-center justify-center mb-2">
                                <i class="fa-solid fa-star"></i>
                            </div>
                            <span class="text-xs text-slate-500 block">Avg Class Score</span>
                            <span class="text-lg font-black text-slate-900">4.4 / 5</span>
                        </div>
                    </div>

                    <!-- Bottom Action Banner (Matched with Image style) -->
                    <div class="bg-gradient-to-r from-teal-800 to-teal-900 p-6 rounded-3xl shadow-xl text-white relative overflow-hidden flex flex-col justify-between">
                        <div class="absolute -right-10 -bottom-10 w-32 h-32 bg-emerald-500/20 rounded-full blur-2xl"></div>
                        <div>
                            <span class="text-xs text-teal-300 font-semibold uppercase tracking-wider">Don't Forget</span>
                            <h4 class="text-base font-bold mt-1">Setup auto-marks & check late penalty rules</h4>
                        </div>
                        <div class="mt-4">
                            <a href="deploy_activity.php" class="inline-block bg-white text-teal-900 px-4 py-2 rounded-xl text-xs font-bold shadow hover:bg-teal-50 transition">
                                Deploy new activity
                            </a>
                        </div>
                    </div>

                </div>

            </div>

        </main>

    </div>

</body>
</html>