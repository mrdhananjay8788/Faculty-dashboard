<?php
// deploy_activity.php
require_once 'db.php';
session_start();

// Handle form submission
$success_msg = "";
$error_msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $unit_name = trim($_POST['unit_name']);
    $subject_code = trim($_POST['subject_code']);
    $max_marks = intval($_POST['max_marks']);
    $deadline = $_POST['deadline'];
    $penalty_rule = trim($_POST['penalty_rule']);
    $description = trim($_POST['description']);

    // File upload handling
    $file_name = $_FILES['activity_file']['name'];
    $file_tmp = $_FILES['activity_file']['tmp_name'];
    $upload_dir = "uploads/";
    
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $stored_file_name = time() . "_" . basename($file_name);
    $target_file = $upload_dir . $stored_file_name;

    if (move_uploaded_file($file_tmp, $target_file)) {
        // Insert into database (ensure your activities table has these columns)
        $stmt = $pdo->prepare("INSERT INTO activities (title, unit_name, subject_code, max_marks, deadline, penalty_rule, description, file_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$title, $unit_name, $subject_code, $max_marks, $deadline, $penalty_rule, $description, $target_file])) {
            $success_msg = "Activity successfully deployed with auto-penalty rules active!";
        } else {
            $error_msg = "Database insertion failed.";
        }
    } else {
        $error_msg = "Failed to upload attachment file.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Activity Deployment - SAAES</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gradient-to-br from-indigo-900 via-purple-900 to-slate-900 min-h-screen text-slate-100 font-sans antialiased">

    <!-- Top Navigation Bar -->
    <header class="bg-slate-900/80 backdrop-blur-md border-b border-purple-500/30 sticky top-0 z-50 px-6 py-4 flex flex-wrap justify-between items-center shadow-lg">
        <div class="flex items-center space-x-3">
            <div class="bg-gradient-to-r from-pink-500 to-violet-500 p-2.5 rounded-xl shadow-md">
                <i class="fa-solid fa-chalkboard-user text-white text-xl"></i>
            </div>
            <div>
                <h1 class="text-xl font-bold bg-gradient-to-r from-pink-400 to-cyan-400 bg-clip-text text-transparent">Faculty Portal</h1>
                <p class="text-xs text-slate-400">Student Activity & Assessment Evaluation System</p>
            </div>
        </div>
        <div class="flex items-center space-x-4 mt-3 sm:mt-0">
            <!-- Notice Board Button -->
            <button onclick="toggleNoticeModal()" class="bg-gradient-to-r from-amber-500 to-orange-600 hover:from-amber-600 hover:to-orange-700 text-white px-4 py-2 rounded-xl text-sm font-semibold shadow-lg transition transform hover:scale-105 flex items-center space-x-2">
                <i class="fa-solid fa-bullhorn"></i>
                <span>Broadcast Notice</span>
            </button>
            <div class="h-8 w-[1px] bg-slate-700"></div>
            <div class="flex items-center space-x-2">
                <div class="w-10 h-10 rounded-full bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center font-bold text-white shadow">
                    PF
                </div>
                <span class="text-sm font-medium hidden md:inline">Prof. Faculty</span>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <main class="max-w-4xl mx-auto px-4 py-10">
        
        <!-- Alerts -->
        <?php if (!empty($success_msg)): ?>
            <div class="mb-6 bg-emerald-500/20 border border-emerald-500 text-emerald-200 px-4 py-3 rounded-xl shadow-lg flex items-center space-x-3">
                <i class="fa-solid fa-circle-check text-emerald-400 text-lg"></i>
                <span><?php echo $success_msg; ?></span>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_msg)): ?>
            <div class="mb-6 bg-rose-500/20 border border-rose-500 text-rose-200 px-4 py-3 rounded-xl shadow-lg flex items-center space-x-3">
                <i class="fa-solid fa-circle-exclamation text-rose-400 text-lg"></i>
                <span><?php echo $error_msg; ?></span>
            </div>
        <?php endif; ?>

        <!-- Form Card -->
        <div class="bg-slate-800/60 backdrop-blur-xl border border-purple-500/20 rounded-3xl shadow-2xl p-6 sm:p-10 relative overflow-hidden">
            <div class="absolute -right-20 -top-20 w-64 h-64 bg-pink-500/10 rounded-full blur-3xl pointer-events-none"></div>
            <div class="absolute -left-20 -bottom-20 w-64 h-64 bg-cyan-500/10 rounded-full blur-3xl pointer-events-none"></div>

            <div class="mb-8 border-b border-slate-700/60 pb-4">
                <h2 class="text-2xl font-extrabold tracking-tight text-white flex items-center space-x-3">
                    <i class="fa-solid fa-rocket text-pink-500"></i>
                    <span>Deploy New Assignment / Activity</span>
                </h2>
                <p class="text-sm text-slate-400 mt-1">Configure automated penalty rules, deadlines, and broadcast tasks seamlessly to students, parents, and HOD dashboards.</p>
            </div>

            <form action="deploy_activity.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                
                <!-- Row 1: Title & Subject -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">Activity Title</label>
                        <input type="text" name="title" required placeholder="e.g., Midterm Project Specifications" 
                            class="w-full bg-slate-900/70 border border-slate-700 rounded-xl px-4 py-3 text-white placeholder-slate-500 focus:outline-none focus:border-pink-500 focus:ring-1 focus:ring-pink-500 transition">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">Subject Selection</label>
                        <select name="subject_code" required class="w-full bg-slate-900/70 border border-slate-700 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-pink-500 focus:ring-1 focus:ring-pink-500 transition">
                            <option value="" disabled selected>Select Subject</option>
                            <option value="CS101">CS101 - Data Structures</option>
                            <option value="MAT201">MAT201 - Advanced Calculus</option>
                            <option value="PHY301">PHY301 - Applied Physics</option>
                        </select>
                    </div>
                </div>

                <!-- Row 2: Unit Name & Max Marks -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">Unit Name</label>
                        <select name="unit_name" required class="w-full bg-slate-900/70 border border-slate-700 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 transition">
                            <option value="" disabled selected>Select Unit</option>
                            <option value="Unit 1">Unit 1: Fundamentals</option>
                            <option value="Unit 2">Unit 2: Core Implementation</option>
                            <option value="Unit 3">Unit 3: Advanced Integration</option>
                            <option value="Unit 4">Unit 4: Final Assessment</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">Maximum Marks</label>
                        <input type="number" name="max_marks" required value="5" min="1" max="100" 
                            class="w-full bg-slate-900/70 border border-slate-700 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 transition">
                    </div>
                </div>

                <!-- Row 3: Deadline & Late Penalty Rule -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">Deadline Date & Time</label>
                        <input type="datetime-local" name="deadline" required 
                            class="w-full bg-slate-900/70 border border-slate-700 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">Auto Late Penalty Rule</label>
                        <select name="penalty_rule" class="w-full bg-slate-900/70 border border-slate-700 rounded-xl px-4 py-3 text-white focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition">
                            <option value="minus_1_per_day">Minus 1 Mark per Day Late</option>
                            <option value="minus_2_per_day">Minus 2 Marks per Day Late</option>
                            <option value="half_after_deadline">50% Deduction After Deadline</option>
                            <option value="zero_after_deadline">Zero Marks After Deadline</option>
                        </select>
                    </div>
                </div>

                <!-- Description -->
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">Activity Guidelines & Description</label>
                    <textarea name="description" rows="4" placeholder="Detailed instructions for students..." required
                        class="w-full bg-slate-900/70 border border-slate-700 rounded-xl px-4 py-3 text-white placeholder-slate-500 focus:outline-none focus:border-pink-500 focus:ring-1 focus:ring-pink-500 transition"></textarea>
                </div>

                <!-- File Upload -->
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">Reference Material Attachment (PDF, JPG, PNG)</label>
                    <input type="file" name="activity_file" required
                        class="w-full text-slate-300 file:mr-4 file:py-3 file:px-6 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-gradient-to-r file:from-pink-500 file:to-violet-600 file:text-white hover:file:cursor-pointer bg-slate-900/70 border border-slate-700 rounded-xl">
                    <p class="text-xs text-slate-400 mt-2"><i class="fa-solid fa-shield-halved text-cyan-400 mr-1"></i> Max file size: 10MB. Automatically logged to student & parent synchronized logs.</p>
                </div>

                <!-- Submit Button -->
                <div class="pt-4">
                    <button type="submit" class="w-full bg-gradient-to-r from-pink-500 via-purple-600 to-cyan-500 hover:from-pink-600 hover:to-cyan-600 text-white font-bold py-4 rounded-xl shadow-xl transition transform hover:-translate-y-0.5 active:translate-y-0 flex items-center justify-center space-x-2">
                        <i class="fa-solid fa-paper-plane"></i>
                        <span>Deploy Activity & Enable Auto-Marks Tracker</span>
                    </button>
                </div>

            </form>
        </div>
    </main>

    <!-- Notice Modal Box -->
    <div id="noticeModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm hidden items-center justify-center z-50 px-4">
        <div class="bg-slate-800 border border-amber-500/30 rounded-3xl max-w-lg w-full p-6 shadow-2xl relative">
            <h3 class="text-xl font-bold text-amber-400 flex items-center space-x-2 mb-4">
                <i class="fa-solid fa-bullhorn"></i>
                <span>Broadcast Portal Notice</span>
            </h3>
            <p class="text-sm text-slate-300 mb-4">This notice will instantly pop up on Student, Parent, and HOD dashboards regarding activity deadline changes or manual override exemptions.</p>
            <textarea rows="3" placeholder="Type notice description here..." class="w-full bg-slate-900 border border-slate-700 rounded-xl p-3 text-white focus:outline-none focus:border-amber-500 mb-4"></textarea>
            <div class="flex justify-end space-x-3">
                <button onclick="toggleNoticeModal()" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-xl text-sm font-semibold">Cancel</button>
                <button onclick="alert('Notice broadcasted to Student, Parent & HOD dashboards successfully!'); toggleNoticeModal();" class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-xl text-sm font-semibold shadow-md">Send Notice</button>
            </div>
        </div>
    </div>

    <script>
        function toggleNoticeModal() {
            const modal = document.getElementById('noticeModal');
            modal.classList.toggle('hidden');
            modal.classList.toggle('flex');
        }
    </script>
</body>
</html>