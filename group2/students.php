<?php
// group2/students.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students Roster - Group 2 SAAES</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="app-container">
        <?php include __DIR__ . '/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-bar">
                <div>
                    <h1 class="page-title">Student Directory</h1>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">View enrolled students, submission completion rates, and individual performance.</p>
                </div>
                <button class="btn btn-secondary">📥 Export Roster CSV</button>
            </header>

            <section class="content-panel">
                <div class="panel-header">
                    <h2 class="panel-title">Enrolled Students (148 Total)</h2>
                    <input type="text" placeholder="Search student name or roll..." style="background: rgba(255,255,255,0.05); border: 1px solid var(--card-border); color: #fff; padding: 0.5rem 1rem; border-radius: 8px; width: 260px;">
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Roll Number</th>
                                <th>Student Name</th>
                                <th>Email Address</th>
                                <th>Division</th>
                                <th>Submissions Complete</th>
                                <th>Overall Grade</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>CS-2024-01</td>
                                <td>Aarav Sharma</td>
                                <td>aarav.sharma@example.edu</td>
                                <td>Div A</td>
                                <td>14 / 15</td>
                                <td><span class="badge badge-success">88.5% (A)</span></td>
                                <td><button class="btn btn-secondary" style="padding: 0.3rem 0.75rem; font-size: 0.8rem;">Profile</button></td>
                            </tr>
                            <tr>
                                <td>CS-2024-05</td>
                                <td>Ananya Patel</td>
                                <td>ananya.patel@example.edu</td>
                                <td>Div A</td>
                                <td>15 / 15</td>
                                <td><span class="badge badge-success">94.0% (A+)</span></td>
                                <td><button class="btn btn-secondary" style="padding: 0.3rem 0.75rem; font-size: 0.8rem;">Profile</button></td>
                            </tr>
                            <tr>
                                <td>CS-2024-12</td>
                                <td>Rohan Mehta</td>
                                <td>rohan.mehta@example.edu</td>
                                <td>Div B</td>
                                <td>11 / 15</td>
                                <td><span class="badge badge-warning">72.0% (B)</span></td>
                                <td><button class="btn btn-secondary" style="padding: 0.3rem 0.75rem; font-size: 0.8rem;">Profile</button></td>
                            </tr>
                            <tr>
                                <td>CS-2024-19</td>
                                <td>Sneha Kulkarni</td>
                                <td>sneha.k@example.edu</td>
                                <td>Div A</td>
                                <td>15 / 15</td>
                                <td><span class="badge badge-success">91.5% (A+)</span></td>
                                <td><button class="btn btn-secondary" style="padding: 0.3rem 0.75rem; font-size: 0.8rem;">Profile</button></td>
                            </tr>
                            <tr>
                                <td>CS-2024-27</td>
                                <td>Vikram Singh</td>
                                <td>vikram.singh@example.edu</td>
                                <td>Div B</td>
                                <td>9 / 15</td>
                                <td><span class="badge badge-danger">58.0% (C)</span></td>
                                <td><button class="btn btn-secondary" style="padding: 0.3rem 0.75rem; font-size: 0.8rem;">Profile</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
