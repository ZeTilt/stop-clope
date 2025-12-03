<?php

namespace App\Command;

use App\Entity\Cigarette;
use App\Entity\WakeUp;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:load-test-data',
    description: 'Load test data for the past month',
)]
class LoadTestDataCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Clear existing data before loading');
        $this->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Number of days to generate', 30);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('clear')) {
            $io->warning('Clearing existing data...');
            $this->entityManager->createQuery('DELETE FROM App\Entity\Cigarette')->execute();
            $this->entityManager->createQuery('DELETE FROM App\Entity\WakeUp')->execute();
        }

        $days = (int) $input->getOption('days');
        $io->section("Generating $days days of data...");

        // Progression réaliste : 20 -> ~10 clopes sur 30 jours
        // Avec variations : bons jours, mauvais jours, weekends plus difficiles
        $baseCount = 20;
        $data = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = new \DateTime("-$i days");
            $dayOfWeek = (int) $date->format('N'); // 1=Lundi, 7=Dimanche
            $progress = ($days - 1 - $i) / ($days - 1); // 0 -> 1

            // Tendance générale à la baisse
            $trend = $baseCount - ($progress * 10); // 20 -> 10

            // Variation aléatoire (-2 à +2)
            $variation = rand(-2, 2);

            // Weekend plus difficile (+1 à +3)
            $weekendBonus = ($dayOfWeek >= 6) ? rand(1, 3) : 0;

            // Quelques "rechutes" aléatoires (10% de chance)
            $relapse = (rand(1, 100) <= 10) ? rand(2, 4) : 0;

            // Quelques très bons jours (10% de chance)
            $goodDay = (rand(1, 100) <= 10) ? -rand(2, 3) : 0;

            $count = max(5, min(22, round($trend + $variation + $weekendBonus + $relapse + $goodDay)));

            // Aujourd'hui : seulement jusqu'à maintenant
            if ($i === 0) {
                $currentHour = (int) date('H');
                $ratio = max(0.3, min(1, $currentHour / 22));
                $count = max(1, round($count * $ratio));
            }

            $data[] = [
                'date' => $date,
                'count' => (int) $count,
                'isWeekend' => $dayOfWeek >= 6,
            ];
        }

        // Créer les données
        $tableData = [];
        foreach ($data as $dayData) {
            $date = $dayData['date'];
            $count = $dayData['count'];

            // Wake up time (entre 6:30 et 8:30, un peu plus tard le weekend)
            $baseWakeHour = $dayData['isWeekend'] ? 8 : 7;
            $wakeMinutes = rand(0, 60);
            $wakeTime = (clone $date)->setTime($baseWakeHour, $wakeMinutes, 0);

            $wakeUp = new WakeUp();
            $wakeUp->setDate((clone $date)->setTime(0, 0, 0));
            $wakeUp->setWakeTime($wakeTime);
            $this->entityManager->persist($wakeUp);

            // Générer les clopes réparties sur la journée
            $this->generateCigarettes($date, $count, $wakeTime);

            $tableData[] = [
                $date->format('D d/m'),
                $wakeTime->format('H:i'),
                $count,
                $dayData['isWeekend'] ? '🗓️' : '',
            ];
        }

        $this->entityManager->flush();

        $io->success('Test data loaded!');
        $io->table(['Date', 'Réveil', 'Clopes', ''], $tableData);

        // Stats
        $total = array_sum(array_column($data, 'count'));
        $avg = round($total / count($data), 1);
        $first = $data[0]['count'];
        $last = $data[count($data) - 1]['count'];

        $io->table(
            ['Stat', 'Value'],
            [
                ['Total clopes', $total],
                ['Moyenne/jour', $avg],
                ['Premier jour', $first],
                ['Dernier jour', $last],
                ['Évolution', ($last - $first) . ' (' . round(($last - $first) / $first * 100) . '%)'],
            ]
        );

        return Command::SUCCESS;
    }

    private function generateCigarettes(\DateTime $date, int $count, \DateTime $wakeTime): void
    {
        if ($count === 0) {
            return;
        }

        $wakeMinutes = (int) $wakeTime->format('H') * 60 + (int) $wakeTime->format('i');
        $endMinutes = 23 * 60; // Jusqu'à 23h max

        // Aujourd'hui : limiter à l'heure actuelle
        $isToday = $date->format('Y-m-d') === (new \DateTime())->format('Y-m-d');
        if ($isToday) {
            $now = new \DateTime();
            $endMinutes = (int) $now->format('H') * 60 + (int) $now->format('i');
        }

        $availableMinutes = $endMinutes - $wakeMinutes;
        if ($availableMinutes <= 0 || $count === 0) {
            return;
        }

        // Première clope : 15-45min après réveil
        $firstCigOffset = rand(15, 45);
        $times = [$wakeMinutes + $firstCigOffset];

        // Répartir les autres clopes
        if ($count > 1) {
            $remainingMinutes = $availableMinutes - $firstCigOffset;
            $avgInterval = $remainingMinutes / ($count - 1);

            for ($i = 1; $i < $count; $i++) {
                // Intervalle variable autour de la moyenne
                $interval = $avgInterval * (0.5 + (rand(0, 100) / 100));
                $lastTime = $times[count($times) - 1];
                $newTime = min($endMinutes, $lastTime + $interval);
                $times[] = (int) $newTime;
            }
        }

        foreach ($times as $minutes) {
            $hours = floor($minutes / 60);
            $mins = $minutes % 60;

            $cig = new Cigarette();
            $cigTime = (clone $date)->setTime((int) $hours, (int) $mins, rand(0, 59));
            $cig->setSmokedAt($cigTime);
            $this->entityManager->persist($cig);
        }
    }
}
