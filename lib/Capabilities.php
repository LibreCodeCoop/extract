<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract;

use OCP\App\IAppManager;
use OCP\Capabilities\IPublicCapability;
use Override;

/**
 * @psalm-import-type ExtractCapabilities from ResponseDefinitions
 */
final class Capabilities implements IPublicCapability {
	public const FEATURES = [
		'extract-zip',
		'extract-rar',
		'extract-tar',
		'extract-tar-gz',
		'extract-tar-bz2',
		'extract-tar-xz',
		'extract-zstd',
		'extract-tar-zstd',
		'extract-7z',
	];

	public function __construct(
		protected IAppManager $appManager,
	) {
	}

	/**
	 * @return array{
	 *      extract?: ExtractCapabilities,
	 * }
	 */
	#[Override]
	public function getCapabilities(): array {
		$capabilities = [
			'features' => self::FEATURES,
			'config' => [
			],
			'version' => $this->appManager->getAppVersion('extract'),
		];

		return [
			'extract' => $capabilities,
		];
	}
}
