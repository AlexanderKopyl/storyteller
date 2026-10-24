<?php

declare(strict_types=1);

namespace Storyteller\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The phase smoke test: the real kernel compiles its container and Doctrine is
 * wired, with no PostgreSQL server reachable.
 *
 * Nothing here may open a connection - no getConnection()->connect(), no
 * executeQuery(), no schema API. The DBAL connection is lazy and must stay so.
 */
#[CoversNothing]
final class KernelBootTest extends KernelTestCase
{
    public function testKernelBootsAndBuildsTheContainer(): void
    {
        $kernel = self::bootKernel();

        self::assertSame('test', $kernel->getEnvironment());
        self::assertTrue($kernel->isDebug());

        $container = self::getContainer();

        self::assertInstanceOf(ContainerInterface::class, $container);
        self::assertTrue($container->has('doctrine'), 'The doctrine service is not registered.');
    }

    public function testDoctrineIsWiredWithoutOpeningAConnection(): void
    {
        self::bootKernel();

        $registry = self::getContainer()->get('doctrine');

        self::assertInstanceOf(ManagerRegistry::class, $registry);
        self::assertInstanceOf(EntityManagerInterface::class, $registry->getManager());
    }
}
