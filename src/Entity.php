<?php
/********************************************************************************
 *   Apache License, Version 2.0                                                *
 *                                                                              *
 *   Copyright [2020] [Nurlan Mukhanov <nurike@gmail.com>]                      *
 *                                                                              *
 *   Licensed under the Apache License, Version 2.0 (the "License");            *
 *   you may not use this file except in compliance with the License.           *
 *   You may obtain a copy of the License at                                    *
 *                                                                              *
 *       http://www.apache.org/licenses/LICENSE-2.0                             *
 *                                                                              *
 *   Unless required by applicable law or agreed to in writing, software        *
 *   distributed under the License is distributed on an "AS IS" BASIS,          *
 *   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.   *
 *   See the License for the specific language governing permissions and        *
 *   limitations under the License.                                             *
 *                                                                              *
 ********************************************************************************/

namespace DBD\Entity;

use DBD\Common\Singleton;
use DBD\Entity\Common\Enforcer;
use DBD\Entity\Common\EntityException;
use DBD\Entity\Common\MapperException;
use DBD\Entity\Interfaces\FullEntity;
use DBD\Entity\Interfaces\OnlyDeclaredPropertiesEntity;
use DBD\Entity\Interfaces\StrictlyFilledEntity;
use DBD\Entity\Interfaces\SyntheticEntity;
use DBD\Entity\Join\ManyToMany;
use DBD\Entity\Join\OneToMany;
use Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionObject;

abstract class Entity
{
    const SCHEME = "abstract";
    const TABLE = "abstract";

    /**
     * Конструктор модели
     *
     * @param array|null $data
     * @param int $maxLevels
     * @param int $currentLevel
     *
     * @throws EntityException
     * @throws MapperException
     * @throws ReflectionException
     */
    public function __construct(array $data = null, int $maxLevels = 2, int $currentLevel = 0)
    {
        $calledClass = get_class($this);

        if (!$this instanceof SyntheticEntity)
            Enforcer::__add(__CLASS__, $calledClass);

        $map = self::map();

        if (!isset(EntityCache::$mapCache[$calledClass])) {

            $columnsDefinition = $map->getOriginFieldNames();

            EntityCache::$mapCache[$calledClass][EntityCache::ARRAY_MAP] = $columnsDefinition;
            EntityCache::$mapCache[$calledClass][EntityCache::ARRAY_REVERSE_MAP] = array_flip($columnsDefinition);

            /*            if ($this instanceof FullEntity or $this instanceof StrictlyFilledEntity) {
                            foreach (get_object_vars($this) as $propertyName => $propertyDefaultValue) {
                                if (!array_key_exists($propertyName, $columnsDefinition))
                                    throw new EntityException(sprintf("FullEntity or StrictlyFilledEntity %s has unmapped property '%s'", $calledClass, $propertyName));
                            }
                        }*/

            // У нас может быть цепочка классов, где какой-то конечный уже не имеет интерфейса OnlyDeclaredPropertiesEntity
            // соответственно нам надо собрать все переменные всех дочерних классов, даже если они расширяют друг друга
            if ($this instanceof OnlyDeclaredPropertiesEntity)
                $this->collectDeclarationsOnly(new ReflectionObject($this), $calledClass);
        }

        if ($this instanceof OnlyDeclaredPropertiesEntity) {
            foreach (get_object_vars($this) as $varName => $varValue) {
                if (!isset(EntityCache::$mapCache[$calledClass][EntityCache::DECLARED_PROPERTIES][$varName])) {
                    unset($this->$varName);
                    EntityCache::$mapCache[$calledClass][EntityCache::UNSET_PROPERTIES][$varName] = true;
                }
            }
        }

        if ($this instanceof FullEntity or $this instanceof StrictlyFilledEntity) {
            $checkAgainst = array_merge($map->getColumns(), $map->getComplex(), $map->getEmbedded(), $map->getConstraints());
            foreach (get_object_vars($this) as $propertyName => $propertyDefaultValue) {
                if (!array_key_exists($propertyName, $checkAgainst))
                    throw new EntityException(sprintf("Strict Entity %s has unmapped property '%s'", $calledClass, $propertyName));
            }
        }

        if (!isset($data))
            return;

        // Если мы определяем класс с интерфейсом OnlyDeclaredPropertiesEntity и экстендим его
        // то по сути мы не можем знать какие переменные классам нам обязательны к обработке.
        // Ладно еще если это 2 класса, а если цепочка?
        //if($this instanceof OnlyDeclaredPropertiesEntity and !$reflectionObject->isFinal())
        //	throw new EntityException("Class " . $reflectionObject->getParentClass()->getShortName() . " which implements OnlyDeclaredPropertiesEntity interface must be final");

        if ($currentLevel <= $maxLevels)
            $this->setModelData($data, $map, $maxLevels, $currentLevel);
    }

    /**
     * @return Singleton|Mapper|static
     * @throws Common\MapperException
     * @throws EntityException
     * @throws ReflectionException
     */
    final public static function map()
    {
        $calledClass = get_called_class();

        /** @var Mapper $mapClass */
        $mapClass = $calledClass . Mapper::POSTFIX;

        if (!class_exists($mapClass, false))
            throw new MapperException(sprintf("Class %s does not have Map definition", $calledClass));

        $reflection = new ReflectionClass($calledClass);
        $interfaces = $reflection->getInterfaces();

        if (isset($interfaces[SyntheticEntity::class]))
            return $mapClass::meWithoutEnforcer();
        else
            return $mapClass::me();
    }

    /**
     * @param ReflectionClass $reflectionObject
     * @param string $calledClass
     * @param string|null $parentClass
     */
    private function collectDeclarationsOnly(ReflectionClass $reflectionObject, string $calledClass, string $parentClass = null): void
    {
        foreach ($reflectionObject->getProperties() as $property) {

            $declaringClass = $property->getDeclaringClass();

            if ($declaringClass->name == $calledClass || $declaringClass->name == $parentClass)
                EntityCache::$mapCache[$calledClass][EntityCache::DECLARED_PROPERTIES][$property->name] = true;
        }

        $parentClass = $reflectionObject->getParentClass();
        $parentInterfaces = $parentClass->getInterfaces();

        if (isset($parentInterfaces[OnlyDeclaredPropertiesEntity::class]))
            $this->collectDeclarationsOnly($parentClass, $calledClass, $parentClass->name);

        /** If we have defined declaredProperties key, we must exclude some keys from reverseMap and arrayMap */
        if (isset(EntityCache::$mapCache[$calledClass][EntityCache::DECLARED_PROPERTIES])) {
            foreach (EntityCache::$mapCache[$calledClass][EntityCache::ARRAY_MAP] as $propertyName => $fieldName) {
                if (!array_key_exists($propertyName, EntityCache::$mapCache[$calledClass][EntityCache::DECLARED_PROPERTIES])) {
                    unset(EntityCache::$mapCache[$calledClass][EntityCache::ARRAY_MAP][$propertyName]);
                    unset(EntityCache::$mapCache[$calledClass][EntityCache::ARRAY_REVERSE_MAP][$fieldName]);
                }
            }
        }
    }

    /**
     * @param array|null $data
     * @param Mapper $map
     * @param int $maxLevels
     * @param int $currentLevel
     *
     * @throws EntityException
     * @throws Exception
     */
    final private function setModelData(?array $data, Mapper $map, int $maxLevels, int $currentLevel): void
    {
        $currentLevel++;

        $this->setBaseColumns($data, $map);

        $this->setConstraints($data, $map, $maxLevels, $currentLevel);

        $this->setEmbedded($data, $map);

        $this->setComplex($data, $map, $maxLevels, $currentLevel);

        $this->postProcessing();
    }

    /**
     * Reads public variables and set them to the self instance
     *
     * @param array $rowData associative array where key is column name and value is column fetched data
     * @param Mapper $mapper
     *
     * @throws EntityException
     */
    final private function setBaseColumns(array $rowData, Mapper $mapper)
    {
        $calledClass = get_called_class();

        /**
         * @var array $fieldMapping are public properties of Mapper
         * where KEY is database origin column name and VALUE is Entity class field declaration
         * Structure look like this:
         * {
         *        "person_email":             "email",
         *        "person_id":                "id",
         *        "person_is_active":         "isActive",
         *        "person_name":              "name",
         *        "person_registration_date": "registrationDate"
         * }
         * EntityCache declaration happens in out constructor only once for time savings
         */
        $fieldMapping = EntityCache::$mapCache[$calledClass][EntityCache::ARRAY_REVERSE_MAP];

        /** If it is FullEntity or StrictlyFilledEntity, we must ensure all database columns are provided */
        if ($this instanceof FullEntity or $this instanceof StrictlyFilledEntity) {
            $intersection = array_intersect_key($fieldMapping, $rowData);
            if ($intersection != $fieldMapping) {
                throw new EntityException(sprintf("Missing columns for FullEntity or StrictlyFilledEntity '%s': %s",
                        get_class($this),
                        json_encode(array_keys(array_diff_key($fieldMapping, $intersection)))
                    )
                );
            }
        }

        /**
         * @var string $originColumnName database origin column name
         * @var mixed $columnValue value of this columns
         */
        foreach ($rowData as $originColumnName => $columnValue) {

            /** process only if Entity class has such field declaration */
            if (!isset($fieldMapping[$originColumnName]))
                continue;

            /** @var string $property name of field declaration in Entity class */
            $property = $fieldMapping[$originColumnName];

            if (!property_exists($this, $property))
                continue;

            /** Note: Function names are case-insensitive, though it is usually good form to call functions as they appear in their declaration. */
            $setterMethod = "set{$property}";

            /** @var Column $fieldDefinition */
            $fieldDefinition = $mapper->$property;

            if (is_null($columnValue) and $fieldDefinition->nullable == false)
                throw new EntityException(sprintf("Column %s of %s shouldn't accept null values according Mapper definition", $originColumnName, $calledClass));

            /** We can define setter method for field definition in Entity class, so let's check it first */
            if (method_exists($this, $setterMethod)) {
                $this->$setterMethod($columnValue);
            } else {
                /** If initially column type is json, then let's parse it as JSON */
                if (stripos($fieldDefinition->originType, "json") !== false) {
                    $this->$property = json_decode($columnValue, true);
                } else {
                    /**
                     * Entity public variables should not have default values.
                     * But some times we need to have default value for column in case of $rowData has null value
                     * In this case we should not override default value if $columnValue is null
                     */
                    if (!isset($this->$property) and isset($columnValue))
                        $this->$property = $columnValue;
                }
            }
        }
    }

    /**
     * @param array $rowData
     * @param Mapper $mapper
     * @param int $maxLevels
     * @param int $currentLevel
     *
     * @throws EntityException
     * @throws MapperException
     */
    final private function setConstraints(array $rowData, Mapper $mapper, int $maxLevels, int $currentLevel)
    {
        foreach ($mapper->getConstraints() as $entityName => $constraint) {
            /**
             * Check we have data for this constraint
             * Проверяем, что у нас есть данные для данного constraint
             */
            if (!property_exists($this, $entityName) or isset(EntityCache::$mapCache[get_called_class()][EntityCache::UNSET_PROPERTIES][$entityName]))
                continue;

            if ($constraint->localColumn instanceof Column) {
                $constraintValue = isset($rowData[$constraint->localColumn->name]) ? $rowData[$constraint->localColumn->name] : null;
            } else {
                /** @var Constraint $constraint */
                $constraintValue = isset($rowData[$constraint->localColumn]) ? $rowData[$constraint->localColumn] : null;
            }

            $testForJsonString = null;

            if (isset($constraintValue) and is_string($constraintValue))
                $testForJsonString = json_decode($constraintValue);

            // Мы данные в первом прогоне могли уже сформировать в полноценный массив
            // Но в дочерние классы мы должны передавать не JSON строкой, а массивом,
            // Поэтому вертаем все назад как было
            if (isset($constraintValue) and is_array($constraintValue)) {
                $testForJsonString = $constraintValue;
                $constraintValue = json_encode($constraintValue, JSON_NUMERIC_CHECK);
            }

            // Если у нас действительно json строка
            if ($testForJsonString !== null) {
                // Если это массив объектов
                if (is_array($testForJsonString)) {
                    if ($constraint->join instanceof ManyToMany or $constraint->join instanceof OneToMany) {
                        // Разбиваем на нормальный массив, чтобы затолкать в переменную
                        $jsonDecodedField = json_decode($constraintValue, true);
                        $classVariableValue = [];

                        foreach ($jsonDecodedField as $object)
                            $classVariableValue[] = new $constraint->class($object, $maxLevels, $currentLevel);

                        $this->$entityName = $classVariableValue;
                    } else {
                        throw new EntityException("Variable '$entityName' of class {$this}");
                    }
                } else {
                    $jsonDecodedField = json_decode($constraintValue, true);
                    $this->$entityName = new $constraint->class($jsonDecodedField, $maxLevels, $currentLevel);
                }
            } else {

                /**
                 * Случай, когда мы просто делаем джоин таблицы и вытаскиваем дополнительные поля,
                 * то просто их прогоняем через класс и на выходе получим готовый объект
                 */
                if (isset($constraintValue)) {
                    $newConstraintValue = new $constraint->class($rowData, $maxLevels, $currentLevel);
                } else {
                    //throw new EntityException("Понять какие это случаи и описать их тут");
                    // Мы можем создать view, в которой не вытаскиваем данные по определенному constraint, потому что они нам не нужны
                    $newConstraintValue = null;
                    /*					if($keyFromMap === null && !isset($arrayMap[$entityName])) {

                                            $newConstraintValue = new $constraint->class($rowData, $maxLevels, $currentLevel);
                                        }
                                        else {
                                            $newConstraintValue = null;
                                        }*/
                }

                $setterMethod = "set" . ucfirst($entityName);

                if (method_exists($this, $setterMethod)) {
                    $this->$setterMethod($newConstraintValue);
                } else {
                    // Если у нас переменная класа уже инициализирована, и нету значения из базы
                    // то скорее всего этот объект является массивом данных
                    if (!isset($this->$entityName) or isset($newConstraintValue)) {
                        if (isset($newConstraintValue))
                            $this->$entityName = $newConstraintValue;
                        else if ($currentLevel <= $maxLevels)
                            $this->$entityName = new $constraint->class($rowData, $maxLevels, $currentLevel);
                    }
                }
            }
        }
    }

    /**
     * @param array|null $data
     * @param Mapper $map
     *
     * @throws Exception
     */
    final private function setEmbedded(?array $data, Mapper $map)
    {
        foreach ($map->getEmbedded() as $embeddedName => $embeddedValue) {

            if (!property_exists($this, $embeddedName) or isset(EntityCache::$mapCache[get_called_class()][EntityCache::UNSET_PROPERTIES][$embeddedName]))
                continue;

            // TODO: do not override default class name if data is null

            if (isset($data[$embeddedValue->name])) {
                if (isset($embeddedValue->dbType) and $embeddedValue->dbType == Type::Json) {
                    if (is_string($data[$embeddedValue->name])) {
                        $data[$embeddedValue->name] = json_decode($data[$embeddedValue->name], true);
                    }
                }
                if (isset($embeddedValue->entityClass)) {
                    if ($embeddedValue->isIterable) {
                        $iterables = [];
                        foreach ($data[$embeddedValue->name] as $value)
                            $iterables[] = new $embeddedValue->entityClass($value);

                        $this->$embeddedName = $iterables;
                    } else {
                        $this->$embeddedName = new $embeddedValue->entityClass($data[$embeddedValue->name]);
                    }
                } else {
                    $this->$embeddedName = $data[$embeddedValue->name];
                }
            }
        }
    }

    /**
     * @param array|null $data
     * @param Mapper $map
     * @param int $maxLevels
     * @param int $currentLevel
     *
     * @throws Exception
     */
    private function setComplex(?array $data, Mapper $map, int $maxLevels, int $currentLevel)
    {
        foreach ($map->getComplex() as $complexName => $complexValue) {
            if (!property_exists($this, $complexName) or isset(EntityCache::$mapCache[get_called_class()][EntityCache::UNSET_PROPERTIES][$complexName]))
                continue;

            $this->$complexName = new $complexValue->complexClass($data, $maxLevels, $currentLevel);
        }
    }

    /**
     * If entity data should be modified after setModelData, create same function in Entity.
     * For example it is heavy cost to aggregate some data in SQL side, any more cost efficient will do that with PHP
     *
     * @see setModelData()
     */
    protected function postProcessing(): void
    {
        /** @noinspection PhpUnnecessaryReturnInspection */
        return;
    }

    /**
     * get Entity table name
     *
     * @return string
     */
    public static function table()
    {
        $calledClass = get_called_class();

        return $calledClass::SCHEME . "." . $calledClass::TABLE;
    }
}
