<?php
declare(strict_types=1);

namespace DAoCCMS\Setup;

class Installer
{
    private array $steps = [
        'welcome',
        'requirements',
        'permissions',
        'database',
        'dol_database',
        'configuration',
        'bridges',
        'administrator',
        'install',
        'finish'
    ];

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function isInstalled(): bool
    {
        return file_exists(__DIR__ . '/../../install.lock');
    }

    public function getSteps(): array
    {
        return $this->steps;
    }

    public function getCurrentStep(): string
    {
        $step = $_GET['step'] ?? 'welcome';
        
        if (!in_array($step, $this->steps, true)) {
            return 'welcome';
        }
        
        return $step;
    }

    public function getStepProgress(string $currentStep): int
    {
        $index = array_search($currentStep, $this->steps, true);
        $total = count($this->steps);
        
        if ($index === false) {
            return 0;
        }
        
        return (int) round((($index + 1) / $total) * 100);
    }
}