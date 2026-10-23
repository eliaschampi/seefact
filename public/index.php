<?php

declare(strict_types=1);

use See\Http\Request;
use See\Kernel;

// Greenter 5.3 declares implicitly nullable parameters, which PHP 8.4+
// reports as E_DEPRECATED when its classes load. First-party code is held
// to zero deprecations by PHPUnit; vendor noise stays out of the log.
error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__).'/vendor/autoload.php';

Kernel::fromEnvironment()->handle(Request::fromGlobals())->emit();
