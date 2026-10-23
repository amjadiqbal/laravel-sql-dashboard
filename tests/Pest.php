<?php

use AmjadIqbal\SqlDashboard\Tests\Integration\IntegrationTestCase;
use AmjadIqbal\SqlDashboard\Tests\TestCase;

uses(TestCase::class)->in(__DIR__.'/Unit', __DIR__.'/Feature');
uses(IntegrationTestCase::class)->group('integration')->in(__DIR__.'/Integration');
