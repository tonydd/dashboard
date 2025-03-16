<?php

namespace App\Service;

use App\Entity\CurrentStatValue;
use App\Enum\StatValueType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class WaterQualityService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HttpClientInterface $httpClient,
    ) {
    }

    public function update(): void
    {
        $url = 'https://hubeau.eaufrance.fr/api/v1/qualite_eau_potable/resultats_dis?code_commune=67474&size=50';

        $response = $this->httpClient->request('GET', $url);
        $content = $response->toArray();

        $waterQuality = $this->evaluateWaterQuality($content);
        $currentStatValue = new CurrentStatValue();
        $currentStatValue->setType(StatValueType::WaterQuality);
        $currentStatValue->setValue($waterQuality);
        $this->entityManager->persist($currentStatValue);
        $this->entityManager->flush();
    }

    private function evaluateWaterQuality($data) {
        $weights = [
            'bacteria' => 0.4,
            'chemistry' => 0.35,
            'sensory' => 0.25
        ];

        $thresholds = [
            "Escherichia coli /100ml - MF" => ["max" => 0],
            "Bactéries coliformes /100ml-MS" => ["max" => 0],
            "Entérocoques /100ml-MS" => ["max" => 0],
            "Arsenic" => ["max" => 10],
            "Ammonium (en NH4)" => ["max" => 0.1],
            "Chlore libre" => ["max" => 0.5],
            "Chlore combiné" => ["max" => 1],
            "Carbone organique total" => ["max" => 2],
            "pH" => ["min" => 6.5, "max" => 9],
            "Conductivité à 25°C" => ["min" => 200, "max" => 1100],
            "Turbidité néphélométrique NFU" => ["max" => 2],
            "Sodium" => ["max" => 200]
        ];

        $categories = [
            'bacteria' => [],
            'chemistry' => [],
            'sensory' => []
        ];

        foreach ($data['data'] ?? [] as $param) {
            $score = 10;
            $value = $param["resultat_numerique"];
            $name = $param["libelle_parametre"];

            if (isset($thresholds[$name])) {
                $min = $thresholds[$name]["min"] ?? null;
                $max = $thresholds[$name]["max"] ?? null;
                if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
                    $score = ($value > $max * 1.5) ? 0 : 5;
                }
            }

            if (in_array($name, ["Odeur (qualitatif)", "Saveur (qualitatif)", "Couleur (qualitatif)", "Aspect (qualitatif)"])) {
                $score = (strpos($param["resultat_alphanumerique"], "normal") !== false) ? 10 : 5;
                $categories['sensory'][] = $score;
            } elseif (array_key_exists($name, $thresholds)) {
                $categories['chemistry'][] = $score;
            } else {
                $categories['bacteria'][] = $score;
            }
        }

        function average($scores) {
            return count($scores) ? array_sum($scores) / count($scores) : 10;
        }

        $finalScore = (
            average($categories['bacteria']) * $weights['bacteria'] +
            average($categories['chemistry']) * $weights['chemistry'] +
            average($categories['sensory']) * $weights['sensory']
        );

        $status = $finalScore >= 9 ? "Excellente qualité" :
            ($finalScore >= 7 ? "Bonne qualité" :
                ($finalScore >= 5 ? "Qualité moyenne" :
                    ($finalScore >= 3 ? "Qualité médiocre" : "Eau non conforme")));

        return ["score" => round($finalScore, 1), "status" => $status];
    }
}