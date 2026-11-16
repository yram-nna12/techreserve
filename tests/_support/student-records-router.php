<?php

// Synthetic HTTP fixture, started only by AuthenticationTest on a random port.
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/api/students/verify') {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json');
if (!hash_equals((string) getenv('AUTH_TEST_API_KEY'), $_SERVER['HTTP_X_API_KEY'] ?? '')) {
    http_response_code(401);
    echo json_encode(['message' => 'Invalid or missing API key.']);
    exit;
}
$id = $_GET['student_id'] ?? '';
if ($id === 'TEST-TIMEOUT') {
    sleep(6);
    echo json_encode(['verified' => false]);
} elseif ($id === 'TEST-OFFLINE') {
    http_response_code(503);
    echo json_encode(['message' => 'Service unavailable.']);
} elseif ($id === 'TEST-INACTIVE') {
    echo json_encode(['verified' => false, 'message' => 'Student is not currently enrolled.']);
} elseif (!in_array($id, ['TEST-ENROLLED', 'TEST-SAMEEMAIL', 'TEST-TAMPER', 'TEST-MISMATCH'], true)) {
    echo json_encode(['verified' => false, 'message' => 'Student record not found.']);
} else {
    echo json_encode(['verified' => true, 'student' => [
        'student_id' => $id === 'TEST-MISMATCH' ? 'OTHER-STUDENT' : $id,
        'full_name' => 'Synthetic Student',
        'institutional_email' => $id === 'TEST-TAMPER' ? 'synthetic-other@example.test' : 'synthetic@example.test',
        'program' => 'Bachelor of Science in Information Technology',
        'program_code' => 'BSITWMA',
        'specialization' => 'Web and Mobile Applications',
        'department' => 'Computing',
    ]]);
}
