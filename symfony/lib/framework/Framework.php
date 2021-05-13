<?php
/**
 * OrangeHRM is a comprehensive Human Resource Management (HRM) System that captures
 * all the essential functionalities required for any enterprise.
 * Copyright (C) 2006 OrangeHRM Inc., http://www.orangehrm.com
 *
 * OrangeHRM is free software; you can redistribute it and/or modify it under the terms of
 * the GNU General Public License as published by the Free Software Foundation; either
 * version 2 of the License, or (at your option) any later version.
 *
 * OrangeHRM is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program;
 * if not, write to the Free Software Foundation, Inc., 51 Franklin Street, Fifth Floor,
 * Boston, MA  02110-1301, USA
 */

namespace OrangeHRM\Framework;

use Exception;
use Monolog\Handler\StreamHandler;
use OrangeHRM\Config\Config;
use OrangeHRM\Framework\Event\EventDispatcher;
use OrangeHRM\Framework\Http\ControllerResolver;
use OrangeHRM\Framework\Http\Request;
use OrangeHRM\Framework\Http\RequestStack;
use OrangeHRM\Framework\Logger\Logger;
use OrangeHRM\Framework\Routing\RequestContext;
use OrangeHRM\Framework\Routing\UrlGenerator;
use OrangeHRM\Framework\Routing\UrlMatcher;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class Framework extends HttpKernel
{
    /**
     * @var null|string
     */
    protected ?string $environment = null;
    /**
     * @var null|bool
     */
    protected ?bool $debug = null;

    public function __construct(string $environment, bool $debug)
    {
        $this->environment = $environment;
        $this->debug = $debug;
        $this->configureContainer();
        $this->configureLogger();

        /** @var EventDispatcher $dispatcher */
        $dispatcher = ServiceContainer::getContainer()->get(Services::EVENT_DISPATCHER);
        /** @var ControllerResolver $resolver */
        $resolver = ServiceContainer::getContainer()->get(Services::CONTROLLER_RESOLVER);
        /** @var RequestStack $requestStack */
        $requestStack = ServiceContainer::getContainer()->get(Services::REQUEST_STACK);
        /** @var ArgumentResolver $argumentResolver */
        $argumentResolver = ServiceContainer::getContainer()->get(Services::ARGUMENT_RESOLVER);

        parent::__construct($dispatcher, $resolver, $requestStack, $argumentResolver);
    }

    protected function configureContainer(): void
    {
        ServiceContainer::getContainer()->register(Services::REQUEST_STACK, RequestStack::class);
        ServiceContainer::getContainer()->register(Services::ROUTER_REQUEST_CONTEXT, RequestContext::class);
        ServiceContainer::getContainer()->register(Services::EVENT_DISPATCHER, EventDispatcher::class);
        ServiceContainer::getContainer()->register(Services::CONTROLLER_RESOLVER, ControllerResolver::class);
        ServiceContainer::getContainer()->register(Services::ARGUMENT_RESOLVER, ArgumentResolver::class);
        ServiceContainer::getContainer()->set(Services::HTTP_KERNEL, $this);
    }

    protected function configureLogger(): void
    {
        $logger = new Logger('orangehrm');
        $logger->pushHandler(
            new StreamHandler(Config::get('ohrm_log_dir') . DIRECTORY_SEPARATOR . 'orangehrm.log', Logger::WARNING)
        );
        ServiceContainer::getContainer()->set(Services::LOGGER, $logger);
    }

    protected function configureRouter(Request $request): void
    {
        /** @var RequestContext $context */
        $context = ServiceContainer::getContainer()->get(Services::ROUTER_REQUEST_CONTEXT);
        $context->fromRequest($request);

        $routes = RouteManager::getRoutes();
        ServiceContainer::getContainer()->register(Services::ROUTER, UrlMatcher::class)
            ->addArgument($routes)
            ->addArgument($context);

        /** @var UrlMatcher $matcher */
        $matcher = ServiceContainer::getContainer()->get(Services::ROUTER);
        /** @var RequestStack $requestStack */
        $requestStack = ServiceContainer::getContainer()->get(Services::REQUEST_STACK);

        /** @var Logger $logger */
        $logger = ServiceContainer::getContainer()->get(Services::LOGGER);

        $routerListener = new RouterListener($matcher, $requestStack, $context, $logger, null, $this->isDebug());
        /** @var EventDispatcher $dispatcher */
        $dispatcher = ServiceContainer::getContainer()->get(Services::EVENT_DISPATCHER);
        $dispatcher->addSubscriber($routerListener);

        $urlGenerator = new UrlGenerator($routes, $context, $logger);
        ServiceContainer::getContainer()->set(Services::URL_GENERATOR, $urlGenerator);

        $urlHelper = new UrlHelper($requestStack, $context);
        ServiceContainer::getContainer()->set(Services::URL_HELPER, $urlHelper);
    }

    protected function configurePlugins(Request $request): void
    {
        $pluginConfigs = Config::get('ohrm_plugin_configs');
        foreach (array_values($pluginConfigs) as $pluginConfig) {
            require_once $pluginConfig['filepath'];
            /** @var PluginConfigurationInterface $configClass */
            $configClass = new $pluginConfig['classname']();
            $configClass->initialize($request);
        }
    }

    /**
     * @param Request $request
     * @param int $type
     * @param bool $catch
     * @return Response
     * @throws Exception
     */
    public function handleRequest(
        Request $request,
        int $type = HttpKernelInterface::MASTER_REQUEST,
        bool $catch = true
    ): Response {
        $this->configureRouter($request);
        $this->configurePlugins($request);

        return parent::handle($request, $type, $catch);
    }

    /**
     * @return string
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }
}
