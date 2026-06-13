"""
ANPR Barrier System — объединённый модуль
Основа: inference.py (классы, квантованная CRNN)
Дополнено из main.py: positional_fix, difflib-поиск, RTSP с переподключением
"""

import argparse
import os
import time
import threading
import datetime
import difflib
from collections import Counter
from typing import List, Dict, Any

import cv2
import torch
import torch.nn as nn
from torchvision import transforms
from ultralytics import YOLO
import torch.ao.quantization.quantize_fx as quantize_fx
from torch.ao.quantization import QConfigMapping
import numpy as np
import mysql.connector
from mysql.connector import Error
from flask import Flask, Response

os.environ["OPENCV_FFMPEG_CAPTURE_OPTIONS"] = "rtsp_transport;tcp|fflags;nobuffer|flags;low_delay"

# ── Глобальные переменные для стриминга ───────────────────
output_frame = None
frame_lock   = threading.Lock()
app          = Flask(__name__)


# ══════════════════════════════════════════════════════════
#  КОНФИГУРАЦИЯ
# ══════════════════════════════════════════════════════════
class Config:
    # Пути к моделям
    YOLO_MODEL_PATH: str = "models/yolo/best.pt"
    OCR_MODEL_PATH:  str = "models/ocr_crnn/crnn_ocr_model_int8_fx.pth"

    # Параметры CRNN
    OCR_IMG_HEIGHT: int = 32
    OCR_IMG_WIDTH:  int = 128
    # Алфавит: цифры + латиница (как детектирует модель) + кириллица
    OCR_ALPHABET: str = "0123456789ABCEHKMOPTXYАВСЕНКМОРТХУ"

    # Детектор
    DETECTION_CONFIDENCE_THRESHOLD: float = 0.5

    # Устройство
    DEVICE: torch.device = torch.device("cuda" if torch.cuda.is_available() else "cpu")

    # База данных
    DB_HOST:     str = "localhost"
    DB_USER:     str = "root"
    DB_PASSWORD: str = ""
    DB_NAME:     str = "parking_system"

    # Снимки
    SNAPSHOTS_DIR: str = os.path.join(os.path.dirname(os.path.abspath(__file__)), "snapshots")

    # Стабилизация: минимальная длина номера и кулдаун логирования (сек)
    MIN_PLATE_LEN:   int   = 6
    LOG_COOLDOWN_S:  float = 15.0
    TRACK_HISTORY_MAX: int = 20

    # difflib: минимальный порог схожести для доступа
    FUZZY_CUTOFF: float = 0.80


# ══════════════════════════════════════════════════════════
#  АРХИТЕКТУРА CRNN
# ══════════════════════════════════════════════════════════
class CRNN(nn.Module):
    def __init__(self, num_classes: int):
        super().__init__()
        self.cnn = nn.Sequential(
            nn.Conv2d(1, 64, 3, padding=1),  nn.ReLU(True), nn.MaxPool2d(2, 2),
            nn.Conv2d(64, 128, 3, padding=1), nn.ReLU(True), nn.MaxPool2d(2, 2),
            nn.Conv2d(128, 256, 3, padding=1), nn.BatchNorm2d(256), nn.ReLU(True),
            nn.Conv2d(256, 256, 3, padding=1), nn.ReLU(True), nn.MaxPool2d((2, 1), (2, 1)),
            nn.Conv2d(256, 512, 3, padding=1), nn.BatchNorm2d(512), nn.ReLU(True),
            nn.Conv2d(512, 512, 3, padding=1), nn.ReLU(True), nn.MaxPool2d((2, 1), (2, 1)),
        )
        self.rnn        = nn.LSTM(512 * 2, 256, bidirectional=True, num_layers=2, batch_first=True)
        self.classifier = nn.Linear(512, num_classes)

    def forward(self, x):
        x = self.cnn(x)
        b, c, h, w = x.size()
        x = x.reshape(b, c * h, w).permute(0, 2, 1)
        x, _ = self.rnn(x)
        x = self.classifier(x).permute(1, 0, 2)
        return nn.functional.log_softmax(x, dim=2)


# ══════════════════════════════════════════════════════════
#  ДЕТЕКТОР НОМЕРНЫХ ЗНАКОВ (YOLO)
# ══════════════════════════════════════════════════════════
class YOLODetector:
    def __init__(self):
        print(f"[INFO] Загрузка YOLO: {Config.YOLO_MODEL_PATH}")
        self.model = YOLO(Config.YOLO_MODEL_PATH)
        self.model.to(Config.DEVICE)

    def track(self, frame: np.ndarray) -> List[Dict[str, Any]]:
        """Возвращает список детекций с bbox, confidence, track_id."""
        dets = self.model.track(frame, persist=True, verbose=False,
                                conf=Config.DETECTION_CONFIDENCE_THRESHOLD,
                                device=Config.DEVICE)
        results = []
        if dets[0].boxes.id is None:
            return results
        for box, tid, conf in zip(dets[0].boxes.xyxy.cpu().numpy(),
                                   dets[0].boxes.id.int().cpu().tolist(),
                                   dets[0].boxes.conf.cpu().numpy()):
            results.append({
                "bbox":       [int(b) for b in box],
                "confidence": float(conf),
                "track_id":   tid,
            })
        return results


# ══════════════════════════════════════════════════════════
#  OCR (CRNN квантованная INT8)
# ══════════════════════════════════════════════════════════
class CRNNRecognizer:
    def __init__(self):
        print(f"[INFO] Загрузка OCR (INT8): {Config.OCR_MODEL_PATH}")
        self.device = Config.DEVICE
        self.transform = transforms.Compose([
            transforms.ToPILImage(),
            transforms.Grayscale(),
            transforms.Resize((Config.OCR_IMG_HEIGHT, Config.OCR_IMG_WIDTH)),
            transforms.ToTensor(),
            transforms.Normalize(mean=[0.5], std=[0.5]),
        ])
        # Индекс 0 зарезервирован под CTC blank
        self.int_to_char = {i + 1: c for i, c in enumerate(Config.OCR_ALPHABET)}
        self.int_to_char[0] = ""

        # Создаём и квантуем архитектуру, затем загружаем веса
        num_classes    = len(Config.OCR_ALPHABET) + 1
        base_model     = CRNN(num_classes).eval()
        qconfig_map    = QConfigMapping().set_global(
            torch.ao.quantization.get_default_qconfig("fbgemm")
        )
        example_input  = (torch.randn(1, 1, Config.OCR_IMG_HEIGHT, Config.OCR_IMG_WIDTH),)
        prepared       = quantize_fx.prepare_fx(base_model, qconfig_map, example_input)
        quantized      = quantize_fx.convert_fx(prepared)
        quantized.load_state_dict(torch.load(Config.OCR_MODEL_PATH, map_location=self.device))
        self.model = quantized

    @torch.no_grad()
    def recognize(self, plate_img: np.ndarray) -> str:
        if plate_img is None or plate_img.size == 0:
            return ""
        # Увеличиваем кроп — помогает на мелких номерах
        plate_img = cv2.resize(plate_img, None, fx=2.0, fy=2.0,
                               interpolation=cv2.INTER_CUBIC)
        tensor = self.transform(plate_img).unsqueeze(0).to(self.device)
        preds  = self.model(tensor)
        return self._ctc_decode(preds)

    def _ctc_decode(self, preds: torch.Tensor) -> str:
        """CTC greedy decode: убираем повторы и blank."""
        indices  = preds.permute(1, 0, 2).argmax(dim=2)[0]
        decoded  = []
        prev_idx = 0
        for idx in indices:
            idx = idx.item()
            if idx != 0 and idx != prev_idx:
                decoded.append(self.int_to_char.get(idx, ""))
            prev_idx = idx
        return "".join(decoded)


# ══════════════════════════════════════════════════════════
#  ПОСТОБРАБОТКА ТЕКСТА
# ══════════════════════════════════════════════════════════

# Таблица перевода латиница → кириллица (для записи в БД)
_EN_TO_RU = str.maketrans("ABCEHKMOPTXY", "АВСЕНКМОРТХУ")


def normalize_plate(text: str) -> str:
    """Верхний регистр + латиница → кириллица."""
    return text.upper().translate(_EN_TO_RU)


def positional_fix(raw: str) -> str:
    """
    Позиционная коррекция по формату RU-номера: Б ЦЦЦ ББ ЦЦ(Ц)
      позиции 0, 4, 5 — буквы
      позиции 1-3, 6+ — цифры
    Исправляет типичные путаницы OCR (O↔0, B↔8 и т.д.)
    """
    letter_to_digit = {"O": "0", "C": "0", "Q": "0", "D": "0",
                       "B": "8", "S": "5", "T": "7",
                       "I": "1", "L": "1", "G": "6"}
    digit_to_letter = {"0": "O", "8": "B", "4": "A",
                       "5": "S", "1": "I", "6": "G"}
    fixed = []
    for i, ch in enumerate(raw):
        if i in (1, 2, 3) or i >= 6:   # зона цифр
            fixed.append(letter_to_digit.get(ch, ch))
        elif i in (0, 4, 5):            # зона букв
            fixed.append(digit_to_letter.get(ch, ch))
        else:
            fixed.append(ch)
    return "".join(fixed)


# ══════════════════════════════════════════════════════════
#  БАЗА ДАННЫХ
# ══════════════════════════════════════════════════════════

def _get_db():
    """Открывает соединение с БД. Возвращает None при ошибке."""
    try:
        return mysql.connector.connect(
            host=Config.DB_HOST, user=Config.DB_USER,
            password=Config.DB_PASSWORD, database=Config.DB_NAME,
        )
    except Error as e:
        print(f"[ERROR] БД: {e}")
        return None


def log_plate_event(plate_text: str, frame: np.ndarray):
    """
    1. Нормализует номер.
    2. Ищет в белом списке через difflib (устойчиво к ошибкам OCR).
    3. Сохраняет снимок в snapshots/<дата>/<номер_время>.jpg.
    4. Пишет запись в entry_logs.
    """
    conn = _get_db()
    if not conn:
        return

    plate_ru = normalize_plate(plate_text)

    try:
        cursor = conn.cursor(dictionary=True)

        # Загружаем все активные номера из БД
        cursor.execute("SELECT plate_number FROM allowed_cars WHERE is_active = 1")
        allowed = [r["plate_number"] for r in cursor.fetchall()]

        # Нечёткое совпадение — устойчиво к ошибкам OCR
        matches     = difflib.get_close_matches(plate_ru, allowed, n=1, cutoff=Config.FUZZY_CUTOFF)
        status      = "access_granted" if matches else "access_denied"
        final_plate = matches[0] if matches else plate_ru

        # Сохранение снимка
        date_folder = datetime.datetime.now().strftime("%d.%m.%Y")
        save_dir    = os.path.join(Config.SNAPSHOTS_DIR, date_folder)
        os.makedirs(save_dir, exist_ok=True)
        filename    = f"{final_plate}_{datetime.datetime.now().strftime('%H%M%S')}.jpg"
        cv2.imwrite(os.path.join(save_dir, filename), frame)

        # Путь для БД — всегда прямые слеши (для браузера)
        db_path = f"snapshots/{date_folder}/{filename}"

        cursor.execute(
            "INSERT INTO entry_logs (plate_number, status, snapshot_path) VALUES (%s, %s, %s)",
            (final_plate, status, db_path),
        )
        conn.commit()

        icon = ">>>" if status == "access_granted" else "!!!"
        print(f"[{icon}] {final_plate} → {status}")

    except Error as e:
        print(f"[SQL ERROR] {e}")
    finally:
        if conn.is_connected():
            conn.close()


# ══════════════════════════════════════════════════════════
#  КОНВЕЙЕР ОБРАБОТКИ КАДРОВ
# ══════════════════════════════════════════════════════════
class ANPRPipeline:
    def __init__(self, recognizer: CRNNRecognizer):
        self.recognizer    = recognizer
        self.track_history: Dict[int, list] = {}
        self.last_logged:   Dict[str, float] = {}

    def process(self, frame: np.ndarray,
                detections: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
        h, w = frame.shape[:2]
        pad  = 5

        for det in detections:
            x1, y1, x2, y2 = det["bbox"]
            crop = frame[max(0, y1 - pad): min(h, y2 + pad),
                         max(0, x1 - pad): min(w, x2 + pad)]

            # OCR
            raw_text = self.recognizer.recognize(crop).strip()

            # Позиционная коррекция (из main.py)
            if len(raw_text) >= Config.MIN_PLATE_LEN:
                raw_text = positional_fix(raw_text)

            # Стабилизация по истории трека (voting)
            tid = det.get("track_id")
            if tid is not None:
                hist = self.track_history.setdefault(tid, [])
                hist.append(raw_text)
                if len(hist) > Config.TRACK_HISTORY_MAX:
                    hist.pop(0)
                final_text = Counter(hist).most_common(1)[0][0] if hist else raw_text
            else:
                final_text = raw_text

            det["text"] = final_text

            # Логирование (кулдаун + минимальная длина)
            if len(final_text) >= Config.MIN_PLATE_LEN:
                now = time.time()
                if now - self.last_logged.get(final_text, 0) > Config.LOG_COOLDOWN_S:
                    threading.Thread(
                        target=log_plate_event,
                        args=(final_text, crop.copy()),
                        daemon=True,
                    ).start()
                    self.last_logged[final_text] = now

        return detections

    def cleanup_history(self, active_ids: List[int]):
        """Удаляет треки которые больше не видны в кадре."""
        self.track_history = {k: v for k, v in self.track_history.items()
                              if k in active_ids}


# ══════════════════════════════════════════════════════════
#  ОТРИСОВКА
# ══════════════════════════════════════════════════════════
def draw_results(frame: np.ndarray, results: List[Dict[str, Any]]) -> np.ndarray:
    for r in results:
        x1, y1, x2, y2 = r["bbox"]
        text = r.get("text", "")
        # Зелёная рамка
        cv2.rectangle(frame, (x1, y1), (x2, y2), (0, 255, 0), 2)
        # Фон под текст
        (tw, th), _ = cv2.getTextSize(text, cv2.FONT_HERSHEY_SIMPLEX, 0.9, 2)
        cv2.rectangle(frame, (x1, y1 - 30), (x1 + tw + 4, y1), (0, 255, 0), -1)
        cv2.putText(frame, text, (x1 + 2, y1 - 8),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.9, (0, 0, 0), 2)
    return frame


# ══════════════════════════════════════════════════════════
#  ПОТОК ОБРАБОТКИ ВИДЕО
# ══════════════════════════════════════════════════════════
def video_thread(source: str):
    global output_frame

    # Инициализация моделей
    try:
        detector   = YOLODetector()
        recognizer = CRNNRecognizer()
        pipeline   = ANPRPipeline(recognizer)
    except Exception as e:
        print(f"[FATAL] Не удалось загрузить модели: {e}")
        return

    print(f"[INFO] Видеопоток: {source}")

    # Определяем тип источника
    src = int(source) if source.isdigit() else source

    def open_capture():
        if isinstance(src, int):
            return cv2.VideoCapture(src, cv2.CAP_DSHOW)
        return cv2.VideoCapture(src)

    cap         = open_capture()
    frame_count = 0

    while True:
        ret, frame = cap.read()

        if not ret:
            # RTSP / файл: переподключение (поведение из main.py)
            print("[WARN] Потеря кадра — переподключение...")
            cap.release()
            time.sleep(1)
            cap = open_capture()
            continue

        frame_count += 1
        vis = frame.copy()

        # 1. Детекция
        detections = detector.track(frame)

        # 2. OCR + логика
        results = pipeline.process(frame, detections)

        # 3. Очистка старых треков раз в 100 кадров
        if frame_count % 100 == 0:
            active_ids = [d["track_id"] for d in detections]
            pipeline.cleanup_history(active_ids)

        # 4. Отрисовка
        vis = draw_results(vis, results)

        # 5. Обновление глобального кадра для Flask
        with frame_lock:
            output_frame = vis

        time.sleep(0.01)   # небольшая пауза для разгрузки CPU

    cap.release()


# ══════════════════════════════════════════════════════════
#  FLASK — MJPEG СТРИМ
# ══════════════════════════════════════════════════════════
def _generate_mjpeg():
    while True:
        with frame_lock:
            if output_frame is None:
                time.sleep(0.05)
                continue
            ok, buf = cv2.imencode(".jpg", output_frame)
            if not ok:
                continue
            data = buf.tobytes()
        yield (b"--frame\r\nContent-Type: image/jpeg\r\n\r\n" + data + b"\r\n")
        time.sleep(0.04)   # ~25 FPS


@app.route("/video_feed")
def video_feed():
    return Response(_generate_mjpeg(),
                    mimetype="multipart/x-mixed-replace; boundary=frame")


@app.route("/")
def index():
    return "ANPR Server running. Stream: <a href='/video_feed'>/video_feed</a>"


# ══════════════════════════════════════════════════════════
#  ТОЧКА ВХОДА
# ══════════════════════════════════════════════════════════
if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="ANPR Barrier System")
    parser.add_argument(
        "--source",
        default="rtsp://admin:admin@192.168.0.105:554/live/ch0",
        help="RTSP URL, путь к видеофайлу или ID веб-камеры (0, 1, ...)",
    )
    args = parser.parse_args()

    os.makedirs(Config.SNAPSHOTS_DIR, exist_ok=True)

    # Видеопоток в отдельном потоке
    t = threading.Thread(target=video_thread, args=(args.source,), daemon=True)
    t.start()

    print(f"[INFO] Сервер запущен → http://localhost:5000/video_feed")
    app.run(host="0.0.0.0", port=5000, debug=False,
            threaded=True, use_reloader=False)