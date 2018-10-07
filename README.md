# PHPStan parallel executor

[![Build Status](https://travis-ci.org/pepakriz/phpstan-parallel.svg)](https://travis-ci.org/pepakriz/phpstan-parallel)
[![Latest Stable Version](https://poser.pugx.org/pepakriz/phpstan-parallel/v/stable)](https://packagist.org/packages/pepakriz/phpstan-parallel)
[![License](https://poser.pugx.org/pepakriz/phpstan-parallel/license)](https://packagist.org/packages/pepakriz/phpstan-parallel)

* [PHPStan](https://github.com/phpstan/phpstan)

## Usage

To use this extension, require it in [Composer](https://getcomposer.org/):

```bash
composer require --dev pepakriz/phpstan-parallel
```

And run it in a similar way like phpstan:

```neon
vendor/bin/phpstan-parallel analyse src tests
```
