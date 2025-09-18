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

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	// Called later than "register".
	#[\Override]
	public function boot(IBootContext $context): void {
	}

	// Called earlier than boot, so anything initialized in the
	// "boot()" method must not be used here.
	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadExtractActions::class);
	}
}
