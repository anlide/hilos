# Runtime Base

Абстрактные базовые классы для runtime-слоя (контекст, элемент, коллекция, действия).

**Назначение:** сюда вынесена реализация, которая раньше жила в `framework/backend/Runtime/Idea/`. Имена классов приведены к виду `Rt*Base` (без «Idea»): при переносе в Hilos от «Idea» в публичном API отказались; в исключениях (`Exception\Runtime\*`) по историческим причинам остаются имена с префиксом Idea (IdeaRtItemException и т.д.), но это только имена классов исключений.

- **RtContextBase** — база для runtime-контекста (хранит state-коллекции и обёртки RtCollection).
- **RtItemBase** — база для read-only обёртки над RtState.
- **RtCollectionBase** — база для read-only обёртки над RtStates, кэш элементов, Actions.
- **RtActionsBase** — база для мутаций (add/remove/clear), проверка TruthSource.

Публичные классы приложения наследуют не Base, а классы из `Hilos\Hilos\Runtime\*`: `RtContext`, `RtItem`, `RtCollection`, `RtActions`.
