<?php

namespace App\Services;

class ResourceService
{
    /** Whitelist and validate resource fields; never accept IDs or deletion timestamps. */
    public function validate(array $input): array
    {
        $data = [];
        foreach (['name', 'description', 'resource_type', 'quantity', 'rate_per_hour', 'status', 'location', 'capacity'] as $field) {
            $data[$field] = isset($input[$field]) && is_string($input[$field]) ? trim($input[$field]) : '';
        }
        // Capacity is not applicable to equipment, including stale or forged form values.
        if ($data['resource_type'] === 'equipment') {
            $data['capacity'] = '';
        }
        $validation = service('validation');
        $validation->reset();
        $validation->setRules([
            'name' => 'required|max_length[150]',
            'description' => 'permit_empty|max_length[5000]',
            'resource_type' => 'required|in_list[facility,equipment]',
            'quantity' => 'required|is_natural_no_zero|less_than_equal_to[4294967295]',
            'rate_per_hour' => 'required|regex_match[/\A[0-9]{1,8}(?:\.[0-9]{1,2})?\z/]',
            'status' => 'required|in_list[available,unavailable,maintenance]',
            'location' => 'permit_empty|max_length[150]',
            'capacity' => 'permit_empty|is_natural_no_zero|less_than_equal_to[4294967295]',
        ]);
        $validation->run($data);
        $errors = $validation->getErrors();
        if ($data['resource_type'] === 'facility' && $data['quantity'] !== '1') {
            $errors['quantity'] = 'A facility must have a quantity of exactly 1.';
        }
        if ($data['resource_type'] === 'facility' && isset($input['capacity']) && !is_string($input['capacity'])) {
            $errors['capacity'] = 'Facility capacity must be blank or a positive integer.';
        }
        if ($errors === []) {
            [$whole, $fraction] = array_pad(explode('.', $data['rate_per_hour'], 2), 2, '');
            $data['rate_per_hour'] = (ltrim($whole, '0') ?: '0') . '.' . str_pad($fraction, 2, '0');
            $data['capacity'] = $data['capacity'] === '' ? null : (int) $data['capacity'];
            $data['quantity'] = (int) $data['quantity'];
            $data['location'] = $data['location'] === '' ? null : $data['location'];
            $data['description'] = $data['description'] === '' ? null : $data['description'];
        }
        return [$data, $errors];
    }
}
