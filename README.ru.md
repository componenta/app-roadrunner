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
composer require componenta/app-roadrunner:dev-dev
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

`Componenta\App\RoadRunner\AppFactory` заменяет конкретную фабрику приложения,
сохраняя публичный `AppFactoryInterface`. RoadRunner-приложение выбирается
только при `RR_MODE=http`. Вне воркера фабрика делегирует стандартной фабрике
Componenta, поэтому точки входа FPM, SAPI и CLI сохраняют прежнее поведение.
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
некорректные данные запроса RoadRunner — в пустой ответ `400`. Ошибка транспорта
или очистки завершает воркер с кодом `1`, чтобы RoadRunner заменил процесс с
недостоверным состоянием.

## Состояние запроса

Пакет автоматически очищает `CurrentUserProviderInterface` после прикладных
resetter-ов. Для других изменяемых общих сервисов зарегистрируйте собственный
resetter:

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
