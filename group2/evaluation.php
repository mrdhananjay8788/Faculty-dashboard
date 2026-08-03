<?php
// group2/evaluation.php
$sub_id = isset($_GET['id']) ? htmlspecialchars($_GET['id']) : '9021';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Evaluation - Group 2 SAAES</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="app-container">
        <?php include __DIR__ . '/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-bar">
                <div>
                    <h1 class="page-title">Evaluate Submission #<?php echo $sub_id; ?></h1>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">Review submission files, apply grading rubrics, and publish marks.</p>
                </div>
                <a href="submissions.php" class="btn btn-secondary">← Back to Submissions</a>
            </header>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
                <section class="content-panel">
                    <div class="panel-header">
                        <h2 class="panel-title">Submission Details & Attachment</h2>
                        <span class="badge badge-primary">CS301 - Data Structures</span>
                    </div>
                    <div style="margin-bottom: 1.5rem; line-height: 1.6;">
                        <p><strong>Student Name:</strong> Aarav Sharma (Roll: CS-2024-01)</p>
                        <p><strong>Assignment:</strong> Lab Assignment 4 - Binary Search Tree Implementation</p>
                        <p><strong>Submitted Date:</strong> 2026-07-22 14:30 IST</p>
                        <p><strong>Attachment:</strong> <a href="#" style="color: var(--accent-color); text-decoration: underline;">aarav_lab4.zip (2.4 MB)</a></p>
                    </div>
                    <div style="background: rgba(0,0,0,0.3); border: 1px solid var(--card-border); padding: 1rem; border-radius: 12px; font-family: monospace; font-size: 0.85rem; color: #a5b4fc; max-height: 250px; overflow-y: auto;">
                        // Binary Search Tree Implementation - Student Code Preview<br>
                        #include &lt;iostream&gt;<br>
                        using namespace std;<br><br>
                        struct Node {<br>
                        &nbsp;&nbsp;&nbsp;&nbsp;int data;<br>
                        &nbsp;&nbsp;&nbsp;&nbsp;Node* left;<br>
                        &nbsp;&nbsp;&nbsp;&nbsp;Node* right;<br>
                        };<br><br>
                        // Insert Function Implementation<br>
                        Node* insert(Node* root, int val) { ... }
                    </div>
                </section>

                <section class="content-panel">
                    <div class="panel-header">
                        <h2 class="panel-title">Grading Form</h2>
                    </div>
                    <form action="#" method="POST" style="display: flex; flex-direction: column; gap: 1rem;">
                        <div>
                            <label style="font-size: 0.85rem; color: var(--text-secondary); display: block; margin-bottom: 0.4rem;">Max Marks</label>
                            <input type="text" value="100" disabled style="width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--card-border); color: #fff; padding: 0.6rem; border-radius: 8px;">
                        </div>
                        <div>
                            <label style="font-size: 0.85rem; color: var(--text-secondary); display: block; margin-bottom: 0.4rem;">Marks Obtained</label>
                            <input type="number" name="marks" min="0" max="100" placeholder="Enter score (e.g. 90)" required style="width: 100%; background: rgba(255,255,255,0.08); border: 1px solid var(--accent-color); color: #fff; padding: 0.6rem; border-radius: 8px; font-weight: 600;">
                        </div>
                        <div>
                            <label style="font-size: 0.85rem; color: var(--text-secondary); display: block; margin-bottom: 0.4rem;">Auto Penalty Deduction</label>
                            <input type="text" value="0% (On Time)" disabled style="width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--card-border); color: #34d399; padding: 0.6rem; border-radius: 8px; font-weight: 600;">
                        </div>
                        <div>
                            <label style="font-size: 0.85rem; color: var(--text-secondary); display: block; margin-bottom: 0.4rem;">Faculty Feedback / Comments</label>
                            <textarea name="feedback" rows="4" placeholder="Enter feedback for student..." style="width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--card-border); color: #fff; padding: 0.6rem; border-radius: 8px; resize: vertical;"></textarea>
                        </div>
                        <button type="button" class="btn btn-primary" style="margin-top: 0.5rem; justify-content: center;" onclick="alert('Score & feedback published successfully!');">Save & Publish Marks</button>
                    </form>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
