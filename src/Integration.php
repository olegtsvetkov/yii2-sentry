<?php
declare(strict_types=1);

namespace OlegTsvetkov\Yii2\Sentry;

use Sentry\Event;
use Sentry\Integration\IntegrationInterface;
use Sentry\Options;
use Sentry\State\Hub;
use Sentry\State\Scope;
use yii\base\BaseObject;

class Integration extends BaseObject implements IntegrationInterface
{
    /**
     * List of HTTP methods for whom the request body must be passed to the Sentry
     *
     * @var string[]
     */
    public $httpMethodsWithRequestBody = ['POST', 'PUT', 'PATCH'];
    /**
     * List of headers, that should be stripped. Use lower case.
     *
     * @var string[]
     */
    public $stripHeaders = ['cookie', 'set-cookie'];
    /**
     * List of headers, that can contain Personal data. Use lower case.
     *
     * @var string[]
     */
    public $piiHeaders = ['authorization', 'remote_addr'];
    /**
     * List of routes with keys, that must be stripped from request body
     * For example:
     *
     * ```php
     * [
     *     'controller/action' => [
     *         'field_1',
     *     ],
     *     'account/login' => [
     *         'email',
     *         'password',
     *     ]
     * ]
     * ```
     *
     * @var array
     */
    public $piiBodyFields = [];
    /**
     * String to replace PII with.
     *
     * @var string|null
     */
    public $piiReplaceText = '[Filtered PII]';

    /**
     * @var Options
     */
    protected $options;

    public function __construct(Options $options, $config = [])
    {
        parent::__construct($config);

        $this->options = $options;
    }

    /**
     * {@inheritdoc}
     */
    public function setupOnce(): void
    {
        Scope::addGlobalEventProcessor(function (Event $event): Event {
            $self = Hub::getCurrent()->getIntegration(self::class);

            if (!$self instanceof self) {
                return $event;
            }

            $this->applyToEvent($event);

            return $event;
        });
    }

    protected function applyToEvent(Event $event): void
    {
        $request = \Yii::$app->getRequest();
        $requestMethod = $request->getMethod();

        $requestData = [
            'url' => $request->getUrl(),
            'method' => $requestMethod,
            'query_string' => $request->getQueryString(),
        ];

        // Process headers, cookies, etc. Done the same way as in RequestIntegration, but using Yii staff.
        /** @see \Sentry\Integration\RequestIntegration */
        if ($this->options->shouldSendDefaultPii()) {
            $headers = $request->getHeaders();
            if ($headers->has('REMOTE_ADDR')) {
                $requestData['env']['REMOTE_ADDR'] = $headers->get('REMOTE_ADDR');
            }

            $requestData['cookies'] = $request->getCookies();
            $requestData['headers'] = $headers->toArray();

            $userContext = $event->getUserContext();

            if (null === $userContext->getIpAddress() && $headers->has('REMOTE_ADDR')) {
                $userContext->setIpAddress($headers->get('REMOTE_ADDR'));
            }
        } else {
            $requestData['headers'] = $this->processHeaders($request->getHeaders()->toArray());
        }

        // Process request body
        if (\in_array($requestMethod, $this->httpMethodsWithRequestBody, true)) {
            $rawBody = $request->getRawBody();
            if ($rawBody !== '') {
                $bodyParams = $request->getBodyParams();

                $actionId = \Yii::$app->requestedAction->getUniqueId();
                if (!$this->options->shouldSendDefaultPii() && isset($this->piiBodyFields[$actionId])) {
                    $requestData['data'] = 'Not available due to PII. See "bodyParams" in Additional data block.';

                    $this->removeKeysFromArrayRecursively($bodyParams, $this->piiBodyFields[$actionId]);
                } else {
                    $requestData['data'] = $rawBody;
                }

                $event->getExtraContext()->setData([
                    'decodedParams' => $bodyParams,
                ]);
            }
        }

        // Set!
        $event->setRequest($requestData);
    }

    protected function removeKeysFromArrayRecursively(array &$array, array $keysToRemove): void
    {
        foreach ($keysToRemove as $key => $value) {
            if (is_string($key) && is_array($value)) {
                $this->removeKeysFromArrayRecursively($array[$key], $value);
            } else {
                if (isset($array[$value])) {
                    $array[$value] = $this->piiReplaceText;
                }
            }
        }
    }

    /**
     * @param array $headers
     * @return array
     */
    protected function processHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $header => $value) {
            if (\in_array(strtolower($header), $this->stripHeaders, true)) {
                continue;
            }

            if (\in_array(strtolower($header), $this->piiHeaders, true)) {
                $result[$header] = $this->piiReplaceText;
            } else {
                $result[$header] = $value;
            }
        }

        return $result;
    }
}
