<?php
// group2/submissions.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submissions Review - Group 2 SAAES</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="app-container">
        <?php include __DIR__ . '/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-bar">
                <div>
                    <h1 class="page-title">Activity Submissions</h1>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">Review submitted student assignments, code files, and reports.</p>
                </div>
                <div style="display: flex; gap: 0.75rem;">
                    <button class="btn btn-secondary">Filter by Subject</button>
                    <button class="btn btn-primary">Bulk Grade</button>
                </div>
            </header>

            <section class="content-panel">
                <div class="panel-header">
                    <h2 class="panel-title">All Submitted Work (342 Submissions)</h2>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Submission ID</th>
                                <th>Student</th>
                                <th>Assignment Title</th>
                                <th>File Name</th>
                                <th>Submission Date</th>
                                <th>Auto Penalty Rule</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>#SUB-9021</td>
                                <td>Aarav Sharma (CS-2024-01)</td>
                                <td>Data Structures - Lab 4</td>
                                <td><code>aarav_lab4.zip</code></td>
                                <td>2026-07-22 14:30</td>
                                <td><span class="badge badge-success">On Time (0%)</span></td>
                                <td><span class="badge badge-warning">Pending Review</span></td>
                                <td><a href="evaluation.php?id=9021" class="btn btn-primary" style="padding: 0.3rem 0.75rem; font-size: 0.8rem;">Evaluate</a></td>
                            </tr>
                            <tr>
                                <td>#SUB-9018</td>
                                <td>Ananya Patel (CS-2024-05)</td>
                                <td>DBMS Unit Test 2</td>
                                <td><code>ananya_dbms.pdf</code></td>
                                <td>2026-07-22 11:15</td>
                                <td><span class="badge badge-success">On Time (0%)</span></td>
                                <td><span class="badge badge-success">Graded (95/100)</span></td>
                                <td><a href="evaluation.php?id=9018" class="btn btn-secondary" style="padding: 0.3rem 0.75rem; font-size: 0.8rem;">Review</a></td>
                            </tr>
                            <tr>
                                <td>#SUB-9014</td>
                                <td>Rohan Mehta (CS-2024-12)</td>
                                <td>Data Structures - Lab 4</td>
                                <td><code>rohan_lab4_v2.cpp</code></td>
                                <td>2026-07-21 18:45</td>
                                <td><span class="badge badge-danger">-10% Late (1 Day)</span></td>
                                <td><span class="badge badge-warning">Pending Review</span></td>
                                <td><a href="evaluation.php?id=9014" class="btn btn-primary" style="padding: 0.3rem 0.75rem; font-size: 0.8rem;">Evaluate</a></td>
                            </tr>
                            <tr>
                                <td>#SUB-9009</td>
                                <td>Sneha Kulkarni (CS-2024-19)</td>
                                <td>Web Eng - Mini Project</td>
                                <td><code>sneha_web_proj.zip</code></td>
                                <td>2026-07-21 10:00</td>
                                <td><span class="badge badge-success">On Time (0%)</span></td>
                                <td><span class="badge badge-success">Graded (98/100)</span></td>
                                <td><a href="evaluation.php?id=9009" class="btn btn-secondary" style="padding: 0.3rem 0.75rem; font-size: 0.8rem;">Review</a></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
