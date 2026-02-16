# План рефакторинга: структура Hilos и demo

## Цель

- **Новое место:** `framework/backend/Hilos` и `framework/backend/Hilos/Runtime`  
- **Устаревшее:** `framework/backend/Runtime`, наследуемая от него структура в demo  
- Привести к единой логичной структуре папок под `hilos` и повторить её в demo.

## Целевая структура каталогов

```
hilos/
├── runtime/
│   ├── item/           # RtItem и наследники
│   ├── collection/     # RtCollection и наследники
│   ├── state/          # State (аналог RtState)
│   └── stateCollection/# StateCollection (аналог RtStates)
├── database/
│   ├── item/           # DbItem и наследники
│   ├── collection/     # DbCollection и наследники
│   ├── object/         # Object (базовый слой или обёртка)
│   ├── objectCollection/
│   ├── entity/
│   └── entityCollection/
└── table/              # Table* (как есть или с подпапками по необходимости)
```

Для PSR-4 имена папок — с большой буквы: `Item`, `Collection`, `State`, `StateCollection`, `Object`, `ObjectCollection`, `Entity`, `EntityCollection`.

---

## Текущее состояние

### Framework backend

| Где сейчас | Что |
|------------|-----|
| `framework/backend/Hilos/Runtime/` | RtContext, RtActions, RtCollection, RtItem (все в одной папке) |
| `framework/backend/Hilos/Database/` | DbContext, Hilos, DbActions, DbCollection, DbItem (в одной папке) |
| `framework/backend/Hilos/Table/` | TablePayloadBuilder, TableActionHandler, TableHub |
| `framework/backend/Runtime/` (устаревшее) | Idea/IdeaRt*, State/RtState, RtStates, RtTruthSourceRegistry |
| `framework/backend/Database/` | Idea/, Object/, Entity/, Hilos/, Schema/, Filter/, … |

### Demo (chat)

| Где сейчас | Что |
|------------|-----|
| `demo/chat/backend/Hilos/Runtime/Rt/` | Connection, Connections (Rt item/collection) |
| `demo/chat/backend/Hilos/Runtime/RtActions/` | ConnectionsActions |
| `demo/chat/backend/Hilos/Database/Db/` | User, Bot, Event, Moderator (Db items) |
| `demo/chat/backend/Hilos/Database/DbCollection/` | Users, Bots, Events, Moderators |
| `demo/chat/backend/Hilos/Database/DbActions/` | UsersActions, EventsActions |
| `demo/chat/backend/Runtime/State/` (устаревшее) | Connection, Connections (state) |
| `demo/chat/backend/Database/Object/`, `ObjectCollection/`, `Entity/`, `EntityCollection/`, `Idea/`, … | Наследуемая от старого Runtime/Database структура |

---

## Этапы переделки

### Этап 1. Framework: Hilos Runtime

1. **Создать подпапки под `Hilos/Runtime/`:**
   - `Item/` — перенести `RtItem.php`, namespace `Hilos\Hilos\Runtime\Item`.
   - `Collection/` — перенести `RtCollection.php`, namespace `Hilos\Hilos\Runtime\Collection`.
   - `State/` — перенести сюда классы из устаревшего `framework/backend/Runtime/State/` (RtState, RtStates), namespace `Hilos\Hilos\Runtime\State` и `Hilos\Hilos\Runtime\StateCollection` (или один общий State с подпространством имён по смыслу).
   - Уточнение: если RtState = один state, RtStates = коллекция, то логично:
     - `State/` → RtState (и при необходимости базовые вещи state),
     - `StateCollection/` → RtStates.

2. **Оставить в корне `Hilos/Runtime/`:**
   - `RtContext.php`, `RtActions.php` (namespace `Hilos\Hilos\Runtime` без изменений), либо вынести в подпапки `Context/`, `Actions/` при желании ещё больше структурировать.

3. **Обновить все `use` и ссылки** на `Hilos\Hilos\Runtime\Item\RtItem`, `Hilos\Hilos\Runtime\Collection\RtCollection`, `Hilos\Hilos\Runtime\State\RtState`, `Hilos\Hilos\Runtime\StateCollection\RtStates` (и т.п. в зависимости от финальных имён/неймспейсов).

4. **Исключения:** обновить пути в `framework/backend/Exception/Hilos/Runtime/` под новые неймспейсы.

---

### Этап 2. Framework: Hilos Database

1. **Создать подпапки под `Hilos/Database/`:**
   - `Item/` — перенести `DbItem.php`, namespace `Hilos\Hilos\Database\Item`.
   - `Collection/` — перенести `DbCollection.php`, namespace `Hilos\Hilos\Database\Collection`.
   - `Object/`, `ObjectCollection/`, `Entity/`, `EntityCollection/` — решить:
     - **Вариант A:** оставить базовые классы в `framework/backend/Database/Object/` и `Entity/`, а в Hilos только item/collection (и при необходимости тонкие обёртки в object/entity позже).
     - **Вариант B:** перенести базовые классы Object, ObjectCollection, Entity, EntityCollection в `Hilos/Database/Object/`, … и перевести неймспейсы на `Hilos\Hilos\Database\*` (тогда старый `Hilos\Database\*` помечать устаревшим и делегировать в Hilos).

2. **Оставить в корне `Hilos/Database/`:**
   - `DbContext.php`, `Hilos.php`, `DbActions.php` (namespace `Hilos\Hilos\Database`) или разнести по подпапкам Context/, Actions/ при необходимости.

3. **Обновить все `use`** на `Hilos\Hilos\Database\Item\DbItem`, `Hilos\Hilos\Database\Collection\DbCollection` и т.д.

4. **Исключения:** обновить `framework/backend/Exception/Hilos/Database/` под новые неймспейсы.

---

### Этап 3. Framework: Hilos Table

- Оставить `Hilos/Table/` как есть (TablePayloadBuilder, TableActionHandler, TableHub) или при желании ввести подпапки (например `Hub/`, `Action/`, `Payload/`) и соответствующие неймспейсы.
- Обновить ссылки на классы Table при любом переименовании/переносе.

---

### Этап 4. Framework: устаревший Runtime

- После переноса State в Hilos:
  - Пометить `framework/backend/Runtime/` как deprecated (комментарии, docblock).
  - Оставить классы Idea (IdeaRt, IdeaRtItem, IdeaRtCollection, IdeaRtActions) в старом месте до отдельного рефакторинга базового слоя или делегировать их в Hilos по необходимости.
- `RtTruthSourceRegistry` — решить: перенос в `Hilos/Runtime/` или оставить в старом Runtime с пометкой deprecated.

---

### Этап 5. Demo (chat): наследование структуры от Hilos

1. **Runtime — повторить структуру:**
   - `demo/chat/backend/Hilos/Runtime/Item/` — при необходимости общие Rt-item’ы (если появятся), иначе оставить только collection/state.
   - `demo/chat/backend/Hilos/Runtime/Collection/` — перенести сюда `Connections.php` (сейчас в `Rt/`), namespace `Demo\Chat\Hilos\Runtime\Collection`.
   - `demo/chat/backend/Hilos/Runtime/State/` — перенести сюда `Connection.php` (state) из `demo/chat/backend/Runtime/State/`, namespace `Demo\Chat\Hilos\Runtime\State`.
   - `demo/chat/backend/Hilos/Runtime/StateCollection/` — перенести сюда `Connections.php` (state collection) из `demo/chat/backend/Runtime/State/`, namespace `Demo\Chat\Hilos\Runtime\StateCollection`.
   - Удалить или пометить deprecated: `demo/chat/backend/Hilos/Runtime/Rt/`, `demo/chat/backend/Runtime/State/`, старые Idea-классы в Runtime.

2. **Database — повторить структуру:**
   - `demo/chat/backend/Hilos/Database/Item/` — перенести сюда классы из `Db/` (User, Bot, Event, Moderator), namespace `Demo\Chat\Hilos\Database\Item` (или оставить подпапку `Db` как синоним Item по смыслу — лучше один раз выбрать «Item» для единообразия).
   - `demo/chat/backend/Hilos/Database/Collection/` — перенести сюда Users, Bots, Events, Moderators из `DbCollection/`, namespace `Demo\Chat\Hilos\Database\Collection`.
   - `demo/chat/backend/Hilos/Database/Object/` — перенести сюда классы из `demo/chat/backend/Database/Object/` (User, Bot, Event, Moderator).
   - `demo/chat/backend/Hilos/Database/ObjectCollection/` — перенести сюда Users, Bots, Events, Moderators из `demo/chat/backend/Database/ObjectCollection/`.
   - `demo/chat/backend/Hilos/Database/Entity/` — перенести классы из `demo/chat/backend/Database/Entity/` (например Moderator).
   - `demo/chat/backend/Hilos/Database/EntityCollection/` — перенести коллекции из `demo/chat/backend/Database/EntityCollection/`.
   - В корне `Hilos/Database/` оставить: Hilos.php, DbChat.php, DbActions в подпапке `Actions/` (UsersActions, EventsActions) с неймспейсом `Demo\Chat\Hilos\Database\Actions` или оставить текущий вариант.

3. **Обновить все импорты** в demo (use, расширения классов, фабрики, bootstrap) под новые пути и неймспейсы.

4. **Устаревшее в demo:** пометить deprecated или удалить после переноса:
   - `demo/chat/backend/Database/Idea/`, `IdeaCollection/`, `IdeaActions/`, `Object/`, `ObjectCollection/`, `Entity/`, `EntityCollection/`
   - `demo/chat/backend/Runtime/Idea/`, `IdeaActions/`, `State/`

---

### Этап 6. Остальные demo-проекты

- Для каждого demo, использующего Hilos/Runtime/Database (simple-todo, simple-poll, chat и т.д.):
  - Привести структуру к той же схеме: `runtime/(item|collection|state|stateCollection)`, `database/(item|collection|object|objectCollection|entity|entityCollection)`, `table` при наличии.
  - Обновить неймспейсы и use по образцу chat.

---

### Этап 7. Исключения и обратная совместимость

- Пройти по всем исключениям в `framework/backend/Exception/` (в т.ч. `Exception/Hilos/`), обновить ссылки на классы и неймспейсы.
- При необходимости оставить алиасы классов в старых неймспейсах с `@deprecated` и перенаправлением на новые (если нужна постепенная миграция без поломки внешнего кода).

---

## Порядок выполнения (рекомендуемый)

1. Framework Hilos Runtime (этап 1) + обновление use и исключений.  
2. Framework Hilos Database (этап 2) + обновление use и исключений.  
3. Framework Hilos Table (этап 3), при необходимости.  
4. Пометить устаревший Runtime (этап 4).  
5. Demo chat: Runtime и Database (этап 5).  
6. Остальные demo (этап 6).  
7. Финальная проверка исключений и обратной совместимости (этап 7).

---

## Решения, которые нужно принять

- **State в framework:** переносить ли RtState/RtStates из `Hilos\Runtime\State` в `Hilos\Hilos\Runtime\State` и `Hilos\Hilos\Runtime\StateCollection` (и переименовать RtStates → StateCollection в неймспейсе Hilos) или оставить базовые классы в старом Runtime и в Hilos только использовать их.
- **Object/Entity в Hilos:** оставить базу в `framework/backend/Database/Object|Entity` и в Hilos только item/collection (вариант A) или переносить объектный/сущностный слой в Hilos (вариант B).
- **RtContext/RtActions:** оставлять в `Hilos/Runtime/` или выносить в `Runtime/Context/`, `Runtime/Actions/` для единообразия с item/collection/state/stateCollection.
- **Имена папок:** строго с большой буквы (Item, Collection, State, StateCollection, Object, ObjectCollection, Entity, EntityCollection) для соответствия PSR-4 и неймспейсам.

После этих решений можно выполнять план по этапам без противоречий с неймспейсами и автозагрузкой.
