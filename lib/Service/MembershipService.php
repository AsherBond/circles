<?php

declare(strict_types=1);


/**
 * Circles - Bring cloud-users closer together.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2021
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Circles\Service;


use daita\MySmallPhpTools\Exceptions\ItemNotFoundException;
use daita\MySmallPhpTools\Traits\Nextcloud\nc22\TNC22Logger;
use OCA\Circles\Db\CircleRequest;
use OCA\Circles\Db\MemberRequest;
use OCA\Circles\Db\MembershipRequest;
use OCA\Circles\Exceptions\CircleNotFoundException;
use OCA\Circles\Exceptions\OwnerNotFoundException;
use OCA\Circles\Exceptions\RequestBuilderException;
use OCA\Circles\Model\Member;
use OCA\Circles\Model\Membership;


/**
 * Class MembershipService
 *
 * @package OCA\Circles\Service
 */
class MembershipService {


	use TNC22Logger;


	/** @var MembershipRequest */
	private $membershipRequest;

	/** @var CircleRequest */
	private $circleRequest;

	/** @var MemberRequest */
	private $memberRequest;

	/** @var EventService */
	private $eventService;


	/**
	 * MembershipService constructor.
	 *
	 * @param MembershipRequest $membershipRequest
	 * @param CircleRequest $circleRequest
	 * @param MemberRequest $memberRequest
	 * @param EventService $eventService
	 */
	public function __construct(
		MembershipRequest $membershipRequest,
		CircleRequest $circleRequest,
		MemberRequest $memberRequest,
		EventService $eventService
	) {
		$this->membershipRequest = $membershipRequest;
		$this->circleRequest = $circleRequest;
		$this->memberRequest = $memberRequest;
		$this->eventService = $eventService;
	}


	/**
	 * @param string $singleId
	 *
	 * @throws RequestBuilderException
	 */
	public function onUpdate(string $singleId): void {
		if ($singleId === '') {
			return;
		}

		try {
			$this->circleRequest->getFederatedUserBySingleId($singleId);
		} catch (CircleNotFoundException | OwnerNotFoundException $e) {
			$this->membershipRequest->removeBySingleId($singleId);
		}

		$children = array_unique(
			array_merge(
				[$singleId],
				$this->getChildrenMembers($singleId),
				$this->getChildrenMemberships($singleId)
			)
		);

		foreach ($children as $singleId) {
			$this->manageMemberships($singleId);
		}
	}


	/**
	 * @param string $singleId
	 *
	 * @return int
	 */
	public function manageMemberships(string $singleId): int {
		$memberships = $this->generateMemberships($singleId);

		return $this->updateMembershipsDatabase($singleId, $memberships);
	}


	/**
	 * @param string $id
	 * @param array $knownIds
	 *
	 * @return array
	 * @throws RequestBuilderException
	 */
	private function getChildrenMembers(string $id, array &$knownIds = []): array {
		$singleIds = array_map(
			function(Member $item): string {
				return $item->getSingleId();
			}, $this->memberRequest->getMembers($id)
		);

		foreach ($singleIds as $singleId) {
			if ($singleId !== $id && !in_array($singleId, $knownIds)) {
				$knownIds[] = $singleId;
				$singleIds = array_merge($singleIds, $this->getChildrenMembers($singleId, $knownIds));
			}
		}

		return array_unique($singleIds);
	}


	/**
	 * @param string $id
	 * @param array $knownIds
	 *
	 * @return array
	 */
	private function getChildrenMemberships(string $id, array &$knownIds = []): array {
		$singleIds = array_map(
			function(Membership $item): string {
				return $item->getSingleId();
			}, $this->membershipRequest->getChildren($id)
		);

		foreach ($singleIds as $singleId) {
			if ($singleId !== $id && !in_array($singleId, $knownIds)) {
				$knownIds[] = $singleId;
				$singleIds = array_merge($singleIds, $this->getChildrenMemberships($singleId, $knownIds));
			}
		}

		return array_unique($singleIds);
	}


	/**
	 * @param string $singleId
	 * @param string $circleId
	 * @param array $memberships
	 * @param array $knownIds
	 * @param array $path
	 *
	 * @return array
	 */
	public function generateMemberships(
		string $singleId,
		string $circleId = '',
		array &$memberships = [],
		array $knownIds = [],
		array $path = []
	): array {
		$circleId = ($circleId === '') ? $singleId : $circleId;
		$path[] = $circleId;
		$knownIds[] = $circleId;

		$members = $this->memberRequest->getMembersBySingleId($circleId);
		foreach ($members as $member) {
			if ($member->getLevel() < Member::LEVEL_MEMBER) {
				continue;
			}

			$membership = new Membership($singleId, count($path) > 1 ? $path[1] : '', $member);
			$membership->setInheritancePath(array_reverse($path))
					   ->setInheritanceDepth(sizeof($path));
			$this->fillMemberships($membership, $memberships);
			if (!in_array($member->getCircleId(), $knownIds)) {
				$this->generateMemberships(
					$singleId,
					$member->getCircleId(),
					$memberships,
					$knownIds,
					$path
				);
			}
		}

		return $memberships;
	}


	/**
	 * @param Membership $membership
	 * @param Membership[] $memberships
	 */
	private function fillMemberships(Membership $membership, array &$memberships) {
		foreach ($memberships as $known) {
			if ($known->getCircleId() === $membership->getCircleId()) {
				if ($known->getLevel() < $membership->getLevel()) {
					$known->setLevel($membership->getLevel());
//					$known->setMemberId($membership->getMemberId());
					$known->setSingleId($membership->getSingleId());
					$known->setInheritanceLast($membership->getInheritanceLast());
				}

				return;
			}
		}

		$memberships[$membership->getCircleId()] = $membership;
	}


	/**
	 * @param string $singleId
	 * @param Membership[] $memberships
	 *
	 * @return int
	 */
	public function updateMembershipsDatabase(string $singleId, array $memberships): int {
		$known = $this->membershipRequest->getMemberships($singleId);

		$deprecated = $this->removeDeprecatedMemberships($memberships, $known);
		if (!empty($deprecated)) {
			$this->eventService->membershipsRemoved($deprecated);
		}

		$new = $this->createNewMemberships($memberships, $known);
		if (!empty($new)) {
			$this->eventService->membershipsCreated($new);
		}

		return count($deprecated) + count($new);
	}


	/**
	 * @param Membership[] $memberships
	 * @param Membership[] $known
	 *
	 * @return Membership[]
	 */
	private function removeDeprecatedMemberships(array $memberships, array $known): array {
		$circleIds = array_map(
			function(Membership $membership): string {
				return $membership->getCircleId();
			}, $memberships
		);

		$deprecated = [];
		foreach ($known as $item) {
			if (!in_array($item->getCircleId(), $circleIds)) {
				$deprecated[] = $item;
				$this->membershipRequest->delete($item);
			}
		}

		return $deprecated;
	}


	/**
	 * @param Membership[] $memberships
	 * @param Membership[] $known
	 *
	 * @return Membership[]
	 */
	private function createNewMemberships(array $memberships, array $known): array {
		$new = [];
		foreach ($memberships as $membership) {
			try {
				$item = $this->getMembershipsFromList($known, $membership->getCircleId());
				if ($item->getLevel() !== $membership->getLevel()) {
					$this->membershipRequest->update($membership);
					$new[] = $item;
				}
			} catch (ItemNotFoundException $e) {
				$this->membershipRequest->insert($membership);
				$new[] = $membership;
			}
		}

		return $new;
	}


	/**
	 * @param Membership[] $list
	 * @param string $circleId
	 *
	 * @return Membership
	 * @throws ItemNotFoundException
	 */
	private function getMembershipsFromList(array $list, string $circleId): Membership {
		foreach ($list as $item) {
			if ($item->getCircleId() === $circleId) {
				return $item;
			}
		}

		throw new ItemNotFoundException();
	}


	/**
	 * @param string $singleId
	 * @param bool $all
	 */
	public function resetMemberships(string $singleId = '', bool $all = false) {
		$this->membershipRequest->removeBySingleId($singleId, $all);
	}

}
