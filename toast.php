<?php
// Уведомления
?>

<link rel="stylesheet" href="toast.css">
<div id="toast-container"></div>

<script>
    const Toast = {
        defaultDuration: 5000,

        show(message, type = 'success', title = null, duration = null) {
            const container = document.getElementById('toast-container');
            if (!container) return;

            const toast = document.createElement('div');
            toast.className = `toast ${type}`;

            const icons = {
                success: '✓',
                error: '✕',
                info: 'i',
                warning: '!'
            };

            const titles = {
                success: 'Отлично',
                error: 'Ошибка',
                info: 'Информация',
                warning: 'Предупреждение'
            };

            toast.innerHTML = `
            <div class="toast-icon">${icons[type] || 'i'}</div>
            <div class="toast-content">
                <div class="toast-title">${title || titles[type] || 'Информация'}</div>
                <div class="toast-message">${message}</div>
            </div>
            <button class="toast-close" onclick="Toast.close(this.parentElement)">×</button>
        `;

            container.appendChild(toast);

            let hideTimer;

            const hide = () => {
                toast.classList.add('hiding');
                setTimeout(() => {
                    if (toast.parentElement) {
                        toast.remove();
                    }
                }, 400);
            };

            const startTimer = () => {
                const toastDuration = duration || this.defaultDuration;
                hideTimer = setTimeout(hide, toastDuration);
            };

            const stopTimer = () => {
                if (hideTimer) {
                    clearTimeout(hideTimer);
                }
            };

            toast.addEventListener('mouseenter', stopTimer);
            toast.addEventListener('mouseleave', startTimer);

            startTimer();
        },

        close(toastElement) {
            if (toastElement) {
                toastElement.classList.add('hiding');
                setTimeout(() => {
                    if (toastElement.parentElement) {
                        toastElement.remove();
                    }
                }, 400);
            }
        },

        success(message, title = null, duration = null) {
            this.show(message, 'success', title, duration);
        },

        error(message, title = null, duration = null) {
            this.show(message, 'error', title, duration);
        },

        info(message, title = null, duration = null) {
            this.show(message, 'info', title, duration);
        },

        warning(message, title = null, duration = null) {
            this.show(message, 'warning', title, duration);
        }
    };

    function showToast(message, type = 'success', title = null) {
        Toast.show(message, type, title);
    }

    <?php if (isset($msg) && $msg): ?>
        Toast.show(<?= json_encode($msg) ?>, 'success');
    <?php endif; ?>
    <?php if (isset($err) && $err): ?>
        Toast.show(<?= json_encode($err) ?>, 'error');
    <?php endif; ?>
    <?php if (isset($success_msg) && $success_msg): ?>
        Toast.show(<?= json_encode($success_msg) ?>, 'success');
    <?php endif; ?>
    <?php if (isset($err_msg) && $err_msg): ?>
        Toast.show(<?= json_encode($err_msg) ?>, 'error');
    <?php endif; ?>
</script>