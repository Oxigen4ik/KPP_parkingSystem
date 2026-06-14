/*
 * КПП КузГТУ — управление шлагбаумом и тревогой
 * Команды от PHP через Serial (USB):
 *   'O' — Open:  открыть шлагбаум (серво 0° → 90°, закрыть через 5 сек)
 *   'A' — Alarm: открыть шлагбаум + включить сирену + мигание лампой
 *
 * Подключение:
 *   Пин 9  → серво-привод шлагбаума (сигнальный провод)
 *   Пин 8  → зуммер / реле сирены  (+ через 220Ом резистор)
 *   Пин 7  → светодиод / реле лампы
 *   GND    → общий минус всех устройств
 */

#include <Servo.h>

// ── Настройки ──────────────────────────────────────────────
const int PIN_SERVO  = 9;
const int PIN_BUZZER = 8;
const int PIN_LAMP   = 7;

const int ANGLE_CLOSED = 0;    // угол серво: шлагбаум закрыт
const int ANGLE_OPEN   = 90;   // угол серво: шлагбаум открыт

const unsigned long BARRIER_OPEN_MS  = 5000;  // время удержания открытым (мс)
const unsigned long ALARM_DURATION_MS = 8000;  // длительность тревоги (мс)

// ── Состояние ──────────────────────────────────────────────
Servo barrierServo;

bool  barrierOpen    = false;
unsigned long barrierOpenedAt = 0;

bool  alarmActive    = false;
unsigned long alarmStartedAt = 0;


// ══════════════════════════════════════════════════════════
void setup() {
    Serial.begin(9600);

    pinMode(PIN_BUZZER, OUTPUT);
    pinMode(PIN_LAMP,   OUTPUT);
    digitalWrite(PIN_BUZZER, LOW);
    digitalWrite(PIN_LAMP,   LOW);

    barrierServo.attach(PIN_SERVO);
    barrierServo.write(ANGLE_CLOSED);

    Serial.println("[KPP] Ready. Commands: O=Open, A=Alarm");
}


// ══════════════════════════════════════════════════════════
void loop() {
    unsigned long now = millis();

    // ── Читаем команды из Serial ──────────────────────────
    if (Serial.available() > 0) {
        char cmd = Serial.read();

        if (cmd == 'O') {
            openBarrier();
            Serial.println("OK:barrier_opened");
        }
        else if (cmd == 'A') {
            openBarrier();
            startAlarm();
            Serial.println("OK:alarm_started");
        }
    }

    // ── Автозакрытие шлагбаума ────────────────────────────
    if (barrierOpen && (now - barrierOpenedAt >= BARRIER_OPEN_MS)) {
        closeBarrier();
    }

    // ── Обновление тревоги (мигание + звук) ──────────────
    if (alarmActive) {
        updateAlarm(now);
        if (now - alarmStartedAt >= ALARM_DURATION_MS) {
            stopAlarm();
        }
    }
}


// ══════════════════════════════════════════════════════════
//  ШЛАГБАУМ
// ══════════════════════════════════════════════════════════
void openBarrier() {
    barrierServo.write(ANGLE_OPEN);
    barrierOpen     = true;
    barrierOpenedAt = millis();
    Serial.println("[Barrier] OPEN");
}

void closeBarrier() {
    barrierServo.write(ANGLE_CLOSED);
    barrierOpen = false;
    Serial.println("[Barrier] CLOSED");
}


// ══════════════════════════════════════════════════════════
//  ТРЕВОГА
// ══════════════════════════════════════════════════════════
void startAlarm() {
    alarmActive   = true;
    alarmStartedAt = millis();
    Serial.println("[Alarm] STARTED");
}

void stopAlarm() {
    alarmActive = false;
    digitalWrite(PIN_BUZZER, LOW);
    digitalWrite(PIN_LAMP,   LOW);
    Serial.println("[Alarm] STOPPED");
}

/*
 * Пульсирующая сирена: 200мс ВКЛ / 200мс ВЫКЛ
 * Лампа мигает в противофазе для визуального эффекта
 */
void updateAlarm(unsigned long now) {
    unsigned long elapsed = now - alarmStartedAt;
    bool phase = (elapsed / 200) % 2 == 0;   // переключается каждые 200мс

    digitalWrite(PIN_BUZZER, phase ? HIGH : LOW);
    digitalWrite(PIN_LAMP,   phase ? LOW  : HIGH);  // лампа в противофазе
}
