// ══════════════════════════════════════════════════════════
//  ЧАС Ы
// ══════════════════════════════════════════════════════════
const updateTime = () => {
    const now = new Date();
    const clockEl = document.getElementById('clock');
    const dateEl  = document.getElementById('date');
    if (clockEl) clockEl.textContent = now.toLocaleTimeString('ru-RU', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
    if (dateEl)  dateEl.textContent  = now.toLocaleDateString('ru-RU', {weekday:'short', day:'numeric', month:'short'});
};

// ══════════════════════════════════════════════════════════
//  ЗВУКИ (Web Audio API — без файлов)
// ══════════════════════════════════════════════════════════
let _audioCtx = null;
function getAudioCtx() {
    if (!_audioCtx) _audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    return _audioCtx;
}

/**
 * Воспроизводит тон заданной частоты.
 * @param {number} freq   - частота в Гц
 * @param {number} dur    - длительность в секундах
 * @param {number} start  - задержка от текущего момента в секундах
 * @param {string} type   - 'sine'|'square'|'sawtooth'|'triangle'
 */
function playTone(freq, dur, start = 0, type = 'sine') {
    const ctx = getAudioCtx();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.type      = type;
    osc.frequency.value = freq;
    gain.gain.setValueAtTime(0.4, ctx.currentTime + start);
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + start + dur);
    osc.start(ctx.currentTime + start);
    osc.stop(ctx.currentTime + start + dur);
}

// Звук: доступ разрешён — два коротких восходящих бипа
function soundGranted() {
    playTone(880, 0.12, 0.00);
    playTone(1200, 0.18, 0.15);
}

// Звук: доступ запрещён — низкий нисходящий сигнал
function soundDenied() {
    playTone(400, 0.15, 0.00, 'square');
    playTone(280, 0.25, 0.18, 'square');
}

// Звук: тревога — пульсирующая сирена (3 цикла)
function soundEmergency() {
    for (let i = 0; i < 3; i++) {
        playTone(880, 0.3, i * 0.65,       'sawtooth');
        playTone(440, 0.3, i * 0.65 + 0.32,'sawtooth');
    }
}

// ══════════════════════════════════════════════════════════
//  ДАННЫЕ (логи + статистика)
// ══════════════════════════════════════════════════════════
let _lastLogId = null;   // следим за новыми событиями для звука

const updateData = async () => {
    try {
        // Статистика
        const statsRes = await fetch('index.php?action=get_stats');
        const stats    = await statsRes.json();
        document.getElementById('stat-total').textContent = stats.total_cars;
        document.getElementById('stat-last').textContent  = stats.last_activity;

        // Логи
        const logsRes = await fetch('index.php?action=get_logs');
        const logs    = await logsRes.json();
        const container = document.getElementById('logs-container');

        if (!logs || !logs.length) {
            container.innerHTML = `<div class="empty-state"><i class="fas fa-inbox"></i><div>Нет событий</div></div>`;
            _lastLogId = null;
        } else {
            // Звук при новом событии (не при первой загрузке)
            const newestId = logs[0]?.id ?? null;
            if (_lastLogId !== null && newestId !== _lastLogId) {
                const newestStatus = logs[0]?.status;
                if (newestStatus === 'access_granted') soundGranted();
                else if (newestStatus === 'access_denied')  soundDenied();
            }
            _lastLogId = newestId;

            container.innerHTML = logs.map(l => {
                const ok   = l.status === 'access_granted';
                const img  = l.snapshot_path || 'https://placehold.co/100x60/222/666?text=—';
                const time = l.event_time ? l.event_time.split(' ')[1].substring(0, 5) : '--:--';
                return `
                <div class="log-item ${ok ? 'log-ok' : 'log-deny'}">
                    <div class="log-thumb">
                        <img src="${img}" alt="" onerror="this.src='https://placehold.co/100x60/222/666?text=—'">
                    </div>
                    <div class="log-content">
                        <div class="log-plate">${l.plate_number || '—'}</div>
                        <div class="log-meta">
                            <span>${time}</span>
                            <span class="log-status ${ok ? 'ok' : 'deny'}">
                                <i class="fas fa-${ok ? 'check' : 'times'}"></i>
                                ${ok ? 'Доступ' : 'Отказ'}
                            </span>
                        </div>
                    </div>
                </div>`;
            }).join('');
        }

        // Статус камеры
        const cam      = document.querySelector('.video-wrapper img');
        const statusEl = document.getElementById('camera-status');
        if (cam && cam.complete && cam.naturalHeight !== 0) {
            statusEl.textContent = '● Онлайн';
            statusEl.className   = 'text-success';
        } else {
            statusEl.textContent = '○ Офлайн';
            statusEl.className   = 'text-dim';
        }

    } catch (e) {
        console.error('Update error:', e);
    }
};

// ══════════════════════════════════════════════════════════
//  ЭКСТРЕННАЯ КНОПКА
// ══════════════════════════════════════════════════════════
let _emergencyActive = false;   // защита от двойного нажатия

async function triggerEmergency() {
    if (_emergencyActive) return;

    const confirmed = confirm(
        '⚠️ ТРЕВОГА\n\nБудет выполнено:\n' +
        '• Открытие шлагбаума\n' +
        '• Уведомление администратора в Telegram\n' +
        '• Сигнал на Arduino (сирена)\n\n' +
        'Подтвердить?'
    );
    if (!confirmed) return;

    _emergencyActive = true;
    const btn = document.getElementById('emergencyBtn');
    if (btn) {
        btn.classList.add('emergency-active');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';
        btn.disabled  = true;
    }

    // Звук тревоги в браузере
    soundEmergency();

    // Мигание экрана
    flashScreen();

    try {
        const res  = await fetch('emergency.php', { method: 'POST' });
        const data = await res.json();

        const tg  = data.results?.telegram ?? '—';
        const ard = data.results?.arduino  ?? '—';

        showEmergencyResult(tg, ard);
    } catch (e) {
        showEmergencyResult('ошибка сети', '—');
        console.error('Emergency error:', e);
    } finally {
        // Разблокировать кнопку через 15 секунд
        setTimeout(() => {
            _emergencyActive = false;
            if (btn) {
                btn.classList.remove('emergency-active');
                btn.innerHTML = '<i class="fas fa-triangle-exclamation"></i> ТРЕВОГА';
                btn.disabled  = false;
            }
        }, 15000);
    }
}

// Красное мигание всего экрана
function flashScreen() {
    const overlay = document.createElement('div');
    overlay.style.cssText = `
        position:fixed; inset:0; z-index:99999; pointer-events:none;
        background:rgba(248,81,73,0.18);
        animation: emergencyFlash 0.4s ease-in-out 4;
    `;
    // Добавляем keyframes если их нет
    if (!document.getElementById('emergencyFlashStyle')) {
        const style = document.createElement('style');
        style.id = 'emergencyFlashStyle';
        style.textContent = `
            @keyframes emergencyFlash {
                0%,100%{ opacity:0; }
                50%    { opacity:1; }
            }
        `;
        document.head.appendChild(style);
    }
    document.body.appendChild(overlay);
    setTimeout(() => overlay.remove(), 1800);
}

// Всплывающее уведомление с результатом отправки
function showEmergencyResult(tgStatus, ardStatus) {
    const tgOk  = tgStatus === 'sent';
    const ardOk = ardStatus.startsWith('sent') || ardStatus.startsWith('OK');

    const box = document.createElement('div');
    box.style.cssText = `
        position:fixed; top:68px; right:20px; z-index:9999;
        background:#0d1117; border:1px solid #1c2a35;
        border-left:3px solid ${tgOk && ardOk ? '#00e07a' : '#e3b341'};
        border-radius:10px; padding:14px 18px;
        font-family:'JetBrains Mono',monospace; font-size:0.78rem;
        box-shadow:0 8px 32px rgba(0,0,0,0.6);
        animation: slideIn 0.25s ease;
        max-width: 300px;
        line-height: 1.8;
    `;
    box.innerHTML = `
        <div style="color:#f85149; font-weight:700; margin-bottom:8px;">
            🚨 ТРЕВОГА АКТИВИРОВАНА
        </div>
        <div style="color:#cdd9e5;">
            Telegram: <span style="color:${tgOk ? '#00e07a' : '#e3b341'}">${tgOk ? '✓ отправлено' : '✗ ' + tgStatus}</span><br>
            Arduino:  <span style="color:${ardOk ? '#00e07a' : '#e3b341'}">${ardOk ? '✓ сигнал отправлен' : '✗ ' + ardStatus}</span>
        </div>
    `;
    document.body.appendChild(box);
    setTimeout(() => box.remove(), 8000);
}

// ══════════════════════════════════════════════════════════
//  РУЧНОЕ ОТКРЫТИЕ ШЛАГБАУМА
// ══════════════════════════════════════════════════════════
let _barrierCooldown = false;

async function openBarrier() {
    if (_barrierCooldown) return;

    const btn = document.getElementById('openBarrierBtn');

    _barrierCooldown = true;
    if (btn) {
        btn.style.background = 'var(--accent-dim)';
        btn.style.borderColor = 'rgba(0,224,122,0.4)';
        btn.querySelector('.stat-value').innerHTML = '<i class="fas fa-spinner fa-spin" style="color:var(--accent)"></i>';
    }

    // Короткий восходящий звук "открыто"
    playTone(600, 0.1, 0.00);
    playTone(900, 0.15, 0.12);
    playTone(1200, 0.2, 0.28);

    try {
        const res  = await fetch('emergency.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'open_barrier' })
        });
        const data = await res.json();
        const ok   = data.results?.arduino?.startsWith('sent') || data.results?.arduino?.startsWith('OK');

        // Показываем результат
        const tip = document.createElement('div');
        tip.style.cssText = `
            position:fixed; bottom:24px; left:50%; transform:translateX(-50%);
            background:#0d1117; border:1px solid ${ok ? 'rgba(0,224,122,0.4)' : 'rgba(248,81,73,0.4)'};
            border-radius:8px; padding:10px 20px;
            font-family:'JetBrains Mono',monospace; font-size:0.78rem;
            color:${ok ? 'var(--accent)' : '#f85149'};
            box-shadow:0 4px 24px rgba(0,0,0,0.5); z-index:9999;
            animation:slideIn 0.2s ease;
        `;
        tip.textContent = ok ? '▲ Шлагбаум открыт' : '✗ Нет связи с Arduino';
        document.body.appendChild(tip);
        setTimeout(() => tip.remove(), 3000);

    } catch(e) {
        console.error('Barrier error:', e);
    } finally {
        setTimeout(() => {
            _barrierCooldown = false;
            if (btn) {
                btn.style.background = '';
                btn.style.borderColor = '';
                btn.querySelector('.stat-value').innerHTML = '<i class="fas fa-arrow-up"></i>';
            }
        }, 5000);
    }
}

// ══════════════════════════════════════════════════════════
//  МОДАЛКА РЕДАКТИРОВАНИЯ
// ══════════════════════════════════════════════════════════
function openEditModal(id, plate, owner) {
    document.getElementById('edit_id').value           = id;
    document.getElementById('edit_plate_number').value = plate;
    document.getElementById('edit_owner_name').value   = owner;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

// ══════════════════════════════════════════════════════════
//  ЖИВОЙ ПОИСК
// ══════════════════════════════════════════════════════════
function initSearch() {
    const input = document.getElementById('searchInput');
    if (!input) return;
    input.addEventListener('input', () => {
        const q = input.value.toLowerCase();
        document.querySelectorAll('#platesTableBody tr').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
}

// ══════════════════════════════════════════════════════════
//  ИНИЦИАЛИЗАЦИЯ
// ══════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    initSearch();

    setInterval(updateTime, 1000);
    updateTime();

    setInterval(updateData, 3000);
    updateData();

    // Авто-скрытие алертов
    document.querySelectorAll('.alert-minimal').forEach(alert => {
        setTimeout(() => {
            try { new bootstrap.Alert(alert).close(); } catch(e) {}
        }, 4000);
    });

    // Запрет повторной отправки формы при обновлении
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }

    // Разблокировка AudioContext после первого клика пользователя
    // (браузер требует жест перед воспроизведением)
    document.addEventListener('click', () => {
        if (!_audioCtx) getAudioCtx();
    }, { once: true });
});