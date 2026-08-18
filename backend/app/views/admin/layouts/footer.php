    <footer class="mt-auto py-3 px-4 text-center small text-muted border-top bg-white" style="border-color: var(--admin-border) !important; color: var(--admin-text-muted) !important;">
        © 2026 <strong>SkinSyntaxVN Commerce Intelligence Center</strong>. All rights reserved.
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // AsmrProg Theme Switcher (Light / Dark Mode Pill)
    var themeToggleBtn = document.getElementById('themeToggleBtn');
    var sunOpt = document.getElementById('themeSunOpt');
    var moonOpt = document.getElementById('themeMoonOpt');

    var applyTheme = function(theme) {
        if (theme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            if (sunOpt) sunOpt.classList.remove('active');
            if (moonOpt) moonOpt.classList.add('active');
        } else {
            document.documentElement.removeAttribute('data-theme');
            if (sunOpt) sunOpt.classList.add('active');
            if (moonOpt) moonOpt.classList.remove('active');
        }
        localStorage.setItem('skinsyntax_admin_theme', theme);
    };

    var currentTheme = localStorage.getItem('skinsyntax_admin_theme') || 'light';
    applyTheme(currentTheme);

    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', function() {
            var activeTheme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            applyTheme(activeTheme);
        });
    }

    // Sidebar Collapsible State Toggle
    var sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
    var backdrop = document.getElementById('sidebarBackdrop');

    var toggleSidebar = function() {
        if (window.innerWidth < 992) {
            document.body.classList.toggle('mobile-sidebar-open');
        } else {
            var isCollapsed = document.documentElement.classList.toggle('sidebar-is-collapsed');
            localStorage.setItem('skinsyntax_admin_collapsed', isCollapsed ? 'true' : 'false');
        }
    };

    if (sidebarToggleBtn) {
        sidebarToggleBtn.addEventListener('click', toggleSidebar);
    }

    if (backdrop) {
        backdrop.addEventListener('click', function() {
            document.body.classList.remove('mobile-sidebar-open');
        });
    }

    // Existing Live Filter Focus State Handler
    var focusStateKey = 'adminLiveFilterFocusState';
    var saveFocusState = function (payload) {
        try {
            sessionStorage.setItem(focusStateKey, JSON.stringify(payload));
        } catch (e) {}
    };

    var readFocusState = function () {
        try {
            var raw = sessionStorage.getItem(focusStateKey);
            if (!raw) return null;
            sessionStorage.removeItem(focusStateKey);
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    };

    var focusState = readFocusState();
    if (focusState && focusState.fieldName && focusState.path === window.location.pathname) {
        var escapedName = (window.CSS && typeof window.CSS.escape === 'function') ? window.CSS.escape(focusState.fieldName) : focusState.fieldName.replace(/"/g, '\\"');
        var targetField = document.querySelector('form[data-live-filter="true"][method="get" i] [name="' + escapedName + '"]');
        if (targetField && typeof targetField.focus === 'function') {
            targetField.focus({ preventScroll: true });
            if (typeof targetField.setSelectionRange === 'function') {
                var cursor = Number.isInteger(focusState.cursor) ? focusState.cursor : String(targetField.value || '').length;
                targetField.setSelectionRange(cursor, cursor);
            }
        }
    }

    var forms = document.querySelectorAll('form[data-live-filter="true"][method="get" i]');
    forms.forEach(function (form) {
        var debounceTimer = null;
        var scheduleSubmit = function (delay, target) {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(function () {
                if (!form.checkValidity()) return;
                if (target && target.name) {
                    var cursorPos = (typeof target.selectionStart === 'number') ? target.selectionStart : String(target.value || '').length;
                    saveFocusState({
                        path: window.location.pathname,
                        fieldName: target.name,
                        cursor: cursorPos,
                    });
                }
                form.requestSubmit();
            }, delay);
        };

        form.addEventListener('input', function (event) {
            var target = event.target;
            if (!target || typeof target.matches !== 'function') return;
            if (target.matches('input[type="text"], input[type="search"], input[type="number"], textarea')) {
                scheduleSubmit(350, target);
            }
        });

        form.addEventListener('change', function (event) {
            var target = event.target;
            if (!target || typeof target.matches !== 'function') return;
            if (target.matches('select, input[type="checkbox"], input[type="radio"], input[type="date"], input[type="datetime-local"], input[type="month"], input[type="week"]')) {
                scheduleSubmit(100, target);
            }
        });
    });

    // Admin Notification Mark As Read AJAX Handler
    var notificationBtn = document.getElementById('adminNotificationButton');
    var notificationBadge = document.getElementById('adminNotificationBadge');
    var markAllReadBtn = document.getElementById('markAllReadBtn');

    var markNotificationsAsSeen = function() {
        if (notificationBadge) {
            notificationBadge.style.display = 'none';
        }
        fetch('index.php?r=admin_notifications_seen', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            }
        }).then(function(res) {
            return res.json();
        }).then(function(data) {
            if (notificationBadge) {
                notificationBadge.remove();
            }
        }).catch(function(err) {
            console.log('Mark notification seen error:', err);
        });
    };

    if (notificationBtn) {
        notificationBtn.addEventListener('show.bs.dropdown', markNotificationsAsSeen);
        notificationBtn.addEventListener('click', markNotificationsAsSeen);
    }

    if (markAllReadBtn) {
        markAllReadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            markNotificationsAsSeen();
        });
    }
});
</script>
</body>
</html>
