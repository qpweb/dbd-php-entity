<?php

namespace DBD\Entity;

use MyCLabs\Enum\Enum;

/**
 * Class Type describes DB types
 *
 * @package Falseclock\DBD\Entity
 * @method static Type Json()
 * @method static Type Varchar()
 * @method static Type Char()
 * @method static Type BigInt()
 * @method static Type Double()
 * @method static Type Int()
 * @method static Type Array()
 */
class Type extends Enum
{
	public const Array   = "array";
	public const BigInt  = "bigint";
	public const Boolean = "boolean";
	public const Char    = "char";
	public const Double  = "double";
	public const Int     = "int";
	public const Json    = "json";
	public const Varchar = "varchar";
}