<?php

namespace App\Controller;

use App\Repository\CigaretteRepository;
use App\Repository\SettingsRepository;
use App\Repository\WakeUpRepository;
use App\Service\ScoringService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

class ExportController extends AbstractController
{
    public function __construct(
        private CigaretteRepository $cigaretteRepository,
        private WakeUpRepository $wakeUpRepository,
        private SettingsRepository $settingsRepository,
        private ScoringService $scoringService
    ) {}

    #[Route('/export/csv', name: 'app_export_csv')]
    public function exportCsv(): Response
    {
        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();
        if (!$firstDate) {
            $this->addFlash('error', 'Aucune donnée à exporter');
            return $this->redirectToRoute('app_stats');
        }

        $response = new StreamedResponse(function () use ($firstDate) {
            $handle = fopen('php://output', 'w');

            // En-têtes CSV
            fputcsv($handle, [
                'Date',
                'Réveil',
                'Nombre de clopes',
                'Score du jour',
                'Heures des clopes',
            ], ';');

            $today = new \DateTime();
            $currentDate = clone $firstDate;

            while ($currentDate <= $today) {
                $cigs = $this->cigaretteRepository->findByDate($currentDate);
                $wakeUp = $this->wakeUpRepository->findByDate($currentDate);
                $dailyScore = $this->scoringService->calculateDailyScore($currentDate);

                // Heures des clopes
                $cigTimes = array_map(
                    fn($c) => $c->getSmokedAt()->format('H:i'),
                    $cigs
                );

                fputcsv($handle, [
                    $currentDate->format('Y-m-d'),
                    $wakeUp ? $wakeUp->getWakeTime()->format('H:i') : '',
                    count($cigs),
                    $dailyScore['total_score'],
                    implode(', ', $cigTimes),
                ], ';');

                $currentDate->modify('+1 day');
            }

            fclose($handle);
        });

        $filename = 'stopclope_export_' . date('Y-m-d') . '.csv';

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    #[Route('/export/json', name: 'app_export_json')]
    public function exportJson(): Response
    {
        $firstDate = $this->cigaretteRepository->getFirstCigaretteDate();
        if (!$firstDate) {
            return $this->json(['error' => 'Aucune donnée à exporter'], 404);
        }

        $data = [
            'export_date' => date('Y-m-d H:i:s'),
            'settings' => [
                'pack_price' => $this->settingsRepository->get('pack_price'),
                'cigs_per_pack' => $this->settingsRepository->get('cigs_per_pack'),
                'initial_daily_cigs' => $this->settingsRepository->get('initial_daily_cigs'),
            ],
            'days' => [],
        ];

        $today = new \DateTime();
        $currentDate = clone $firstDate;

        while ($currentDate <= $today) {
            $cigs = $this->cigaretteRepository->findByDate($currentDate);
            $wakeUp = $this->wakeUpRepository->findByDate($currentDate);
            $dailyScore = $this->scoringService->calculateDailyScore($currentDate);

            $cigData = array_map(fn($c) => [
                'time' => $c->getSmokedAt()->format('H:i'),
                'retroactive' => $c->isRetroactive(),
            ], $cigs);

            $data['days'][] = [
                'date' => $currentDate->format('Y-m-d'),
                'wake_time' => $wakeUp ? $wakeUp->getWakeTime()->format('H:i') : null,
                'cigarette_count' => count($cigs),
                'score' => $dailyScore['total_score'],
                'cigarettes' => $cigData,
            ];

            $currentDate->modify('+1 day');
        }

        $response = $this->json($data);
        $filename = 'stopclope_export_' . date('Y-m-d') . '.json';
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }
}
