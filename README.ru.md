# Componenta App RoadRunner

`componenta/app-roadrunner` запускает существующий HTTP-конвейер Componenta
внутри воркера RoadRunner. Пакет сохраняет `Componenta\App\Scope::HTTP`,
обычные HTTP-загрузчики, конфигурацию middleware, маршрутизацию и обработку
ошибок.

Пакет отвечает только за транспорт и жизненный цикл RoadRunner. Сервисы,
хранящие данные запроса, должны очищать своё состояние через
`RequestResetterInterface`. Пакет не исследует закрытое состояние Componenta,
Cycle или PHP с помощью Reflection.

English documentation: [README.md](README.md).

## Установка

```bash
composer require componenta/app-roadrunner:^3.0
composer require --dev spiral/roadrunner-cli:^2.7
vendor/bin/rr get-binary
```

Скопируйте точку входа воркера и пример конфигурации сервера:

```bash
cp vendor/componenta/app-roadrunner/resources/bin/roadrunner.php bin/roadrunner.php
cp vendor/componenta/app-roadrunner/resources/.rr.yaml .rr.yaml
```

Composer-плагин Componenta обнаруживает
`Componenta\App\RoadRunner\ConfigProvider` из метаданных пакета. Если список
провайдеров ведётся вручную, добавьте его после стандартного
`Componenta\App\Server\ConfigProvider`.

После установки или изменения конфигурации пересоберите production-кеш:

```bash
php bin/console.php app:build
```

Запустите сервер:

```bash
./rr serve -c .rr.yaml
```

## Выбор среды выполнения

Провайдер декорирует `Componenta\App\Server\HttpAppAdapter` через DI адаптером
`Componenta\App\RoadRunner\HttpAppAdapter`. Общая фабрика приложения выбирает
HTTP-adapter обычным способом. При `RR_MODE=http` он создаёт RoadRunner-приложение,
а вне воркера делегирует исходному HTTP-adapter. Порядок провайдеров не меняет
выбор реализации; CLI и WebSocket сохраняют своё поведение.
RoadRunner-приложение само реализует `HttpBootTargetInterface`, а стандартный
адаптер `componenta/app-http` использует его без отдельного интеграционного адаптера.

Отдельного scope RoadRunner и ручных режимов `always`/`never` нет. Запуск
RoadRunner-приложения без протокола воркера некорректен, как и запуск обычного
SAPI-приложения внутри воркера.

## Жизненный цикл запроса

RoadRunner-приложение:

1. один раз строит настроенный PSR-15-конвейер;
2. после загрузки Componenta обновляет базовое состояние `$_SERVER`;
3. получает PSR-7-запросы через официальный `PSR7Worker`;
4. обрабатывает каждый запрос тем же конвейером Componenta;
5. подготавливает и отправляет PSR-7-ответ;
6. запускает resetter-ы в порядке конфигурации;
7. закрывает буферы вывода, открытые запросом;
8. принимает следующий запрос.

Приоритет middleware и порядок элементов с одинаковым приоритетом совпадают с
`componenta/app-http`.

Необработанное исключение конвейера превращается в пустой ответ `500`, а
распознанные некорректные данные запроса — в пустой ответ `400`.
При непредвиденной ошибке получения запроса ответ не отправляется, поскольку
транспорт может потерять синхронизацию. Ошибка транспорта
или очистки завершает воркер с кодом `1`, чтобы RoadRunner заменил процесс с
недостоверным состоянием.

## Состояние запроса

Изменяемые общие сервисы регистрируют собственные resetter-ы. В DI 5 нет
провайдера текущего пользователя; контекст пользователя и его очистка принадлежат
приложению:

```php
use App\Tenant\TenantContext;
use Componenta\App\RoadRunner\Reset\RequestResetterInterface;

final readonly class TenantContextResetter implements RequestResetterInterface
{
    public function __construct(private TenantContext $context) {}

    public function reset(): void
    {
        $this->context->clear();
    }
}
```

```php
return [
    'roadrunner' => [
        'resetters' => [
            App\Infrastructure\Reset\TenantContextResetter::class,
        ],
    ],
];
```

Все resetter-ы вызываются, даже если один из них завершился с ошибкой. Ошибки
объединяются в `ResetFailedException`, после чего воркер завершается.

## Конфигурация

```php
return [
    'roadrunner' => [
        'http' => ['chunk_size' => 0],
        'worker' => ['intercept_side_effects' => true],
        'resetters' => [],
    ],
];
```

- `roadrunner.http.chunk_size` управляет потоковой отправкой PSR-7-ответа.
- `roadrunner.worker.intercept_side_effects` защищает протокол Goridge от
  случайного вывода PHP в stdout.
- `roadrunner.resetters` содержит упорядоченный список идентификаторов сервисов,
  реализующих `RequestResetterInterface`.

## Подготовка HTTP-ответов

При HEAD неперематываемое тело не вычитывается для вычисления необязательных
метаданных. Заголовок `Accept-Ranges` передаётся, если его явно установило
приложение. Ответ 304 сохраняет явно заданные допустимые метаданные
представления. При конфликте `Transfer-Encoding` и `Content-Length` удаляется
`Content-Length`.

При нулевом размере порции тело ответа материализуется до отправки через официальный транспорт.
Положительное значение включает потоковую отправку через открытый транспортный
интерфейс RoadRunner. Неперематываемые потоки предварительно буферизуются;
отсутствие продвижения чтения приводит к ошибке, а не к усечённому ответу.
Большие или неограниченные неперематываемые потоки не подходят для потоковой
передачи с ограниченным потреблением памяти.

Поставляемая точка входа защищает стандартный вывод неудаляемым буфером
и учитывает маску `error_reporting()`. Сообщения об устаревании регистрируются
PHP, но не превращаются в ошибки воркера. Прямые записи в STDOUT запрещены.
Проверка структуры постоянных буферов не гарантирует обнаружение замены
полностью идентичным обработчиком. Приложение не должно изменять постоянные
буферы среды выполнения и сохранять данные запроса в глобальном состоянии PHP.
