<?php

declare(strict_types=1);


/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\Circles\Model\Helpers;

use OCA\Circles\Exceptions\MemberHelperException;
use OCA\Circles\Exceptions\MemberLevelException;
use OCA\Circles\Exceptions\ParseMemberLevelException;
use OCA\Circles\Model\Member;
use OCA\Circles\Tools\Traits\TArrayTools;

/**
 * Class MemberHelper
 *
 * @method void mustBeMember() @throws MemberHelperException, MemberLevelException
 * @method void mustBeModerator() @throws MemberHelperException, MemberLevelException
 * @method void mustBeAdmin() @throws MemberHelperException, MemberLevelException
 * @method void mustBeOwner() @throws MemberHelperException, MemberLevelException
 * @method void cannotBeMember() @throws MemberHelperException, MemberLevelException
 * @method void cannotBeModerator() @throws MemberHelperException, MemberLevelException
 * @method void cannotBeAdmin() @throws MemberHelperException, MemberLevelException
 * @method void cannotBeOwner() @throws MemberHelperException, MemberLevelException
 *
 * @package OCA\Circles\Model\Helpers
 */
class MemberHelper {
	use TArrayTools;


	/** @var Member */
	private $member;


	/**
	 * Member constructor.
	 *
	 * @param Member $member
	 */
	public function __construct(Member $member) {
		$this->member = $member;
	}


	/**
	 * @param string $name
	 * @param array $arguments
	 *
	 * @throws MemberHelperException
	 * @throws MemberLevelException
	 */
	public function __call(string $name, array $arguments): void {
		if (substr(strtolower($name), 0, 8) === 'cannotbe') {
			$this->cannotBe(substr($name, 8), $arguments);

			return;
		}
		if (substr(strtolower($name), 0, 6) === 'mustbe') {
			$this->mustBe(substr($name, 6), $arguments);

			return;
		}

		throw new MemberHelperException('unknown method call');
	}


	/**
	 * @param string $levelString
	 * @param array $arguments
	 *
	 * @throws MemberHelperException
	 * @throws MemberLevelException
	 */
	private function mustBe(string $levelString, array $arguments): void {
		try {
			$level = Member::parseLevelString($levelString);
		} catch (ParseMemberLevelException $e) {
			throw new MemberHelperException('method ' . $levelString . ' not found');
		}

		$this->mustHaveLevelEqualOrAbove($level);
	}


	/**
	 * @param string $levelString
	 * @param array $arguments
	 *
	 * @throws MemberHelperException
	 * @throws MemberLevelException
	 */
	private function cannotBe(string $levelString, array $arguments): void {
		try {
			$level = Member::parseLevelString($levelString);
		} catch (ParseMemberLevelException $e) {
			throw new MemberHelperException('method ' . $levelString . ' not found');
		}

		if ($this->member->getLevel() >= $level) {
			throw new MemberLevelException('Member cannot be ' . $levelString);
		}
	}


	/**
	 * @param int $level
	 *
	 * @throws MemberLevelException
	 */
	public function mustHaveLevelAbove(int $level) {
		if ($this->member->getLevel() <= $level) {
			throw new MemberLevelException('Insufficient rights');
		}
	}


	/**
	 * @param int $level
	 *
	 * @throws MemberLevelException
	 */
	public function mustHaveLevelAboveOrEqual(int $level) {
		if ($this->member->getLevel() < $level) {
			throw new MemberLevelException('Insufficient rights');
		}
	}


	/**
	 * @param int $level
	 *
	 * @throws MemberLevelException
	 */
	public function mustHaveLevelEqualOrAbove(int $level) {
		if ($this->member->getLevel() < $level) {
			throw new MemberLevelException('Insufficient rights');
		}
	}


	/**
	 * @param Member $compare
	 *
	 * @throws MemberLevelException
	 */
	public function mustBeHigherLevelThan(Member $compare) {
		$this->mustHaveLevelAbove($compare->getLevel());
	}

	/**
	 * @param Member $compare
	 *
	 * @throws MemberLevelException
	 */
	public function mustBeHigherOrSameLevelThan(Member $compare) {
		$this->mustHaveLevelEqualOrAbove($compare->getLevel());
	}
}
