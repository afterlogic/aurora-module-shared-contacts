<?php
/**
 * This code is licensed under Afterlogic Software License.
 * For full statements of the license see LICENSE file.
 */

namespace Aurora\Modules\SharedContacts;

use Afterlogic\DAV\Constants;
use Aurora\Api;
use Aurora\Modules\Contacts\Enums\Access;
use Aurora\Modules\Contacts\Enums\SortField;
use Aurora\Modules\Contacts\Enums\StorageType;
use Aurora\Modules\Contacts\Models\AddressBook;
use Aurora\Modules\Contacts\Models\Contact;
use Aurora\Modules\Contacts\Module as ContactsModule;
use Aurora\Modules\Core\Models\Group;
use Aurora\Modules\Core\Models\User;
use Aurora\System\Enums\UserRole;
use Aurora\System\Exceptions\InvalidArgumentException;
use Aurora\System\Notifications;
use Illuminate\Database\Capsule\Manager as Capsule;
use Sabre\DAV\UUIDUtil;
use Aurora\Modules\Core\Module as CoreModule;

/**
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 *
 * @property Settings $oModuleSettings
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
    protected static $iStorageOrder = 10;

    protected $oBeforeDeleteUser = null;

    public function init()
    {
        $this->subscribeEvent('Contacts::GetStorages', array($this, 'onGetStorages'));
        $this->subscribeEvent('Contacts::PrepareFiltersFromStorage', array($this, 'onPrepareFiltersFromStorage'));

        $this->subscribeEvent('Contacts::UpdateSharedContacts::after', array($this, 'onAfterUpdateSharedContacts'));

        $this->subscribeEvent('Contacts::CheckAccessToObject::after', array($this, 'onAfterCheckAccessToObject'));
        $this->subscribeEvent('Contacts::GetContactSuggestions', array($this, 'onGetContactSuggestions'));
        $this->subscribeEvent('Contacts::GetAddressBooks::after', array($this, 'onAfterGetAddressBooks'), 1000);
        $this->subscribeEvent('Contacts::PopulateContactModel', array($this, 'onPopulateContactModel'));

        $this->subscribeEvent('Core::AddUsersToGroup::after', [$this, 'onAfterAddUsersToGroup']);
        $this->subscribeEvent('Core::RemoveUsersFromGroup::after', [$this, 'onAfterRemoveUsersFromGroup']);
        $this->subscribeEvent('Core::CreateUser::after', [$this, 'onAfterCreateUser']);
        $this->subscribeEvent('Core::UpdateUser::after', [$this, 'onAfterUpdateUser']);
        $this->subscribeEvent('Core::DeleteUser::before', [$this, 'onBeforeDeleteUser']);
        $this->subscribeEvent('Core::DeleteUser::after', [$this, 'onAfterDeleteUser']);
        $this->subscribeEvent('Core::DeleteGroup::after', [$this, 'onAfterDeleteGroup']);

        $this->subscribeEvent('Contacts::ContactQueryBuilder', array($this, 'onContactQueryBuilder'));
        $this->subscribeEvent('Contacts::CreateContact::before', array($this, 'onBeforeCreateContact'));
        $this->subscribeEvent('Contacts::CheckAccessToAddressBook::after', array($this, 'onAfterCheckAccessToAddressBook'));

        $this->subscribeEvent(self::GetName() . '::UpdateAddressbookShare::before', array($this, 'onBeforeUpdateAddressbookShare'));
        $this->subscribeEvent(self::GetName() . '::GetSharesForAddressbook::before', array($this, 'onBeforeUpdateAddressbookShare'));

        $this->subscribeEvent(self::GetName() . '::LeaveShare::before', array($this, 'onBeforeUpdateAddressbookShare'));
        $this->subscribeEvent('Contacts::GetContacts::before', array($this, 'populateStorage'));

    }

    /**
     * @return Module
     */
    public static function getInstance()
    {
        return parent::getInstance();
    }

    /**
     * @return Module
     */
    public static function Decorator()
    {
        return parent::Decorator();
    }

    /**
     * @return Settings
     */
    public function getModuleSettings()
    {
        return $this->oModuleSettings;
    }

    public function GetAddressbooks($UserId)
    {
        $mResult = [];

        Api::checkUserRoleIsAtLeast(UserRole::NormalUser);
        Api::CheckAccess($UserId);

        $dBPrefix = Api::GetSettings()->DBPrefix;
        $stmt = Api::GetPDO()->prepare("
		select ab.*, sab.access, sab.group_id from " . $dBPrefix . "adav_shared_addressbooks sab 
		left join " . $dBPrefix . "adav_addressbooks ab on sab.addressbook_id = ab.id
			where sab.principaluri = ?
		");

        $principalUri = Constants::PRINCIPALS_PREFIX . Api::getUserPublicIdById($UserId);
        $stmt->execute([
            $principalUri
        ]);

        $abooks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($abooks as $abook) {
            if ($abook['principaluri'] !== $principalUri) {
                if (isset($abook['id'])) {
                    $storage =  StorageType::Shared . '-' . $abook['id'];
                } else {
                    $storage =  StorageType::Shared . '-' . StorageType::Personal;
                }

                if (count($mResult) > 0) {
                    foreach ($mResult as $key => $val) {
                        if ($val['Id'] === $storage) {
                            if ($val['GroupId'] != 0) { //group sharing
                                if ($abook['access'] !== Access::Read) {
                                    if ($val['Access'] > (int) $abook['access'] || (int) $abook['access'] === Access::NoAccess) {
                                        $mResult[$key]['Access'] = (int) $abook['access'];
                                    }
                                } elseif ($val['Access'] !== Access::Write) {
                                    $mResult[$key]['Access'] = (int) $abook['access'];
                                }
                            }
                            continue 2;
                        }
                    }
                }

                $prevState = Api::skipCheckUserRole(true);
                Api::skipCheckUserRole($prevState);

                $mResult[] = [
                    'Id' => $storage,
                    'EntityId' => isset($abook['id']) ? (int) $abook['id'] : null,
                    'CTag' => isset($abook['synctoken']) ? (int) $abook['synctoken'] : 0,
                    'Display' => true,
                    'Order' => 1,
                    'DisplayName' => $abook['displayname'] . ' (' . basename($abook['principaluri']) . ')',
                    'Shared' => true,
                    'Access' => (int) $abook['access'],
                    'Owner' => basename($abook['principaluri']),
                    'GroupId' => (int) $abook['group_id']
                ];
            }
        }

        return array_filter($mResult, function ($item) {
            return ($item['Access'] !== Access::NoAccess);
        });
    }

    public function GetSharesForAddressbook($UserId, $Id)
    {
        Api::checkUserRoleIsAtLeast(UserRole::NormalUser);
        Api::CheckAccess($UserId);

        $aResult = [];

        $shares = $this->_getSharesForAddressbook($UserId, $Id);
        if (count($shares) > 0) {
            $oUser = Api::getUserById($UserId);
            $groups = [];
            foreach ($shares as $share) {
                if ($share['group_id'] != 0) {
                    if (!in_array($share['group_id'], $groups)) {
                        $oGroup = CoreModule::Decorator()->GetGroup($oUser->IdTenant, (int) $share['group_id']);
                        if ($oGroup) {
                            $groups[] = $share['group_id'];
                            $aResult[] = [
                                'PublicId' => $oGroup->getName(),
                                'Access' => (int) $share['access'],
                                'IsGroup' => true,
                                'IsAll' => !!$oGroup->IsAll,
                                'GroupId' => (int) $share['group_id']
                            ];
                        }
                    }
                } else {
                    $aResult[] = [
                        'PublicId' => basename($share['principaluri']),
                        'Access' => (int) $share['access']
                    ];
                }
            }
        }

        return $aResult;
    }

    protected function _getSharesForAddressbook($iUserId, $abookComplexId)
    {
        $dBPrefix = Api::GetSettings()->DBPrefix;
        $stmt = Api::GetPDO()->prepare("
		select sab.* from " . $dBPrefix . "adav_shared_addressbooks sab 
		left join " . $dBPrefix . "adav_addressbooks ab on sab.addressbook_id = ab.id
			left join " . $dBPrefix . "core_users cu on ab.principaluri = CONCAT('principals/', cu.PublicId)
				where cu.Id = ? AND ab.id = ?
		");

        $stmt->execute([
            $iUserId,
            $abookComplexId
        ]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    protected function getShareForAddressbook($iUserId, $abookComplexId, $principalUri, $groupId = 0)
    {
        $dBPrefix = Api::GetSettings()->DBPrefix;
        $stmt = Api::GetPDO()->prepare("
		select sab.*
		from " . $dBPrefix . "adav_shared_addressbooks sab 
		left join " . $dBPrefix . "adav_addressbooks ab on sab.addressbook_id = ab.id
			left join " . $dBPrefix . "core_users cu on ab.principaluri = CONCAT('principals/', cu.PublicId)
				where cu.Id = ? and sab.principaluri = ? and sab.group_id = ? and ab.id = ?
		");

        $stmt->execute([
            $iUserId,
            $principalUri,
            $groupId,
            $abookComplexId
        ]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    protected function deleteShareByPublicIds($userId, $abookComplexId, $publicIds)
    {
        $dBPrefix = Api::GetSettings()->DBPrefix;

        $sharesIds = [];
        foreach ($publicIds as $publicId) {
            $publicId = \json_decode($publicId);
            $shares = $this->getShareForAddressbook($userId, $abookComplexId, Constants::PRINCIPALS_PREFIX . $publicId[0], $publicId[1]);
            if (is_array($shares) && count($shares) > 0) {
                $ids = array_map(function ($share) {
                    return $share['id'];
                }, $shares);
                $sharesIds = array_merge($sharesIds, $ids);
            }
        }
        if (count($sharesIds) > 0) {
            $stmt = Api::GetPDO()->prepare("delete from " . $dBPrefix . "adav_shared_addressbooks where id in (" . \implode(',', $sharesIds) . ")");
            $stmt->execute();
        }
    }

    protected function getAddressbook($iUserId, $abookId)
    {
        $mResult = false;

        $dBPrefix = Api::GetSettings()->DBPrefix;

        $userPublicId = Api::getUserPublicIdById($iUserId);
        if (!empty($abookId) && $userPublicId) {
            $stmt = Api::GetPDO()->prepare("select * from " . $dBPrefix . "adav_addressbooks where principaluri = ? and id = ?");
            $stmt->execute([Constants::PRINCIPALS_PREFIX . $userPublicId, $abookId]);
            $mResult = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        return $mResult;
    }

    protected function createShare($iUserId, $abookComplexId, $share)
    {
        $dBPrefix = Api::GetSettings()->DBPrefix;

        $book = $this->getAddressbook($iUserId, $abookComplexId);
        if ($book) {
            $shareePublicId = $share['PublicId'];
            $access = $share['Access'];
            $groupId = $share['GroupId'];
            $stmt = Api::GetPDO()->prepare("insert into " . $dBPrefix . "adav_shared_addressbooks
			(principaluri, access, addressbook_id, addressbookuri, group_id)
			values (?, ?, ?, ?, ?)");
            $stmt->execute([Constants::PRINCIPALS_PREFIX . $shareePublicId, $access, $book['id'], UUIDUtil::getUUID(), $groupId]);
        }
    }

    protected function updateShare($iUserId, $abookComplexId, $share)
    {
        $dBPrefix = Api::GetSettings()->DBPrefix;
        $book = $this->getAddressbook($iUserId, $abookComplexId);
        if ($book) {
            $shareePublicId = $share['PublicId'];
            $access = $share['Access'];
            $groupId = $share['GroupId'];
            $stmt = Api::GetPDO()->prepare("update " . $dBPrefix . "adav_shared_addressbooks 
			set access = ? where principaluri = ? and addressbook_id = ? and group_id = ?");
            $stmt->execute([$access, Constants::PRINCIPALS_PREFIX . $shareePublicId, $book['id'], $groupId]);
        }
    }

    public function onGetStorages(&$aStorages)
    {
        // $aStorages[self::$iStorageOrder] = StorageType::Shared;
    }

    public function onPrepareFiltersFromStorage(&$aArgs, &$mResult)
    {
        if (isset($aArgs['Storage']) && $aArgs['Storage'] === StorageType::Shared || $aArgs['Storage'] === StorageType::All) {
            $oUser = Api::getUserById($aArgs['UserId']);
            $aArgs['IsValid'] = true;

            $q = Capsule::connection()->table('adav_shared_addressbooks')
                ->select('addressbook_id')
                ->from('adav_shared_addressbooks')
                ->where('principaluri', Constants::PRINCIPALS_PREFIX . $oUser->PublicId);

            if ($aArgs['Storage'] !== StorageType::All && isset($aArgs['AddressBookId'])) {
                $q->where('addressbook_id', (int) $aArgs['AddressBookId']);
            }

            $ids = $q->pluck('addressbook_id')->all();

            $mResult->whereIn('adav_cards.addressbookid', $ids, 'or');

            if (isset($aArgs['Query']) && count($ids) > 0) {
                $aArgs['Query']->addSelect(Capsule::connection()->raw(
                    'CASE
                    WHEN ' . Capsule::connection()->getTablePrefix() . 'adav_cards.addressbookid IN (' . implode(',', $ids) . ') THEN true
                    ELSE false
                END as Shared'
                ));
            }
        }
    }

    public function onAfterUpdateSharedContacts($aArgs, &$mResult)
    {
        $oContacts = \Aurora\Modules\Contacts\Module::Decorator();
        $aUUIDs = isset($aArgs['UUIDs']) ? $aArgs['UUIDs'] : [];

        foreach ($aUUIDs as $sUUID) {
            $oContact = $oContacts->GetContact($sUUID, $aArgs['UserId']);
            if ($oContact instanceof Contact) {
                $sOldStorage = $oContact->getStorageWithId();
                $iUserId = -1;

                if ($oContact->Storage === StorageType::Shared) {
                    $oContact->Storage = StorageType::Personal;
                    $iUserId = $oContact->IdTenant;
                    $oContact->IdUser = $aArgs['UserId'];
                } elseif ($oContact->Storage === StorageType::Personal) {
                    $oContact->Storage = StorageType::Shared;
                    $iUserId = $oContact->IdUser;
                }
                // update CTag for previous storage
                \Aurora\Modules\Contacts\Module::getInstance()->getManager()->updateCTag($iUserId, $sOldStorage);
                $mResult = $oContacts->UpdateContact($aArgs['UserId'], $oContact->toArray());
            }
        }
    }

    public function onAfterCheckAccessToObject(&$aArgs, &$mResult)
    {
        $oUser = $aArgs['User'];
        $oContact = isset($aArgs['Contact']) ? $aArgs['Contact'] : null;
        $Access = isset($aArgs['Access']) ? (int) $aArgs['Access'] : null;

        if ($oContact instanceof \Aurora\Modules\Contacts\Models\Contact) {
            if ($oContact->IdUser === $oUser->Id) {
                $mResult = true;
                return true; // break other subscriptions
            }
            if ($oContact->Storage === StorageType::Shared) {
                if ($oUser->Role !== \Aurora\System\Enums\UserRole::SuperAdmin && $oUser->IdTenant !== $oContact->IdTenant) {
                    $mResult = false;
                } else {
                    $mResult = true;
                }
            } elseif ($oContact->Storage === StorageType::Personal || $oContact->Storage === StorageType::AddressBook) {
                $dBPrefix = Api::GetSettings()->DBPrefix;
                $sql = "select ab.*, sab.access, ca.Id as addressbook_id, cu.Id as UserId from " . $dBPrefix . "adav_shared_addressbooks sab 
				left join " . $dBPrefix . "adav_addressbooks ab on sab.addressbook_id = ab.id
					left join " . $dBPrefix . "core_users cu on ab.principaluri = CONCAT('principals/', cu.PublicId)
						left join " . $dBPrefix . "contacts_addressbooks ca on ca.UUID = ab.uri
							where sab.principaluri = ? and cu.Id = ?";
                if ($oContact->Storage === StorageType::AddressBook) {
                    $sql .= ' and ca.Id = ' . $oContact->AddressBookId;
                } elseif ($oContact->Storage === StorageType::Personal) {
                    $sql .= ' and ca.Id is null';
                }

                $stmt = Api::GetPDO()->prepare($sql);

                $stmt->execute([
                    Constants::PRINCIPALS_PREFIX . $oUser->PublicId,
                    $oContact->IdUser
                ]);

                $abook = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($abook) {
                    if ((int) $abook['access'] === Access::NoAccess) {
                        $mResult = false;
                    } elseif (isset($Access)) {
                        if ($Access === (int) $abook['access'] && $Access === Access::Write) {
                            $mResult = true;
                        } else {
                            $mResult = false;
                        }
                    } else {
                        $mResult = true;
                    }

                    return true; // break other subscriptions
                }
            }
        }
    }

    public function onGetContactSuggestions(&$aArgs, &$mResult)
    {
        if ($aArgs['Storage'] === 'all' || $aArgs['Storage'] === StorageType::Shared) {
            $mResult[StorageType::Shared] = \Aurora\Modules\Contacts\Module::Decorator()->GetContacts(
                $aArgs['UserId'],
                StorageType::Shared,
                0,
                $aArgs['Limit'],
                $aArgs['SortField'],
                $aArgs['SortOrder'],
                $aArgs['Search']
            );
        }
    }

    public function onAfterGetAddressBooks(&$aArgs, &$mResult)
    {
        if (!is_array($mResult)) {
            $mResult = [];
        }
        foreach ($mResult as $key => $abook) {
            $mResult[$key]['Shares'] = self::Decorator()->GetSharesForAddressbook($aArgs['UserId'], $abook['Id']);
        }
        $mResult = array_merge(
            $mResult,
            $this->GetAddressbooks($aArgs['UserId'])
        );
    }

    public function onPopulateContactModel(&$oContact, &$mResult)
    {
        if ($oContact instanceof Contact) {
            $aStorageParts = \explode('-', $oContact->Storage);
            if (is_array($aStorageParts) && count($aStorageParts) === 3 && $aStorageParts[0] === StorageType::Shared) {
                $abooks = $this->GetAddressbooks($oContact->IdUser);
                foreach ($abooks as $abook) {
                    if ($abook['Id'] === $oContact->Storage) {
                        if ($aStorageParts[2] === StorageType::Personal) {
                            $oContact->Storage = StorageType::Personal;
                        } else {
                            $oContact->Storage = StorageType::AddressBook;
                            $oContact->AddressBookId = (int) $aStorageParts[2];
                        }

                        $oContact->IdUser = (int) $aStorageParts[1];
                        break;
                    }
                }
            }
        }
    }

    public function UpdateAddressbookShare($UserId, $Id, $Shares)
    {
        $mResult = true;

        Api::checkUserRoleIsAtLeast(UserRole::NormalUser);
        Api::CheckAccess($UserId);

        if (!isset($Id) || !is_array($Shares)) {
            throw new InvalidArgumentException("", Notifications::InvalidInputParameter);
        }

        try {
            $oUser = Api::getUserById($UserId);
            $currentABookShares = $this->_getSharesForAddressbook($UserId, $Id);

            $newABookShares = [];
            foreach ($Shares as $share) {
                if (isset($share['GroupId'])) {
                    $aUsers = CoreModule::Decorator()->GetGroupUsers($oUser->IdTenant, (int) $share['GroupId']);
                    foreach ($aUsers as $aUser) {
                        $newABookShares[] = [
                            'PublicId' => $aUser['PublicId'],
                            'Access' => (int) $share['Access'],
                            'GroupId' => (int) $share['GroupId'],
                        ];
                    }
                } else {
                    $share['GroupId'] = 0;
                    $newABookShares[] = $share;
                }
            }

            $currentShares = array_map(function ($share) {
                return \json_encode([
                    basename($share['principaluri']),
                    $share['group_id']
                ]);
            }, $currentABookShares);

            $newShares = array_map(function ($share) {
                return \json_encode([
                    $share['PublicId'],
                    $share['GroupId']
                ]);
            }, $newABookShares);

            $sharesToDelete = array_diff($currentShares, $newShares);
            $sharesToCreate = array_diff($newShares, $currentShares);
            $sharesToUpdate = array_intersect($currentShares, $newShares);

            if (count($sharesToDelete) > 0) {
                $this->deleteShareByPublicIds($UserId, $Id, $sharesToDelete);
            }

            foreach ($newABookShares as $share) {
                $sharePublicIdAndGroupId = \json_encode([
                    $share['PublicId'],
                    $share['GroupId']
                ]);
                if (in_array($sharePublicIdAndGroupId, $sharesToCreate)) {
                    $this->createShare($UserId, $Id, $share);
                }
                if (in_array($sharePublicIdAndGroupId, $sharesToUpdate)) {
                    $this->updateShare($UserId, $Id, $share);
                }
            }

            $mResult = true;
        } catch (\Exception $oException) {
            Api::LogException($oException);
            $mResult = false;
        }

        return $mResult;
    }

    public function LeaveShare($UserId, $Id)
    {
        Api::checkUserRoleIsAtLeast(UserRole::NormalUser);
        Api::CheckAccess($UserId);

        $userPublicId = Api::getUserPublicIdById($UserId);

        $sharedAddressBook =  Capsule::connection()->table('adav_shared_addressbooks')
            ->where('principaluri', Constants::PRINCIPALS_PREFIX . $userPublicId)
            ->where('addressbook_id', $Id)
            ->where('group_id', 0)
            ->first();

        if ($sharedAddressBook) {
            Capsule::connection()->table('adav_shared_addressbooks')
            ->where('principaluri', Constants::PRINCIPALS_PREFIX . $userPublicId)
            ->where('addressbook_id', $Id)
            ->where('group_id', 0)
            ->update(['access' => Access::NoAccess]);
        } else {
            $stmt = Api::GetPDO()->prepare("insert into " . Api::GetSettings()->DBPrefix . "adav_shared_addressbooks
			(principaluri, access, addressbook_id, addressbookuri, group_id)
			values (?, ?, ?, ?, ?)");
            $stmt->execute([Constants::PRINCIPALS_PREFIX . $userPublicId,  Access::NoAccess, $Id, UUIDUtil::getUUID(), 0]);
        }

        return true;
    }

    public function onAfterDeleteGroup($aArgs, &$mResult)
    {
        if ($mResult) {
            $dBPrefix = Api::GetSettings()->DBPrefix;
            $stmt = Api::GetPDO()->prepare("delete from " . $dBPrefix . "adav_shared_addressbooks where group_id = ?");
            $stmt->execute([$aArgs['GroupId']]);
        }
    }

    /**
     * @ignore
     * @param array $aArgs Arguments of event.
     * @param mixed $mResult Is passed by reference.
     */
    public function onBeforeDeleteUser($aArgs, &$mResult)
    {
        if (isset($aArgs['UserId'])) {
            $this->oBeforeDeleteUser = Api::getUserById($aArgs['UserId']);
        }
    }

    /**
     * @ignore
     * @param array $aArgs Arguments of event.
     * @param mixed $mResult Is passed by reference.
     */
    public function onAfterDeleteUser($aArgs, $mResult)
    {
        if ($mResult && $this->oBeforeDeleteUser instanceof User) {
            $dBPrefix = Api::GetSettings()->DBPrefix;
            $stmt = Api::GetPDO()->prepare("delete from " . $dBPrefix . "adav_shared_addressbooks where principaluri = ?");
            $stmt->execute([Constants::PRINCIPALS_PREFIX . $this->oBeforeDeleteUser->PublicId]);
        }
    }

    public function onAfterAddUsersToGroup($aArgs, &$mResult)
    {
        if ($mResult) {
            foreach ($aArgs['UserIds'] as $iUserId) {
                $userPublicId = Api::getUserPublicIdById($iUserId);
                $sUserPrincipalUri = Constants::PRINCIPALS_PREFIX . $userPublicId;

                $dBPrefix = Api::GetSettings()->DBPrefix;
                $stmt = Api::GetPDO()->prepare("select distinct addressbook_id, access from " . $dBPrefix . "adav_shared_addressbooks where group_id = ?");
                $stmt->execute([$aArgs['GroupId']]);
                $shares = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($shares as $share) {
                    if (is_array($share)) {
                        $stmt = Api::GetPDO()->prepare("insert into " . $dBPrefix . "adav_shared_addressbooks
						(principaluri, access, addressbook_id, addressbookuri, group_id)
						values (?, ?, ?, ?, ?)");
                        $stmt->execute([$sUserPrincipalUri, $share['access'], $share['addressbook_id'], UUIDUtil::getUUID(), $aArgs['GroupId']]);
                    }
                }
            }
        }
    }

    public function onAfterCreateUser($aArgs, &$mResult)
    {
        if ($mResult) {
            $oUser = User::find($mResult);
            if ($oUser) {
                $oGroup = CoreModule::getInstance()->GetAllGroup($oUser->IdTenant);
                if ($oGroup) {
                    $newArgs = [
                        'GroupId' => $oGroup->Id,
                        'UserIds' => [$mResult]
                    ];
                    $newResult = true;
                    $this->onAfterAddUsersToGroup($newArgs, $newResult);
                }
            }
        }
    }

    public function onAfterUpdateUser($aArgs, &$mResult)
    {
        if ($mResult) {
            $groupIds = $aArgs['GroupIds'];
            $userId = $aArgs['UserId'];

            if ($groupIds !== null) {
                $userPublicId = Api::getUserPublicIdById($userId);
                $sUserPrincipalUri = Constants::PRINCIPALS_PREFIX . $userPublicId;

                $dBPrefix = Api::GetSettings()->DBPrefix;
                $stmt = Api::GetPDO()->prepare("select * from " . $dBPrefix . "adav_shared_addressbooks where group_id <> 0 and principaluri = ?");
                $stmt->execute([$sUserPrincipalUri]);
                $shares = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                $currentGroupsIds = [];
                if (is_array($shares)) {
                    $currentGroupsIds = array_map(function ($share) {
                        return $share['group_id'];
                    }, $shares);
                }

                $groupsIdsToDelete = array_diff($currentGroupsIds, $groupIds);
                $groupsIdsToCreate = array_diff($groupIds, $currentGroupsIds);

                if (count($groupsIdsToDelete) > 0) {
                    $stmt = Api::GetPDO()->prepare("delete from " . $dBPrefix . "adav_shared_addressbooks 
					where group_id in (" . \implode(',', $groupsIdsToDelete) . ") and principaluri = ?");
                    $stmt->execute([$sUserPrincipalUri]);
                }

                if (count($groupsIdsToCreate) > 0) {
                    $stmt = Api::GetPDO()->prepare("select distinct addressbook_id, access, group_id from " . $dBPrefix . "adav_shared_addressbooks where group_id in (" . \implode(',', $groupsIdsToCreate) . ")");
                    $stmt->execute();
                    $shares = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    foreach ($shares as $share) {
                        if (is_array($share)) {
                            $stmt = Api::GetPDO()->prepare("insert into " . $dBPrefix . "adav_shared_addressbooks
							(principaluri, access, addressbook_id, addressbookuri, group_id)
							values (?, ?, ?, ?, ?)");
                            $stmt->execute([$sUserPrincipalUri, $share['access'], $share['addressbook_id'], UUIDUtil::getUUID(), $share['group_id']]);
                        }
                    }
                }
            }
        }
    }

    public function onAfterRemoveUsersFromGroup($aArgs, &$mResult)
    {
        if ($mResult) {
            $principals = [];
            foreach ($aArgs['UserIds'] as $iUserId) {
                $oUser = Api::getUserById($iUserId);
                $principals[] = Constants::PRINCIPALS_PREFIX . $oUser->PublicId;
            }

            if (count($principals) > 0) {
                $dBPrefix = Api::GetSettings()->DBPrefix;
                $stmt = Api::GetPDO()->prepare("delete from " . $dBPrefix . "adav_shared_addressbooks where principaluri in (" . \implode(',', $principals) . ") and group_id = ?");
                $stmt->execute([$aArgs['GroupId']]);
            }
        }
    }

    public function onContactQueryBuilder(&$aArgs, &$query)
    {
        $userPublicId = Api::getUserPublicIdById($aArgs['UserId']);

        if (isset($aArgs['Query'])) {
            $aArgs['Query']->leftJoin('adav_shared_addressbooks', 'adav_cards.addressbookid', '=', 'adav_shared_addressbooks.addressbook_id');
        }
        $query->orWhere(function ($q) use ($userPublicId, $aArgs) {
            $q->where('adav_shared_addressbooks.principaluri', Constants::PRINCIPALS_PREFIX . $userPublicId);
            if (is_array($aArgs['UUID'])) {
                if (count($aArgs['UUID']) > 0) {
                    $q->whereIn('adav_cards.id', $aArgs['UUID']);
                }
            } else {
                $q->where('adav_cards.id', $aArgs['UUID']);
            }
        });
    }

    public function onBeforeCreateContact(&$aArgs, &$mResult)
    {
        if (isset($aArgs['Contact'])) {
            if (isset($aArgs['UserId'])) {
                $aArgs['Contact']['UserId'] = $aArgs['UserId'];
            }
            $this->populateStorage($aArgs['Contact'], $mResult);
        }
    }

    /**
     *
     */
    public function populateStorage(&$aArgs)
    {
        if (isset($aArgs['Storage'], $aArgs['UserId'])) {
            $aStorageParts = \explode('-', $aArgs['Storage']);
            if (count($aStorageParts) > 1) {
                $iAddressBookId = $aStorageParts[1];
                if ($aStorageParts[0] === StorageType::Shared) {
                    if (!is_numeric($iAddressBookId)) {
                        return;
                    }
                    $aArgs['Storage'] = $aStorageParts[0];
                    $aArgs['AddressBookId'] = $iAddressBookId;
                }
            }
        }
    }

    public function onAfterCheckAccessToAddressBook(&$aArgs, &$mResult)
    {
        if (isset($aArgs['User'], $aArgs['AddressBookId'])) {
            $query = Capsule::connection()->table('adav_addressbooks')
                ->select('adav_addressbooks.id')
                ->leftJoin('adav_shared_addressbooks', 'adav_addressbooks.id', '=', 'adav_shared_addressbooks.addressbook_id')
                ->where('adav_shared_addressbooks.principaluri', Constants::PRINCIPALS_PREFIX . $aArgs['User']->PublicId)
                ->where('adav_addressbooks.id', $aArgs['AddressBookId']);
            if (isset($aArgs['Access'])) {
                if (is_array($aArgs['Access'])) {
                    $query->whereIn('access', $aArgs['Access']);
                } else {
                    $query->where('access', $aArgs['Access']);
                }
            }
            $mResult = !!$query->first();
            if ($mResult) {
                return true;
            }
        };
    }

    /**
     *
     */
    public function onBeforeUpdateAddressbookShare(&$aArgs)
    {
        if (isset($aArgs['Id'], $aArgs['UserId'])) {
            $aStorageParts = \explode('-', $aArgs['Id']);
            if (count($aStorageParts) > 1) {
                $iAddressBookId = $aStorageParts[1];

                if (!is_numeric($iAddressBookId)) {
                    return;
                }
                $aArgs['Id'] = $iAddressBookId;
            } elseif (isset($aStorageParts[0])) {
                $storagesMapToAddressbooks = \Aurora\Modules\Contacts\Module::Decorator()->GetStoragesMapToAddressbooks();
                if (isset($storagesMapToAddressbooks[$aStorageParts[0]])) {
                    $addressbookUri = $storagesMapToAddressbooks[$aStorageParts[0]];
                    $userPublicId = Api::getUserPublicIdById($aArgs['UserId']);
                    if ($userPublicId) {
                        $row = Capsule::connection()->table('adav_addressbooks')
                            ->where('principaluri', Constants::PRINCIPALS_PREFIX . $userPublicId)
                            ->where('uri', $addressbookUri)
                            ->select('adav_addressbooks.id as addressbook_id')->first();
                        if ($row) {
                            $aArgs['Id'] = $row->addressbook_id;
                        }
                    }
                }
            }
        }
    }
}
