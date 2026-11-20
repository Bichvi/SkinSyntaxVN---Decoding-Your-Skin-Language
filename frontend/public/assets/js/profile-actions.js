(() => {
    'use strict';
    if (document.documentElement.dataset.profileActions) return;
    document.documentElement.dataset.profileActions = '1';

    document.querySelectorAll('[data-close-feedback]').forEach(button => {
        button.addEventListener('click', () => {
            const panel = button.closest('details');
            panel.open = false;
            panel.querySelector('summary').focus();
        });
    });

    document.querySelectorAll('[data-profile-async]').forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const button = form.querySelector('[type="submit"]');
            const status = form.querySelector('[data-profile-status]');
            button.disabled = true;
            status.textContent = 'Đang lưu…';
            try {
                const response = await fetch(form.action, {
                    method: 'POST', body: new FormData(form),
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                });
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.message || 'Chưa lưu được. Hãy thử lại.');
                if (result.avatar_url) {
                    document.querySelectorAll('[data-account-avatar]').forEach(image => {
                        image.src = result.avatar_url;
                        image.hidden = false;
                    });
                    status.textContent = 'Đã lưu ảnh đại diện.';
                } else {
                    status.textContent = 'Đã lưu đánh giá. Cảm ơn bạn!';
                }
                form.reset();
            } catch (error) {
                status.textContent = error instanceof SyntaxError ? 'Phiên đăng nhập đã đổi. Hãy tải lại trang.' : error.message;
            } finally {
                button.disabled = false;
            }
        });
    });
})();
