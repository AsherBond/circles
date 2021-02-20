<?php

declare(strict_types=1);

/**
 * Circles - Bring cloud-users closer together.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@pontapreta.net>
 * @copyright 2017
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


namespace OCA\Circles\Db;


use daita\MySmallPhpTools\Db\Nextcloud\nc21\NC21ExtendedQueryBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use OC;
use OCA\Circles\IFederatedUser;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\Federated\RemoteInstance;
use OCA\Circles\Model\Member;
use OCA\Circles\Service\ConfigService;
use OCP\DB\QueryBuilder\ICompositeExpression;

/**
 * Class CoreQueryBuilder
 *
 * @package OCA\Circles\Db
 */
class CoreQueryBuilder extends NC21ExtendedQueryBuilder {


	const PREFIX_MEMBER = 'member_';
	const PREFIX_OWNER = 'owner_';
	const PREFIX_INITIATOR = 'initiator_';
	const PREFIX_CIRCLE = 'circle_';


	/** @var ConfigService */
	private $configService;


	/**
	 * CoreQueryBuilder constructor.
	 */
	public function __construct() {
		parent::__construct();

		$this->configService = OC::$server->get(ConfigService::class);
	}


	/**
	 * @param IFederatedUser $member
	 *
	 * @return string
	 */
	public function getInstance(IFederatedUser $member): string {
		$instance = $member->getInstance();

		return ($this->configService->isLocalInstance($instance)) ? '' : $instance;
	}


	/**
	 * @param string $id
	 */
	public function limitToCircleId(string $id): void {
		$this->limitToDBField('circle_id', $id, true);
	}

	/**
	 * @param int $config
	 */
	public function limitToConfig(int $config): void {
		$this->limitToDBFieldInt('config', $config);
	}


	/**
	 * @param string $singleId
	 */
	public function limitToSingleId(string $singleId): void {
		$this->limitToDBField('single_id', $singleId, true);
	}

	/**
	 * @param string $host
	 */
	public function limitToInstance(string $host): void {
		$this->limitToDBField('instance', $host, false);
	}


	/**
	 * @param int $userType
	 */
	public function limitToUserType(int $userType): void {
		$this->limitToDBFieldInt('user_type', $userType);
	}


	/**
	 * @param IFederatedUser $initiator
	 * @param string $alias
	 */
	public function limitToInitiator(IFederatedUser $initiator, string $alias = ''): void {
		$this->leftJoinInitiator($initiator, 'init', $alias);
		$this->limitVisibility('init', $alias);
	}


	/**
	 * @param string $instance
	 * @param string $aliasCircle
	 * @param string $aliasOwner
	 * @param bool $sensitive
	 */
	public function limitToRemoteInstance(
		string $instance,
		bool $sensitive = true,
		string $aliasCircle = 'c',
		string $aliasOwner = 'o'
	): void {
		$this->leftJoinRemoteInstance($instance, 'ri');
		$this->leftJoinMemberFromInstance($instance, 'mi', $aliasCircle);
		$this->leftJoinMemberFromRemoteCircle($instance, 'rco', $aliasCircle);
		$this->limitRemoteVisibility($sensitive, 'ri', 'rco', $aliasCircle, $aliasOwner, 'mi');
	}


	/**
	 * @param IFederatedUser $member
	 * @param int $level
	 */
	public function limitToMembership(IFederatedUser $member, int $level = Member::LEVEL_MEMBER): void {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $this->expr();

		$alias = 'm';
		$this->generateMemberSelectAlias($alias, self::PREFIX_MEMBER)
			 ->leftJoin(
				 $this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_MEMBER, $alias,
				 $expr->eq($alias . '.circle_id', $this->getDefaultSelectAlias() . '.unique_id')
			 );

		// TODO: Check in big table if it is better to put condition in andWhere() or in LeftJoin()
		$this->andWhere(
			$expr->andX(
				$expr->eq($alias . '.user_id', $this->createNamedParameter($member->getUserId())),
				$expr->eq($alias . '.user_type', $this->createNamedParameter($member->getUserType())),
				$expr->eq($alias . '.instance', $this->createNamedParameter($this->getInstance($member))),
				$expr->gte($alias . '.level', $this->createNamedParameter($level))
			)
		);
	}


	/**
	 * @param IFederatedUser|null $initiator
	 */
	public function leftJoinCircle(?IFederatedUser $initiator = null) {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $this->expr();

		$alias = 'c';
		$this->generateCircleSelectAlias($alias, self::PREFIX_CIRCLE)
			 ->leftJoin(
				 $this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_CIRCLE, $alias,
				 $expr->andX(
					 $expr->eq($alias . '.unique_id', $this->getDefaultSelectAlias() . '.circle_id')
				 )
			 );

		$this->leftJoinOwner($alias);
		if (!is_null($initiator)) {
			$this->limitToInitiator($initiator, $alias);
		}

	}


	/**
	 * @param string $circleTableAlias
	 */
	public function leftJoinOwner(string $circleTableAlias = '') {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		if ($circleTableAlias === '') {
			$circleTableAlias = $this->getDefaultSelectAlias();
		}
		$expr = $this->expr();

		$alias = 'o';
		$this->generateMemberSelectAlias($alias, self::PREFIX_OWNER)
			 ->leftJoin(
				 $circleTableAlias, CoreRequestBuilder::TABLE_MEMBER, $alias,
				 $expr->andX(
					 $expr->eq($alias . '.circle_id', $circleTableAlias . '.unique_id'),
					 $expr->eq($alias . '.level', $this->createNamedParameter(Member::LEVEL_OWNER))
				 )
			 );
	}


	/**
	 * Left join members to filter userId as initiator.
	 *
	 * @param IFederatedUser $initiator
	 * @param string $alias
	 * @param string $aliasCircle
	 */
	public function leftJoinInitiator(
		IFederatedUser $initiator, string $alias = 'init', string $aliasCircle = ''
	): void {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $this->expr();
		$aliasCircle = ($aliasCircle === '') ? $this->getDefaultSelectAlias() : $aliasCircle;
		$this->generateMemberSelectAlias($alias, self::PREFIX_INITIATOR)
			 ->leftJoin(
				 $aliasCircle, CoreRequestBuilder::TABLE_MEMBER, $alias,
				 $expr->andX(
					 $expr->eq($alias . '.circle_id', $aliasCircle . '.unique_id'),
					 $expr->eq($alias . '.user_id', $this->createNamedParameter($initiator->getUserId())),
					 $expr->eq($alias . '.user_type', $this->createNamedParameter($initiator->getUserType())),
					 $expr->eq(
						 $alias . '.instance', $this->createNamedParameter($this->getInstance($initiator))
					 )
				 )
			 );
	}


	/**
	 * left join members to check memberships of someone from instance
	 *
	 * @param string $instance
	 * @param string $alias
	 * @param string $aliasCircle
	 */
	private function leftJoinMemberFromInstance(
		string $instance, string $alias = 'mi', string $aliasCircle = 'c'
	) {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $this->expr();

		$this->leftJoin(
			$this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_MEMBER, $alias,
			$expr->andX(
				$expr->eq($alias . '.circle_id', $aliasCircle . '.unique_id'),
				$expr->eq($alias . '.instance', $this->createNamedParameter($instance)),
				$expr->gte($alias . '.level', $this->createNamedParameter(Member::LEVEL_MEMBER))
			)
		);
	}


	/**
	 * left join circle is member of a circle from remote instance
	 *
	 * @param string $instance
	 * @param string $aliasRemoteOwner
	 * @param string $aliasCircle
	 */
	private function leftJoinMemberFromRemoteCircle(
		string $instance, string $aliasRemoteOwner = 'rco', string $aliasCircle = 'c'
	) {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$alias = 'rc';
		$expr = $this->expr();
		$this->leftJoin(
			$this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_MEMBER, $alias,
			$expr->andX(
				$expr->eq($alias . '.single_id', $aliasCircle . '.unique_id'),
				$expr->emptyString($alias . '.instance'),
				$expr->gte($alias . '.level', $this->createNamedParameter(Member::LEVEL_MEMBER))
			)
		);
		$this->leftJoin(
			$this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_MEMBER, $aliasRemoteOwner,
			$expr->andX(
				$expr->eq($alias . '.circle_id', $aliasRemoteOwner . '.circle_id'),
				$expr->eq($aliasRemoteOwner . '.instance', $this->createNamedParameter($instance)),
				$expr->eq($aliasRemoteOwner . '.level', $this->createNamedParameter(Member::LEVEL_OWNER))
			)
		);
	}


	/**
	 * Left join remotes to filter visibility based on RemoteInstance.
	 *
	 * @param string $instance
	 * @param string $alias
	 */
	public function leftJoinRemoteInstance(string $instance, string $alias = 'ri'): void {
		if ($this->getType() !== QueryBuilder::SELECT) {
			return;
		}

		$expr = $this->expr();
		$this->leftJoin(
			$this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_REMOTE, $alias,
			$expr->eq($alias . '.instance', $this->createNamedParameter($instance))
		);
	}


	/**
	 * @param string $alias
	 * @param string $aliasCircle
	 */
	protected function limitVisibility(string $alias = 'init', string $aliasCircle = '') {
		$expr = $this->expr();
		$aliasCircle = ($aliasCircle === '') ? $this->getDefaultSelectAlias() : $aliasCircle;

		// Visibility to non-member is
		// - 0 (default), if initiator is member
		// - 2 (Personal), if initiator is owner)
		// - 4 (Visible to everyone)
		$orX = $expr->orX();
		$orX->add(
			$expr->andX($expr->gte($alias . '.level', $this->createNamedParameter(Member::LEVEL_MEMBER)))
		);
		$orX->add(
			$expr->andX(
				$expr->bitwiseAnd($aliasCircle . '.config', Circle::CFG_PERSONAL),
				$expr->eq($alias . '.level', $this->createNamedParameter(Member::LEVEL_OWNER))
			)
		);
		$orX->add($expr->bitwiseAnd($aliasCircle . '.config', Circle::CFG_VISIBLE));
		$this->andWhere($orX);


//		$orTypes = $this->generateLimit($qb, $circleUniqueId, $userId, $type, $name, $forceAll);
//		if (sizeof($orTypes) === 0) {
//			throw new ConfigNoCircleAvailableException(
//				$this->l10n->t(
//					'You cannot use the Circles Application until your administrator has allowed at least one type of circles'
//				)
//			);
//		}

//		$orXTypes = $this->expr()
//						 ->orX();
//		foreach ($orTypes as $orType) {
//			$orXTypes->add($orType);
//		}
//
//		$qb->andWhere($orXTypes);
	}


	/**
	 * CFG_SINGLE, CFG_HIDDEN and CFG_BACKEND means hidden from listing.
	 *
	 * TODO: add a filter for allowing those circles in some request
	 *
	 * @param string $alias
	 */
	public function filterSystemCircles(string $alias = ''): void {
		$alias = ($alias === '') ? $this->getDefaultSelectAlias() : $alias;
		$expr = $this->expr();

		$orHidden = $expr->orX();
		$orHidden->add($expr->bitwiseAnd($alias . '.config', Circle::CFG_SINGLE));
		$orHidden->add($expr->bitwiseAnd($alias . '.config', Circle::CFG_HIDDEN));
		$orHidden->add($expr->bitwiseAnd($alias . '.config', Circle::CFG_BACKEND));
		$this->andWhere($this->createFunction('NOT') . $orHidden);
	}


	/**
	 * - global_scale: visibility on all Circles
	 * - trusted: visibility on all FEDERATED Circle if owner is local
	 * - external: visibility on all FEDERATED Circle if owner is local and:
	 *    - with if Circle contains at least one member from the remote instance
	 *    - one circle from the remote instance contains the local circle as member, and confirmed (using
	 *      sync locally)
	 * - passive: like external, but the members list will only contains member from the local instance and
	 * from the remote instance.
	 *
	 * @param bool $sensitive
	 * @param string $alias
	 * @param string $aliasRemoteOwner
	 * @param string $aliasCircle
	 * @param string $aliasOwner
	 * @param string $aliasMembers
	 */
	protected function limitRemoteVisibility(
		bool $sensitive = true,
		string $alias = 'ri',
		string $aliasRemoteOwner = 'rco',
		string $aliasCircle = 'c',
		string $aliasOwner = 'o',
		string $aliasMembers = 'mi'
	) {
		$expr = $this->expr();

		$orX = $expr->orX();
		$orX->add(
			$expr->eq($alias . '.type', $this->createNamedParameter(RemoteInstance::TYPE_GLOBAL_SCALE))
		);

		$orExtOrPassive = $expr->orX();
		$orExtOrPassive->add(
			$expr->eq($alias . '.type', $this->createNamedParameter(RemoteInstance::TYPE_EXTERNAL))
		);
		if (!$sensitive) {
			$orExtOrPassive->add(
				$expr->eq($alias . '.type', $this->createNamedParameter(RemoteInstance::TYPE_PASSIVE))
			);
		} else {
			if ($this->getDefaultSelectAlias() === 'm') {
				$orExtOrPassive->add($this->limitRemoteVisibility_Sensitive_Members($alias));
			}
		}


		$orInstance = $expr->orX();
		$orInstance->add($expr->isNotNull($aliasMembers . '.instance'));
		$orInstance->add($expr->isNotNull($aliasRemoteOwner . '.instance'));

		$andExternal = $expr->andX();
		$andExternal->add($orExtOrPassive);
		$andExternal->add($orInstance);

		$orExtOrTrusted = $expr->orX();
		$orExtOrTrusted->add($andExternal);
		$orExtOrTrusted->add(
			$expr->eq($alias . '.type', $this->createNamedParameter(RemoteInstance::TYPE_TRUSTED))
		);

		$andTrusted = $expr->andX();
		$andTrusted->add($orExtOrTrusted);
		$andTrusted->add($expr->bitwiseAnd($aliasCircle . '.config', Circle::CFG_FEDERATED));
		$andTrusted->add($expr->emptyString($aliasOwner . '.instance'));
		$orX->add($andTrusted);

		$this->andWhere($orX);
	}


	/**
	 * Limit visibility on Sensitive information when search for members.
	 *
	 * @param string $alias
	 *
	 * @return ICompositeExpression
	 */
	private function limitRemoteVisibility_Sensitive_Members(string $alias = 'ri'): ICompositeExpression {
		$expr = $this->expr();
		$andPassive = $expr->andX();
		$andPassive->add(
			$expr->eq($alias . '.type', $this->createNamedParameter(RemoteInstance::TYPE_PASSIVE))
		);

		$orMemberOrLevel = $expr->orX();
		$orMemberOrLevel->add(
			$expr->eq($this->getDefaultSelectAlias() . '.instance', $alias . '.instance')
		);
		// TODO: do we need this ? (display members from the local instance)
		$orMemberOrLevel->add(
			$expr->emptyString($this->getDefaultSelectAlias() . '.instance')
		);

		$orMemberOrLevel->add(
			$expr->eq(
				$this->getDefaultSelectAlias() . '.level',
				$this->createNamedParameter(Member::LEVEL_OWNER)
			)
		);
		$andPassive->add($orMemberOrLevel);

		return $andPassive;
	}


	/**ha
	 * @param int $flag
	 */
	public function filterConfig(int $flag): void {
		$this->andWhere($this->expr()->bitwiseAnd($this->getDefaultSelectAlias() . '.config', $flag));
	}


	/**
	 * @param string $alias
	 * @param string $prefix
	 *
	 * @return $this
	 */
	private function generateCircleSelectAlias(string $alias, string $prefix): self {
		$this->selectAlias($alias . '.unique_id', $prefix . 'unique_id')
			 ->selectAlias($alias . '.name', $prefix . 'name')
			 ->selectAlias($alias . '.alt_name', $prefix . 'alt_name')
			 ->selectAlias($alias . '.description', $prefix . 'description')
			 ->selectAlias($alias . '.settings', $prefix . 'settings')
			 ->selectAlias($alias . '.config', $prefix . 'config')
			 ->selectAlias($alias . '.contact_addressbook', $prefix . 'contact_addressbook')
			 ->selectAlias($alias . '.contact_groupname', $prefix . 'contact_groupname')
			 ->selectAlias($alias . '.creation', $prefix . 'creation');

		return $this;
	}

	/**
	 * @param string $alias
	 * @param string $prefix
	 *
	 * @return $this
	 */
	private function generateMemberSelectAlias(string $alias, string $prefix): self {
		$this->selectAlias($alias . '.circle_id', $prefix . 'circle_id')
			 ->selectAlias($alias . '.single_id', $prefix . 'single_id')
			 ->selectAlias($alias . '.user_id', $prefix . 'user_id')
			 ->selectAlias($alias . '.user_type', $prefix . 'user_type')
			 ->selectAlias($alias . '.member_id', $prefix . 'member_id')
			 ->selectAlias($alias . '.instance', $prefix . 'instance')
			 ->selectAlias($alias . '.cached_name', $prefix . 'cached_name')
			 ->selectAlias($alias . '.cached_update', $prefix . 'cached_update')
			 ->selectAlias($alias . '.status', $prefix . 'status')
			 ->selectAlias($alias . '.level', $prefix . 'level')
			 ->selectAlias($alias . '.note', $prefix . 'note')
			 ->selectAlias($alias . '.contact_id', $prefix . 'contact_id')
			 ->selectAlias($alias . '.contact_meta', $prefix . 'contact_meta')
			 ->selectAlias($alias . '.joined', $prefix . 'joined');

		return $this;
	}

}

