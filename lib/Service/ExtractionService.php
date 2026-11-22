<?php
/**
 * SPDX-FileCopyrightText: 2012-2022 Paul Lereverend <paulereverend@gmail.com>
 * SPDX-FileCopyrightText: 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Extract\Service;

use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/** Stable entry point for the Nextcloud extraction controller. */
final class ExtractionService {
	private ZipExtractor $zip;
	private PhpRarExtractor $phpRar;
	private CliArchiveExtractor $cli;

	public function __construct(IL10N $l, LoggerInterface $logger, IConfig $config) {
		$safety = new ArchiveSafetyValidator($l, $logger);
		$limits = new ExtractionLimits($config);
		$copier = new BoundedStreamCopier();
		$this->zip = new ZipExtractor($l, $safety, $limits, $copier);
		$this->phpRar = new PhpRarExtractor($l, $logger, $safety, $limits, $copier);
		$this->cli = new CliArchiveExtractor($l, $logger, $safety, $limits);
	}

	/** @return array{code: 0|1, desc?: string} */
	public function extractZip(string $file, string $extractTo, ?int $availableBytes = null): array {
		return $this->zip->extract($file, $extractTo, $availableBytes);
	}

	/** @return array{code: 0|1, desc?: string} */
	public function extractRar(string $file, string $extractTo, ?int $availableBytes = null): array {
		if (extension_loaded('rar')) {
			return $this->phpRar->extract($file, $extractTo, $availableBytes);
		}
		return $this->cli->extractRar($file, $extractTo, $availableBytes);
	}

	/** @return array{code: 0|1, desc?: string} */
	public function extractOther(string $file, string $extractTo, ?int $availableBytes = null): array {
		return $this->cli->extractOther($file, $extractTo, $availableBytes);
	}
}
