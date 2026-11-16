<?php

namespace App\Filters;

use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthSafeToolbar extends DebugToolbar
{
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $path = trim($request->getUri()->getPath(), '/');
        $path = preg_replace('#^index\.php/#', '', $path);
        if (in_array($path, ['register', 'login', 'logout', 'borrower/dashboard', 'admin/dashboard'], true)
            || preg_match('#^(admin|borrower)/(resources|reservations)(?:/|$)#', $path)) {
            // Do not persist form values, identity data, or password-hash queries.
            return null;
        }

        return parent::after($request, $response, $arguments);
    }
}
