<?php

/**
 * SPDX-FileCopyrightText: 2012-2022 Paul Lereverend <paulereverend@gmail.com>
 * SPDX-FileCopyrightText: 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Service;

use OCP\IL10N;
use Psr\Log\LoggerInterface;

/** Validate entry names and archive metadata independently from decoders. */
final class ArchiveSafetyValidator {
	public function __construct(
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	private function isUnsafePath(string $entry): bool {
		$path = str_replace('\\', '/', $entry);
		if (preg_match('/[\x00-\x1f\x7f]/', $entry) === 1) {
			return true;
		}
		if (str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path) === 1) {
			return true;
		}
		return preg_match('#(^|/)\.\.($|/)#', $path) === 1;
	}

	public function rejectUnsafe(string $entry): ?array {
		if (!$this->isUnsafePath($entry)) {
			return null;
		}
		$this->logger->warning('Refusing archive with unsafe entry');
		return ['code' => 0, 'desc' => $this->l->t('Archive contains an unsafe path and was not extracted')];
	}

	public function rejectUnsafeMetadata(string $line): ?array {
		if (preg_match('/^(?:Symbolic Link|Hard Link|Junction|Reparse Point)\s*=\s*\S/i', $line) !== 1
			&& !(str_starts_with($line, 'Attributes = ')
				&& preg_match('/(?:^|\s)[lbpcs][-rwxstST]{9}(?:\s|$)/', $line) === 1)) {
			return null;
		}
		$this->logger->warning('Refusing archive with links or special files');
		return ['code' => 0, 'desc' => $this->l->t('Archive contains links or unsupported special files')];
	}
}
