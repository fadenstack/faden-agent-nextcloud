<?php

declare(strict_types=1);

namespace OCA\FadenstackAgent\AppInfo;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\FadenstackAgent\DAV\SabrePluginListener;
use OCA\FadenstackAgent\History\AccessResolverRegistry;
use OCA\FadenstackAgent\History\CalendarAccessResolver;
use OCA\FadenstackAgent\History\CommunicationAccessResolver;
use OCA\FadenstackAgent\History\ContactsAccessResolver;
use OCA\FadenstackAgent\History\ContentAccessResolver;
use OCA\FadenstackAgent\History\FilesAccessResolver;
use OCA\FadenstackAgent\History\StructuredAccessResolver;
use OCA\FadenstackAgent\History\TasksAccessResolver;
use OCA\FadenstackAgent\History\WorkflowAccessResolver;
use OCA\FadenstackAgent\Listener\ShellListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'fadenstack_agent';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, ShellListener::class);
		$context->registerEventListener(SabrePluginAddEvent::class, SabrePluginListener::class);
		$context->registerService(AccessResolverRegistry::class, static function (\Psr\Container\ContainerInterface $container): AccessResolverRegistry {
			$registry = new AccessResolverRegistry();
			$registry->register('files', $container->get(FilesAccessResolver::class));
			$registry->register('calendar', $container->get(CalendarAccessResolver::class));
			$registry->register('tasks', $container->get(TasksAccessResolver::class));
			$registry->register('contacts', $container->get(ContactsAccessResolver::class));
			$registry->register('tables', $container->get(StructuredAccessResolver::class));
			$registry->register('deck', $container->get(StructuredAccessResolver::class));
			$registry->register('mail', $container->get(CommunicationAccessResolver::class));
			$registry->register('talk', $container->get(CommunicationAccessResolver::class));
			foreach (['collectives', 'forms', 'notes', 'bookmarks'] as $provider) {
				$registry->register($provider, $container->get(ContentAccessResolver::class));
			}
			foreach (['whiteboard', 'flow'] as $provider) {
				$registry->register($provider, $container->get(WorkflowAccessResolver::class));
			}
			return $registry;
		});
	}

	public function boot(IBootContext $context): void {
	}
}
