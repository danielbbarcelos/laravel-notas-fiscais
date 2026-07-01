<?php

declare(strict_types=1);

use DanielBBarcelos\NotasFiscais\Tests\DemoTestCase;
use DanielBBarcelos\NotasFiscais\Tests\TestCase;

uses(TestCase::class)->in('Ipm');
uses(TestCase::class)->in('Abrasf');
uses(TestCase::class)->in('Export');
uses(TestCase::class)->in('Integracao');
uses(DemoTestCase::class)->in('Demo');
