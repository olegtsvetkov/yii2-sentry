<?php
declare(strict_types=1);

namespace OlegTsvetkov\Yii2\Sentry;

use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Options;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use yii\base\ActionEvent;
use yii\base\BootstrapInterface;
use yii\base\Controller;
use yii\base\Event;
use yii\base\InlineAction;
use yii\web\User;
use yii\web\UserEvent;

class Component extends \yii\base\Component implements BootstrapInterface
{
    /**
     * @var string Sentry client key.
     */
    public $dsn;
    /**
     * @var array
     */
    public $sentrySettings = [];
    /**
     * @var array
     */
    public $integrations = [
        Integration::class,
        ErrorListenerIntegration::class,
    ];
    /**
     * @var string
     */
    public $appBasePath = '@app';

    /**
     * @var HubInterface
     */
    protected $hub;

    public function init()
    {
        parent::init();

        $basePath = \Yii::getAlias($this->appBasePath);

        $options = new Options(array_merge([
            'dsn' => $this->dsn,
            'send_default_pii' => false,
            'environment' => YII_ENV,
            'prefixes' => [
                $basePath,
            ],
            'project_root' => $basePath,
            'in_app_exclude' => [
                $basePath . '/vendor/',
            ],
            'integrations' => [],
            // By default Sentry enabled ExceptionListenerIntegration, ErrorListenerIntegration and RequestIntegration
            // integrations. ExceptionListenerIntegration defines a global exception handler as well as Yii.
            // Sentry's handler is always being called first, because it was defined later then Yii's one. This leads
            // to report duplication when handling any exception, that is supposed to be handled by Yii.
            'default_integrations' => false,
        ], $this->sentrySettings));

        $integrations = [];
        foreach ($this->integrations as $item) {
            if (is_object($item)) {
                $integrations[] = $item;
            } else {
                $integrations[] = \Yii::createObject($item, [$options]);
            }
        }

        $options->setIntegrations($integrations);

        /** @var ClientBuilder $builder */
        $builder = \Yii::$container->get(ClientBuilder::class, [$options]);

        Hub::setCurrent(new Hub($builder->getClient()));

        $this->hub = Hub::getCurrent();
    }

    /**
     * @inheritDoc
     */
    public function bootstrap($app)
    {
        Event::on(User::class, User::EVENT_AFTER_LOGIN, function (UserEvent $event) {
            $this->hub->configureScope(function (Scope $scope) use ($event): void {
                $scope->setUser([
                    'id' => $event->identity->getId(),
                ]);
            });
        });

        Event::on(Controller::class, Controller::EVENT_BEFORE_ACTION, function (ActionEvent $event) use ($app) {
            $route = $event->action->getUniqueId();

            $metadata = [];
            // Retrieve action's function
            if ($app->requestedAction instanceof InlineAction) {
                $metadata['action'] = get_class($app->requestedAction->controller) . '::' . $app->requestedAction->actionMethod . '()';
            } else {
                $metadata['action'] = get_class($app->requestedAction) . '::run()';
            }

            // Set breadcrumb
            $this->hub->addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_NAVIGATION,
                'route',
                $route,
                $metadata
            ));

            // Set "route" tag
            $this->hub->configureScope(function (Scope $scope) use ($route): void {
                $scope->setTag('route', $route);
            });
        });
    }

    public function getHub(): HubInterface
    {
        return $this->hub;
    }
}
