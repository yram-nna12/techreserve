<?php

namespace App\Controllers;

use App\Models\UserModel;
use App\Services\RegistrationService;
use DomainException;

class AuthController extends BaseController
{
    protected $helpers = ['form', 'url'];

    public function registerForm()
    {
        return view('auth/register', ['title' => 'Create your student account']);
    }

    public function register()
    {
        $studentId = $this->input('student_id');
        try {
            (new RegistrationService())->register($studentId, $this->input('password'), $this->input('password_confirm'));
        } catch (DomainException $exception) {
            // Never use withInput(): it would retain submitted passwords in the session.
            return redirect()->to(site_url('register'))->with('error', $exception->getMessage())
                ->with('student_id', $studentId);
        }

        return redirect()->to(site_url('login'))->with('success', 'Account created. You can now log in.');
    }

    public function loginForm()
    {
        return view('auth/login', ['title' => 'Welcome back']);
    }

    public function login()
    {
        $studentId = trim($this->input('student_id'));
        $password = $this->input('password');
        $user = strlen($studentId) <= 30 && $studentId !== ''
            ? (new UserModel())->where('student_id', $studentId)->first() : null;
        // A fixed dummy hash reduces timing differences for unknown accounts.
        $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid = strlen($password) <= 72 && !str_contains($password, "\0") && password_verify($password, $hash);
        if (!$valid || !$user || $user['status'] !== 'active' || !in_array($user['role'], ['borrower', 'admin'], true)) {
            return redirect()->to(site_url('login'))->with('error', 'Invalid student ID or password, or the account is inactive.')
                ->with('student_id', $studentId);
        }

        session()->regenerate(true);
        session()->set('user_id', (int) $user['id']);
        service('security')->generateHash();

        return redirect()->to(site_url($user['role'] . '/dashboard'));
    }

    public function logout()
    {
        session()->remove('user_id');
        session()->destroy();
        return redirect()->to(site_url('login'));
    }

    private function input(string $name): string
    {
        $value = $this->request->getPost($name);
        return is_string($value) ? $value : '';
    }
}
