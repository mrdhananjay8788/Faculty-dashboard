<!-- END SESSION BACK-BUTTON CONFIRMATION MODAL -->
<div id="modalEndSessionLogout" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); z-index:99999; align-items:center; justify-content:center;">
    <div style="background:#1e293b; border:1px solid #334155; border-top:4px solid #ef4444; border-radius: 4px; max-width:440px; width:90%; padding:1.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align:center; font-family: system-ui, -apple-system, sans-serif;">
        <div style="width:60px; height:60px; background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3); color:#ef4444; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem; font-size:1.6rem;">
            <i class="fa-solid fa-right-from-bracket"></i>
        </div>
        <h3 style="color:#fff; font-size:1.3rem; font-weight:700; margin:0 0 0.5rem 0;">End Session & Log Out?</h3>
        <p style="color:#94a3b8; font-size:0.9rem; margin:0 0 1.5rem 0; line-height:1.5;">
            You have navigated back to the main dashboard. Would you like to end your current session and return to the main home page?
        </p>
        <div style="display:flex; gap:0.75rem; justify-content:center;">
            <button type="button" onclick="closeEndSessionLogoutModal()" style="padding:0.65rem 1.2rem; background:transparent; border:1px solid #475569; color:#e2e8f0; border-radius: 4px; font-weight:600; font-size:0.9rem; cursor:pointer; flex:1; transition: background 0.2s;">
                Cancel / Stay
            </button>
            <?php
            $is_in_auth = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/auth/') !== false;
            $logout_path = $is_in_auth ? 'logout.php' : 'auth/logout.php';
            ?>
            <a href="<?php echo $logout_path; ?>" style="padding:0.65rem 1.2rem; background:#ef4444; color:#fff; border-radius: 4px; font-weight:600; font-size:0.9rem; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:0.4rem; flex:1; transition: background 0.2s;">
                <i class="fa-solid fa-power-off"></i> Yes, Log Out
            </a>
        </div>
    </div>
</div>

<script>
(function() {
    function showEndSessionLogoutModal() {
        const modal = document.getElementById('modalEndSessionLogout');
        if (modal) modal.style.display = 'flex';
    }

    window.closeEndSessionLogoutModal = function() {
        const modal = document.getElementById('modalEndSessionLogout');
        if (modal) modal.style.display = 'none';
    };

    if (window.history && window.history.pushState) {
        window.history.pushState({ dashboard_root: true }, "", window.location.href);

        window.addEventListener('popstate', function(e) {
            const urlParams = new URLSearchParams(window.location.search);
            const currentView = urlParams.get('view');
            
            // If user is at main/root dashboard page
            if (!currentView || currentView === 'dashboard' || currentView === '') {
                window.history.pushState({ dashboard_root: true }, "", window.location.href);
                showEndSessionLogoutModal();
            }
        });
    }
})();
</script>
