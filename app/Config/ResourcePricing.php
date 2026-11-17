<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class ResourcePricing extends BaseConfig
{
    public array $facilityRates = [
        'Roofdeck' => '100.00',
        'Basketball Gym with Aircon' => '250.00',
        'Basketball Gym without Aircon' => '150.00',
        'Basketball Gym with Aircon and Green Matting' => '300.00',
        'Multipurpose Room 2' => '100.00',
        'F1603 Audio Visual Room' => '150.00',
        'F1604 Case Room' => '75.00',
        'F1502 Multipurpose Room' => '50.00',
        'F1503 Multipurpose Room' => '50.00',
        'F1504 Multipurpose Room' => '50.00',
        'Executive Lounge 1' => '75.00',
        'Executive Lounge 2' => '75.00',
        'Executive Lounge 1 and 2 Combined' => '125.00',
        'Classroom' => '30.00',
        'Swimming Pool' => '200.00',
        'FIT Student Plaza' => '100.00',
        'Study Areas' => '20.00',
    ];

    public array $equipmentRates = [
        'Peavey Speaker' => '150.00',
        'Crown Portable Speaker' => '100.00',
        'JBL Party Speaker' => '180.00',
        'Platinum Wireless Microphone' => '50.00',
        'AKG Wireless Microphone' => '80.00',
        'Peavey Wireless Microphone' => '60.00',
        'Shure Wired Microphone' => '50.00',
        'AKG P3S Wired Microphone' => '40.00',
        'Behringer Wired Microphone' => '40.00',
        'Shure PGA58 Microphone' => '50.00',
        'AKG Wireless Receiver' => '100.00',
        'Extension Box (6 outlets)' => '20.00',
        'Extension Cable Reel' => '30.00',
        'Extension Power Cord' => '10.00',
        'Extension Cord' => '10.00',
        'Flag Pole' => '20.00',
        'Electronic Podium' => '150.00',
    ];

    public function suggestedRate(string $type, string $name): ?string
    {
        $rates = $type === 'facility' ? $this->facilityRates : $this->equipmentRates;
        foreach ($rates as $knownName => $rate) {
            if (strcasecmp(trim($name), $knownName) === 0) {
                return $rate;
            }
        }

        return null;
    }

    public function defaultConflictGroups(string $name): array
    {
        $key = strtolower(trim($name));

        if (in_array($key, [
            'basketball gym with aircon',
            'basketball gym without aircon',
            'basketball gym with aircon and green matting',
        ], true)) {
            return ['basketball-gym'];
        }
        if ($key === 'executive lounge 1') {
            return ['executive-lounge-1'];
        }
        if ($key === 'executive lounge 2') {
            return ['executive-lounge-2'];
        }
        if ($key === 'executive lounge 1 and 2 combined') {
            return ['executive-lounge-1', 'executive-lounge-2'];
        }

        return [];
    }
}
