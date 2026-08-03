document.addEventListener('DOMContentLoaded', () => {
    // 1. Current Date in Header
    const dateElement = document.getElementById('current-date');
    if (dateElement) {
        const options = { year: 'numeric', month: 'long', day: 'numeric' };
        dateElement.textContent = new Date().toLocaleDateString(undefined, options);
    }
    
    const headerDateElement = document.getElementById('header-time-string');
    if (headerDateElement) {
        const options = { year: 'numeric', month: 'long', day: 'numeric' };
        headerDateElement.textContent = new Date().toLocaleDateString(undefined, options);
    }

    // 2. Tab Navigation Logic (if applicable)
    const navItems = document.querySelectorAll('.nav-item');
    const tabContents = document.querySelectorAll('.tab-content');

    navItems.forEach(item => {
        item.addEventListener('click', function(e) {
            if(this.dataset.tab) {
                e.preventDefault();
                navItems.forEach(nav => nav.classList.remove('active'));
                tabContents.forEach(tab => tab.classList.remove('active'));
                
                this.classList.add('active');
                let targetId = this.dataset.tab;
                // Prepend tab- if it's not present (some templates use tab-{name})
                const targetTab = document.getElementById(targetId) || document.getElementById('tab-' + targetId);
                if(targetTab) {
                    targetTab.classList.add('active');
                }
            }
        });
    });

    // Generic redirect to tab for summary cards & quick actions
    const targetTabTriggers = document.querySelectorAll('[data-target-tab]');
    targetTabTriggers.forEach(trigger => {
        trigger.addEventListener('click', function(e) {
            const targetId = this.dataset.targetTab;
            const targetNavItem = document.querySelector(`.nav-item[data-tab="${targetId}"]`);
            if (targetNavItem) {
                targetNavItem.click();
            }
        });
    });

    // 3. Profile Dropdown Logic
    const profileBtn = document.getElementById('profileBtn');
    const profileDropdown = document.getElementById('profileDropdown');

    if (profileBtn && profileDropdown) {
        profileBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown.classList.toggle('show');
        });

        document.addEventListener('click', (e) => {
            if (!profileBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
                profileDropdown.classList.remove('show');
            }
        });
    }

    // 4. Modal Logic
    const modals = {
        'viewAssignment': document.getElementById('viewAssignmentModal'),
        'viewNotice': document.getElementById('viewNoticeModal')
    };

    const actionBtns = document.querySelectorAll('.btn-secondary-sm');
    actionBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const text = this.textContent.trim().toLowerCase();
            if (text.includes('view') && this.closest('.dashboard-card').querySelector('h3').textContent.includes('Assignment')) {
                openModal('viewAssignment');
            } else if (text.includes('view') && this.closest('.dashboard-card').querySelector('h3').textContent.includes('Notices')) {
                openModal('viewNotice');
            }
        });
    });

    const closeBtns = document.querySelectorAll('.close-modal-btn');
    closeBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const modal = this.closest('.modal-overlay');
            if(modal) modal.classList.remove('show');
        });
    });

    const cancelBtns = document.querySelectorAll('.btn-secondary-outline');
    cancelBtns.forEach(btn => {
        if(btn.textContent.includes('Cancel')) {
            btn.addEventListener('click', function() {
                const modal = this.closest('.modal-overlay');
                if(modal) modal.classList.remove('show');
            });
        }
    });

    function openModal(modalId) {
        if(modals[modalId]) {
            modals[modalId].classList.add('show');
        }
    }

    // 5. Toast System
    window.showToast = function(message, type = 'info') {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = 'toast';
        
        let icon = 'fa-info-circle text-info';
        if(type === 'success') icon = 'fa-check-circle text-success';
        if(type === 'warning') icon = 'fa-exclamation-triangle text-warning';
        if(type === 'error') icon = 'fa-times-circle text-danger';

        toast.innerHTML = `<i class="fa-solid ${icon}"></i> <span>${message}</span>`;
        
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.animation = 'slideInRight 0.3s ease-out reverse forwards';
            setTimeout(() => {
                toast.remove();
            }, 300);
        }, 3000);
    };

    // 6. Extended Faculty Dashboard Charts
    if (typeof Chart !== 'undefined') {
        const chartOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } };

        // Unit-wise Average Marks (Bar Chart)
        const ctxUnit = document.getElementById('chartUnitMarks');
        if (ctxUnit) {
            new Chart(ctxUnit, {
                type: 'bar',
                data: {
                    labels: ['Unit 1', 'Unit 2', 'Unit 3', 'Unit 4', 'Unit 5', 'Unit 6'],
                    datasets: [{
                        label: 'Avg Marks',
                        data: [4.2, 4.6, 3.8, 4.1, 4.3, 4.5],
                        backgroundColor: '#0B3D91',
                        borderRadius: 4
                    }]
                },
                options: { ...chartOptions, scales: { y: { beginAtZero: true, max: 5 } } }
            });
        }

        // Submission Status (Pie Chart)
        const ctxSub = document.getElementById('chartSubStatus');
        if (ctxSub) {
            new Chart(ctxSub, {
                type: 'doughnut',
                data: {
                    labels: ['Submitted', 'Pending', 'Late'],
                    datasets: [{
                        data: [92, 28, 25],
                        backgroundColor: ['#22C55E', '#F59E0B', '#EF4444'],
                        borderWidth: 0
                    }]
                },
                options: chartOptions
            });
        }

        // Weekly Submission Trend (Line Chart)
        const ctxTrend = document.getElementById('chartWeeklyTrend');
        if (ctxTrend) {
            new Chart(ctxTrend, {
                type: 'line',
                data: {
                    labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                    datasets: [{
                        label: 'Submissions',
                        data: [45, 65, 80, 110],
                        borderColor: '#7C3AED',
                        tension: 0.3,
                        fill: false
                    }]
                },
                options: { ...chartOptions, scales: { y: { beginAtZero: true } } }
            });
        }

        // Marks Distribution (Bar Chart)
        const ctxDist = document.getElementById('chartMarksDist');
        if (ctxDist) {
            new Chart(ctxDist, {
                type: 'bar',
                data: {
                    labels: ['0-1', '1-2', '2-3', '3-4', '4-5'],
                    datasets: [{
                        label: 'Students',
                        data: [2, 5, 12, 40, 65],
                        backgroundColor: '#3B82F6',
                        borderRadius: 4
                    }]
                },
                options: { ...chartOptions, scales: { y: { beginAtZero: true } } }
            });
        }
    }

    // Populate Student Dashboard Tables
    if (window.PHP_DATA) {
        const data = window.PHP_DATA;
        
        // Populate My Activities
        const myActivitiesList = document.getElementById('my-activities-list');
        if (myActivitiesList && data.activities) {
            data.activities.forEach(act => {
                let badgeClass = 'status-upcoming';
                if (act.status === 'Completed') badgeClass = 'status-submitted';
                if (act.status === 'Pending') badgeClass = 'status-pending';
                
                const row = `<tr>
                    <td>${act.unit || ''}</td>
                    <td><strong>${act.name || act.activity}</strong></td>
                    <td>${act.faculty || 'Prof.'}</td>
                    <td>${act.duedate || act.date}</td>
                    <td><span class="status-badge ${badgeClass}">${act.status}</span></td>
                    <td class="text-right"><button class="btn btn-secondary-sm add-random-btn">View</button></td>
                </tr>`;
                myActivitiesList.insertAdjacentHTML('beforeend', row);
            });
        }

        // Populate Recent Submissions
        const recentList = document.getElementById('recent-submissions-list');
        if (recentList && data.submissions) {
            data.submissions.forEach(sub => {
                let badgeClass = sub.status === 'Late' ? 'status-pending text-danger' : 'status-submitted';
                const row = `<tr>
                    <td><strong>${sub.activity}</strong></td>
                    <td>${sub.date}</td>
                    <td><strong>${sub.marks}</strong></td>
                    <td><span class="text-muted">Feedback Given</span></td>
                    <td><span class="status-badge ${badgeClass}">${sub.status}</span></td>
                </tr>`;
                recentList.insertAdjacentHTML('beforeend', row);
            });
        }
        
        // Populate Upcoming Deadlines
        const deadlinesList = document.getElementById('deadlines-list');
        if (deadlinesList && data.deadlines) {
            data.deadlines.forEach(dl => {
                const row = `<div class="deadline-item">
                    <div class="dl-date bg-light-red">
                        <span class="dl-month">${dl.date.split(' ')[1]}</span>
                        <span class="dl-day text-danger">${dl.date.split(' ')[0]}</span>
                    </div>
                    <div class="dl-info">
                        <h5>${dl.activity}</h5>
                        <p>Remaining: <span class="text-danger font-medium">Few Days</span></p>
                    </div>
                </div>`;
                deadlinesList.insertAdjacentHTML('beforeend', row);
            });
        }

        // Populate Attendance Summary
        const attendanceList = document.getElementById('attendance-list');
        if (attendanceList && data.attendance) {
            data.attendance.forEach(att => {
                const row = `<tr>
                    <td>${att.subject}</td>
                    <td class="text-right text-success">${att.rate}%</td>
                </tr>`;
                attendanceList.insertAdjacentHTML('beforeend', row);
            });
        }

        // Add functionality to buttons to append random data
        const addRandomRow = (e) => {
            e.preventDefault();
            const units = ['Unit 1', 'Unit 2', 'Unit 3', 'Unit 4'];
            const activities = ['New Assignment', 'Lab Work', 'Quiz', 'Presentation'];
            const statuses = ['Pending', 'Submitted', 'Upcoming'];
            
            const randomUnit = units[Math.floor(Math.random() * units.length)];
            const randomAct = activities[Math.floor(Math.random() * activities.length)];
            const randomStat = statuses[Math.floor(Math.random() * statuses.length)];
            
            if (myActivitiesList) {
                const row = `<tr>
                    <td>${randomUnit}</td>
                    <td><strong>${randomAct}</strong></td>
                    <td>Random Faculty</td>
                    <td>Random Date</td>
                    <td><span class="status-badge status-pending">${randomStat}</span></td>
                    <td class="text-right"><button class="btn btn-secondary-sm add-random-btn">View</button></td>
                </tr>`;
                myActivitiesList.insertAdjacentHTML('beforeend', row);
            }
            showToast('Random data added successfully!', 'success');
        };

        // Attach to all general buttons in the student dashboard
        const actionButtons = document.querySelectorAll('.add-random-btn, #btn-view-all-activities-tab');
        actionButtons.forEach(btn => {
            btn.addEventListener('click', addRandomRow);
        });
        
        // Dynamically attached buttons
        document.body.addEventListener('click', function(e) {
            if (e.target && e.target.classList.contains('add-random-btn')) {
                addRandomRow(e);
            }
        });
    }

    // Mobile Menu Toggle Logic
    const mobileMenuBtn = document.getElementById('mobile-menu-btn');
    const sidebar = document.querySelector('.sidebar');
    
    if (mobileMenuBtn && sidebar) {
        mobileMenuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            sidebar.classList.toggle('active');
        });

        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 && !sidebar.contains(e.target) && !mobileMenuBtn.contains(e.target)) {
                sidebar.classList.remove('active');
            }
        });
    }
});
