<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Tests\TestCase;

class UserProfileFieldsMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_users_table_has_phone_and_avatar_path_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'phone'));
        $this->assertTrue(Schema::hasColumn('users', 'avatar_path'));
    }

    public function test_phone_and_avatar_path_columns_are_nullable(): void
    {
        $columns = DB::select(
            "SELECT column_name, is_nullable, data_type, character_maximum_length
             FROM information_schema.columns
             WHERE table_name = 'users'
               AND column_name IN ('phone', 'avatar_path')"
        );

        $byName = [];
        foreach ($columns as $col) {
            $byName[$col->column_name] = $col;
        }

        $this->assertArrayHasKey('phone', $byName);
        $this->assertArrayHasKey('avatar_path', $byName);

        $this->assertEquals('YES', $byName['phone']->is_nullable);
        $this->assertEquals('YES', $byName['avatar_path']->is_nullable);

        $this->assertEquals('character varying', $byName['phone']->data_type);
        $this->assertEquals(20, $byName['phone']->character_maximum_length);

        $this->assertEquals('character varying', $byName['avatar_path']->data_type);
        $this->assertEquals(255, $byName['avatar_path']->character_maximum_length);
    }

    public function test_phone_and_avatar_path_can_be_set_and_read_back(): void
    {
        $id = DB::table('users')->insertGetId([
            'email' => 'profile@example.com',
            'password' => 'hashed-password',
            'phone' => '+7 999 123-45-67',
            'avatar_path' => 'avatars/abc.jpg',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $row = DB::table('users')->where('id', $id)->first();

        $this->assertEquals('+7 999 123-45-67', $row->phone);
        $this->assertEquals('avatars/abc.jpg', $row->avatar_path);
    }

    public function test_phone_and_avatar_path_default_to_null_when_omitted(): void
    {
        $id = DB::table('users')->insertGetId([
            'email' => 'minimal@example.com',
            'password' => 'hashed-password',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $row = DB::table('users')->where('id', $id)->first();

        $this->assertNull($row->phone);
        $this->assertNull($row->avatar_path);
    }
}
