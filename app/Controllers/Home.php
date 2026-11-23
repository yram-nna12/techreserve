<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
        return view('public/home', ['title' => 'Campus resource reservations']);
    }

    public function information(string $page): string
    {
        $pages = [
            'about' => ['About TechReserve', '<p>TechReserve is the FEU Tech platform for verified student reservations of campus facilities and equipment.</p>'],
            'contact' => ['Contact Us', '<p>For account, reservation, or facilities assistance, please contact the Facilities Office through your official campus channels.</p>'],
            'terms' => ['Terms', '<p>Use TechReserve responsibly and provide accurate information when requesting campus resources. Reservation approvals remain subject to Facilities Office policies.</p>'],
            'privacy' => ['Privacy', '<p>TechReserve uses account and reservation information only to provide campus resource services. Student verification is performed through the connected Student Records service.</p>'],
        ];
        if (!isset($pages[$page])) throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        return view('public/information', ['title' => $pages[$page][0], 'heading' => $pages[$page][0], 'content' => $pages[$page][1]]);
    }
}
