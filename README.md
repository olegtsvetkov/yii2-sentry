# yii2-sentry

Yii2 integration for [Sentry](https://getsentry.com/) using Sentry PHP SDK v2.

Inspired by official [sentry/sentry-simfony](https://github.com/getsentry/sentry-symfony) and
[sentry/sentry-laravel](https://github.com/getsentry/sentry-laravel) packages.

## Installation

The preferred way to install this package is through [composer](http://getcomposer.org/download/):

```bash
composer require olegtsvetkov/yii2-sentry:^1.0
```

Package doesn't provide any HTTP transport out of the box. To install package with a recommended HTTP client do:

```bash
composer require olegtsvetkov/yii2-sentry:^1.0 php-http/curl-client:^1.0|^2.0 http-interop/http-factory-guzzle:^1.0
```

## Basic Usage

Add "sentry" component to application's config and configure log target as follows:

```php
<?php

return [
    'id' => 'my-app',
    
    'bootstrap' => [
        'sentry',
        'log',
    ],
    
    'components' => [
        'sentry' => [
            'class' => OlegTsvetkov\Yii2\Sentry\Component::class,
            'dsn' => 'https://abcdefghijklmnopqrstuvwxyz123456:abcdefghijklmnopqrstuvwxyz123456@sentry.io/0000000',
        ],
        
        'log' => [
            'targets' => [
                [
                    'class' => OlegTsvetkov\Yii2\Sentry\LogTarget::class,
                    'levels' => ['error', 'warning'],
                    'except' => [
                        'yii\web\HttpException:40*',
                    ],
                ],
            ],
        ],
    ],
];
```

_Don't forget to change DSN to your own._ 

After this all exceptions (except blacklisted), PHP errors and calls for `Yii::error()` and `Yii:warning()` will be
logged to Sentry.

It is highly recommended to blacklist all Yii's exceptions for 40x responses, because they are used for handling 
requests and doesn't indicate about problems of any kind.

Out of the box component provides detailed information about request, such as:

* Request Method
* Request URL (including query string)
* Request Headers
* Request Body
* Resolved route (goes to tags)
* User ID for logged in
* Visitor's IP address
* Visitor's Cookies (with send default PII enabled)
* App environment (taken from `YII_ENV`)
* Stack trace for exception

### Important nuance about LogTarget

Bundled in Log Target will send **only one** message to Sentry per request. This message will be based on the log entry
with highest severity level. All other entries will go to the extra field "logs" of message 

## Advanced usage

### Sentry client configuration

Component provides out-of-box configuration for Sentry client. It can be overridden and extend using 
`Component::$sentrySettings` property. Use options from Sentry PHP SDK as-is.

Also, Sentry's ClientBuilder is being created using Yii's container, which allows custom builder injection.

### Personally identifying information (PII) handling

By default Sentry provides PII handling on it's side, but it doesn't give full control over PII stripping process.
Because of this, Yii2 Sentry package is able to strip PPI from  both request headers and request body. 

Example of component configuration with a complete list of PII-related settings:

```php
<?php

[
    'class' => OlegTsvetkov\Yii2\Sentry\Component::class,
    'dsn' => 'https://abcdefghijklmnopqrstuvwxyz123456:abcdefghijklmnopqrstuvwxyz123456@sentry.io/0000000',
    'integrations' => [
        [
            'class' => OlegTsvetkov\Yii2\Sentry\Integration::class,
            // Headers that should not be send to Sentry at all
            'stripHeaders' => ['cookie', 'set-cookie'],
            // Headers which values should be filtered before sending to Sentry
            'piiHeaders' => ['custom-token-header', 'authorization'],
            // Body fields which values should be filtered before sending to Sentry
            'piiBodyFields' => [
                'controller/action' => [
                    'field_1' => [
                        'field_2',
                    ],
                    'field_2',
                ],
                'account/login' => [
                    'email',
                    'password',
                ],
            ],
            // Text to replace PII values with
            'piiReplaceText' => '[Filtered PII]',
        ],
        Sentry\Integration\ErrorListenerIntegration::class,
    ],
]

``` 