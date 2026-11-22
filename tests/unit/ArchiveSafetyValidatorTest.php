<?php

/**
 * SPDX-FileCopyrightText: 2012-2022 Paul Lereverend <paulereverend@gmail.com>
 * SPDX-FileCopyrightText: 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Extract\Tests\unit;

use OCA\Extract\Service\ArchiveSafetyValidator;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ArchiveSafetyValidatorTest extends TestCase {
	private ArchiveSafetyValidator $validator;

	protected function setUp(): void {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$this->validator = new ArchiveSafetyValidator($l10n, $this->createStub(LoggerInterface::class));
	}

	public function testRejectsTraversalAndAbsoluteNames(): void {
		foreach (['../outside', 'a/../../outside', '/etc/passwd', 'C:\\\\Windows\\\\secret', 'some/..\\\\outside', "bad\\0name"] as $name) {
			self::assertSame(0, $this->validator->rejectUnsafe($name)['code'], $name);
		}
	}

	public function testAcceptsValidUnicodeAndNestedNames(): void {
		foreach (['Café.txt', '한국어/전자책.txt', 'dir/sub/file.txt'] as $name) {
			self::assertNull($this->validator->rejectUnsafe($name), $name);
		}
	}

	public function testRejectsActualLinksButNotEmptyLinkFields(): void {
		foreach (['Symbolic Link = ../out', 'Hard Link = file', 'Attributes = A lrwxrwxrwx'] as $line) {
			self::assertSame(0, $this->validator->rejectUnsafeMetadata($line)['code']);
		}
		foreach (['Symbolic Link = ', 'Hard Link = ', 'Attributes = RA', 'Attributes = A -rw-r--r--'] as $line) {
			self::assertNull($this->validator->rejectUnsafeMetadata($line));
		}
	}
}
