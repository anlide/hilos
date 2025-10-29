# WebSocket Test Demo Project

Простой demo проект для тестирования WebSocket функциональности Hilos v2 фреймворка.

## Структура проекта

```
websocket-test/
├── src/
│   ├── Bootstrap/
│   │   ├── daemon.php              # Точка входа daemon (аналогично framework)
│   │   ├── docker.php              # Docker watchdog (аналогично framework)
│   │   └── cli.php                 # CLI интерфейс (аналогично framework)
│   └── Core/
│       ├── Daemon/
│       │   └── WebSocketTestDaemon.php  # Главный daemon (наследует Hilos\Core\Daemon\DaemonManager)
│       ├── Worker/                 # Worker процессы (реализация замысла фреймворка)
│       └── Agent/                  # Агенты (реализация замысла фреймворка)
├── docker/
│   ├── Dockerfile                  # Docker образ для проекта
│   └── docker-compose.yml          # Docker Compose конфигурация
├── .env                            # Конфигурация (создается из .env.example)
├── .env.example                    # Пример конфигурации
├── composer.json                   # Автозагрузка и зависимости (с локальным подключением фреймворка)
└── README.md                       # Этот файл
```

## Настройка

1. **Важно:** Demo проект использует корневой `.env` файл проекта (`../../.env` из `demo/websocket-test/`)
   
   Если корневой `.env` ещё не создан, создайте его из корня проекта:
   ```bash
   cd ../../
   cp .env.example .env  # Если есть .env.example
   # Или создайте .env вручную
   ```

2. При необходимости отредактируйте `.env` файл в корне проекта.

**Примечание:** Demo проект использует тот же `.env` файл что и основной фреймворк для единообразия конфигурации.

3. Установите зависимости с локальным подключением фреймворка:
   ```bash
   composer install
   ```
   
   **Важно:** `composer.json` настроен на локальное подключение пакета `anlide/hilos` через `path` repository. Это позволяет использовать фреймворк напрямую из монoreпо без публикации в packagist.

## Запуск

### Через Docker (рекомендуется)

1. Убедитесь, что главная Docker сеть создана:
   ```bash
   cd ../../docker
   docker-compose up -d hilos-mysql  # Запустить MySQL для общей сети
   ```

2. Запустите проект:
   ```bash
   cd demo/websocket-test/docker
   docker-compose up
   ```

3. Для управления через CLI:
   ```bash
   docker-compose --profile composer run --rm websocket-test-cli php src/Bootstrap/cli.php status
   ```

4. Мониторинг:
   ```bash
   docker-compose --profile monitor run --rm websocket-test-monitor php src/Bootstrap/cli.php monitor
   ```

### Локальный запуск

```bash
# Запуск daemon напрямую
php src/Bootstrap/daemon.php

# Или через Docker watchdog (рекомендуется для разработки)
php src/Bootstrap/docker.php
```

## Особенности

- **Структура повторяет фреймворк** - `Bootstrap/`, `Core/`, `Socket/`, `API/` и т.д.
- **Bootstrap файлы** - `daemon.php`, `docker.php`, `cli.php` аналогичны `framework/src/Bootstrap/`
- **Namespace**: `Demo\WebSocketTest\`
- **.env в корне проекта** - `demo/websocket-test/.env`
- **Наследование DaemonManager** - `WebSocketTestDaemon` расширяет `Hilos\Core\Daemon\DaemonManager`
- **Agents и Workers** - директории готовы для реализации компонентов фреймворка
- **Локальное подключение фреймворка** - через `path` repository в `composer.json`

## Docker

Docker конфигурация:
- Использует существующую сеть `hilos-network` из главного `docker-compose.yml`
- Монтирует весь проект корень для доступа к фреймворку
- Работает из директории проекта (`/app/demo/websocket-test`)
- Использует отдельный IP адрес (`172.25.0.11`)

## Композиция локального пакета

`composer.json` настроен на локальное подключение `anlide/hilos`:

```json
"repositories": [
    {
        "type": "path",
        "url": "../../",
        "options": {
            "symlink": true
        }
    }
],
"require": {
    "anlide/hilos": "@dev"
}
```

Это позволяет:
- Использовать фреймворк напрямую из монoreпо
- Видеть изменения в фреймворке сразу без `composer update`
- Тестировать изменения фреймворка на реальном проекте

## Дальнейшее развитие

После реализации WebSocket сервера в фреймворке, здесь можно будет:
- Добавить WebSocket сервер в `Bootstrap/daemon.php`
- Создать тестовых агентов для обработки WebSocket соединений
- Создать worker процессы для тяжелых операций
- Добавить тестовый HTML/JS клиент в `public/` директорию

