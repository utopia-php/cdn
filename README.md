# Utopia CDN

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/cdn.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia CDN is a lightweight PHP library for interacting with CDN providers. It currently focuses on two workflows: purging cached URLs and managing CDN-backed certificates. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project, it can be used as a standalone package with any PHP project or framework.

## Getting Started

Install using composer:

```bash
composer require utopia-php/cdn
```

## Cache Purging

The cache API supports two purge modes:

- URL purges for providers like Cloudflare and Fastly
- key purges for providers like Fastly

URL purges expect fully qualified URLs.

### Cloudflare

```php
<?php

use Utopia\Cdn\Cache;
use Utopia\Cdn\Cache\Adapter\Cloudflare;

$cache = new Cache(new Cloudflare(
    zoneId: 'YOUR_ZONE_ID',
    apiToken: 'YOUR_API_TOKEN'
));

$cache->purgeUrls([
    'https://example.com/files/hero.png',
    'https://example.com/files/logo.svg',
]);
```

### Fastly

```php
<?php

use Utopia\Cdn\Cache;
use Utopia\Cdn\Cache\Adapter\Fastly;

$cache = new Cache(new Fastly(
    apiToken: 'YOUR_API_TOKEN',
    serviceId: 'YOUR_SERVICE_ID',
    softPurge: false
));

$cache->purgeUrls([
    'https://example.com/files/hero.png',
    'https://example.com/files/logo.svg',
]);

$cache->purgeKeys([
    'host-deadbeef',
    'deployment-12345',
]);
```

## Certificates

The current certificate provider support is focused on CDN-managed certificates through Fastly TLS subscriptions.

```php
<?php

use Utopia\Cdn\Certificates;
use Utopia\Cdn\Certificates\Provider\FastlyTls;

$certificates = new Certificates(new FastlyTls(
    apiToken: 'YOUR_API_TOKEN',
    tlsConfigurationId: 'YOUR_TLS_CONFIGURATION_ID'
));

$renewDate = $certificates->issueCertificate(
    certName: 'my-cert',
    domain: 'cdn.example.com',
    domainType: null
);

$status = $certificates->getCertificateStatus('cdn.example.com', null);
$renewRequired = $certificates->isRenewRequired('cdn.example.com', null);
```

`issueCertificate()` returns a renew date when Fastly already has an issued or renewing certificate. For asynchronous states like `pending` or `processing`, it returns `null`.

## Supported Providers

### Cache

- [x] [Cloudflare](https://www.cloudflare.com/)
- [x] [Fastly](https://www.fastly.com/)

### Certificates

- [x] [Fastly TLS subscriptions](https://www.fastly.com/documentation/guides/getting-started/domains/securing-domains-with-tls/)
- [ ] Cloudflare

## System Requirements

Utopia CDN requires PHP 8.1 or later. We recommend using the latest PHP version whenever possible.

## Tests

Run the test suite:

```bash
composer test
```

Run static analysis:

```bash
composer analyse
```

Run formatting checks:

```bash
composer lint
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
