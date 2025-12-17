<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.schema
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\System\Schema\Extension\Schema;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     */
    public function register(Container $container)
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new Schema((array) PluginHelper::getPlugin('system', 'schema'));
                $plugin->setDispatcher($container->get(DispatcherInterface::class));
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
