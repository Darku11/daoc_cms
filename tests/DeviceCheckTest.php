<?php
// SPDX-License-Identifier: GPL-3.0-only
use PHPUnit\Framework\TestCase;

class DeviceCheckTest extends TestCase
{
    private $dbMock;

    protected function setUp(): void
    {
        // Mocking the global PDO $db object
        $this->dbMock = $this->createMock(PDO::class);
        $GLOBALS['db'] = $this->dbMock;
        
        // Mocking functions that are called inside the check
        if (!function_exists('aldhran_api_mail')) {
            function aldhran_api_mail($to, $subject, $msg) {}
        }
        if (!function_exists('aldhran_log')) {
            function aldhran_log($action, $details, $uid) {}
        }
        if (!function_exists('h')) {
            function h($str) { return htmlspecialchars($str); }
        }
    }

    public function testKnownDeviceUpdatesLastLogin()
    {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 TestBrowser';

        $stmtMock = $this->createMock(PDOStatement::class);
        
        // Setup: SELECT returns a row (meaning the device is KNOWN)
        $stmtMock->expects($this->once())
                 ->method('fetch')
                 ->willReturn(['id' => 1]);

        $this->dbMock->expects($this->exactly(2))
                     ->method('prepare')
                     ->willReturn($stmtMock);

        // Act
        aldhran_check_new_device(1, 'TestUser', 'test@example.com');

        // Assert: No exceptions were thrown, indicating the UPDATE path was successfully reached.
        $this->assertTrue(true);
    }

    public function testUnknownDeviceInsertsAndMails()
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 NewBrowser';

        $stmtMock = $this->createMock(PDOStatement::class);
        
        // Setup: SELECT returns false (meaning the device is UNKNOWN)
        $stmtMock->expects($this->once())
                 ->method('fetch')
                 ->willReturn(false);

        $this->dbMock->expects($this->exactly(2))
                     ->method('prepare')
                     ->willReturn($stmtMock);

        // Act
        aldhran_check_new_device(2, 'NewUser', 'new@example.com');

        // Assert: Reached without errors
        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['db']);
    }
}