<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Reset;

use Componenta\App\RoadRunner\ConfigKey;
use Componenta\Config\ConfigPath;
use Componenta\Config\ContainerValue;
use InvalidArgumentException;

final readonly class RequestResetterFactory
{
    public function __invoke(ContainerValue $container): RequestResetterInterface
    {
        $serviceIds = $container->config->array(new ConfigPath(ConfigKey::RESETTERS), []);
        $resetters = [];
        $resolvedIds = [];

        foreach ($serviceIds as $index => $serviceId) {
            if (!is_string($serviceId) || trim($serviceId) === '') {
                throw new InvalidArgumentException(sprintf(
                    'RoadRunner resetter at index %s must be a non-empty service id, %s given.',
                    (string) $index,
                    get_debug_type($serviceId),
                ));
            }

            if ($serviceId === RequestResetterInterface::class) {
                throw new InvalidArgumentException(sprintf(
                    'RoadRunner resetter list cannot contain its aggregate service %s.',
                    RequestResetterInterface::class,
                ));
            }

            if (isset($resolvedIds[$serviceId])) {
                continue;
            }

            $resetters[] = $container->get($serviceId, RequestResetterInterface::class);
            $resolvedIds[$serviceId] = true;
        }

        return new CompositeRequestResetter($this->deduplicate($resetters));
    }

    /**
     * @param list<RequestResetterInterface> $resetters
     * @return list<RequestResetterInterface>
     */
    private function deduplicate(array $resetters): array
    {
        $unique = [];
        $seen = [];

        foreach ($resetters as $resetter) {
            $id = spl_object_id($resetter);

            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $unique[] = $resetter;
            }
        }

        return $unique;
    }
}
