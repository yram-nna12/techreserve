<?php

namespace App\Commands;

use App\Services\AdministratorProvisioningService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DomainException;
use RuntimeException;

class CreateAdministrator extends BaseCommand
{
    protected $group = 'TechReserve';
    protected $name = 'admin:create';
    protected $description = 'Interactively create a local Facilities Office administrator (development only).';

    public function run(array $params)
    {
        if (PHP_SAPI !== 'cli' || ENVIRONMENT !== 'development') {
            CLI::error('Administrator provisioning is available only through CLI in development mode.');
            return EXIT_ERROR;
        }
        if ($params !== [] || CLI::getOptions() !== []) {
            CLI::error('This command accepts no arguments or options. Enter account details only at its prompts.');
            return EXIT_ERROR;
        }
        if (!stream_isatty(STDIN)) {
            CLI::error('Use an interactive terminal. Passwords must not be supplied through command arguments or redirected input.');
            return EXIT_ERROR;
        }
        try {
            $identifier = CLI::prompt('Office login identifier (e.g. OFFICE-ADMIN-01)');
            $name = CLI::prompt('Full name');
            $email = CLI::prompt('Office email');
            $password = $this->hiddenPassword('Password');
            $confirmation = $this->hiddenPassword('Confirm password');
            (new AdministratorProvisioningService())->create($identifier, $name, $email, $password, $confirmation);
            unset($password, $confirmation);
            CLI::write('Administrator created. Use the office identifier on the existing login page.', 'green');
            return EXIT_SUCCESS;
        } catch (DomainException | RuntimeException $exception) {
            CLI::error($exception->getMessage());
            return EXIT_ERROR;
        }
    }

    private function hiddenPassword(string $label): string
    {
        CLI::write($label . ' (hidden):');
        if (PHP_OS_FAMILY === 'Windows') {
            // Only the child reads the console. Its output is captured, never echoed.
            $script = '$s = $Host.UI.ReadLineAsSecureString(); if ($null -eq $s) { exit 1 }; '
                . '$p = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s); '
                . 'try { [Console]::Out.Write([Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes([Runtime.InteropServices.Marshal]::PtrToStringBSTR($p)))) } '
                . 'finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($p); $s.Dispose() }';
            $process = proc_open(['powershell.exe', '-NoProfile', '-Command', $script],
                [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot open a hidden password prompt. No account was created.');
            }
            $encoded = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            $password = base64_decode(trim($encoded), true);
            if ($status !== 0 || $password === false) {
                throw new RuntimeException('Hidden password entry failed. No account was created.');
            }
            return $password;
        }
        exec('stty -echo 2>/dev/null', $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('Cannot disable terminal echo. No account was created.');
        }
        try {
            $line = fgets(STDIN);
        } finally {
            exec('stty echo 2>/dev/null');
            CLI::newLine();
        }
        return $line === false ? '' : rtrim($line, "\r\n");
    }
}
