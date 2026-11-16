<?php

namespace App\Services;

use Config\Services;
use Throwable;

class StudentRecordsService
{
    public function verifyStudent(string $studentId): array
    {
        $baseUrl = rtrim(
            (string) env('studentRecords.baseUrl', ''),
            '/'
        );

        $apiKey = (string) env('studentRecords.apiKey', '');

        if ($baseUrl === '' || $apiKey === '') {
            return [
                'verified' => false,
                'message' => 'Student Records is not configured.',
            ];
        }

        try {
            $client = Services::curlrequest([
                'timeout' => 5,
                'http_errors' => false,
            ]);

            $response = $client->get(
                $baseUrl . '/api/students/verify',
                [
                    'headers' => [
                        'X-API-Key' => $apiKey,
                        'Accept' => 'application/json',
                    ],
                    'query' => [
                        'student_id' => $studentId,
                    ],
                ]
            );

            if ($response->getStatusCode() !== 200) {
                return [
                    'verified' => false,
                    'message' => 'Student verification service error.',
                ];
            }

            $data = json_decode($response->getBody(), true);

            if (!is_array($data)) {
                return [
                    'verified' => false,
                    'message' => 'Invalid Student Records response.',
                ];
            }

            return $data;

        } catch (Throwable $e) {
            log_message('error', 'Student Records request failed: {error}', [
                'error' => $e->getMessage(),
            ]);

            return [
                'verified' => false,
                'message' => 'Student Records is temporarily unavailable.',
            ];
        }
    }
}
