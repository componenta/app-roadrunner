<?php

declare(strict_types=1);

use Componenta\App\RoadRunner\Reset\CompositeRequestResetter;
use Componenta\App\RoadRunner\Reset\RequestResetterInterface;
use Componenta\App\RoadRunner\Reset\ResetFailedException;
use Componenta\App\RoadRunner\Tests\Fixture\CountingResetter;

it('runs resetters in declared order', function (): void {
    $order = [];
    $make = static function (string $name) use (&$order): RequestResetterInterface {
        return new class($name, $order) implements RequestResetterInterface {
        public function __construct(private string $name, private array &$order) {}

        public function reset(): void
        {
            $this->order[] = $this->name;
        }
        };
    };

    (new CompositeRequestResetter([$make('first'), $make('second')]))->reset();

    expect($order)->toBe(['first', 'second']);
});

it('runs every resetter and reports all failures', function (): void {
    $first = new CountingResetter();
    $first->failReset = true;
    $second = new CountingResetter();
    $second->failReset = true;

    try {
        (new CompositeRequestResetter([$first, $second]))->reset();
        test()->fail('Expected reset failure.');
    } catch (ResetFailedException $exception) {
        expect($exception->failures)->toHaveCount(2)
            ->and($first->resetCalls)->toBe(1)
            ->and($second->resetCalls)->toBe(1);
    }
});
