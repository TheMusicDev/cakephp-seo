<?php
declare(strict_types=1);

namespace TestApp;

use Cake\Core\Configure;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\Middleware\RoutingMiddleware;
use TheMusicDev\Seo\Middleware\SeoRedirectsMiddleware;

/**
 * The smallest application that installs the plugin the way a host does: error handling, then the redirects
 * middleware before routing (so a path no route knows can still redirect, and a gone page answers 410).
 */
class Application extends BaseApplication
{
    /**
     * @inheritDoc
     */
    public function bootstrap(): void
    {
        parent::bootstrap();
        $this->addPlugin('TheMusicDev/Seo', ['path' => ROOT . DS]);
    }

    /**
     * @inheritDoc
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue
            ->add(new ErrorHandlerMiddleware(Configure::read('Error', [])))
            ->add(SeoRedirectsMiddleware::fromConfig())
            ->add(new RoutingMiddleware($this));
    }
}
