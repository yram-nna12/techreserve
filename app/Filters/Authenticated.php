<?php

namespace App\Filters;

use App\Models\UserModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class Authenticated implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $id = session()->get('user_id');
        $user = $id ? (new UserModel())->find($id) : null;
        if (!$user || $user['status'] !== 'active') {
            session()->remove('user_id');
            return redirect()->to(site_url('login'));
        }
        if ($arguments && !in_array($user['role'], $arguments, true)) {
            return service('response')->setStatusCode(403)->setBody('Access denied for this account role.');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
