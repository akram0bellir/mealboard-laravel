<?php

namespace App\Models;

use App\Support\Roles;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The existing `user` table (string ids, bcrypt passwords that may start with $2a$,
 * UTC datetime(3) columns written by hand).
 */
class User extends Authenticatable
{
    protected $table = 'user';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['password', 'tokenKey'];

    /** No "remember me" column in this table. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function fullName(): string
    {
        $name = trim($this->firstname . ' ' . $this->lastname);
        return $name !== '' ? $name : (string) $this->email;
    }

    public function role(): string
    {
        return Roles::normalize($this->User_Type);
    }

    public function isActive(): bool
    {
        return (int) $this->Status === 1;
    }
}
