# PHP Class Members

Read this before adding, moving, or reordering constants, properties, or methods
in a PHP class.

## Core Rule

Declare class members in this order:

1. Public constants
2. Protected constants
3. Private constants
4. Public properties
5. Protected properties
6. Private properties
7. Constructor
8. Public methods
9. Protected methods
10. Private methods

Within each visibility group, keep related members together. When a class mixes
static and instance members in the same visibility group, prefer static members
before instance members.

## Workflow

1. When adding a new constant to a class that already has properties, place the
   constant above all properties, not after them.
2. When refactoring an existing class, reorder members only when you are already
   editing that class for another reason, unless the user explicitly asked for
   a member-order cleanup.
3. Keep constants-only classes (`*Constants.php`, enums, pure config holders)
   limited to constants; they do not need property sections.
4. Do not split one logical constant group across the class body. Keep page,
   agent, signal, and field-name constants together in their visibility block.

## Preferred Shape

```php
final class ExamplePage extends AbstractPage
{
    public const string PAGE = 'example';

    public const array ACTIONS = [];

    protected PageAgentInterface $agent;

    public function __construct(PageAgentInterface $agent)
    {
        $this->agent = $agent;
    }

    public function onSubscribe(string $acceptKey, PageRouteParams $params): void
    {
        // ...
    }
}
```

Static utility classes follow the same constant-before-property rule:

```php
final class Logger
{
    public const string LEVEL_INFO = 'INFO';

    private const int AGENT_USER_MESSAGE_MAX_LENGTH = 200;

    private static ?string $logFile = null;

    public static function info(string $message): void
    {
        // ...
    }
}
```

## Anti-Patterns

```php
// Wrong: properties declared before constants.
class Logger
{
    private static ?string $logFile = null;

    public const string LEVEL_INFO = 'INFO';
}
```

```php
// Wrong: duplicate import after adding a new use statement.
use Hilos\Utils\Logger;
use Hilos\Utils\Logger;
```

When adding a new `use` import, check the existing import block first. Do not
append a symbol that is already imported.

## Exceptions

- Vendor code and generated files are outside this rule.
- Traits may declare members in the order required by the trait's contract; when
  a class `use`s a trait, keep the trait import with other traits according to
  project import order, not inside the member-order list above.
- Abstract base classes that intentionally expose only constants plus abstract
  methods may omit unused property sections.

## Validation

After reordering members or adding imports in a PHP class, run:

```bash
php -l path/to/ChangedClass.php
```

For framework changes with tests nearby, run the smallest relevant composer test
script from [testing.md](../testing.md).
