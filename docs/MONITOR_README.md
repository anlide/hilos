# Hilos v2 - Daemon Monitor

Интерактивный монитор для наблюдения за состоянием демона Hilos в реальном времени.

## Возможности

- ✅ **Реальные данные**: время, использование памяти, CPU
- ✅ **Обновляемая таблица**: обновление каждую секунду
- ✅ **Graceful exit**: Ctrl+C для прерывания
- ✅ **Кроссплатформенность**: Windows и Linux

## Использование

### Linux (Production)

```bash
# Запуск монитора
composer run daemon-monitor

# Или напрямую через docker-compose
docker-compose -f docker/docker-compose.yml exec hilos php framework/src/Bootstrap/cli.php daemon:monitor
```

### Windows (Development)

```bash
# Используйте PowerShell скрипт
scripts/monitor.ps1

# Или через PowerShell напрямую
powershell -ExecutionPolicy Bypass -File scripts/monitor.ps1
```

## Требования

- **Docker** и **docker-compose**
- **PHP 8.4+** с расширениями PCNTL и POSIX
- **TTY терминал** (для интерактивного режима)

## Мониторинг для Zabbix

Для интеграции с Zabbix используйте команду `daemon:status`:

```bash
# Получение статуса (JSON формат)
composer run daemon-status

# Или напрямую
docker-compose -f docker/docker-compose.yml run --rm hilos-cli php framework/src/Bootstrap/cli.php daemon:status
```

## Устранение проблем

### Ошибка "Terminal not supported"

**Причина**: Команда запущена без TTY (например, через cron или CI/CD)

**Решение**:
1. Используйте `daemon:status` вместо `daemon:monitor`
2. На Windows используйте `scripts/monitor.ps1`
3. Убедитесь, что запускаете в терминале

### Ошибка "PCNTL not available"

**Причина**: Расширение PCNTL не установлено

**Решение**:
```bash
# Установка PCNTL в Docker
docker-php-ext-install pcntl posix
```

### Ctrl+C не работает

**Причина**: Проблемы с передачей сигналов в Docker

**Решение**:
1. Используйте `docker exec -it` вместо `docker-compose run`
2. На Windows используйте `scripts/monitor.ps1`
3. Убедитесь, что контейнер запущен с `-it` флагом

## Архитектура

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Terminal      │───▶│   Docker        │───▶│   PHP Monitor   │
│   (Ctrl+C)      │    │   (TTY)         │    │   (PCNTL)       │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

## Файлы

- `framework/src/Bootstrap/cli.php` - Основной код монитора
- `scripts/monitor.ps1` - PowerShell скрипт для Windows
- `docker/docker-compose.yml` - Конфигурация Docker
- `composer.json` - Команды для запуска

## Разработка

Для добавления новых метрик:

1. Обновите функцию `getMonitorData()` в `cli.php`
2. Добавьте новую строку в таблицу в `updateDisplay()`
3. Протестируйте на Windows и Linux

## Лицензия

MIT License
