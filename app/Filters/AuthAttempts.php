<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthAttempts implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $action = $arguments[0] ?? 'login';
        $throttler = service('throttler');
        $ipKey = 'auth_ip_' . hash('sha256', $action . '|' . $request->getIPAddress());
        $allowed = $throttler->check($ipKey, $action === 'login' ? 30 : 10, 300);
        if ($allowed && $action === 'login') {
            $id = $request->getPost('student_id');
            $accountKey = 'auth_account_' . hash('sha256', strtolower(trim(is_string($id) ? $id : '')));
            $allowed = $throttler->check($accountKey, 5, 300);
        }
        if (!$allowed) {
            return service('response')->setStatusCode(429)
                ->setHeader('Retry-After', (string) $throttler->getTokenTime())
                ->setBody('Too many attempts. Please wait before trying again.');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
