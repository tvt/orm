<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
*/

namespace Doctrine\ORM\Tools;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadataInfo;

/**
 * Performs strict validation of the mapping schema
 *
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link        www.doctrine-project.com
 * @since       1.0
 * @author      Benjamin Eberlei <kontakt@beberlei.de>
 * @author      Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author      Jonathan Wage <jonwage@gmail.com>
 * @author      Roman Borschel <roman@code-factory.org>
 */
class SchemaValidator
{
    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @param EntityManager $em
     */
    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    /**
     * Checks the internal consistency of all mapping files.
     *
     * There are several checks that can't be done at runtime or are too expensive, which can be verified
     * with this command. For example:
     *
     * 1. Check if a relation with "mappedBy" is actually connected to that specified field.
     * 2. Check if "mappedBy" and "inversedBy" are consistent to each other.
     * 3. Check if "referencedColumnName" attributes are really pointing to primary key columns.
     * 4. Check if there are public properties that might cause problems with lazy loading.
     *
     * @return array
     */
    public function validateMapping()
    {
        $errors = array();
        $cmf = $this->em->getMetadataFactory();
        $classes = $cmf->getAllMetadata();

        foreach ($classes AS $class) {
            if ($ce = $this->validateClass($class)) {
                $errors[$class->name] = $ce;
            }
        }

        return $errors;
    }

    /**
     * Validate a single class of the current
     *
     * @param ClassMetadataInfo $class
     * @return array
     */
    public function validateClass(ClassMetadataInfo $class)
    {
        $ce = array();
        $cmf = $this->em->getMetadataFactory();

        foreach ($class->associationMappings AS $fieldName => $assoc) {
            if (!$cmf->hasMetadataFor($assoc['targetEntity'])) {
                $ce[] = "The target entity '" . $assoc['targetEntity'] . "' specified on " . $class->name . '#' . $fieldName . ' is unknown.';
                return $ce;
            }

            if ($assoc['mappedBy'] && $assoc['inversedBy']) {
                $ce[] = "The association " . $class . "#" . $fieldName . " cannot be defined as both inverse and owning.";
            }

            $targetMetadata = $cmf->getMetadataFor($assoc['targetEntity']);

            /* @var $assoc AssociationMapping */
            if ($assoc['mappedBy']) {
                if ($targetMetadata->hasField($assoc['mappedBy'])) {
                    $ce[] = "The association " . $class->name . "#" . $fieldName . " refers to the owning side ".
                            "field " . $assoc['targetEntity'] . "#" . $assoc['mappedBy'] . " which is not defined as association.";
                }
                if (!$targetMetadata->hasAssociation($assoc['mappedBy'])) {
                    $ce[] = "The association " . $class->name . "#" . $fieldName . " refers to the owning side ".
                            "field " . $assoc['targetEntity'] . "#" . $assoc['mappedBy'] . " which does not exist.";
                } else if ($targetMetadata->associationMappings[$assoc['mappedBy']]['inversedBy'] == null) {
                    $ce[] = "The field " . $class->name . "#" . $fieldName . " is on the inverse side of a ".
                            "bi-directional relationship, but the specified mappedBy association on the target-entity ".
                            $assoc['targetEntity'] . "#" . $assoc['mappedBy'] . " does not contain the required ".
                            "'inversedBy' attribute.";
                } else  if ($targetMetadata->associationMappings[$assoc['mappedBy']]['inversedBy'] != $fieldName) {
                    $ce[] = "The mappings " . $class->name . "#" . $fieldName . " and " .
                            $assoc['targetEntity'] . "#" . $assoc['mappedBy'] . " are ".
                            "incosistent with each other.";
                }
            }

            if ($assoc['inversedBy']) {
                if ($targetMetadata->hasField($assoc['inversedBy'])) {
                    $ce[] = "The association " . $class->name . "#" . $fieldName . " refers to the inverse side ".
                            "field " . $assoc['targetEntity'] . "#" . $assoc['inversedBy'] . " which is not defined as association.";
                }
                if (!$targetMetadata->hasAssociation($assoc['inversedBy'])) {
                    $ce[] = "The association " . $class->name . "#" . $fieldName . " refers to the inverse side ".
                            "field " . $assoc['targetEntity'] . "#" . $assoc['inversedBy'] . " which does not exist.";
                } else if ($targetMetadata->associationMappings[$assoc['inversedBy']]['mappedBy'] == null) {
                    $ce[] = "The field " . $class->name . "#" . $fieldName . " is on the owning side of a ".
                            "bi-directional relationship, but the specified mappedBy association on the target-entity ".
                            $assoc['targetEntity'] . "#" . $assoc['mappedBy'] . " does not contain the required ".
                            "'inversedBy' attribute.";
                } else  if ($targetMetadata->associationMappings[$assoc['inversedBy']]['mappedBy'] != $fieldName) {
                    $ce[] = "The mappings " . $class->name . "#" . $fieldName . " and " .
                            $assoc['targetEntity'] . "#" . $assoc['inversedBy'] . " are ".
                            "incosistent with each other.";
                }

                // Verify inverse side/owning side match each other
                $targetAssoc = $targetMetadata->associationMappings[$assoc['inversedBy']];
                if ($assoc['type'] == ClassMetadata::ONE_TO_ONE && $targetAssoc['type'] !== ClassMetadata::ONE_TO_ONE){
                    $ce[] = "If association " . $class->name . "#" . $fieldName . " is one-to-one, then the inversed " .
                            "side " . $targetMetadata->name . "#" . $assoc['inversedBy'] . " has to be one-to-one as well.";
                } else if ($assoc['type'] == ClassMetadata::MANY_TO_ONE && $targetAssoc['type'] !== ClassMetadata::ONE_TO_MANY){
                    $ce[] = "If association " . $class->name . "#" . $fieldName . " is many-to-one, then the inversed " .
                            "side " . $targetMetadata->name . "#" . $assoc['inversedBy'] . " has to be one-to-many.";
                } else if ($assoc['type'] == ClassMetadata::MANY_TO_MANY && $targetAssoc['type'] !== ClassMetadata::MANY_TO_MANY){
                    $ce[] = "If association " . $class->name . "#" . $fieldName . " is many-to-many, then the inversed " .
                            "side " . $targetMetadata->name . "#" . $assoc['inversedBy'] . " has to be many-to-many as well.";
                }
            }

            if ($assoc['isOwningSide']) {
                if ($assoc['type'] == ClassMetadataInfo::MANY_TO_MANY) {
                    foreach ($assoc['joinTable']['joinColumns'] AS $joinColumn) {
                        if (!isset($class->fieldNames[$joinColumn['referencedColumnName']])) {
                            $ce[] = "The referenced column name '" . $joinColumn['referencedColumnName'] . "' does not " .
                                    "have a corresponding field with this column name on the class '" . $class->name . "'.";
                            break;
                        }

                        $fieldName = $class->fieldNames[$joinColumn['referencedColumnName']];
                        if (!in_array($fieldName, $class->identifier)) {
                            $ce[] = "The referenced column name '" . $joinColumn['referencedColumnName'] . "' " .
                                    "has to be a primary key column.";
                        }
                    }
                    foreach ($assoc['joinTable']['inverseJoinColumns'] AS $inverseJoinColumn) {
                        if (!isset($targetMetadata->fieldNames[$inverseJoinColumn['referencedColumnName']])) {
                            $ce[] = "The inverse referenced column name '" . $inverseJoinColumn['referencedColumnName'] . "' does not " .
                                    "have a corresponding field with this column name on the class '" . $targetMetadata->name . "'.";
                            break;
                        }

                        $fieldName = $targetMetadata->fieldNames[$inverseJoinColumn['referencedColumnName']];
                        if (!in_array($fieldName, $targetMetadata->identifier)) {
                            $ce[] = "The referenced column name '" . $inverseJoinColumn['referencedColumnName'] . "' " .
                                    "has to be a primary key column.";
                        }
                    }

                    if (count($targetMetadata->getIdentifierColumnNames()) != count($assoc['joinTable']['inverseJoinColumns'])) {
                        $ce[] = "The inverse join columns of the many-to-many table '" . $assoc['joinTable']['name'] . "' " .
                                "have to contain to ALL identifier columns of the target entity '". $targetMetadata->name . "', " .
                                "however '" . implode(", ", array_diff($targetMetadata->getIdentifierColumnNames(), array_values($assoc['relationToTargetKeyColumns']))) .
                                "' are missing.";
                    }

                    if (count($class->getIdentifierColumnNames()) != count($assoc['joinTable']['joinColumns'])) {
                        $ce[] = "The join columns of the many-to-many table '" . $assoc['joinTable']['name'] . "' " .
                                "have to contain to ALL identifier columns of the source entity '". $class->name . "', " .
                                "however '" . implode(", ", array_diff($class->getIdentifierColumnNames(), array_values($assoc['relationToSourceKeyColumns']))) .
                                "' are missing.";
                    }

                } else if ($assoc['type'] & ClassMetadataInfo::TO_ONE) {
                    foreach ($assoc['joinColumns'] AS $joinColumn) {
                        if (!isset($targetMetadata->fieldNames[$joinColumn['referencedColumnName']])) {
                            $ce[] = "The referenced column name '" . $joinColumn['referencedColumnName'] . "' does not " .
                                    "have a corresponding field with this column name on the class '" . $targetMetadata->name . "'.";
                            break;
                        }

                        $fieldName = $targetMetadata->fieldNames[$joinColumn['referencedColumnName']];
                        if (!in_array($fieldName, $targetMetadata->identifier)) {
                            $ce[] = "The referenced column name '" . $joinColumn['referencedColumnName'] . "' " .
                                    "has to be a primary key column.";
                        }
                    }

                    if (count($class->getIdentifierColumnNames()) != count($assoc['joinColumns'])) {
                        $ids = array();
                        foreach ($assoc['joinColumns'] AS $joinColumn) {
                            $ids[] = $joinColumn['name'];
                        }

                        $ce[] = "The join columns of the association '" . $assoc['fieldName'] . "' " .
                                "have to match to ALL identifier columns of the source entity '". $class->name . "', " .
                                "however '" . implode(", ", array_diff($class->getIdentifierColumnNames(), $ids)) .
                                "' are missing.";
                    }
                }
            }

            if (isset($assoc['orderBy']) && $assoc['orderBy'] !== null) {
                foreach ($assoc['orderBy'] AS $orderField => $orientation) {
                    if (!$targetMetadata->hasField($orderField)) {
                        $ce[] = "The association " . $class->name."#".$fieldName." is ordered by a foreign field " .
                                $orderField . " that is not a field on the target entity " . $targetMetadata->name;
                    }
                }
            }
        }

        foreach ($class->reflClass->getProperties(\ReflectionProperty::IS_PUBLIC) as $publicAttr) {
            if ($publicAttr->isStatic()) {
                continue;
            }
            $ce[] = "Field '".$publicAttr->getName()."' in class '".$class->name."' must be private ".
                    "or protected. Public fields may break lazy-loading.";
        }

        foreach ($class->subClasses AS $subClass) {
            if (!in_array($class->name, class_parents($subClass))) {
                $ce[] = "According to the discriminator map class '" . $subClass . "' has to be a child ".
                        "of '" . $class->name . "' but these entities are not related through inheritance.";
            }
        }

        return $ce;
    }

    /**
     * Check if the Database Schema is in sync with the current metadata state.
     *
     * @return bool
     */
    public function schemaInSyncWithMetadata()
    {
        $schemaTool = new SchemaTool($this->em);

        $allMetadata = $this->em->getMetadataFactory()->getAllMetadata();
        return (count($schemaTool->getUpdateSchemaSql($allMetadata, true)) == 0);
    }
}
