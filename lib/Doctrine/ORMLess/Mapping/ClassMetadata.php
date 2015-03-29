<?php

namespace Doctrine\ORMLess\Mapping;

class ClassMetadata implements ClassMetadataInterface
{
    /**
     * @var string
     */
    public $name;

    /**
     * @var array
     */
    public $identifier = array();

    /**
     * @var array
     */
    public $fieldNames = array();

    /**
     * @var array
     */
    public $fieldMappings = array();

    /**
     * @var \ReflectionClass
     */
    public $reflClass;

    /**
     * @param string $className
     */
    public function __construct($className)
    {
        $this->name = $className;
        $this->reflClass = new \ReflectionClass($className);
    }

    /**
     * @return array $fieldMappings
     */
    public function autoMapFields()
    {
        foreach ($this->reflClass->getProperties() as $property) {
            $this->mapField(array(
                'fieldName' => $property->getName(),
            ));
        }
    }

    /**
     * @param array $mapping
     */
    public function mapField(array $mapping)
    {
        $this->fieldMappings[$mapping['fieldName']] = $mapping;
        $this->fieldNames[] = $mapping['fieldName'];
    }

    /**
     * Gets the fully-qualified class name of this persistent class.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Gets the mapped identifier field name.
     *
     * The returned structure is an array of the identifier field names.
     *
     * @return array
     */
    public function getIdentifier()
    {
        return $this->identifier;
    }

    /**
     * Gets the ReflectionClass instance for this mapped class.
     *
     * @return \ReflectionClass
     */
    public function getReflectionClass()
    {
        return $this->reflClass;
    }

    /**
     * Checks if the given field name is a mapped identifier for this class.
     *
     * @param string $fieldName
     *
     * @return bool
     */
    public function isIdentifier($fieldName)
    {
        return in_array($fieldName, $this->getIdentifierFieldNames());
    }

    /**
     * Checks if the given field is a mapped property for this class.
     *
     * @param string $fieldName
     *
     * @return bool
     */
    public function hasField($fieldName)
    {
        return in_array($fieldName, $this->fieldNames);
    }

    /**
     * A numerically indexed list of field names of this persistent class.
     *
     * This array includes identifier fields if present on this class.
     *
     * @return array
     */
    public function getFieldNames()
    {
        return $this->fieldNames;
    }

    /**
     * An array of field mappings for this persistent class indexed by field name.
     *
     * @return array
     */
    public function getFieldMappings()
    {
        return $this->fieldMappings;
    }

    /**
     * Returns an array of identifier field names numerically indexed.
     *
     * @return array
     */
    public function getIdentifierFieldNames()
    {
        return $this->identifier;
    }
}