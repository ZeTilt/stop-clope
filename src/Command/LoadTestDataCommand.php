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
    description: 'Load test data for yesterday and today',
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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('clear')) {
            $io->warning('Clearing existing data...');
            $this->entityManager->createQuery('DELETE FROM App\Entity\Cigarette')->execute();
            $this->entityManager->createQuery('DELETE FROM App\Entity\WakeUp')->execute();
        }

        $yesterday = new \DateTime('yesterday');
        $today = new \DateTime('today');

        // Wake up times
        $io->section('Creating wake up times...');

        // Yesterday wake up at 7:30
        $yesterdayWakeUp = new WakeUp();
        $yesterdayWakeUp->setDate((clone $yesterday)->setTime(0, 0, 0));
        $yesterdayWakeUp->setWakeTime((clone $yesterday)->setTime(7, 30, 0));
        $this->entityManager->persist($yesterdayWakeUp);
        $io->writeln('  - Yesterday wake up: 07:30');

        // Today wake up at 7:45
        $todayWakeUp = new WakeUp();
        $todayWakeUp->setDate((clone $today)->setTime(0, 0, 0));
        $todayWakeUp->setWakeTime((clone $today)->setTime(7, 45, 0));
        $this->entityManager->persist($todayWakeUp);
        $io->writeln('  - Today wake up: 07:45');

        // Yesterday cigarettes (réveil 7:30)
        $io->section('Creating yesterday cigarettes...');
        $yesterdayCigs = [
            '08:00', // 30min après réveil
            '09:15', // 1h45 après réveil
            '10:30', // 3h après réveil
            '11:00', // 3h30 après réveil
            '12:00', // 4h30 après réveil
            '13:30', // 6h après réveil
            '15:00',
            '16:30',
            '18:00',
            '20:00',
            '22:00',
        ];

        foreach ($yesterdayCigs as $time) {
            [$h, $m] = explode(':', $time);
            $cig = new Cigarette();
            $cig->setSmokedAt((clone $yesterday)->setTime((int)$h, (int)$m, 0));
            $this->entityManager->persist($cig);
            $io->writeln("  - $time");
        }

        // Today cigarettes (réveil 7:45, jusqu'à 12:30)
        $io->section('Creating today cigarettes (until 12:30)...');
        $todayCigs = [
            '08:15', // 30min après réveil (comme hier)
            '09:45', // 2h après réveil (30min plus tard qu'hier)
            '11:00', // 3h15 après réveil (45min plus tard qu'hier)
            '12:00', // 4h15 après réveil (45min plus tard qu'hier)
        ];

        foreach ($todayCigs as $time) {
            [$h, $m] = explode(':', $time);
            $cig = new Cigarette();
            $cig->setSmokedAt((clone $today)->setTime((int)$h, (int)$m, 0));
            $this->entityManager->persist($cig);
            $io->writeln("  - $time");
        }

        $this->entityManager->flush();

        $io->success('Test data loaded!');
        $io->table(
            ['Day', 'Wake up', 'Cigarettes'],
            [
                ['Yesterday', '07:30', count($yesterdayCigs)],
                ['Today', '07:45', count($todayCigs)],
            ]
        );

        $io->note('Next cigarette comparison: Today\'s 5th vs Yesterday\'s 5th (13:30)');
        $io->note('With today wake up at 07:45, target time would be around 13:45 (6h after wake up)');

        return Command::SUCCESS;
    }
}
