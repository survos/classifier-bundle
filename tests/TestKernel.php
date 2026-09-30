<?php

declare(strict_types=1);

namespace Survos\ClassifierBundle\Tests;

use Survos\ClassifierBundle\SurvosClassifierBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new SurvosClassifierBundle()];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', ['secret' => 'test', 'test' => true]);
            // Tests stay offline; bin/console turns Jev on when a key is present.
            $container->loadFromExtension('survos_classifier', ['jev' => [
                'enabled' => (bool) getenv('TYPESAFE_API_KEY'),
                'threshold' => (float) (getenv('JEV_THRESHOLD') ?: 0.5),
                'cache' => 'classifier.jev_cache',
            ]]);
            // Outside the kernel cache dir, so a threshold sweep replays the same responses instead of paying again.
            $container->register('classifier.jev_cache', FilesystemAdapter::class)->setArguments(['jev', 0, $this->getProjectDir().'/jev-responses']);
        });
    }

    /** Jev is wired at compile time, so runs with a different key presence or threshold must not share a container. */
    public function getCacheDir(): string
    {
        return parent::getCacheDir().(getenv('TYPESAFE_API_KEY') ? '-jev'.getenv('JEV_THRESHOLD') : '');
    }

    public function getProjectDir(): string
    {
        // Per checkout: a container compiled against one vendor/ must not be reused by another.
        return sys_get_temp_dir().'/survos-classifier-bundle-'.substr(hash('xxh64', __DIR__), 0, 8);
    }
}
