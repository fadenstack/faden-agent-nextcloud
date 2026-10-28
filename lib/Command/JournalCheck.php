<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\Command;

use OCA\FadenstackAgent\History\JournalInspector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class JournalCheck extends Command {
	public function __construct(
		private JournalInspector $inspector,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('fadenstack_agent:journal:check')
			->setDescription('Check action-history integrity and retention counts without changing data')
			->addOption('output', null, InputOption::VALUE_REQUIRED, 'text or json', 'text');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!in_array($input->getOption('output'), ['text', 'json'], true)) {
			$output->writeln('<error>Use --output=text or --output=json.</error>');
			return self::INVALID;
		}
		$result = $this->inspector->check();
		if ($input->getOption('output') === 'json') {
			$output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
		} else {
			$output->writeln('Action history check (read-only; counts only)');
			$output->writeln('Rows: ' . $result['rows'] . '; readable: ' . $result['readable'] . '; unavailable: ' . array_sum($result['unavailable']));
			foreach (['formats', 'states', 'unavailable', 'due'] as $group) {
				foreach ($result[$group] as $label => $count) {
					$output->writeln($group . '.' . $label . ': ' . $count);
				}
			}
		}
		return $result['unavailable'] === [] ? self::SUCCESS : self::FAILURE;
	}
}
