<?php

namespace App\Controllers;

use App\Models\UserModel;

class DashboardController extends BaseController
{
    protected $helpers = ['form', 'url'];

    public function borrower()
    {
        return $this->dashboard('Student dashboard');
    }

    public function admin()
    {
        return $this->dashboard('Facilities Office');
    }

    private function dashboard(string $title): string
    {
        $user = (new UserModel())->select('id, student_id, full_name, email, role, program, program_code, specialization, department')
            ->find(session()->get('user_id'));
        return view('auth/dashboard', ['title' => $title, 'user' => $user]);
    }
}
