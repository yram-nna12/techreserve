<?php

namespace App\Services;

use App\Models\UserModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use DomainException;

class RegistrationService
{
    public function register(string $studentId, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirmation): int
    {
        $studentId = trim($studentId);
        $validation = service('validation');
        $validation->reset();
        $validation->setRules([
            'studentId' => 'required|max_length[30]|regex_match[/\A[A-Za-z0-9-]+\z/]',
            'password' => 'required|min_length[12]',
            'confirmation' => 'required|matches[password]',
        ]);
        if (!$validation->run(compact('studentId', 'password', 'confirmation')) || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new DomainException('Enter a valid student ID and matching passwords of at least 12 characters and at most 72 bytes.');
        }

        $users = new UserModel();
        if ($users->where('student_id', $studentId)->first()) {
            throw new DomainException('This student ID already has an account. Please log in.');
        }

        $verification = service('studentRecords')->verifyStudent($studentId);
        if (($verification['verified'] ?? null) !== true) {
            $message = $verification['message'] ?? '';
            throw new DomainException(match ($message) {
                'Student record not found.' => 'Student record not found. Check your student ID.',
                'Student is not currently enrolled.' => 'Only currently enrolled students can register.',
                default => 'Student Records is temporarily unavailable. Please try again later.',
            });
        }

        $student = $verification['student'] ?? null;
        $validation->reset();
        $validation->setRules([
            'full_name' => 'required|max_length[150]',
            'institutional_email' => 'required|valid_email|max_length[150]',
            'program' => 'required|max_length[150]',
            'program_code' => 'required|max_length[30]',
            'specialization' => 'permit_empty|max_length[150]',
            'department' => 'required|max_length[150]',
        ]);
        if (!is_array($student) || ($student['student_id'] ?? null) !== $studentId || !$validation->run($student)) {
            throw new DomainException('Student Records returned incomplete identity details. Please contact the Facilities Office.');
        }

        if ($users->where('email', $student['institutional_email'])->first()) {
            throw new DomainException('An account already uses this institutional email. Please contact the Facilities Office.');
        }

        // Explicit mapping: no posted identity, role, or status is accepted.
        try {
            $id = $users->insert([
                'student_id' => $studentId,
                'full_name' => $student['full_name'],
                'email' => $student['institutional_email'],
                'program' => $student['program'],
                'program_code' => $student['program_code'],
                'specialization' => $student['specialization'] ?? null,
                'department' => $student['department'],
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'borrower',
                'status' => 'active',
            ]);
        } catch (DatabaseException $exception) {
            // Unique database indexes also protect simultaneous registrations.
            log_message('error', 'Registration could not save an account.');
            throw new DomainException('The account could not be created. It may already exist; please try logging in.');
        }
        if ($id === false) {
            throw new DomainException('The account could not be created. Please try again later.');
        }

        return (int) $id;
    }
}
