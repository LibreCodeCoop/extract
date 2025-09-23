<?php

/**
 * SPDX-FileCopyrightText: 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\AppInfo;

use OCA\Extract\Listener\LoadExtractActions;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap {

	public const APP_ID = 'extract';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadExtractActions::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
