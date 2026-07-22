<?php
// group2/subjects.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subjects - Group 2 SAAES</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="app-container">
        <?php include __DIR__ . '/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-bar">
                <div>
                    <h1 class="page-title">Assigned Subjects</h1>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">Manage course curriculum, syllabus units, and assigned activities.</p>
                </div>
                <button class="btn btn-primary">+ Add New Subject</button>
            </header>

            <section class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">📚</div>
                    <div class="stat-info">
                        <h4>CS301 - Data Structures</h4>
                        <div style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.25rem;">Semester V • 60 Enrolled</div>
                        <div style="margin-top: 0.75rem;"><span class="badge badge-primary">4 Units Active</span></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">💻</div>
                    <div class="stat-info">
                        <h4>CS302 - DBMS</h4>
                        <div style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.25rem;">Semester V • 58 Enrolled</div>
                        <div style="margin-top: 0.75rem;"><span class="badge badge-primary">3 Units Active</span></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🌐</div>
                    <div class="stat-info">
                        <h4>CS305 - Web Engineering</h4>
                        <div style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.25rem;">Semester V • 30 Enrolled</div>
                        <div style="margin-top: 0.75rem;"><span class="badge badge-primary">5 Units Active</span></div>
                    </div>
                </div>
            </section>

            <section class="content-panel">
                <div class="panel-header">
                    <h2 class="panel-title">Subject Units & Activity Overview</h2>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject Title</th>
                                <th>Department</th>
                                <th>Students</th>
                                <th>Activities Deployed</th>
                                <th>Avg Class Score</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="badge badge-primary">CS301</span></td>
                                <td>Data Structures & Algorithms</td>
                                <td>Computer Science</td>
                                <td>60</td>
                                <td>8 Tasks</td>
                                <td>84.5%</td>
                                <td><button class="btn btn-secondary" style="padding: 0.3rem 0.75rem; font-size: 0.8rem;">Manage</button></td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-primary">CS302</span></td>
                                <td>Database Management Systems</td>
                                <td>Computer Science</td>
                                <td>58</td>
                                <td>6 Tasks</td>
                                <td>79.2%</td>
                                <td><button class="btn btn-secondary" style="padding: 0.3rem 0.75rem; font-size: 0.8rem;">Manage</button></td>
                            </tr>
                            <tr>
                                <td><span class="badge badge-primary">CS305</span></td>
                                <td>Web Engineering & Design</td>
                                <td>Information Technology</td>
                                <td>30</td>
                                <td>10 Tasks</td>
                                <td>91.0%</td>
                                <td><button class="btn btn-secondary" style="padding: 0.3rem 0.75rem; font-size: 0.8rem;">Manage</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
