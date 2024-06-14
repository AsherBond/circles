<?php

declare(strict_types=1);


/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Circles\Model\Probes;

use OCA\Circles\Model\Member;

/**
 * Class MemberProbe
 *
 * @package OCA\Circles\Model\Probes
 */
class MemberProbe extends BasicProbe {
	private int $minimumLevel = Member::LEVEL_NONE;
	private bool $emulateVisitor = false;
	private bool $requestingMembership = false;
	private bool $initiatorDirectMember = false;

	/**
	 * allow the initiator as a requesting member
	 *
	 * @param bool $can
	 *
	 * @return $this
	 */
	public function canBeRequestingMembership(bool $can = true): self {
		$this->requestingMembership = $can;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isRequestingMembership(): bool {
		return $this->requestingMembership;
	}


	/**
	 * @param bool $include
	 *
	 * @return $this
	 */
	public function initiatorAsDirectMember(bool $include = true): self {
		$this->initiatorDirectMember = $include;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function directMemberInitiator(): bool {
		return $this->initiatorDirectMember;
	}


	/**
	 * force the generation an initiator if visitor
	 *
	 * @return $this
	 */
	public function emulateVisitor(): self {
		$this->emulateVisitor = true;

		return $this;
	}

	public function isEmulatingVisitor(): bool {
		return $this->emulateVisitor;
	}


	/**
	 * @return int
	 */
	public function getMinimumLevel(): int {
		return $this->minimumLevel;
	}

	/**
	 * @return $this
	 */
	public function mustBeMember(bool $must = true): self {
		if ($must) {
			$this->minimumLevel = Member::LEVEL_MEMBER;
		} else {
			$this->minimumLevel = Member::LEVEL_NONE;
		}

		return $this;
	}

	/**
	 * @return $this
	 */
	public function mustBeModerator(): self {
		$this->minimumLevel = Member::LEVEL_MODERATOR;

		return $this;
	}

	/**
	 * @return $this
	 */
	public function mustBeAdmin(): self {
		$this->minimumLevel = Member::LEVEL_ADMIN;

		return $this;
	}

	/**
	 * @return $this
	 */
	public function mustBeOwner(): self {
		$this->minimumLevel = Member::LEVEL_OWNER;

		return $this;
	}


	/**
	 * @return array
	 */
	public function getAsOptions(): array {
		return array_merge(
			[
				'minimumLevel' => $this->getMinimumLevel(),
				'emulateVisitor' => $this->isEmulatingVisitor(),
				'allowRequestingMembership' => $this->isRequestingMembership(),
				'initiatorDirectMember' => $this->directMemberInitiator(),
			],
			parent::getAsOptions()
		);
	}


	/**
	 * @return array
	 */
	public function JsonSerialize(): array {
		return $this->getAsOptions();
	}
}
