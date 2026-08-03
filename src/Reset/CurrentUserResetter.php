<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Reset;

use Componenta\DI\Resolver\CurrentUserProviderInterface;
use RuntimeException;

final readonly class CurrentUserResetter implements RequestResetterInterface
{
    private const int MAX_CLEAR_ATTEMPTS = 32;

    public function __construct(private CurrentUserProviderInterface $provider) {}

    public function reset(): void
    {
        for ($attempt = 0; $attempt < self::MAX_CLEAR_ATTEMPTS; ++$attempt) {
            $user = $this->provider->getUser();
            if ($user === null) {
                return;
            }

            $this->provider->setUser(null);

            // The local reference must be released inside the loop so a user
            // destructor can run now. It may install a replacement user; the
            // next iteration clears that value as well.
            unset($user);
        }

        if ($this->provider->getUser() !== null) {
            throw new RuntimeException(sprintf(
                'Current-user cleanup did not converge after %d attempts.',
                self::MAX_CLEAR_ATTEMPTS,
            ));
        }
    }
}
