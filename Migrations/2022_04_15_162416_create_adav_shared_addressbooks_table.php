<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

class CreateAdavSharedAddressbooksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Capsule::schema()->create('adav_shared_addressbooks', function (Blueprint $table) {
            $table->increments('id');
            $table->string('principaluri')->index();
            $table->unsignedTinyInteger('access')->default(0);
            $table->unsignedInteger('addressbook_id');
            $table->foreign('addressbook_id')->references('id')->on('adav_addressbooks')->cascadeOnDelete();
            $table->uuid('addressbookuri');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Capsule::schema()->dropIfExists('adav_shared_addressbooks');
    }
}
