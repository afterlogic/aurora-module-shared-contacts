<?php

namespace Aurora\Modules\SharedContacts\Tests\Unit;

use Aurora\Modules\Contacts\Enums\StorageType;
use Aurora\Modules\SharedContacts\Module;
use Aurora\System\Api;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

class FakeUser
{
    public $Id;
    public $PublicId;
    public $Role = 0;

    public function __construct($id, $publicId)
    {
        $this->Id = $id;
        $this->PublicId = $publicId;
    }
}

class TestableModule extends Module
{
    public $sharedWithAll = null;

    public function GetSharedWithAllAddressbook($UserId)
    {
        return $this->sharedWithAll;
    }
}

class PrepareFiltersFromStorageTest extends TestCase
{
    private $module;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->schema()->create('adav_addressbooks', function ($table) {
            $table->increments('id');
            $table->string('principaluri');
        });
        $capsule->schema()->create('adav_shared_addressbooks', function ($table) {
            $table->increments('id');
            $table->string('principaluri');
            $table->integer('addressbook_id');
        });
        $capsule->schema()->create('adav_cards', function ($table) {
            $table->increments('id');
            $table->integer('addressbookid');
        });

        $capsule->schema()->create('contacts_cards', function ($table) {
            $table->increments('Id');
            $table->integer('CardId');
            $table->integer('AddressBookId');
        });

        Capsule::table('adav_addressbooks')->insert([
            ['id' => 1, 'principaluri' => 'principals/user@example.com'],
            ['id' => 2, 'principaluri' => 'principals/owner@example.com'],
            ['id' => 3, 'principaluri' => 'principals/owner@example.com'],
            ['id' => 4, 'principaluri' => 'principals/owner@example.com'],
        ]);
        Capsule::table('adav_shared_addressbooks')->insert([
            ['principaluri' => 'principals/user@example.com', 'addressbook_id' => 1],
            ['principaluri' => 'principals/user@example.com', 'addressbook_id' => 2],
            ['principaluri' => 'principals/user@example.com', 'addressbook_id' => 3],
        ]);
        Capsule::table('adav_cards')->insert([
            ['id' => 10, 'addressbookid' => 1],
            ['id' => 20, 'addressbookid' => 2],
            ['id' => 30, 'addressbookid' => 3],
            ['id' => 40, 'addressbookid' => 4],
        ]);
        Capsule::table('contacts_cards')->insert([
            ['CardId' => 10, 'AddressBookId' => 1],
            ['CardId' => 20, 'AddressBookId' => 2],
            ['CardId' => 30, 'AddressBookId' => 3],
            ['CardId' => 40, 'AddressBookId' => 4],
        ]);

        Api::skipCheckUserRole(true);
        Api::GetModuleManager();
        $this->module = new TestableModule(dirname(__DIR__, 4) . '/');

        (new \ReflectionProperty(Api::class, 'usersCache'))->setValue(null, [
            100 => new FakeUser(100, 'user@example.com'),
            200 => new FakeUser(200, 'nobody@example.com'),
        ]);
    }

    protected function tearDown(): void
    {
        Api::skipCheckUserRole(false);
        (new \ReflectionProperty(Api::class, 'usersCache'))->setValue(null, []);
    }

    private function buildQuery($userId, $storage, $addressBookId = null)
    {
        $query = Capsule::table('contacts_cards')
            ->select('adav_cards.id')
            ->join('adav_cards', 'contacts_cards.CardId', '=', 'adav_cards.id');

        $query->where(function ($whereQuery) use ($query, $userId, $storage, $addressBookId) {
            $aArgs = ['UserId' => $userId, 'Storage' => $storage, 'AddressBookId' => $addressBookId, 'Query' => $query];
            $this->module->onPrepareFiltersFromStorage($aArgs, $whereQuery);
        });

        return $query->orderBy('adav_cards.id');
    }

    private function ids($query)
    {
        return array_map('intval', $query->pluck('id')->all());
    }

    public function testAddressBookIdsAreLiteralSoTheIndexCanBeUsed()
    {
        $sql = strtolower($this->buildQuery(100, StorageType::All)->toSql());

        $this->assertStringNotContainsString('(select', $sql);
        $this->assertStringNotContainsString('exists', $sql);
    }

    public function testReturnsBooksSharedByOthersButNotOwnBooks()
    {
        $this->assertSame([20, 30], $this->ids($this->buildQuery(100, StorageType::All)));
    }

    public function testSharedStorageIsLimitedToTheRequestedAddressBook()
    {
        $this->assertSame([30], $this->ids($this->buildQuery(100, StorageType::Shared, 3)));
    }

    public function testSharedWithAllBookIsIncludedForAllStorage()
    {
        $this->module->sharedWithAll = ['id' => 4];

        $this->assertSame([20, 30, 40], $this->ids($this->buildQuery(100, StorageType::All)));
    }

    public function testUserWithoutSharedBooksGetsNoContacts()
    {
        $this->assertSame([], $this->ids($this->buildQuery(200, StorageType::Shared)));
    }

    public function testSharedFlagIsSelected()
    {
        $row = $this->buildQuery(100, StorageType::All)->first();
        $this->assertEquals(1, $row->Shared);

        $sql = $this->buildQuery(200, StorageType::Shared)->toSql();
        $this->assertStringContainsString('false as Shared', $sql);
    }
}
