<?php

namespace App\Services;

use App\Models\UserModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use DomainException;

class AdministratorProvisioningService
{
    public function __construct(private ?UserModel $users = null)
    {
    }

    // Called by the guarded CLI command only; never exposed through a route.
    public function create(string $identifier, string $name, string $email, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirmation): int
    {
        $identifier = trim($identifier);
        $name = trim($name);
        $email = trim($email);
        $validation = service('validation');
        $validation->reset();
        $validation->setRules([
            'identifier' => 'required|max_length[30]|regex_match[/\AOFFICE-[A-Z0-9-]+\z/]',
            'name' => 'required|max_length[150]',
            'email' => 'required|valid_email|max_length[150]',
            'password' => 'required|min_length[12]',
            'confirmation' => 'required|matches[password]',
        ]);
        if (!$validation->run(compact('identifier', 'name', 'email', 'password', 'confirmation'))
            || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new DomainException('Use an OFFICE- identifier (max 30 characters), a name, a valid email, and matching passwords of at least 12 characters and at most 72 bytes.');
        }
        $users = $this->users ?? new UserModel();
        if ($users->groupStart()->where('student_id', $identifier)->orWhere('email', $email)->groupEnd()->first()) {
            throw new DomainException('An account already uses that login identifier or email. Existing users were not changed.');
        }
        try {
            $id = $users->insert([
                'student_id' => $identifier, 'full_name' => $name, 'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'admin', 'status' => 'active',
            ]);
        } catch (DatabaseException $exception) {
            throw new DomainException('Administrator creation failed. Check for duplicate accounts and database availability.');
        }
        if ($id === false) {
            throw new DomainException('Administrator creation failed; no existing account was overwritten.');
        }
        return (int) $id;
    }
}
