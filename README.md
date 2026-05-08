# 🅿️ Parking System с ANPR

Интеллектуальная система управления автостоянкой с использованием распознавания номерных знаков (ANPR) на основе компьютерного зрения.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Status: Beta](https://img.shields.io/badge/Status-Beta-orange.svg)](https://github.com)
[![Python: 3.8+](https://img.shields.io/badge/Python-3.8%2B-blue.svg)](https://www.python.org/)

## 🎯 Описание проекта

Система автоматизирует процесс управления парковкой путем:
- 📸 **Распознавания номеров** — детектирование и чтение номерных знаков в реальном времени
- 🗄️ **Управления базой данных** — регистрация транспортных средств и истории парковок
- 👤 **Управления доступом** — аутентификация пользователей и проверка разрешений
- 📊 **Аналитики** — отчеты о загруженности и статистике

## ⚙️ Технический стек

### Backend
- **PHP 7.4+** — веб-приложение и API
- **MySQL** — база данных
- **Python 3.8+** — ML-модели и обработка изображений

### Machine Learning
- **YOLOv8** — детектирование номерных знаков
- **CRNN** — распознавание текста на номерах
- **PyTorch** — фреймворк для моделей

### Frontend
- **HTML/CSS** — интерфейс
- **JavaScript** — интерактивность

## 🚀 Быстрый старт

### Требования
- Python 3.8+
- PHP 7.4+
- MySQL 5.7+
- pip / conda

### Установка

1. **Клонируйте репозиторий**
   ```bash
   git clone https://github.com/yourusername/parking_system.git
   cd parking_system
   ```

2. **Установите Python зависимости**
   ```bash
   pip install -r requirements.txt
   ```

3. **Настройте базу данных**
   ```bash
   mysql -u root -p < ANPR-System-main/parking_system.sql
   ```

4. **Обновите конфигурацию БД**
   Отредактируйте `ANPR-System-main/db_config.php` с вашими учетными данными.

5. **Запустите приложение**
   ```bash
   php -S localhost:8000
   python ANPR-System-main/main.py
   ```

## 📁 Структура проекта

```
parking_system/
├── ANPR-System-main/
│   ├── index.php              # Главная страница
│   ├── auth.php               # Аутентификация
│   ├── db_config.php          # Конфигурация БД
│   ├── main.py                # Основной скрипт Python
│   ├── inference.py           # Инференс моделей
│   ├── models/                # Обученные модели
│   │   ├── yolo/              # YOLOv8 для детектирования
│   │   └── ocr_crnn/          # CRNN для распознавания
│   └── parking_system.sql     # Schema БД
├── ocr_crnn/                  # Тренировка CRNN модели
├── yolo_finetun/              # Тренировка YOLO модели
└── README.md                  # Этот файл
```

## 📖 Документация

Подробная документация находится в папке `docs/`:
- [Инструкция по установке и настройке](docs/SETUP.md)
- [Архитектура системы](docs/ARCHITECTURE.md)
- [API документация](docs/API.md)

## 🔧 Использование

### Детектирование номерных знаков
```python
from ANPR-System-main.inference import detect_plates

image_path = "path/to/image.jpg"
results = detect_plates(image_path)
for plate in results:
    print(f"Номер: {plate['text']}, Уверенность: {plate['confidence']}")
```

### Тестирование на изображении
```bash
python ANPR-System-main/test_photo.py path/to/image.jpg
```

## 📊 Результаты

- ✅ Точность детектирования номеров: **95%+**
- ✅ Точность распознавания текста: **92%+**
- ✅ Скорость обработки: **<500ms per image**

## 🤝 Вклад в проект

Мы приветствуем помощь! Смотрите [CONTRIBUTING.md](CONTRIBUTING.md) для подробностей.

## 📝 Лицензия

Этот проект лицензирован под MIT лицензией — смотрите файл [LICENSE](LICENSE).


---

**Последнее обновление:** май 2026
