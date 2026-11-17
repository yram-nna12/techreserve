<?php

namespace App\Services;

class ResourceService
{
    /** Whitelist and validate resource fields; never accept IDs or deletion timestamps. */
    public function validate(array $input): array
    {
        $type = is_string($input['resource_type'] ?? null) ? trim($input['resource_type']) : '';
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        $category = is_string($input['category'] ?? null) ? trim($input['category']) : '';
        $rate = is_string($input['rate_per_hour'] ?? null) ? trim($input['rate_per_hour']) : '';
        if ($rate === '' && (!array_key_exists('rate_per_hour', $input) || is_string($input['rate_per_hour']))) {
            $rate = config('ResourcePricing')->suggestedRate($type, $name) ?? '';
        }

        $data = [];
        foreach (['description', 'quantity', 'status', 'location', 'capacity', 'catalog_group', 'inventory_identifier'] as $field) {
            $data[$field] = isset($input[$field]) && is_string($input[$field]) ? trim($input[$field]) : '';
        }
        $data['name'] = $name;
        $data['category'] = $category !== '' ? $category : ($type === 'equipment' ? 'Equipment' : 'Facility');
        $data['resource_type'] = $type;
        $data['rate_per_hour'] = $rate;

        // Capacity is not applicable to equipment, including stale or forged form values.
        if ($type === 'equipment') {
            $data['capacity'] = '';
            if ($data['catalog_group'] === '') {
                $data['catalog_group'] = $name;
            }
        } else {
            $data['catalog_group'] = '';
            $data['inventory_identifier'] = '';
        }

        $conflictInput = $input['conflict_groups'] ?? null;
        $conflictGroups = [];
        $errors = [];
        foreach (['category', 'catalog_group', 'inventory_identifier'] as $field) {
            if (array_key_exists($field, $input) && !is_string($input[$field])) {
                $errors[$field] = 'Enter a valid ' . str_replace('_', ' ', $field) . '.';
            }
        }
        if ($type === 'facility') {
            if ($conflictInput !== null && !is_string($conflictInput)) {
                $errors['conflict_groups'] = 'Scheduling conflict groups must be comma-separated identifiers.';
            } elseif (is_string($conflictInput) && trim($conflictInput) !== '') {
                $conflictGroups = array_map('trim', explode(',', $conflictInput));
            } else {
                $conflictGroups = config('ResourcePricing')->defaultConflictGroups($name);
            }
            if (count($conflictGroups) > 2) {
                $errors['conflict_groups'] = 'A facility can belong to at most two scheduling conflict groups.';
            }
            foreach ($conflictGroups as $group) {
                if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,79}\z/', $group)) {
                    $errors['conflict_groups'] = 'Use letters, numbers, dots, underscores, and hyphens for conflict groups.';
                    break;
                }
            }
            $conflictGroups = array_values(array_unique(array_map('strtolower', $conflictGroups)));
        }
        $data['conflict_group'] = $conflictGroups[0] ?? null;
        $data['conflict_group_alt'] = $conflictGroups[1] ?? null;

        $validation = service('validation');
        $validation->reset();
        $validation->setRules([
            'name' => 'required|max_length[150]',
            'description' => 'permit_empty|max_length[5000]',
            'resource_type' => 'required|in_list[facility,equipment]',
            'category' => 'required|max_length[100]',
            'quantity' => 'required|is_natural_no_zero|less_than_equal_to[4294967295]',
            'rate_per_hour' => 'required|regex_match[/\A[0-9]{1,8}(?:\.[0-9]{1,2})?\z/]',
            'status' => 'required|in_list[available,unavailable,maintenance]',
            'location' => 'permit_empty|max_length[150]',
            'capacity' => 'permit_empty|is_natural_no_zero|less_than_equal_to[4294967295]',
            'catalog_group' => 'permit_empty|max_length[100]',
            'inventory_identifier' => 'permit_empty|max_length[100]',
        ]);
        $validation->run($data);
        $errors += $validation->getErrors();
        if ($data['resource_type'] === 'facility' && $data['quantity'] !== '1') {
            $errors['quantity'] = 'A facility must have a quantity of exactly 1.';
        }
        if ($data['resource_type'] === 'facility' && isset($input['capacity']) && !is_string($input['capacity'])) {
            $errors['capacity'] = 'Facility capacity must be blank or a positive integer.';
        }
        if (array_key_exists('rate_per_hour', $input) && !is_string($input['rate_per_hour'])) {
            $errors['rate_per_hour'] = 'Enter a valid hourly rate.';
        }
        if ($errors === []) {
            [$whole, $fraction] = array_pad(explode('.', $data['rate_per_hour'], 2), 2, '');
            $data['rate_per_hour'] = (ltrim($whole, '0') ?: '0') . '.' . str_pad($fraction, 2, '0');
            $data['capacity'] = $data['capacity'] === '' ? null : (int) $data['capacity'];
            $data['quantity'] = (int) $data['quantity'];
            $data['location'] = $data['location'] === '' ? null : $data['location'];
            $data['description'] = $data['description'] === '' ? null : $data['description'];
            $data['catalog_group'] = $data['catalog_group'] === '' ? null : $data['catalog_group'];
            $data['inventory_identifier'] = $data['inventory_identifier'] === '' ? null : $data['inventory_identifier'];
        }
        return [$data, $errors];
    }
}
