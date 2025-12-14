/* StopClope Main JavaScript */

// Register Service Worker
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js')
        .then(reg => {
            console.log('SW registered');
            // Check for pending offline data on load
            syncOfflineData();
        })
        .catch(err => console.log('SW registration failed', err));

    // Listen for sync messages from SW
    navigator.serviceWorker.addEventListener('message', event => {
        if (event.data.type === 'SYNC_OFFLINE_DATA') {
            syncOfflineData();
        }
    });
}

// Variable pour stocker le timeout actuel du toast
let currentToastTimeout = null;

function showToast(message, options = {}) {
    const toast = document.getElementById('toast');
    if (!toast) return;

    const { type = 'info', points = null, icon = null, action = null, actionLabel = null, duration = 3000 } = options;

    // Annuler le timeout précédent si existe
    if (currentToastTimeout) {
        clearTimeout(currentToastTimeout);
    }

    // Reset classes
    toast.className = 'toast';

    // Build toast content
    let html = '';
    if (icon) {
        html += '<span class="toast-icon" aria-hidden="true">' + icon + '</span>';
    }
    html += '<div class="toast-content">';
    html += '<span class="toast-message">' + message + '</span>';
    if (points !== null && points !== undefined) {
        const pointsClass = points > 0 ? 'positive' : (points < 0 ? 'negative' : '');
        const pointsText = points > 0 ? '+' + points + ' pts' : points + ' pts';
        html += '<span class="toast-points ' + pointsClass + '">' + pointsText + '</span>';
    }
    html += '</div>';

    // Bouton d'action (ex: Annuler)
    if (action && actionLabel) {
        html += '<button class="toast-action" type="button">' + actionLabel + '</button>';
    }

    toast.innerHTML = html;
    toast.classList.add('toast-' + type, 'show');

    // Attacher l'événement au bouton d'action
    if (action && actionLabel) {
        const actionBtn = toast.querySelector('.toast-action');
        if (actionBtn) {
            actionBtn.onclick = function() {
                action();
                toast.classList.remove('show');
                if (currentToastTimeout) {
                    clearTimeout(currentToastTimeout);
                }
            };
        }
    }

    // Haptic feedback (vibration) si supporte
    if ('vibrate' in navigator) {
        if (type === 'success' || points > 0) {
            navigator.vibrate(50); // Vibration courte pour succes
        } else if (type === 'warning' || points < 0) {
            navigator.vibrate([50, 30, 50]); // Double vibration pour avertissement
        }
    }

    currentToastTimeout = setTimeout(() => {
        toast.classList.remove('show');
        // Reset apres animation
        setTimeout(() => toast.className = 'toast', 300);
    }, duration);
}

// Offline data management
function getOfflineQueue() {
    return JSON.parse(localStorage.getItem('offlineQueue') || '[]');
}

function addToOfflineQueue(action, data) {
    const queue = getOfflineQueue();
    queue.push({ action, data, timestamp: Date.now() });
    localStorage.setItem('offlineQueue', JSON.stringify(queue));
}

function clearOfflineQueue() {
    localStorage.setItem('offlineQueue', '[]');
}

// Verification reseau reelle (ping serveur)
async function isReallyOnline() {
    if (!navigator.onLine) return false;
    try {
        // Ping leger sur une route existante avec timeout court
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 3000);
        const response = await fetch('/login', {
            method: 'HEAD',
            cache: 'no-store',
            signal: controller.signal
        });
        clearTimeout(timeoutId);
        return response.ok || response.status === 302;
    } catch (e) {
        return false;
    }
}

async function syncOfflineData() {
    const queue = getOfflineQueue();
    if (queue.length === 0) return;

    // Verifier qu'on est vraiment en ligne
    const online = await isReallyOnline();
    if (!online) {
        console.log('Sync skipped: not really online');
        return;
    }

    console.log('Syncing', queue.length, 'offline items');

    // Recuperer le token CSRF depuis la page
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    let successCount = 0;
    const failedItems = [];

    for (const item of queue) {
        try {
            let response;
            if (item.action === 'log_cigarette') {
                // Utiliser le bon format (local_time + tz_offset)
                const body = new URLSearchParams();
                if (item.data.local_time) {
                    body.append('local_time', item.data.local_time);
                    body.append('tz_offset', item.data.tz_offset || -new Date().getTimezoneOffset());
                }
                response = await fetch('/log', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-Token': csrfToken
                    },
                    body: body.toString()
                });
            } else if (item.action === 'log_wakeup') {
                const body = new URLSearchParams();
                body.append('wake_time', item.data.wake_time);
                body.append('tz_offset', item.data.tz_offset || -new Date().getTimezoneOffset());
                response = await fetch('/wakeup', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-Token': csrfToken
                    },
                    body: body.toString()
                });
            }

            if (response && response.ok) {
                successCount++;
            } else {
                // Erreur serveur (403 CSRF, etc.) - ne pas re-tenter
                console.error('Sync failed for item:', response?.status);
            }
        } catch (e) {
            console.error('Network error syncing item:', e);
            failedItems.push(item);
        }
    }

    // Garder seulement les items qui ont echoue a cause du reseau
    if (failedItems.length > 0) {
        localStorage.setItem('offlineQueue', JSON.stringify(failedItems));
    } else {
        clearOfflineQueue();
    }

    if (successCount > 0) {
        showToast(successCount + ' donnee(s) synchronisee(s)', { type: 'success', icon: '✅' });
        // Recharger pour afficher les donnees a jour
        setTimeout(() => window.location.reload(), 1500);
    }
}

// Check online status
window.addEventListener('online', () => {
    showToast('Connexion retablie', { icon: '📶' });
    syncOfflineData();
});

window.addEventListener('offline', () => {
    showToast('Mode hors-ligne', { icon: '📴' });
});

// Sync au chargement de la page si des donnees sont en attente
if (getOfflineQueue().length > 0) {
    syncOfflineData();
}

// ===== Rappel reveil (notifications locales) =====
function scheduleWakeupReminderGlobal() {
    const enabled = localStorage.getItem('wakeupReminderEnabled') === 'true';
    if (!enabled || !('Notification' in window) || Notification.permission !== 'granted') return;

    const time = localStorage.getItem('wakeupReminderTime') || '08:00';
    const [hours, minutes] = time.split(':').map(Number);

    const now = new Date();
    const nextReminder = new Date();
    nextReminder.setHours(hours, minutes, 0, 0);

    if (nextReminder <= now) {
        nextReminder.setDate(nextReminder.getDate() + 1);
    }

    const delay = nextReminder.getTime() - now.getTime();

    setTimeout(() => {
        // Verifier si le reveil a deja ete note aujourd'hui
        const notification = new Notification('StopClope', {
            body: 'N\'oublie pas de noter ton heure de reveil !',
            icon: '/icons/icon-192.png',
            tag: 'wakeup-reminder',
            requireInteraction: true
        });
        notification.onclick = () => {
            window.focus();
            window.location.href = '/';
            notification.close();
        };
        scheduleWakeupReminderGlobal();
    }, delay);
}
scheduleWakeupReminderGlobal();

// ===== Focus Trap pour les modales (accessibilité) =====
function createFocusTrap(modalElement) {
    const focusableSelector = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

    function getFocusableElements() {
        return Array.from(modalElement.querySelectorAll(focusableSelector))
            .filter(el => !el.disabled && el.offsetParent !== null);
    }

    function handleKeyDown(e) {
        if (e.key !== 'Tab') return;

        const focusable = getFocusableElements();
        if (focusable.length === 0) return;

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (e.shiftKey) {
            // Shift+Tab : si on est sur le premier, aller au dernier
            if (document.activeElement === first) {
                e.preventDefault();
                last.focus();
            }
        } else {
            // Tab : si on est sur le dernier, aller au premier
            if (document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    return {
        activate: function() {
            modalElement.addEventListener('keydown', handleKeyDown);
            // Focus sur le premier élément focusable
            const focusable = getFocusableElements();
            if (focusable.length > 0) {
                setTimeout(() => focusable[0].focus(), 50);
            }
        },
        deactivate: function() {
            modalElement.removeEventListener('keydown', handleKeyDown);
        }
    };
}

// Auto-initialiser les focus traps sur les modales existantes
document.addEventListener('DOMContentLoaded', function() {
    const modals = document.querySelectorAll('.modal');
    const traps = new Map();

    modals.forEach(modal => {
        const trap = createFocusTrap(modal);
        traps.set(modal, trap);

        // Observer les changements de classe pour activer/désactiver le trap
        const observer = new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                if (mutation.attributeName === 'class') {
                    if (modal.classList.contains('active')) {
                        trap.activate();
                    } else {
                        trap.deactivate();
                    }
                }
            });
        });

        observer.observe(modal, { attributes: true });

        // Si la modal est déjà active au chargement
        if (modal.classList.contains('active')) {
            trap.activate();
        }
    });
});

// Export functions for use in templates
window.showToast = showToast;
window.getOfflineQueue = getOfflineQueue;
window.addToOfflineQueue = addToOfflineQueue;
window.clearOfflineQueue = clearOfflineQueue;
window.isReallyOnline = isReallyOnline;
window.syncOfflineData = syncOfflineData;
window.createFocusTrap = createFocusTrap;
