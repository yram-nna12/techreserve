<?php

namespace App\Libraries;

final class NotificationEmailRenderer
{
    public function render(array $event): array
    {
        $baseUrl = rtrim((string) config('App')->baseURL, '/');
        $data = [
            'brand' => 'TechReserve',
            'title' => $event['title'],
            'message' => $event['message'],
            'details' => $event['details'],
            'reservationUrl' => $baseUrl . '/' . ltrim($event['path'], '/'),
        ];

        return [
            'subject' => $event['subject'],
            'html_body' => view('emails/notification_html', $data),
            'text_body' => view('emails/notification_text', $data),
        ];
    }
}
