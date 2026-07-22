<?php
// group2/profile.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Profile - Group 2 SAAES</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="app-container">
        <?php include __DIR__ . '/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-bar">
                <div>
                    <h1 class="page-title">Faculty Profile</h1>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">Manage personal details, contact info, and department assignments.</p>
                </div>
                <button class="btn btn-primary">Edit Profile</button>
            </header>

            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 1.5rem;">
                <section class="content-panel" style="text-align: center;">
                    <div class="avatar" style="width: 90px; height: 90px; font-size: 2.2rem; margin: 0 auto 1rem auto; box-shadow: 0 4px 20px rgba(139, 92, 246, 0.4);">
                        PF
                    </div>
                    <h2 style="font-size: 1.3rem; font-weight: 700;">Prof. Faculty</h2>
                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 0.25rem;">Associate Professor</p>
                    <div style="margin-top: 1rem;"><span class="badge badge-primary">Computer Science Dept</span></div>
                    <div style="margin-top: 1.5rem; text-align: left; font-size: 0.9rem; color: var(--text-secondary); line-height: 1.8;">
                        <p>📧 <strong>Email:</strong> faculty@institution.edu</p>
                        <p>📞 <strong>Phone:</strong> +91 98765 43210</p>
                        <p>🏢 <strong>Office:</strong> Academic Block B, Room 304</p>
                    </div>
                </section>

                <section class="content-panel">
                    <div class="panel-header">
                        <h2 class="panel-title">Academic & Teaching Load</h2>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                        <div>
                            <h4 style="color: var(--accent-color); margin-bottom: 0.5rem;">Current Academic Session</h4>
                            <p style="font-size: 0.9rem; color: var(--text-secondary);">Academic Year 2026-2027 • Odd Semester (III & V)</p>
                        </div>
                        <div>
                            <h4 style="color: var(--accent-color); margin-bottom: 0.5rem;">Assigned Courses</h4>
                            <ul style="list-style: none; font-size: 0.9rem; line-height: 1.8;">
                                <li>• <strong>CS301:</strong> Data Structures & Algorithms (60 Students)</li>
                                <li>• <strong>CS302:</strong> Database Management Systems (58 Students)</li>
                                <li>• <strong>CS305:</strong> Web Engineering (30 Students)</li>
                            </ul>
                        </div>
                        <div>
                            <h4 style="color: var(--accent-color); margin-bottom: 0.5rem;">System Roles & Privileges</h4>
                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.5rem;">
                                <span class="badge badge-success">Activity Creator</span>
                                <span class="badge badge-primary">Evaluator</span>
                                <span class="badge badge-warning">Notice Publisher</span>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
