<?php

namespace App\Service;

use App\Entity\FuelStat;
use Doctrine\ORM\EntityManagerInterface;

class FuelService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function update(): void
    {
        $url = 'https://api.prix-carburants.2aaz.fr/station/67250002';


        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Cache-Control: no-cache',
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        /**
         * { "id": 67250002, "Brand": { "id": 42, "name": "E.Leclerc", "short_name": "eleclerc", "nb_stations": 710 },
         * "type": "R", "name": "LECLERC SOULTZ sous FORETS", "Address": { "street_line": "1 rue du chene", "city_line": "67250 Soultz-sous-For\u00eats" },
         * "Coordinates": { "latitude": "48.929", "longitude": "7.896" }, "Hours": { "automate_24_7": true, "Days": [] },
         * "Services": [ "Lavage automatique", "Vente de gaz domestique (Butane, Propane)", "Automate CB 24\/24", "DAB (Distributeur automatique de billets)", "Location de v\u00e9hicule", "Toilettes publiques", "Boutique alimentaire" ],
         * "Fuels": [ { "id": 1, "name": "Gazole", "short_name": "Gazole", "picto": "B7", "Update": { "value": "2025-03-07T07:52:39Z", "text": "07\/03\/2025 07:52:39" }, "available": true, "Price": { "value": 1.659, "currency": "EUR", "text": "1.659 \u20ac" } },
         * { "id": 5, "name": "Super Sans Plomb 95 E10", "short_name": "SP95-E10", "picto": "E10", "Update": { "value": "2025-03-07T07:52:39Z", "text": "07\/03\/2025 07:52:39" }, "available": true, "Price": { "value": 1.689, "currency": "EUR", "text": "1.689 \u20ac" } },
         * { "id": 2, "name": "Super Sans Plomb 95", "short_name": "SP95", "picto": "E5", "Update": { "value": "2024-01-08T12:10:01Z", "text": "08\/01\/2024 12:10:01" }, "available": false, "Price": { "value": 1.789, "currency": "EUR", "text": "1.789 \u20ac" } },
         * { "id": 6, "name": "Super Sans Plomb 98", "short_name": "SP98", "picto": "E5", "Update": { "value": "2025-03-07T07:52:39Z", "text": "07\/03\/2025 07:52:39" }, "available": true, "Price": { "value": 1.779, "currency": "EUR", "text": "1.779 \u20ac" } }
         * ],
         * "LastUpdate": { "value": "2025-03-07T07:52:39Z", "text": "07\/03\/2025 07:52:39" } }
         *
         */
        $fuelData = $data['Fuels'];
        foreach ($fuelData as $fuel) {
            if (in_array($fuel['short_name'], ['Gazole', 'SP95-E10', 'SP98'])) {
                $fuelStat = new FuelStat();
                $fuelStat->setPrice($fuel['Price']['value']);
                $fuelStat->setTs(new \DateTime());
                $fuelStat->setType($fuel['picto']);
                $this->entityManager->persist($fuelStat);
            }
        }
        $this->entityManager->flush();
    }
}