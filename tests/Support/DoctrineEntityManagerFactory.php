<?php

declare(strict_types=1);

namespace Watchtower\Tests\Support;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\SymfonyFileLocator;

final class DoctrineEntityManagerFactory
{
    public static function create(
        string $mappingDirectory,
        string $mappingNamespace = 'Watchtower\\Tests\\Support\\Fixtures\\Entity'
    ): EntityManagerInterface {
        $configuration = new Configuration;
        $configuration->setMetadataDriverImpl(
            new XmlDriver(
                new SymfonyFileLocator(
                    [$mappingDirectory => $mappingNamespace],
                    '.dcm.xml'
                )
            )
        );
        $configuration->setProxyDir(\sys_get_temp_dir().'/watchtower_tests_proxies');
        $configuration->setProxyNamespace('WatchtowerTests\\Proxy');

        if (\method_exists($configuration, 'enableNativeLazyObjects')) {
            $configuration->enableNativeLazyObjects(true);
        }

        if (\method_exists($configuration, 'setAutoGenerateProxyClasses')) {
            $configuration->setAutoGenerateProxyClasses(true);
        }

        $connectionParams = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ];

        if (\method_exists(EntityManager::class, 'create')) {
            /** @var EntityManagerInterface $entityManager */
            $entityManager = EntityManager::create($connectionParams, $configuration);
        } else {
            /** @var EntityManagerInterface $entityManager */
            $entityManager = new EntityManager(
                DriverManager::getConnection($connectionParams),
                $configuration
            );
        }

        self::createSchema($entityManager);

        return $entityManager;
    }

    private static function createSchema(
        EntityManagerInterface $entityManager
    ): void {
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        (new SchemaTool($entityManager))->createSchema($metadata);
    }
}
