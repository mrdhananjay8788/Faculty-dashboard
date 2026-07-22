<?php
// group2/settings.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Settings - Group 2 SAAES</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="app-container">
        <?php include __DIR__ . '/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-bar">
                <div>
                    <h1 class="page-title">Assessment & System Settings</h1>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">Configure auto-penalty rules, default deadline policies, and notifications.</p>
                </div>
                <button class="btn btn-primary" onclick="alert('Settings saved successfully!');">Save Settings</button>
            </header>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <section class="content-panel">
                    <div class="panel-header">
                        <h2 class="panel-title">Auto-Penalty & Grace Rules</h2>
                    </div>
                    <form style="display: flex; flex-direction: column; gap: 1.25rem;">
                        <div>
                            <label style="font-size: 0.85rem; color: var(--text-secondary); display: block; margin-bottom: 0.4rem;">Late Penalty Rate per Day (%)</label>
                            <input type="number" value="10" min="0" max="50" style="width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--card-border); color: #fff; padding: 0.6rem; border-radius: 8px;">
                        </div>
                        <div>
                            <label style="font-size: 0.85rem; color: var(--text-secondary); display: block; margin-bottom: 0.4rem;">Max Penalty Cap (%)</label>
                            <input type="number" value="50" min="0" max="100" style="width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--card-border); color: #fff; padding: 0.6rem; border-radius: 8px;">
                        </div>
                        <div>
                            <label style="font-size: 0.85rem; color: var(--text-secondary); display: block; margin-bottom: 0.4rem;">Grace Period (Hours)</label>
                            <input type="number" value="2" min="0" max="24" style="width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--card-border); color: #fff; padding: 0.6rem; border-radius: 8px;">
                        </div>
                    </form>
                </section>

                <section class="content-panel">
                    <div class="panel-header">
                        <h2 class="panel-title">Notifications & Security</h2>
                    </div>
                    <form style="display: flex; flex-direction: column; gap: 1.25rem;">
                        <div>
                            <label style="font-size: 0.85rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" checked style="accent-color: var(--accent-color);">
                                Email alert on new student submission
                            </label>
                        </div>
                        <div>
                            <label style="font-size: 0.85rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" checked style="accent-color: var(--accent-color);">
                                Auto-notify students upon score publication
                            </label>
                        </div>
                        <div>
                            <label style="font-size: 0.85rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" checked style="accent-color: var(--accent-color);">
                                Weekly summary report broadcast
                            </label>
                        </div>
                    </form>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
