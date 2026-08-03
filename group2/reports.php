<?php
// group2/reports.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Group 2 SAAES</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="app-container">
        <?php include __DIR__ . '/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-bar">
                <div>
                    <h1 class="page-title">Performance Analytics & Reports</h1>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">View student assessment metrics, grade distributions, and report summaries.</p>
                </div>
                <button class="btn btn-primary">📊 Download Report PDF</button>
            </header>

            <section class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">📈</div>
                    <div class="stat-info">
                        <h4>Class Average</h4>
                        <div class="stat-value">82.4%</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🏆</div>
                    <div class="stat-info">
                        <h4>Top Score</h4>
                        <div class="stat-value">98.5%</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⏱</div>
                    <div class="stat-info">
                        <h4>On-Time Submission</h4>
                        <div class="stat-value">91.2%</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⚠️</div>
                    <div class="stat-info">
                        <h4>Need Attention</h4>
                        <div class="stat-value">6 Students</div>
                    </div>
                </div>
            </section>

            <section class="content-panel">
                <div class="panel-header">
                    <h2 class="panel-title">Subject Performance Summary</h2>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject Title</th>
                                <th>Total Submissions</th>
                                <th>Highest Score</th>
                                <th>Lowest Score</th>
                                <th>Average Score</th>
                                <th>Pass Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="badge badge-primary">CS301</span></td>
                                <td>Data Structures & Algorithms</td>
                                <td>118</td>
                                <td>99%</td>
                                <td>45%</td>
                                <td>84.5%</td>
                                <td><span class="badge badge-success">96.6%</span></td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-primary">CS302</span></td>
                                <td>Database Management Systems</td>
                                <td>112</td>
                                <td>97%</td>
                                <td>38%</td>
                                <td>79.2%</td>
                                <td><span class="badge badge-success">92.8%</span></td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-primary">CS305</span></td>
                                <td>Web Engineering & Design</td>
                                <td>58</td>
                                <td>100%</td>
                                <td>60%</td>
                                <td>91.0%</td>
                                <td><span class="badge badge-success">100%</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
