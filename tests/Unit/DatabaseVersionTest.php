<?php

namespace Tum\SystemReporter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tum\SystemReporter\Collectors\RuntimeCollector;

class DatabaseVersionTest extends TestCase
{
    public function test_mariadb_prefix_is_stripped(): void
    {
        $this->assertSame(
            ['product' => 'mariadb', 'version' => '10.11.6'],
            RuntimeCollector::normalizeDatabase('mysql', '5.5.5-10.11.6-MariaDB-0+deb12u1-log')
        );
    }

    public function test_mysql_and_postgres(): void
    {
        $this->assertSame(['product' => 'mysql', 'version' => '8.0.39'], RuntimeCollector::normalizeDatabase('mysql', '8.0.39'));
        $this->assertSame(['product' => 'postgresql', 'version' => '16.4'], RuntimeCollector::normalizeDatabase('pgsql', '16.4 (Debian 16.4-1.pgdg120+1)'));
    }
}
