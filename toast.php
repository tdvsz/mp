<?php
// Toast Notifications Component
?>

<!-- Toast Container -->
<div id="toast-container"></div>

<style>
/* ===================================
   TOAST NOTIFICATIONS
   =================================== */
#toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 12px;
    max-width: 420px;
    width: 90%;
    pointer-events: none;
}

.toast {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.15);
    padding: 16px;
    display: flex;
    align-items: flex-start;
    gap: 14px;
    animation: slideInRight 0.4s ease;
    position: relative;
    overflow: hidden;
    border-left: 4px solid;
    pointer-events: auto;
    transition: opacity 0.4s ease, transform 0.4s ease;
}

.toast.hiding {
    opacity: 0;
    transform: translateX(100%);
}

.toast::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
}

.toast.success {
    border-left-color: #22c55e;
}

.toast.success::before {
    background: #22c55e;
}

.toast.error {
    border-left-color: #ef4444;
}

.toast.error::before {
    background: #ef4444;
}

.toast.info {
    border-left-color: #3b82f6;
}

.toast.info::before {
    background: #3b82f6;
}

.toast.warning {
    border-left-color: #f59e0b;
}

.toast.warning::before {
    background: #f59e0b;
}

.toast-icon {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 16px;
}

.toast.success .toast-icon {
    background: #dcfce7;
    color: #22c55e;
}

.toast.error .toast-icon {
    background: #fee2e2;
    color: #ef4444;
}

.toast.info .toast-icon {
    background: #dbeafe;
    color: #3b82f6;
}

.toast.warning .toast-icon {
    background: #fef3c7;
    color: #f59e0b;
}

.toast-content {
    flex: 1;
    min-width: 0;
}

.toast-title {
    font-weight: 700;
    font-size: 15px;
    color: #1e293b;
    margin-bottom: 4px;
    line-height: 1.3;
}

.toast-message {
    font-size: 14px;
    color: #64748b;
    line-height: 1.4;
}

.toast-close {
    position: absolute;
    top: 12px;
    right: 12px;
    width: 24px;
    height: 24px;
    border: none;
    background: transparent;
    cursor: pointer;
    color: #94a3b8;
    font-size: 18px;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all 0.2s;
}

.toast-close:hover {
    background: #f1f5f9;
    color: #475569;
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@media(max-width: 600px) {
    #toast-container {
        left: 10px;
        right: 10px;
        max-width: none;
        top: 10px;
    }
    
    .toast {
        padding: 14px;
    }
}
</style>

<script>
// Toast Notification System
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
        
        // Timer ID для управления
        let hideTimer;
        
        // Функция скрытия
        const hide = () => {
            toast.classList.add('hiding');
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.remove();
                }
            }, 400);
        };
        
        // Запуск таймера
        const startTimer = () => {
            const toastDuration = duration || this.defaultDuration;
            hideTimer = setTimeout(hide, toastDuration);
        };
        
        // Остановка таймера
        const stopTimer = () => {
            if (hideTimer) {
                clearTimeout(hideTimer);
            }
        };
        
        // Hover события - пауза при наведении
        toast.addEventListener('mouseenter', stopTimer);
        toast.addEventListener('mouseleave', startTimer);
        
        // Запускаем таймер
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

// Обратная совместимость со старой функцией showToast
function showToast(message, type = 'success', title = null) {
    Toast.show(message, type, title);
}

// Показ тостов из PHP-переменных (если есть)
<?php if(isset($msg) && $msg): ?>
    Toast.show(<?=json_encode($msg)?>, 'success');
<?php endif; ?>
<?php if(isset($err) && $err): ?>
    Toast.show(<?=json_encode($err)?>, 'error');
<?php endif; ?>
<?php if(isset($success_msg) && $success_msg): ?>
    Toast.show(<?=json_encode($success_msg)?>, 'success');
<?php endif; ?>
<?php if(isset($err_msg) && $err_msg): ?>
    Toast.show(<?=json_encode($err_msg)?>, 'error');
<?php endif; ?>
</script>