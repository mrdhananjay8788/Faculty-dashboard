<?php
// group2/dashboard.php
$dbPath = __DIR__ . '/../db.php';
if (file_exists($dbPath)) {
    @include_once $dbPath;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Dashboard - Group 2 SAAES</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="app-container">
        <?php include __DIR__ . '/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-bar">
                <div>
                    <h1 class="page-title">Dashboard Overview</h1>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">Welcome back, Prof. Faculty! Here is your assessment summary.</p>
                </div>
                <div class="user-badge">
                    <div class="avatar">PF</div>
                    <div>
                        <div style="font-weight: 600; font-size: 0.9rem;">Prof. Faculty</div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary);">Computer Department</div>
                    </div>
                </div>
            </header>

            <section class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">📚</div>
                    <div class="stat-info">
                        <h4>Active Subjects</h4>
                        <div class="stat-value">6</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">👨‍🎓</div>
                    <div class="stat-info">
                        <h4>Total Students</h4>
                        <div class="stat-value">148</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📤</div>
                    <div class="stat-info">
                        <h4>Submissions</h4>
                        <div class="stat-value">342</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-info">
                        <h4>Evaluated</h4>
                        <div class="stat-value">298</div>
                    </div>
                </div>
            </section>

            <section class="content-panel">
                <div class="panel-header">
                    <h2 class="panel-title">Recent Activity & Submissions</h2>
                    <a href="submissions.php" class="btn btn-secondary">View All</a>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student Roll</th>
                                <th>Name</th>
                                <th>Subject Code</th>
                                <th>Assignment Title</th>
                                <th>Submitted Date</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>CS-2024-01</td>
                                <td>Aarav Sharma</td>
                                <td>CS301</td>
                                <td>Data Structures & Algorithms - Lab 4</td>
                                <td>2026-07-22 14:30</td>
                                <td><span class="badge badge-warning">Pending</span></td>
                                <td><a href="evaluation.php?id=1" class="btn btn-primary" style="padding: 0.3rem 0.75rem; font-size: 0.8rem;">Evaluate</a></td>
                            </tr>
                            <tr>
                                <td>CS-2024-05</td>
                                <td>Ananya Patel</td>
                                <td>CS302</td>
                                <td>Database Management Systems - Unit Test 2</td>
                                <td>2026-07-22 11:15</td>
                                <td><span class="badge badge-success">Evaluated</span></td>
                                <td><a href="evaluation.php?id=2" class="btn btn-secondary" style="padding: 0.3rem 0.75rem; font-size: 0.8rem;">View Score</a></td>
                            </tr>
                            <tr>
                                <td>CS-2024-12</td>
                                <td>Rohan Mehta</td>
                                <td>CS301</td>
                                <td>Data Structures & Algorithms - Lab 4</td>
                                <td>2026-07-21 18:45</td>
                                <td><span class="badge badge-danger">Late Submission</span></td>
                                <td><a href="evaluation.php?id=3" class="btn btn-primary" style="padding: 0.3rem 0.75rem; font-size: 0.8rem;">Evaluate</a></td>
                            </tr>
                            <tr>
                                <td>CS-2024-19</td>
                                <td>Sneha Kulkarni</td>
                                <td>CS305</td>
                                <td>Web Engineering - Mini Project Draft</td>
                                <td>2026-07-21 10:00</td>
                                <td><span class="badge badge-success">Evaluated</span></td>
                                <td><a href="evaluation.php?id=4" class="btn btn-secondary" style="padding: 0.3rem 0.75rem; font-size: 0.8rem;">View Score</a></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
